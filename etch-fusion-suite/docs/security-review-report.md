# Etch Fusion Suite – Security Review Report

**Review completed:** 2025-02-07  
**Scope:** Security components, AJAX handlers, REST API endpoints, WordPress Coding Standards, edge cases, testing recommendations.

---

## 1. Security Components – Findings

### 1.1 Rate Limiter (`class-rate-limiter.php`)

| Check | Status | Notes |
|-------|--------|-------|
| Default rate limits | ✅ | AJAX 60/min, REST 30/min, auth 10/min, sensitive 5/min – appropriate for abuse prevention. |
| Sliding window | ✅ | Lines 59–66: `array_filter` keeps only timestamps where `(current_time - timestamp) < window`. |
| Transient cleanup | ✅ | `set_transient(..., $window)` (line 99) – WordPress expiration handles cleanup. |
| IP detection | ✅ | Proxy headers (CF, X-Real-IP, X-Forwarded-For) with `sanitize_text_field(wp_unslash())` and `filter_var(..., FILTER_VALIDATE_IP)`. |
| Bypass mechanisms | ✅ | No hardcoded bypass for users or IPs. |
| Transient keys | ✅ | `get_transient_key()` uses `md5($identifier)` (line 216) to avoid key length issues. |
| `get_identifier()` | ✅ | Uses `user_<id>` when logged in, otherwise `get_client_ip()` – covers both auth states. |

**Conclusion:** Rate limiter is correctly implemented and aligned with requirements.

---

### 1.2 Input Validator (`class-input-validator.php`)

| Check | Status | Notes |
|-------|--------|-------|
| Input types | ✅ | URL, text, integer, array, JSON, API key, token, migration key, post ID – all have validation methods. |
| Error codes | ✅ | Machine-readable codes (e.g. `url_required`, `text_max_length`, `migration_key_invalid_format`). |
| Sanitize before validate | ✅ | e.g. `esc_url_raw()` before URL validation, `sanitize_text_field()` for text. |
| Recursive array depth | ✅ | Max depth 20 (line 419) to prevent resource exhaustion. |
| String length limits | ✅ | Recursive string values truncated to 2048 chars (line 462). |
| User-facing messages | ✅ | Localized via `get_user_error_message()` (lines 564–635); no sensitive data exposed. |
| `validate_request_data()` | ✅ | Handles all field types via switch; exceptions caught and re-thrown with generic message. |

**Conclusion:** Input validator is comprehensive and safe; sanitization precedes validation throughout.

---

### 1.3 CORS Manager (`class-cors-manager.php`)

| Check | Status | Notes |
|-------|--------|-------|
| Whitelist-based | ✅ | No wildcard `*`; origins from settings or `get_default_origins()`. |
| Default origins | ✅ | Development-only: localhost:8888, 8889, 127.0.0.1 (lines 64–70). |
| Empty origin | ✅ | Treated as allowed (lines 79–83) for same-origin/server-to-server. |
| Preflight OPTIONS | ✅ | `handle_preflight_request()` sets CORS headers, 204, Content-Length: 0, exit (164–178). |
| CORS violations logged | ✅ | Audit logger used when origin not allowed (119–135). |
| Credentials / Vary | ✅ | `Access-Control-Allow-Credentials: true` and `Vary: Origin` set (148–149). |
| Origin normalization | ✅ | `rtrim($origin, '/')` applied to origin and allowed list for comparison. |

**Conclusion:** CORS is whitelist-based with no production wildcards; preflight and logging are correct.

---

### 1.4 Audit Logger (`class-audit-logger.php`)

| Check | Status | Notes |
|-------|--------|-------|
| Sensitive keys masked | ✅ | api_key, authorization, client_secret, key, migration_key, nonce, password, private_key, secret, token (55–66). |
| Log levels | ✅ | low, medium, high, critical (line 48). |
| Log rotation | ✅ | Default 1000 events; filter `etch_fusion_suite_audit_logger_max_events`; `array_slice(..., 0, $max_events)` (130). |
| High/critical mirroring | ✅ | `error_log()` and optional error_handler for high/critical (136–157). |
| Context limits | ✅ | Max depth 5, max 25 items per array (340–372). |
| String truncation | ✅ | Messages 300 chars, context strings 500 chars (328, 391). |
| Legacy migration | ✅ | `maybe_migrate_legacy_logs()` migrates from `b2e_security_log` (477–495). |

**Conclusion:** Audit logger masks credentials, limits size and depth, and mirrors critical events appropriately.

---

### 1.5 Security Headers (`class-security-headers.php`)

| Check | Status | Notes |
|-------|--------|-------|
| Environment-aware CSP | ✅ | Admin vs frontend via `is_admin_page()`; separate `get_admin_csp_directives()` / `get_frontend_csp_directives()`. |
| Required headers | ✅ | X-Frame-Options: SAMEORIGIN, X-Content-Type-Options: nosniff, X-XSS-Protection: 1; mode=block (37–43). |
| Referrer / Permissions | ✅ | Referrer-Policy and Permissions-Policy set (46–49). |
| Bricks overrides | ✅ | Conditional on `is_bricks_builder_request()`; script-src gets 'unsafe-eval', font-src CDN (214–223). |
| Skip conditions | ✅ | wp-login.php, DOING_CRON, OPTIONS (338–369). |
| Admin vs frontend CSP | ✅ | Both use 'unsafe-inline' for script/style where needed; Bricks adds 'unsafe-eval' only for builder. |

**Conclusion:** Headers and CSP are environment-aware; Bricks is handled only when builder is active.

---

## 2. AJAX Handlers – Verification

### 2.1 Pattern Compliance (all 9 handlers)

| Criterion | Status |
|-----------|--------|
| Extends `EFS_Base_Ajax_Handler` | ✅ All 9 |
| `verify_request()` first in each method | ✅ |
| `check_rate_limit()` with appropriate limits | ✅ |
| `validate_input()` or `get_post()` for user input | ✅ |
| No direct `$_POST`/`$_GET`/`$_REQUEST` | ✅ (only via `get_post()` with phpcs justification in base) |
| `wp_send_json_success()` / `wp_send_json_error()` | ✅ |
| `log_security_event()` for security events | ✅ |
| `mask_sensitive_values()` before logging | ✅ (base handler + audit logger) |

### 2.2 Handler-Specific Checks

- **Validation AJAX:** migration_key type, `convert_to_internal_url()`, `mask_sensitive_values()` on success payload – ✅  
- **Connection AJAX:** URL + migration key validation, feature flag whitelist, API key masked in errors – ✅  
- **Migration AJAX:** controller availability checks, rate limits (start 5/min, progress 60/min, batch 30/min), proper status codes – ✅  
- **Cleanup AJAX:** confirmation required, critical severity for cleanup execution, migration key validated before cleanup – ✅  
- **Logs AJAX:** audit logger availability, clear logged before execution, rate limits (clear 10/min, fetch 60/min) – ✅  

---

## 3. WordPress Coding Standards Compliance

| Area | Status | Notes |
|------|--------|-------|
| Nonce verification | ✅ | Central `efs_nonce`; `verify_request()` before any POST; `get_post()` has documented phpcs:ignore. |
| Input sanitization | ✅ | All POST via `get_post()` with type; arrays via `sanitize_array()`/`validate_input()`; URLs via validator or `esc_url_raw()`. |
| Output escaping | ✅ | JSON via `wp_send_json_*()`; exception messages handled by caller; audit log masks sensitive data. |
| Strict comparison | ✅ | `in_array(..., true)` used; security logic uses `===`/`!==`. |
| Yoda conditions | ✅ | Literals on left where checked in reviewed code. |
| Hook prefixing | ✅ | AJAX `wp_ajax_efs_*`; public `etch_fusion_suite_*`; internal `efs_*`. |
| Date/time | ✅ | `current_time('mysql')` / `current_time('timestamp')`; `wp_date()` for display. |

---

## 4. REST API Endpoints – Verification

| Endpoint | CORS | Rate limit | Input validation | Response type | Audit logging |
|----------|------|------------|-------------------|---------------|---------------|
| extract_template_rest | ✅ global | ✅ 15/min | ✅ validate_request_data | WP_REST_Response / WP_Error | via validator/controller |
| get_saved_templates_rest | ✅ | ✅ 30/min | read-only | WP_REST_Response | — |
| preview_template_rest | ✅ | ✅ 25/min | id (int) | WP_REST_Response / WP_Error | — |
| delete_template_rest | ✅ | ✅ 15/min | id (int) | WP_REST_Response / WP_Error | — |
| import_template_rest | ✅ | ✅ 10/min | payload, name | WP_REST_Response / WP_Error | — |
| get_plugin_status | ✅ | ✅ 30/min | none | WP_REST_Response | — |
| handle_key_migration | ✅ check_cors_origin | — | migration_key, target_url | WP_REST_Response / WP_Error | — |
| generate_migration_key | ✅ | — | target_url from param | WP_REST_Response / WP_Error | — |
| validate_migration_token | ✅ | — | validate_request_data (migration_key) | WP_REST_Response / WP_Error | — |

CORS enforced globally via `rest_request_before_callbacks` → `enforce_cors_globally()` for `/efs/v1/` routes.  
**Note:** A leftover docblock and orphan closing braces in `api_endpoints.php` (previously around “Validate request payload”) were removed during review; the file now parses correctly.

---

## 5. Edge Cases and Special Scenarios

| Item | Status |
|------|--------|
| Exception messages (service container) | ✅ Exception message uses `esc_html((string)$id)` in container (line 100). |
| Exception messages (migrator registry) | ✅ `esc_html((string)$type)` in RuntimeException (line 63). |
| `mask_sensitive_values()` (base handler) | ✅ Covers api_key, token, migration_key, authorization, password, secret. |
| Audit logger sensitive keys | ✅ Same plus client_secret, key, nonce, private_key. |
| Audit log output | ✅ Sensitive keys redacted; max events limit; high/critical mirrored. |
| JSON encoding | ✅ `wp_json_encode()` / `wp_send_json_*()`; no raw `json_encode()` for responses. |
| Redirects | ✅ No `wp_redirect()`/`wp_safe_redirect()` to user-controlled URLs without validation in reviewed code. |

---

## 6. Recommendations

1. **Ongoing:** Run `composer phpcs` (or `vendor/bin/phpcs --standard=phpcs.xml.dist`) before each release; keep `phpcs:ignore` justifications documented.
2. **Testing:** Add or maintain manual checks for invalid/missing/expired nonce, capability failure, validation errors, rate limit (429), and CORS from unauthorized origin.
3. **Peer review:** Second reviewer to validate security docs and any change touching nonce, input validation, or logging.
4. **REST:** Consider adding explicit rate limiting for `handle_key_migration` and `generate_migration_key` (e.g. auth/sensitive limits) if not already covered at infrastructure level.

---

## 7. Best Practices Adherence

The codebase aligns with the documented patterns:

- Single nonce action (`efs_nonce`) and verification in base handler.
- Consistent flow: `verify_request()` → `check_rate_limit()` → `validate_input()` / `get_post()` → process → respond.
- Centralized validation and sanitization via `EFS_Input_Validator`.
- Sensitive data masked before logging in base handler and audit logger.
- Nonce strategy documented in `nonce-strategy.md`; security verification checklist updated and sign-off completed.

---

*End of Security Review Report*
