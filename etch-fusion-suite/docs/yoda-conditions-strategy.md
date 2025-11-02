# Yoda Conditions Strategy

**Updated:** 2025-10-28 21:05

## 1. Overview

WordPress Coding Standards require the use of Yoda conditions (placing literals/constants on the left side of comparisons) via the `WordPress.PHP.YodaConditions` sniff. Although previous documentation claimed zero violations, manual review uncovered remaining non-Yoda comparisons across `includes/`. This strategy ensures complete compliance, provides automation tooling, and establishes verification workflows.

## 2. What Are Yoda Conditions?

- **Definition:** Place literals/constants on the left side: `'value' === $variable`
- **Origin:** Named after Yoda’s speech pattern. Ensures accidental assignments throw syntax errors instead of passing silently.
- **WordPress Requirement:** Enforced by the WordPress-Core ruleset and mandatory for WordPress.org submissions.

## 3. Conversion Rules

### Always Convert

- Strings: `$var === 'value'` → `'value' === $var`
- Numbers: `$count === 0` → `0 === $count`
- Booleans: `$is_active === true` → `true === $is_active`
- Null: `$value === null` → `null === $value`
- Constants: `$mode === SOME_CONSTANT` → `SOME_CONSTANT === $mode`

### Context-Dependent

- Variable-to-variable comparisons → choose order that favors readability.
- Comparisons with method calls/array access → review manually; Yoda style may reduce clarity.

### Exceptions

- Leave variable-to-variable comparisons when reordering harms readability. If deviating, document with `// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Reason`.

## 4. Confirmed Violations (Initial Review)

1. `includes/security/class-cors-manager.php` (line 91): `$allowed === $origin`
2. `includes/security/class-audit-logger.php` (line 271): `$log['severity'] === $severity`
3. `includes/css_converter.php` (line 1379): `$selector === '.' . $class_name || $selector === $class_name`
4. `includes/gutenberg_generator.php` (lines 853, 908): `$class_name === $bricks_class_name`, `$style_id === $etch_id`

Additional directories (AJAX handlers, services, controllers, repositories, migrators) require verification.

## 5. Conversion Process

1. Run verification script: `./scripts/verify-yoda-conditions.sh --report`
2. Review `docs/yoda-conditions-violations-report.md` (auto-generated)
3. Apply conversions:
   - Use Yoda style for literal/constant comparisons
   - Review complex cases manually
4. Add `// phpcs:ignore` with justification if Yoda harms readability
5. Re-run script and PHPCS until violations reach zero

## 6. Testing Strategy

- PHPUnit: `vendor/bin/phpunit tests/unit/test-yoda-conditions.php`
- Ensure regression coverage for CORS validation, audit logging, CSS conversion, and Gutenberg generation
- Playwright E2E (if applicable) for full workflow validation

## 7. Tooling

- Verification script: `scripts/verify-yoda-conditions.sh`
- Composer alias: `composer verify-yoda`
- Report: `docs/yoda-conditions-violations-report.md`
- Unit tests: `tests/unit/test-yoda-conditions.php`

## 8. Common Pitfalls

- Short-circuit logic: ensure reordering does not alter evaluation intent.
- Array key comparisons: confirm readability when swapping.
- Method calls: keep natural order if Yoda reduces clarity; document exceptions.

## 9. Prevention

- Run `composer verify-yoda` pre-commit.
- Integrate script in CI (GitHub Actions).
- Add Yoda checks to code review templates and onboarding guides.

## 10. References

- [WordPress Coding Standards – Yoda Conditions](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/#yoda-conditions)
- [`phpcs.xml.dist`](../phpcs.xml.dist)
- [Verification Script](../scripts/verify-yoda-conditions.sh)
- [Violations Report](yoda-conditions-violations-report.md)
- [Unit Tests](../tests/unit/test-yoda-conditions.php)
