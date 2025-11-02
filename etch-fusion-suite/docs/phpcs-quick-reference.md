# Quick Reference: PHPCS Compliance

**Last Updated:** 2025-10-29

---

## Commands

**Check for violations:**

```bash
composer phpcs
composer phpcs:report
composer phpcs:full
```

**Auto-fix violations:**

```bash
composer phpcbf
```

**Run verifications:**

```bash
composer verify-phpcs
composer verify-strict
composer verify-yoda
composer verify-hooks
composer verify-datetime
```

**Install pre-commit hook:**

```bash
composer install-hooks
```

---

## Security Patterns

### Nonce Verification (AJAX Handlers)

```php
if ( ! $this->verify_request( 'manage_options' ) ) {
    return; // verify_request() sends JSON error
}
```

### Input Validation

```php
$rules = array(
    'target_url' => array( 'type' => 'url', 'required' => true ),
    'api_key'    => array( 'type' => 'api_key', 'required' => true ),
);
$validated = $this->validate_input( $_POST, $rules );
```

### Output Escaping

```php
wp_send_json_success( $result );
wp_send_json_error( array( 'message' => $error ) );

return new WP_REST_Response( $data, 200 );
```

---

## Yoda Conditions

**Rule:** Place literal/constant on the left side.

```php
if ( 'value' === $variable ) {
}
if ( 123 === $count ) {
}
if ( true === $is_active ) {
}
if ( null === $value ) {
}
if ( CONSTANT === $var ) {
}
```

**Avoid:**

```php
if ( $variable === 'value' ) {
}
if ( $count === 123 ) {
}
```

---

## Strict Comparisons

**Rule:** Always set the third parameter of `in_array()` to `true`.

```php
if ( in_array( $value, $array, true ) ) {
}
```

**Why:** Prevents type juggling (`'0' == 0`).

---

## Hook Prefixing

**AJAX Actions:**

```php
add_action( 'wp_ajax_efs_validate_api_key', array( $this, 'validate_api_key' ) );
```

**Public Hooks:**

```php
do_action( 'etch_fusion_suite_register_migrators', $registry );
```

**Global Functions:**

```php
function etch_fusion_suite_debug_log( $message ) {
}
```

**Constants:**

```php
define( 'ETCH_FUSION_SUITE_VERSION', '0.11.0' );
```

---

## Date/Time Functions

**Database Timestamps:**

```php
$log_entry = array(
    'timestamp' => current_time( 'mysql' ),
);
```

**Formatted Output:**

```php
$response = array(
    'expires_at' => wp_date( 'Y-m-d H:i:s', $timestamp ),
);
```

**Avoid:** `date()` and `gmdate()`.

---

## Error Logging

**Preferred:**

```php
$this->error_handler->log_error( 'E401', array( 'context' => $data ) );
$this->error_handler->log_info( 'Migration started' );
```

**Use `error_log()` only when necessary:**

```php
// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Global debug helper for CLI usage
error_log( $message );
```

---

## `phpcs:ignore` Usage

**When to use:**

- Infrastructure files that cannot inject dependencies
- WordPress APIs that require direct calls
- Legacy compatibility shims

**Always include rationale:**

```php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping handled by wp_kses_post()
echo $html;
```

**Never ignore:** EscapeOutput, ValidatedSanitizedInput, NonceVerification.

---

## Pre-commit Workflow

1. `composer install-hooks`
2. `composer phpcbf`
3. `composer phpcs`
4. Commit changes

---

## Documentation References

- `docs/security-architecture.md`
- `docs/nonce-strategy.md`
- `docs/naming-conventions.md`
- `docs/yoda-conditions-strategy.md`
- `docs/datetime-functions-strategy.md`
- `docs/phpcs-lessons-learned.md`
- `docs/phpcs-final-verification-report.md`
