# Security Verification Checklist

**Created:** 2025-10-28 13:27

This checklist verifies ongoing compliance with the WordPress.Security standards (`EscapeOutput`, `ValidatedSanitizedInput`, `NonceVerification`) across the Etch Fusion Suite codebase.

## Input Validation & Sanitization Checklist

## Nonce Verification Compliance

**Reference:** [nonce-strategy.md](nonce-strategy.md)

### Centralized Nonce Architecture

- ✅ **Single nonce action** — All AJAX requests rely on `'efs_nonce'`
- ✅ **Nonce creation** — `admin_interface.php::enqueue_admin_assets()` @89-96
- ✅ **Nonce transmission** — Delivered to JavaScript via `wp_localize_script()` (`efsData.nonce`)
- ✅ **Dual-layer verification**
  - Layer 1: `admin_interface.php::get_request_payload()` @144-169
  - Layer 2: `EFS_Base_Ajax_Handler::verify_request()` @118-156

### Handler Compliance (9/9 handlers verified)

All handlers extend `EFS_Base_Ajax_Handler` and call `verify_request()` as the first executable line:

- ✅ `class-validation-ajax.php` — @55, @136
- ✅ `class-connection-ajax.php` — @39, @132
- ✅ `class-migration-ajax.php` — @67, @101, @134, @168, @201, @230
- ✅ `class-cleanup-ajax.php` — @38
- ✅ `class-logs-ajax.php` — @38, @81
- ✅ `class-css-ajax.php` — @65, @191
- ✅ `class-media-ajax.php` — @64
- ✅ `class-content-ajax.php` — @94, @276
- ✅ `class-template-ajax.php` — @47, @100, @116, @180, @197

### Nonce Verification Flow

1. ✅ `verify_request()` calls `verify_nonce()`
2. ✅ `verify_nonce()` delegates to `check_ajax_referer( 'efs_nonce', 'nonce', false )`
3. ✅ `$die = false` enables custom JSON error handling
4. ✅ Invalid nonce results in `401` + `invalid_nonce` response
5. ✅ Audit logger records authentication success/failure

### Error Handling

- ✅ Invalid nonce → `401 Unauthorized` (`invalid_nonce`)
- ✅ Missing capability → `403 Forbidden` (`forbidden`)
- ✅ Error messages remain user friendly and localized
- ✅ Audit trail records all authentication attempts

### AJAX Handlers (`includes/ajax/handlers/`)

For each handler confirm the following:

1. ✅ Extends `EFS_Base_Ajax_Handler`
2. ✅ Calls `verify_request()` before processing
3. ✅ Applies `check_rate_limit()`
4. ✅ Uses `validate_input()` or `get_post()` for sanitized input
5. ✅ Avoids direct `$_POST`, `$_GET`, `$_REQUEST`
6. ✅ Responds with `wp_send_json_success()` / `wp_send_json_error()`
7. ✅ Logs security events via `EFS_Audit_Logger`
8. ✅ Masks sensitive data before logging

> **All handlers verified for nonce compliance. See [Nonce Verification Compliance](#nonce-verification-compliance).**

#### Handler Details

- **class-validation-ajax.php** @54-219
  - Validates `target_url` (url), `api_key` (api_key), `token` (token), `expires` (integer with min)
  - Converts internal Docker URLs via `convert_to_internal_url()`
- **class-connection-ajax.php** @38-222
  - Validates export/import credentials with URL and API key rules
  - Masks API key in error responses
- **class-migration-ajax.php** @66-258
  - Uses `get_post()` for migration IDs, batches, and configuration
  - Applies rate limits on start, process, cancel, and report actions
- **class-cleanup-ajax.php** @37-128
  - Requires confirmation text, enforces `manage_options`, logs with critical severity
- **class-logs-ajax.php** @37-110
  - Enforces nonce/capability, logs access to audit trail, no user input required
- **class-css-ajax.php** @64-221
  - Validates target URL and API key, performs recursive sanitization for CSS payloads
- **class-media-ajax.php** @62-183
  - Validates target URL and API key, tracks migration batches securely
- **class-content-ajax.php** @93-344
  - Validates `post_id` (integer min 1), target URL, API key, handles batch arrays with sanitization
- **class-template-ajax.php** @46-245
  - Uses `sanitize_array()` for template payloads, validates deletion IDs, masks sensitive data

### REST API Endpoints (`includes/api_endpoints.php`)

Verify for each endpoint:

1. ✅ Enforces CORS via `check_cors_origin()` or global filter
2. ✅ Applies rate limiting (`enforce_template_rate_limit()` or `check_rate_limit()`)
3. ✅ Validates input through `validate_request_data()` or equivalent
4. ✅ Returns `WP_REST_Response` or `WP_Error`
5. ✅ Logs violations through `EFS_Audit_Logger`

Endpoints to inspect:

- `extract_template_rest()` @89-129 — rate limit, JSON validation, sanitized controller output
- `get_saved_templates_rest()` @137-149 — read-only, rate limited
- `preview_template_rest()` @157-179 — validates integer ID with min constraint
- `delete_template_rest()` @187-209 — validates deletion ID, ensures capability via controller
- `import_template_rest()` @217-256 — validates payload arrays, sanitizes template content
- `get_plugin_status()` @408-427 — rate limited, no user input
- `handle_key_migration()` @432-483 — documents sanitized REST params and token validation
- `generate_migration_key()` @489-529 — generates tokens, no user input
- `validate_migration_token()` @535-595 — sanitizes JSON body (`token`, `expires`), returns masked API key

Special cases:

- Direct `$_SERVER['HTTP_ORIGIN']` access is sanitized with `esc_url_raw( wp_unslash() )`
- Global enforcement (`enforce_cors_globally()`) logs origin, route, and method on failure

### Admin Interface (`includes/admin_interface.php`)

- `enqueue_admin_assets()` creates nonce via `wp_create_nonce( 'efs_nonce' )` and uses `wp_localize_script()` for safe data transfer
- `get_request_payload()` verifies nonce with `$die = false`, recursively sanitizes payload, removes nonce field before returning
- Nonce field is stripped from sanitized payload before handler delegation (`unset( $payload['nonce'] )`)
- Error responses remain JSON-formatted thanks to `$die = false`
- `sanitize_payload_recursively()` provides defense-in-depth sanitization
- `resolve_ajax_handler()` pulls handlers from the container to ensure security services are injected

### Security Services

- `EFS_Input_Validator` — central validation rules, `sanitize_array_recursive()` for nested payloads
- `EFS_Rate_Limiter` — identifier-based throttling with audit logging
- `EFS_Audit_Logger` — structured logging with severity levels and sensitive data masking
- `EFS_CORS_Manager` — origin whitelisting, preflight handling, header injection

## PHPCS Compliance Checks

- Confirm `docs/phpcs-manual-fixes-backlog.md` reports zero violations
- Ensure `phpcs:ignore` directives are documented inline with justification
- Run `composer phpcs` (or `vendor/bin/phpcs --standard=phpcs.xml.dist`) before sign-off

## Edge Cases to Review

1. Exception messages: verify they do not expose user-provided data
2. Error logging: ensure `mask_sensitive_values()` covers keys (`api_key`, `token`, `password`, `secret`, `authorization`)
3. Audit log output: confirm sensitive data is redacted, review `efs_audit_logger_max_events` limit
4. JSON encoding: ensure manual JSON responses use `wp_json_encode()`
5. Redirects: verify all `wp_redirect()` calls use sanitized URLs (`esc_url_raw`)

## Testing Recommendations

1. **Manual** — submit requests with invalid nonces, missing parameters, invalid types
2. **Automated** — execute PHPCS and unit tests (`composer test`, `composer phpcs`)
3. **Security scanning** — run WPScan or Sucuri where applicable
4. **Peer review** — second reviewer validates security documentation and recent changes

## Sign-off Checklist

- [ ] All AJAX handlers verified
- [ ] All REST API endpoints verified
- [ ] Admin interface verified
- [ ] Security services integration verified
- [ ] PHPCS compliance verified
- [ ] Edge cases reviewed
- [ ] Documentation updated
- [ ] Nonce verification compliance verified (9/9 handlers)
- [ ] Nonce strategy documented in `nonce-strategy.md`
- [ ] Tests executed
