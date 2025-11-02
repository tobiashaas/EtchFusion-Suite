# Date/Time Functions Strategy

**Created:** 2025-10-28 23:30

This guide documents how Etch Fusion Suite handles date and time operations while staying compliant with WordPress best practices and coding standards.

## 1. Overview

- ✅ 100% compliant with WordPress date/time standards (verified 2025-10-28 23:55).
- ✅ Enforced via `WordPress.DateTime.RestrictedFunctions` sniff configured in `phpcs.xml.dist`.
- ✅ Verified by `scripts/verify-datetime-functions.sh` (Composer alias: `composer verify-datetime`).
- ✅ All timestamps respect the WordPress timezone setting from **Settings → General → Timezone**.

## 2. WordPress Date/Time Functions

### 2.1 Recommended Functions

1. **`current_time('mysql')`** — Database timestamps

   - Returns a MySQL-formatted datetime string (`Y-m-d H:i:s`).
   - Respects WordPress timezone setting.
   - Use for storing timestamps in database fields, options, and metadata.

   ```php
   $log_entry = array(
       'timestamp' => current_time( 'mysql' ),
       'message'   => $message,
   );
   ```

2. **`current_time('timestamp')`** — Unix timestamps

   - Returns an integer Unix timestamp.
   - Respects WordPress timezone setting.
   - Use for calculations, comparisons, and expiry logic.

   ```php
   $expires_at = current_time( 'timestamp' ) + DAY_IN_SECONDS;
   ```

3. **`wp_date( $format, $timestamp )`** — Formatted output

   - Returns a formatted date string, localized and timezone-aware.
   - Use for user-facing strings, API responses, and logs.

   ```php
   $response = array(
       'expires_at' => wp_date( 'Y-m-d H:i:s', $timestamp ),
   );
   ```

### 2.2 Prohibited Functions

- **`date()`** — Uses server timezone, not WordPress timezone. Replaced with `wp_date()` or `current_time()`.
- **`gmdate()`** — Always UTC; ignores WordPress timezone. Use `current_time( 'mysql', true )` when UTC is required.

## 3. Current Usage Inventory

| File | Function | Context |
| ---- | -------- | ------- |
| `includes/ajax/handlers/class-media-ajax.php` | `current_time('mysql')` (line 170) | Media migration timestamp |
| `includes/api_endpoints.php` | `current_time('mysql')` (lines 536, 582, 669) | Migration/token timestamps |
| `includes/api_endpoints.php` | `wp_date()` (line 580) | Token expiration formatting |
| `includes/error_handler.php` | `current_time('mysql')` (lines 241, 282) | Error & warning timestamps |
| `includes/error_handler.php` | `current_time('Y-m-d H:i:s')` (line 402) | Debug log formatting |
| `includes/gutenberg_generator.php` | `wp_date()` (lines 82, 942) | Template timestamps |
| `includes/migration_token_manager.php` | `current_time('mysql')` (lines 75, 143) | Token creation timestamps |
| `includes/migration_token_manager.php` | `wp_date()` (lines 76, 144, 170, 171, 237, 277) | Token expiry / debug logging |
| `includes/security/class-audit-logger.php` | `current_time('mysql')` (line 121) | Security event timestamp |
| `includes/services/class-content-service.php` | `current_time('mysql')` (line 225) | Content migration meta |
| `includes/services/class-migration-service.php` | `current_time('mysql')` (lines 151, 323, 359, 362, 370, 448) | Migration stats, progress, step tracking |
| `includes/services/class-template-extractor-service.php` | `current_time('mysql')` (line 161) | Extraction timestamp |
| `includes/templates/class-etch-template-generator.php` | `current_time('mysql')` (line 59) | Template generation timestamp |
| `etch-fusion-suite.php` | `current_time('Y-m-d H:i:s')` (line 353) | Debug log timestamp |

**Totals:** 20 calls to `current_time()` (18 × `'mysql'`, 2 × andere Formate), 9 calls zu `wp_date()`, 0 verbotene Funktionen.

## 4. Usage Patterns

### Pattern 1: Database Timestamps

```php
$entry = array(
    'timestamp' => current_time( 'mysql' ),
    'message'   => $message,
);
```

### Pattern 2: Formatted Output

```php
$response = array(
    'expires_at' => wp_date( 'Y-m-d H:i:s', $timestamp ),
);
```

### Pattern 3: Unix Timestamps

```php
$expires_at = current_time( 'timestamp' ) + ( 24 * HOUR_IN_SECONDS );
```

### Pattern 4: UTC Timestamps (when needed)

```php
$utc_timestamp = current_time( 'mysql', true );
```

## 5. Why This Matters

- **Timezone Consistency:** WordPress timezone setting is respected; avoids mismatches between server and user locales.
- **Hosting Independence:** Eliminates reliance on server configuration for accurate timestamps.
- **Internationalization:** `wp_date()` supports WordPress localization, ensuring translated month/day names.
- **Security:** Accurate timestamps are crucial for audit logs, token expiry, and forensic analysis.

## 6. Common Pitfalls to Avoid

1. **Using `date()` for timestamps**

   ```php
   // ❌ Wrong
   $timestamp = date( 'Y-m-d H:i:s' );

   // ✅ Correct
   $timestamp = current_time( 'mysql' );
   ```

2. **Mixing timezone-aware and naive functions**

   ```php
   // ❌ Wrong
   $start = current_time( 'timestamp' );
   $end   = strtotime( '+1 day' );

   // ✅ Correct
   $start = current_time( 'timestamp' );
   $end   = $start + DAY_IN_SECONDS;
   ```

3. **Formatting with `gmdate()`**

   ```php
   // ❌ Wrong
   $formatted = gmdate( 'F j, Y', $timestamp );

   // ✅ Correct
   $formatted = wp_date( 'F j, Y', $timestamp );
   ```

## 7. Verification & Tooling

```bash
# Run PHPCS restricted function check
vendor/bin/phpcs --standard=phpcs.xml.dist --sniffs=WordPress.DateTime.RestrictedFunctions includes/

# Run verification script
./scripts/verify-datetime-functions.sh

# Via Composer alias
composer verify-datetime
composer verify-datetime -- --report
```

The verification script performs:

- PHPCS scan for restricted functions.
- Grep-style analysis for `date()` / `gmdate()` usage.
- Inventory of `current_time()` and `wp_date()` calls with context.
- Optional regeneration of `docs/datetime-functions-verification-report.md` when `--report` is supplied.

## 8. Best Practices Checklist

- [x] Use `current_time('mysql')` for persisted timestamps.
- [x] Use `wp_date()` for formatted strings.
- [x] Use `current_time('timestamp')` for calculations.
- [x] Never use `date()` or `gmdate()` in plugin code.
- [x] Test flows with multiple WordPress timezone settings.
- [x] Run `composer verify-datetime` before releasing.

## 9. Testing Recommendations

- **Unit Tests:** Validate timestamp generation under varying timezone settings.
- **Integration Tests:** Exercise migration flows to confirm stored timestamps align with expectations.
- **Manual Tests:** Change WordPress timezone, trigger migrations/API calls, verify consistent timestamps in UI and database.

## 10. References

- WordPress `current_time()` — <https://developer.wordpress.org/reference/functions/current_time/>
- WordPress `wp_date()` — <https://developer.wordpress.org/reference/functions/wp_date/>
- WordPress Coding Standards — <https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/>
- Verification script — `scripts/verify-datetime-functions.sh`

For detailed verification results, see `docs/datetime-functions-verification-report.md`.
