# Security Verification Checklist

**Created:** 2025-10-28 13:27  
**Last security review:** 2025-02-07

This checklist verifies ongoing compliance with the WordPress.Security standards (`EscapeOutput`, `ValidatedSanitizedInput`, `NonceVerification`) across the Etch Fusion Suite codebase. Detailed findings from the latest review are in [security-review-report.md](security-review-report.md).

## Input Validation & Sanitization Checklist

## Nonce Verification Compliance

**Reference:** [nonce-strategy.md](nonce-strategy.md)

### Centralized Nonce Architecture

- ✅ **Single nonce action** — All AJAX requests rely on `'efs_nonce'`
- ✅ **Nonce creation** — `admin_interface.php::enqueue_admin_assets()` @89-96
- ✅ **Nonce transmission** — Delivered to JavaScript via `wp_localize_script()` (`efsData.nonce`)
- ✅ **Single-layer verification** — `EFS_Base_Ajax_Handler::verify_request()` @118-156 in each handler (admin_interface.php only creates the nonce at line 102 and passes it via wp_localize_script() at line 110)

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

- `enqueue_admin_assets()` creates the nonce via `wp_create_nonce('efs_nonce')` at line 102 and passes it to JavaScript via `wp_localize_script()` at line 110.
- All nonce verification is performed in handler classes; each handler calls `verify_request()` before any `$_POST` access via `get_post()`.

### Security Services

- `EFS_Input_Validator` — central validation rules, `sanitize_array_recursive()` for nested payloads
- `EFS_Rate_Limiter` — identifier-based throttling with audit logging
- `EFS_Audit_Logger` — structured logging with severity levels and sensitive data masking
- `EFS_CORS_Manager` — origin whitelisting, preflight handling, header injection
- `EFS_Security_Headers` — CSP (admin/frontend), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy

**Security components deep review (2025-02-07):** All five components verified against requirements. See [security-review-report.md](security-review-report.md) §1 for findings (rate limits, sliding window, input types, CORS whitelist, sensitive key masking, CSP, etc.). No bypass logic or production wildcards; recommendations in report §6.

## PHPCS Compliance Checks

- Confirm `docs/phpcs-manual-fixes-backlog.md` reports zero violations
- Ensure `phpcs:ignore` directives are documented inline with justification
- Run `composer phpcs` (or `vendor/bin/phpcs --standard=phpcs.xml.dist`) before sign-off

## Edge Cases to Review

1. ✅ Exception messages: verified – service container and migrator registry use `esc_html()` for exception content; no user-provided data exposed
2. ✅ Error logging: `mask_sensitive_values()` in base handler covers `api_key`, `token`, `migration_key`, `authorization`, `password`, `secret`; audit logger masks same plus `client_secret`, `key`, `nonce`, `private_key`
3. ✅ Audit log output: sensitive data redacted; `etch_fusion_suite_audit_logger_max_events` (default 1000) prevents unbounded growth; high/critical mirrored to error_log
4. ✅ JSON encoding: manual JSON uses `wp_json_encode()`; AJAX uses `wp_send_json_*()`
5. ✅ Redirects: no user-controlled redirects without validation in reviewed code

## Testing Recommendations

See [security-review-report.md](security-review-report.md) §6–7 for full recommendations. Summary:

1. **Manual** — Invalid nonce → 401 `invalid_nonce`; missing nonce → 401; expired nonce → 401; valid nonce, no capability → 403 `forbidden`; invalid params → 400; exceed rate limit → 429 with Retry-After; CORS from unauthorized origin → violation logged
2. **Automated** — `composer phpcs` (or `vendor/bin/phpcs --standard=phpcs.xml.dist`) must pass; unit tests for validators, rate limiter, audit logger; integration tests for AJAX handlers
3. **Security scanning** — WPScan or Sucuri where applicable
4. **Peer review** — second reviewer validates security documentation and `phpcs:ignore` justifications

## Sign-off Checklist

- [x] All AJAX handlers verified
- [x] All REST API endpoints verified
- [x] Admin interface verified
- [x] Security services integration verified
- [x] PHPCS compliance verified
- [x] Edge cases reviewed
- [x] Documentation updated
- [x] Nonce verification compliance verified (9/9 handlers)
- [x] Nonce strategy documented in `nonce-strategy.md`
- [x] Tests executed (PHPCS compliance run documented in Final Audit Sign-off below)

## Final Audit Sign-off (2025-02-07)

- **PHPCS Compliance:** `composer phpcs` executed — 0 errors, 0 warnings
- **Files Scanned:** 80+ files across includes/, ajax/, security/, templates/
- **Critical Fixes:** 3 nonce verification errors, 10 output escaping errors
- **Security Review:** All 5 security components verified, 9/9 AJAX handlers compliant
- **Documentation:** Updated security-verification-checklist.md, nonce-strategy.md
