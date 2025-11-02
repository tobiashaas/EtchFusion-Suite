# Security Best Practices

**Created:** 2025-10-28 13:29

This guide defines the required patterns for maintaining WordPress.Security compliance in Etch Fusion Suite.

> **📖 Reference:** For the complete nonce implementation playbook, review [nonce-strategy.md](nonce-strategy.md).

## Adding New AJAX Handlers

1. Extend `EFS_Base_Ajax_Handler` — never build standalone AJAX classes.
2. Call `verify_request()` as the first instruction inside each handler method. The helper performs nonce validation (`check_ajax_referer( 'efs_nonce', 'nonce', false )`), capability enforcement, and audit logging — **always** return immediately when it fails.
3. Apply `check_rate_limit()` with sensible limits (authentication: 10/min, destructive actions: 5/min, reads: 30-60/min).
4. Use `validate_input()` or `get_post()` for every piece of user input; never touch superglobals directly.
5. Return data with `wp_send_json_success()` / `wp_send_json_error()`; avoid `echo`/`print`.
6. Log outcomes with `log_security_event()` and mask sensitive fields via `mask_sensitive_values()`.

### Nonce Verification Pattern

```php
if ( ! $this->verify_request( 'manage_options' ) ) {
    return; // verify_request() already sent the JSON error response
}

```

### Reference Implementation (AJAX)

- `includes/ajax/handlers/class-validation-ajax.php::validate_api_key()` (`@54-130`) demonstrates the full verify → rate limit → validate → process → respond pattern with `verify_request()` as the first line.

## Adding New REST API Endpoints

1. Register routes with a `permission_callback` that enforces authentication where possible.
2. Invoke `check_cors_origin()` (or rely on `enforce_cors_globally()`) before processing requests.
3. Reuse `EFS_Input_Validator::validate_request_data()` for JSON payloads to mirror AJAX validation.
4. Rate-limit using `enforce_template_rate_limit()` or `check_rate_limit()`.
5. Return `WP_REST_Response` or `WP_Error` objects only.
6. Audit failures through `EFS_Audit_Logger`.

### Reference Implementation (REST)

- `includes/api_endpoints.php::extract_template_rest()` for the standard flow.
- `includes/api_endpoints.php::handle_key_migration()` for parameter sanitization and token validation.

## Admin Interface Output

1. Generate nonces with `wp_create_nonce( 'efs_nonce' )` and pass them via `wp_localize_script()` (ensuring the action matches `EFS_Base_Ajax_Handler::$nonce_action`).
2. Never render HTML directly from `admin_interface.php`; defer to controllers/templates.
3. Sanitize POST payloads with `sanitize_payload_recursively()` before delegating to handlers.
4. Resolve handlers via the service container to ensure rate limiter, validator, and audit logger dependencies are injected.
5. Remember that WordPress nonces expire after 24 hours; prompt users to refresh the admin page when authentication errors occur.

## Secure Nonce Handling

### Secure Nonce Handling DO

- ✅ Use the centralized nonce action `'efs_nonce'` for every AJAX endpoint.
- ✅ Invoke `verify_request()` as the first executable line in each handler method.
- ✅ Return immediately if `verify_request()` returns `false` — the base class already dispatched the JSON error.
- ✅ Pass nonce tokens to JavaScript with `wp_localize_script()` and rely on automated escaping.
- ✅ Keep `$die = false` when calling `check_ajax_referer()` so custom JSON errors propagate correctly.
- ✅ Log authentication outcomes via `audit_logger->log_authentication_attempt()`.

### Nonce Verification DON'T

- ❌ Introduce multiple nonce actions for different handlers.
- ❌ Process request data prior to calling `verify_request()`.
- ❌ Switch `$die` to `true` in `check_ajax_referer()` — it prevents consistent JSON error handling.
- ❌ Expose sensitive debugging information in nonce failure messages.
- ❌ Assume read-only operations are exempt from nonce checks.

**Why a single nonce action?** Centralizing on `'efs_nonce'` simplifies frontend management, aligns with capability checks, and keeps the audit trail cohesive. See [nonce-strategy.md](nonce-strategy.md) for lifecycle diagrams and detailed rationale.

## Strict Comparison Best Practices

**Updated:** 2025-10-28 19:55

Strict comparisons prevent type juggling bugs that can bypass security checks. Every `in_array()` call in the codebase must pass the third parameter `true`. See [`docs/phpcs-strict-comparison-verification.md`](phpcs-strict-comparison-verification.md) for the full verification report.

### Do

- ✅ Always call `in_array( $needle, $haystack, true )` when validating against allow/deny lists.
- ✅ Use strict operators (`===`, `!==`) when comparing scalars in security-sensitive code.
- ✅ Test edge cases (numeric strings, booleans, empty strings) whenever adding new comparisons.

### Strict Comparison DON'T

- ❌ Never rely on loose comparison (`==`, `!=`) for security decisions.
- ❌ Do not omit the third parameter in `in_array()` — `in_array( '0', array( 0 ) )` returns `true` without strict mode.
- ❌ Avoid suppressing the `WordPress.PHP.StrictInArray` sniff without a documented exception.

### Strict Comparison Code Examples

```php
// Good
if ( ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
    throw new InvalidArgumentException( 'Only http/https supported.' );
}

// Bad
if ( in_array( $user_role, array( 'admin', 'editor' ) ) ) {
    grant_admin_access();
}
```

### Strict Comparison Testing & Automation

- Unit tests: `tests/unit/test-strict-comparison.php` covers validators, environment detector, audit logger, migrator filtering, and API client.
- Automation: `scripts/verify-strict-comparison.sh` enforces the `WordPress.PHP.StrictInArray` sniff and optional grep double-check.
- Composer: `composer verify-strict` (added) runs the verification script.

### When Loose Comparison Is Acceptable

Loose comparison should only appear with an inline justification and PHPCS ignore, e.g. `// phpcs:ignore WordPress.PHP.StrictInArray -- reason`. Provide a comment explaining why type coercion is safe.

### Strict Comparison Checklist

- [ ] All `in_array()` calls include the strict parameter.
- [ ] No security-critical comparison uses loose operators.
- [ ] Regression tests cover type juggling edge cases.
- [ ] Verification script run before merging.

## Yoda Conditions Best Practices

**Updated:** 2025-10-28 21:12

WordPress Coding Standards require Yoda conditions for comparisons involving literals, numbers, booleans, `null`, and constants. Compliance protects against accidental assignment bugs and keeps Etch Fusion Suite aligned with WordPress.org requirements.

### Yoda DO

- ✅ Place literals/constants on the left: `'value' === $variable`, `0 === $count`, `null === $result`, `MY_CONST === $mode`.
- ✅ Use Yoda style inside security-sensitive checks (CORS origin validation, audit log filtering, converter mappings).
- ✅ Run `composer verify-yoda` before submitting changes. The script executes PHPCS (`WordPress.PHP.YodaConditions`) and heuristic regex scans.
- ✅ Review the generated report at `docs/yoda-conditions-violations-report.md` for categorized findings.
- ✅ Add regression coverage in `tests/unit/test-yoda-conditions.php` when modifying comparison-heavy logic.

### Yoda DON'T

- ❌ Skip Yoda style for literal comparisons because it “looks cleaner”; WordPress treats this as a violation.
- ❌ Suppress `WordPress.PHP.YodaConditions` without a justification. If readability suffers (variable-to-variable comparisons), document with `// phpcs:ignore … -- Reason`.
- ❌ Rely solely on regex tooling; PHPCS remains authoritative for compliance.

### Testing & Tooling

- Verification script: `scripts/verify-yoda-conditions.sh`
- Composer alias: `composer verify-yoda`
- Strategy guide: `docs/yoda-conditions-strategy.md`
- Unit tests: `tests/unit/test-yoda-conditions.php`
- Status tracking: `TODOS.md` Phase 5 (Yoda Conditions)

### Checklist Additions

- [ ] All literal/constant/boolean/null comparisons use Yoda style.
- [ ] Any non-Yoda comparisons include `phpcs:ignore` with rationale.
- [ ] `composer verify-yoda` passes locally.
- [ ] Documentation and reports updated with timestamps when violations are addressed.

## Handling Sensitive Data

- Mask keys: `api_key`, `token`, `authorization`, `password`, `secret`.
- Avoid logging full payloads; use `mask_sensitive_values()` before persisting.
- Enforce HTTPS for external requests via the API client.
- Ensure migration tokens expire (24-hour default) and validate with `EFS_Migration_Token_Manager`.

## Error Handling Guidelines

- Use `WP_Error` in AJAX/REST contexts; include sanitized error codes and HTTP status data.
- Provide generic user messages; capture detailed context in audit logs.
- Treat validation failures as `400`, unauthorized as `401`, forbidden as `403`, rate-limited as `429`, server issues as `500`/`503`.

## PHPCS Workflow

1. Run `composer phpcs` before committing.
2. Auto-fix with `composer phpcbf` where applicable.
3. Document every `phpcs:ignore` with a justification referencing nonce or sanitization context (e.g., `EFS_Base_Ajax_Handler::get_post()` explains prior nonce verification).
4. Never ignore `WordPress.Security.EscapeOutput` or `ValidatedSanitizedInput` unless the value is sanitized immediately afterward.

## Security Testing Checklist

- Invalid nonce → expect `401` JSON error (`invalid_nonce`).
- Expired nonce → expect `401` JSON error (`invalid_nonce`).
- Missing nonce → expect `401` JSON error (`invalid_nonce`).
- Valid nonce but missing capability → expect `403` JSON error (`forbidden`).
- Rate-limit exhaustion → expect `429` JSON error with `Retry-After` header.
- Invalid input → expect `400` with structured details.
- Disallowed origin → expect `403` from CORS enforcement.

## Code Review Checklist

- [ ] Handler extends `EFS_Base_Ajax_Handler`
- [ ] `verify_request()` invoked before processing
- [ ] No direct superglobal usage
- [ ] All responses use JSON/REST helpers
- [ ] Sensitive data masked in logs
- [ ] PHPCS passes with zero violations
- [ ] Documentation updated (architecture, checklist, best practices)
- [ ] Tests executed (PHPCS, unit/integration as applicable)

## Hook Prefixing Best Practices

**Updated:** 2025-10-28 21:55

WordPress requires all globally accessible identifiers to use unique prefixes. Etch Fusion Suite maintains a dual-prefix strategy that balances brevity (`efs_`) for internal usage and clarity (`etch_fusion_suite_`) for public APIs. Adhering to this strategy prevents namespace collisions and satisfies the `WordPress.NamingConventions.PrefixAllGlobals` sniff configured in `phpcs.xml.dist`.

### Why It Matters

1. **Namespace Isolation:** Prefixed hooks and functions avoid conflicts with other plugins/themes.
2. **Security & Maintainability:** Predictable naming allows automated scans to detect unapproved global overrides.
3. **Compliance:** WordPress.org guidelines mandate unique prefixes for distributed plugins.

### Required Prefixes

- `efs_` — AJAX actions (`wp_ajax_efs_*`), options, transients, internal filters.
- `etch_fusion_suite_` — Public hooks, global helper functions, service accessors.
- `efs_security_headers_`, `efs_cors_` — Subsystem-specific filters and utilities.
- `ETCH_FUSION_SUITE_` — Constants (defined in `etch-fusion-suite.php`).

Allowed prefixes are declared in `phpcs.xml.dist`. The verification script enforces parity between configuration, code, and documentation.

### Verification Workflow

- Run `composer verify-hooks` (calls `scripts/verify-hook-prefixing.sh`).
- Use `--report` to regenerate `docs/hook-prefixing-verification-report.md`.
- Consult `docs/naming-conventions.md` for naming patterns and examples.

The script validates:

- `add_action()` / `add_filter()` registrations
- `do_action()` / `apply_filters()` dispatches
- Standalone function declarations (via token parsing)
- Intentional exceptions (e.g., `https_local_ssl_verify`) documented with `phpcs:ignore`

### DO

- ✅ Preface new AJAX actions with `wp_ajax_efs_` (and `wp_ajax_nopriv_efs_` when unauthenticated access is required).
- ✅ Use `etch_fusion_suite_` for public extensibility points and document them in PHPDoc with `@since` tags.
- ✅ Add new options/transients using `efs_` and include them in uninstall/deactivation cleanup.
- ✅ Document any WordPress core hook usage with inline `phpcs:ignore` rationale.
- ✅ Regenerate the verification report when introducing new hooks or global functions.

### DON'T

- ❌ Register unprefixed custom hooks (`add_action( 'migrators_registered', ... )`).
- ❌ Define global helper functions without the full `etch_fusion_suite_` prefix.
- ❌ Introduce new prefixes without updating `phpcs.xml.dist`, `docs/naming-conventions.md`, and the verification script.
- ❌ Remove legacy prefixes without auditing third-party integrations.

### Code Examples

```php
// ✅ AJAX registration
add_action( 'wp_ajax_efs_start_migration', array( $this, 'start_migration' ) );

// ✅ Public hook
do_action( 'etch_fusion_suite_register_migrators', $registry );

// ✅ Global function
function etch_fusion_suite_debug_log( $message, $data = null ) {
    // Implementation
}

// ✅ Intentional exception (WordPress core hook)
$args['sslverify'] = apply_filters( 'https_local_ssl_verify', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
```

### Testing & Automation

- Verification script: `scripts/verify-hook-prefixing.sh`
- Composer alias: `composer verify-hooks`
- Report: `docs/hook-prefixing-verification-report.md`
- Naming guide: `docs/naming-conventions.md`

### Hook Prefixing Review Checklist

- [ ] All new hooks follow `efs_` or `etch_fusion_suite_` prefixes.
- [ ] Global functions use the `etch_fusion_suite_` prefix and include PHPDoc.
- [ ] Intentional exceptions documented with inline `phpcs:ignore` and rationale.
- [ ] `composer verify-hooks` passes locally and the report is up to date.
- [ ] Documentation updated (naming conventions, reports, best practices).

### Date/Time Functions Best Practices

**Updated:** 2025-10-28 23:35

WordPress requires timezone-aware date/time functions. Consistent usage prevents discrepancies between server configuration and WordPress settings, and is essential for reliable security logging, token expiry, and audit trails.

#### Why Date/Time Functions Matter

1. **Timezone Consistency:** WordPress allows admins to configure site timezone (`Settings → General → Timezone`). PHP's `date()` uses the server timezone, which can differ from the configured site timezone. Using WordPress helpers guarantees that timestamps match user expectations.
2. **Hosting Independence:** Deployments across staging/production often exhibit mismatched server timezone configuration. `current_time()` and `wp_date()` abstract these differences away and ensure predictable behaviour.
3. **Security & Compliance:** Accurate timestamps underpin forensic analysis, migration token lifetimes, and audit log correlation. Misaligned timezones make it difficult to reconstruct timelines during incident response.

#### Required Practices

✅ **DO:**

- Use `current_time( 'mysql' )` when storing timestamps in the database (options, post meta, logs, migration stats).
- Use `current_time( 'timestamp' )` for Unix timestamp arithmetic (expiry calculations, comparisons).
- Use `wp_date()` for user/API output that needs formatting or localisation.
- Document timezone assumptions where behaviour varies (e.g., mention UTC expectations when using the `$gmt` parameter).
- Run `composer verify-datetime` before releases or when touching time-sensitive code.

❌ **DON'T:**

- Call `date()` or `gmdate()` in plugin code — they ignore WordPress timezone configuration.
- Mix `strtotime()`/`time()` derived values with `current_time()` results without normalising to a common reference.
- Store formatted date strings in persistent storage; keep raw timestamps and format on render.
- Hardcode timezone offsets or rely on server locale settings.

#### Date/Time Code Examples

##### Database Timestamps

```php
// ✅ Good: WordPress timezone aware
$entry = array(
    'timestamp' => current_time( 'mysql' ),
    'event'     => 'token_generated',
);

// ❌ Bad: Server timezone dependent
$entry = array(
    'timestamp' => date( 'Y-m-d H:i:s' ),
);
```

##### Formatted Output

```php
// ✅ Good: Uses wp_date() for localisation + timezone awareness
$response['expires_at'] = wp_date( 'Y-m-d H:i:s', $expires_timestamp );

// ❌ Bad: Always UTC
$response['expires_at'] = gmdate( 'Y-m-d H:i:s', $expires_timestamp );
```

##### Token Expiration

```php
// ✅ Good: Mixed use with Unix timestamp math and formatted output
$expires = current_time( 'timestamp' ) + DAY_IN_SECONDS;
$token_data = array(
    'created_at' => current_time( 'mysql' ),
    'expires_at' => wp_date( 'Y-m-d H:i:s', $expires ),
);

// ❌ Bad: Mixed timezone references (server offset + WordPress timezone)
$expires = time() + ( 24 * 3600 );
$token_data['expires_at'] = date( 'Y-m-d H:i:s', $expires );
```

#### Real-World Security Impact

- **Audit Logger (`includes/security/class-audit-logger.php`)** — Accurate event timestamps enable incident reconstruction and subscription to security dashboards.
- **Migration Token Manager (`includes/migration_token_manager.php`)** — Token creation and display rely on consistent timestamps to prevent premature invalidation or unexpected grace periods.
- **Error Handler (`includes/error_handler.php`)** — Logging pipeline uses timestamps for chronological correlation with WordPress debug logs and external monitoring tools.

#### Tooling & Verification

- Script: [`scripts/verify-datetime-functions.sh`](../scripts/verify-datetime-functions.sh)
- Composer alias: `composer verify-datetime`
- Strategy: [`docs/datetime-functions-strategy.md`](datetime-functions-strategy.md)
- Verification report: [`docs/datetime-functions-verification-report.md`](datetime-functions-verification-report.md)

The verification script runs the `WordPress.DateTime.RestrictedFunctions` sniff, scans for `date()`/`gmdate()`, inventories recommended function usage, and can regenerate documentation via `--report`.

#### Code Review Checklist Additions

- [ ] All persisted timestamps use `current_time( 'mysql' )`.
- [ ] Calculations rely on `current_time( 'timestamp' )` or normalised Unix timestamps.
- [ ] All formatted output uses `wp_date()` and avoids `date()`/`gmdate()`.
- [ ] Verification script executed as part of the change (`composer verify-datetime`).
- [ ] Documentation/report updated if new date/time usage patterns are introduced.
