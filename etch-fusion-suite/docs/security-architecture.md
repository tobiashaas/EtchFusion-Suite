# Etch Fusion Suite Security Architecture

**Created:** 2025-10-28 13:25

## Overview

Etch Fusion Suite implements a layered security model to satisfy the WordPress.Security coding standards (EscapeOutput, ValidatedSanitizedInput, NonceVerification) and to harden the migration workflow between Bricks and Etch installations. This document catalogues the existing defences, provides code references, and highlights the operational patterns that future changes must honour.

## 1. Input Validation & Sanitization Strategy

### 1.1 Centralised Access via `EFS_Base_Ajax_Handler::get_post()`

- Location: `includes/ajax/class-base-ajax-handler.php` @165-200
- All AJAX handlers extend `EFS_Base_Ajax_Handler`.
- `get_post()` is the *only* method in the codebase that touches `$_POST`. The method is guarded by a `phpcs:ignore` comment explaining that `verify_request()` must be executed before any access occurs.
- Sanitization strategies include `text`, `url`, `key`, `int`, `float`, `bool`, `array`, and `raw`. Arrays are recursively sanitized through `sanitize_array()`.
- Example pattern:

  ```php
  if ( ! $this->verify_request() ) {
      return;
  }
  $target_url = $this->get_post( 'target_url', '', 'url' );
  ```

### 1.2 Validation Service `EFS_Input_Validator`

- Location: `includes/security/class-input-validator.php`
- `EFS_Base_Ajax_Handler::validate_input()` delegates to `EFS_Input_Validator::validate_request_data()` to enforce consistent rules across all handlers.
- Supported rules: `url`, `api_key`, `token`, `integer` (with `min`/`max`), `text` (with `max_length`), `array`, and nested arrays via `sanitize_array_recursive()`.
- Validation failures throw `InvalidArgumentException`; the handler converts these into JSON errors and audit logs.
- `phpcs:ignore` at validator line 85 (`EscapeOutput.ExceptionNotEscaped`) is documented because exception messages are sanitized before display.

### 1.3 REST Endpoints

- Location: `includes/api_endpoints.php`
- REST handlers use `validate_request_data()` as well, mirroring the AJAX pattern.
- `WP_REST_Request::get_params()` is documented to return sanitized parameters. Additional validation is handled by the token manager when necessary.

## 2. Output Escaping Strategy

### 2.1 AJAX Responses

- All handlers respond via `wp_send_json_success()` / `wp_send_json_error()`. Both functions JSON-encode payloads and set appropriate headers.
- Direct `echo`/`print` usage is forbidden.

### 2.2 REST Responses

- REST endpoints return `WP_REST_Response` objects or `WP_Error` instances. Data is JSON-encoded by WordPress.

### 2.3 Admin Interface

- Data is passed to JavaScript via `wp_localize_script()`, which escapes values automatically.
- PHP controllers are responsible for rendering sanitized output when required; `admin_interface.php` avoids direct HTML rendering.

## 3. Authentication & Authorization

### 3.1 Nonce Verification & Capability Checks

> **See also:** [Nonce Strategy](nonce-strategy.md) for the canonical reference, plus [Security Verification Checklist](security-verification-checklist.md) and [Security Best Practices](security-best-practices.md) for implementation guidance.

- `EFS_Base_Ajax_Handler::verify_request()` @118-156 executes the **three-layer security model**: (1) nonce verification through `verify_nonce()` @98-101, (2) capability enforcement via `check_capability()` @109-111 with `current_user_can( 'manage_options' )`, and (3) audit logging through `audit_logger->log_authentication_attempt()`.
- Failure paths return JSON immediately — invalid nonce → `401` (`invalid_nonce`), missing capability → `403` (`forbidden`) — ensuring handlers never process unauthenticated data.
- Nonces originate in `admin_interface.php::enqueue_admin_assets()` @89-96 (`wp_create_nonce( 'efs_nonce' )`) and are exposed to JavaScript via `wp_localize_script()` in the `efsData.nonce` property.
- Dual-layer verification is enforced: `admin_interface.php::get_request_payload()` @144-169 pre-validates incoming AJAX requests before delegating to handler classes.

#### Nonce Lifecycle (Summary)

1. **Creation** — `enqueue_admin_assets()` generates `'efs_nonce'` and localizes it for the admin bundle.
2. **Transmission** — Admin JavaScript attaches the nonce as the `nonce` POST field on every AJAX request.
3. **Verification** — `get_request_payload()` performs the first check (`$die = false` enables custom JSON errors), handlers run `verify_request()` for the primary guardrail, logging results through the audit logger.

#### Handler Compliance

All nine AJAX handler classes extend the base handler and call `verify_request()` as the first executable statement, guaranteeing consistent nonce enforcement across: validation, connection, migration, cleanup, logs, CSS, media, content, and template operations.

### 3.2 Rate Limiting

- `EFS_Base_Ajax_Handler::check_rate_limit()` @223-252 enforces per-action limits using `EFS_Rate_Limiter`.
- Rate limits are documented per handler (see Section 5).

### 3.3 Audit Logging

- `EFS_Audit_Logger` integrates with nonce verification, rate limiting, validation failures, and CORS checks.
- Logged events include `auth_failure`, `auth_success`, `rate_limit_exceeded`, `invalid_input`, `cors_violation`, `cleanup_executed`, and `logs_cleared`.

## 4. CORS Protection

### 4.1 Manager

- Location: `includes/security/class-cors-manager.php`
- Handles origin whitelisting, header injection, and global filters.

### 4.2 REST Enforcement

- `EFS_API_Endpoints::check_cors_origin()` @325-353 sanitizes `$_SERVER['HTTP_ORIGIN']` with `esc_url_raw( wp_unslash() )`.
- `enforce_cors_globally()` @367-403 ensures all `/efs/v1/` routes respect CORS.
- Comments explain why direct `$_SERVER` access is acceptable after sanitization and why the origin is retrieved twice for accurate audit logging.

## 5. Rate Limiting

| Handler / Endpoint | Rate Limit | Window |
|--------------------|------------|--------|
| validation_ajax::validate_api_key | 10 req/min | 60s |
| validation_ajax::validate_migration_token | 10 req/min | 60s |
| connection_ajax::test_export_connection | 10 req/min | 60s |
| connection_ajax::test_import_connection | 10 req/min | 60s |
| migration_ajax::start_migration | 5 req/min | 60s |
| migration_ajax::process_batch | 30 req/min | 60s |
| migration_ajax::generate_migration_key | 5 req/min | 60s |
| cleanup_ajax::cleanup_etch | 5 req/min | 120s |
| logs_ajax::get_logs | 60 req/min | 60s |
| api_endpoints::extract_template_rest | 15 req/min | 60s |
| api_endpoints::delete_template_rest | 15 req/min | 60s |
| api_endpoints::preview_template_rest | 25 req/min | 60s |
| api_endpoints::handle_key_migration | 10 req/min | 60s |
| api_endpoints::validate_migration_token | 10 req/min | 60s |

## 6. Audit Logging

- `EFS_Base_Ajax_Handler::verify_request()` logs authentication outcomes.
- `check_rate_limit()` logs rate limit breaches via `log_rate_limit_exceeded()`.
- `validate_input()` logs invalid data with sensitive values masked (`mask_sensitive_values()`).
- `EFS_API_Endpoints::enforce_cors_globally()` logs origin, route, and method when blocked.
- Audit data can be retrieved via `EFS_Audit_Logger::get_security_logs()`.

## 7. Security Patterns by Component

### 7.1 AJAX Handlers (9 total)

- Standard flow: `verify_request()` → `check_rate_limit()` → `validate_input()`/`get_post()` → business logic → `wp_send_json_success()`/`wp_send_json_error()`.
- Sensitive values (API keys, tokens) are masked before logging.
- `class-template-ajax.php` uses `sanitize_array()` for nested payloads.

### 7.2 REST API Endpoints

- `EFS_API_Endpoints::extract_template_rest()` demonstrates the canonical rate-limit → validate → process pattern.
- `handle_key_migration()` documents WP REST parameter sanitization and token validation.
- `validate_migration_token()` sanitizes JSON payloads field-by-field and delegates token validation to the token manager.

### 7.3 Admin Interface

- `enqueue_admin_assets()` generates nonces and uses `wp_localize_script()` for safe data transfer.
- `get_request_payload()` verifies the nonce with `$die = false` to allow JSON handling, sanitizes recursively, and strips the nonce before returning.
- AJAX handlers are resolved from the service container to ensure dependencies (rate limiter, validator, audit logger) are injected.

## 8. PHPCS Compliance Status

- Reference: `docs/phpcs-manual-fixes-backlog.md` (zero violations).
- Backup branch: `backup/phpcbf-20251028-135453`.
- Relevant commit: `feat: PHPCS tooling refinements and Phase 2-12 compliance`.
- All `phpcs:ignore` usages include explanatory comments referencing nonce verification or controlled sanitization.

## 9. Code References

| Module | Key File | Highlights |
|--------|----------|------------|
| Base AJAX | `includes/ajax/class-base-ajax-handler.php` @98-512 | Nonce verification, rate limiting, validation, sanitization |
| Validation AJAX | `includes/ajax/handlers/class-validation-ajax.php` | Canonical handler pattern, URL/API key validation |
| Admin Interface | `includes/admin_interface.php` | Nonce generation, payload sanitization, handler delegation |
| REST API | `includes/api_endpoints.php` | CORS checks, REST validation, token handling |
| CORS Manager | `includes/security/class-cors-manager.php` | Origin whitelist enforcement |
| Input Validator | `includes/security/class-input-validator.php` | Central validation logic |
| Rate Limiter | `includes/security/class-rate-limiter.php` | Throttling implementation |
| Audit Logger | `includes/security/class-audit-logger.php` | Structured security logging |

## 10. Future Work Checklist

- [ ] Review new handlers for adherence to Section 7 patterns.
- [ ] Update rate limiting table when adding new actions.
- [ ] Document any additional `phpcs:ignore` directives with justification.
- [ ] Keep CORS origin documentation synchronized with `EFS_CORS_Manager` logic.

---

For quick verification steps, see `docs/security-verification-checklist.md`. For development guidance, consult `docs/security-best-practices.md`.
