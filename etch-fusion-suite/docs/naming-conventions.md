# Etch Fusion Suite Naming Conventions

**Updated:** 2026-03-06 18:35

## 1. Overview

WordPress requires all globally accessible identifiers—hooks, functions, constants, options, transients, classes—to be uniquely prefixed. This prevents collisions with other plugins and themes and is enforced within Etch Fusion Suite through a combination of PHP_CodeSniffer (PHPCS) rules and automated verification tooling. As of 2025-10-28, the codebase is **100% compliant** with the configured naming rules defined in `phpcs.xml.dist`.

Key configuration:

- PHPCS sniff: `WordPress.NamingConventions.PrefixAllGlobals`
- Configuration source: `phpcs.xml.dist`
- Allowed prefixes: `efs`, `efs_security_headers`, `efs_cors`, `etch_fusion_suite`, `EFS`, `EtchFusion`, `EtchFusionSuite`, `b2e`, `B2E`, `Bricks2Etch`
- Verification tooling: `scripts/verify-hook-prefixing.sh` (available via `composer verify-hooks`)

## 2. Prefix Strategy

Etch Fusion Suite uses a dual-prefix model with additional subsystem-specific prefixes:

1. **`efs_`** – Short prefix for frequently used internal items.
   - AJAX actions (via `wp_ajax_efs_*`)
   - WordPress options (`efs_settings`, `efs_api_key`)
   - Transients (`efs_migration_progress_cache`)
   - Internal filters (`efs_security_headers_csp_directives`)

2. **`etch_fusion_suite_`** – Descriptive prefix for public APIs.
   - Global helper functions (`etch_fusion_suite_debug_log()`)
   - Extensibility hooks (`etch_fusion_suite_register_migrators`)
   - Service accessors (`etch_fusion_suite_container()`)

3. **Subsystem Prefixes** – Group related functionality.
   - `efs_security_headers_` – Security headers management
   - `efs_cors_` – CORS management (reserved in PHPCS)
   - `EFS_`, `EtchFusion_`, `EtchFusionSuite_` – Class naming conventions
   - Legacy compatibility prefixes: `b2e`, `B2E`, `Bricks2Etch`

This strategy balances brevity for internal tooling with clarity for public extension points.

## 3. Naming Conventions by Artifact Type

### 3.1 AJAX Actions

**Pattern:** `wp_ajax_efs_{action}` / `wp_ajax_nopriv_efs_{action}`

**Registration:** Typically within `includes/ajax/handlers/*`

**Examples:**

- `wp_ajax_efs_validate_api_key` (`includes/ajax/handlers/class-validation-ajax.php`)
- `wp_ajax_efs_start_migration` (`includes/ajax/handlers/class-migration-ajax.php`)
- `wp_ajax_efs_save_settings` (`includes/admin/admin_interface.php`)

**Guidelines:**

- Always prefix the action slug (portion after `wp_ajax_`) with `efs_`.
- Register unauthenticated actions via `wp_ajax_nopriv_efs_*`.
- Document security considerations in PHPDoc when appropriate.

### 3.2 Custom Action & Filter Hooks

**Actions:** `etch_fusion_suite_{hook}` or `efs_{subsystem}_{hook}`

**Filters:** Follow same prefixing rules

**Action Examples:**

- `do_action( 'etch_fusion_suite_register_migrators', $registry );` @ `includes/migration/class-migrator-discovery.php`
- `do_action( 'etch_fusion_suite_styles_updated' );` @ `includes/css_converter.php`

**Filter Examples:**

- `apply_filters( 'etch_fusion_suite_https_local_ssl_verify', true );` @ `includes/services/class-template-extractor-service.php`
- `apply_filters( 'efs_security_headers_csp_directives', $directives );` @ `includes/security/class-security-headers.php`

### 3.3 Global Functions

- Pattern: `etch_fusion_suite_{function}`
- Location: `etch-fusion-suite.php`
- Examples:
  - `etch_fusion_suite()` – returns the plugin singleton
  - `etch_fusion_suite_debug_log()` – diagnostic logging helper
  - `etch_fusion_suite_container()` – service container accessor

**Guidelines:**
- Guard definitions with `function_exists` checks when necessary.
- Provide PHPDoc summaries, parameters, and return descriptions.
- Prefer class methods when scoping logic is possible.

### 3.4 Constants

- Pattern: `ETCH_FUSION_SUITE_{CONSTANT}`
- Examples: `ETCH_FUSION_SUITE_VERSION`, `ETCH_FUSION_SUITE_DIR`
- Location: `etch-fusion-suite.php`

### 3.5 Options & Transients

- Options: `efs_{option}` (`efs_settings`, `efs_migration_token`)
- Transients: `efs_{name}` (WordPress auto-prefixes `_transient_`)
- Cleanup: All options/transients must be removed in the deactivation routine within `etch-fusion-suite.php`

### 3.6 Class Names & Namespaces

- Primary namespace: `Bricks2Etch\`
- Classes: `EFS_{Component}` or `Etch_Fusion_Suite_{Component}`
- Interfaces/Traits follow same prefixes.
- New namespaced classes must use an autoloadable file path that matches the class name.
- Current canonical example: `Bricks2Etch\Core\EFS_DB_Installer` lives at `includes/core/EFS_DB_Installer.php`.
- `Bricks2Etch\Core\*` has no root-level fallback. Core classes must live under `includes/core/`.
- Legacy root-level files under `includes/` still exist for other namespaces and are supported through Composer `classmap` plus the manual autoloader. That compatibility layer does not apply to `Bricks2Etch\Core\`.
- Do not hardcode `require_once` paths to individual namespaced class files in plugin code, uninstall hooks, or test scripts. Load `includes/autoloader.php` or rely on the main plugin bootstrap instead.

## 4. WordPress Core Hooks

WordPress core hooks remain unprefixed. Examples include `init`, `admin_menu`, `rest_api_init`, `send_headers`, and `rest_pre_dispatch`. Intentional non-prefixed usage is documented inline with `// phpcs:ignore` when required (e.g., `https_local_ssl_verify`).

## 5. Verification Workflow

1. Run automated check: `composer verify-hooks`
2. Inspect generated report: `docs/hook-prefixing-verification-report.md`
3. Address any violations by applying the appropriate prefix.
4. Update this document with new hooks, functions, or prefixes.

The verification script parses the PHPCS configuration to ensure parity between documentation and enforcement.

## 6. Best Practices for Future Development

### Adding AJAX Actions

- Use `wp_ajax_efs_{verb}_{noun}` naming.
- Register within the handler class’s `register_hooks()` method.
- Consider `wp_ajax_nopriv_efs_*` for public endpoints and secure them appropriately.

### Adding Extensibility Hooks

- Prefer `etch_fusion_suite_{event}` for public events.
- Document hooks using PHPDoc with `@since` and `@param` annotations.
- Add hook description to `docs/naming-conventions.md` and verification report.

### Adding Global Functions

- Validate that the functionality cannot live within a class scope.
- Use `etch_fusion_suite_{purpose}` naming.
- Wrap in `if ( ! function_exists( '...' ) )` when backward compatibility is needed.

### Adding Namespaced Classes

- Put the file in the namespace directory and use the exact class name as the filename.
- Example: `Bricks2Etch\Core\EFS_DB_Installer` → `includes/core/EFS_DB_Installer.php`
- For `Bricks2Etch\Core\`, root-level `includes/*.php` files are not allowed.
- If you touch legacy class files in `includes/` root, migrate them deliberately instead of adding another non-PSR-4 file beside them.
- Update `tests/autoloader-audit.php` and `tests/live-autoload-test.php` when introducing a new autoload-critical class.

### Defining Options/Transients

- Prefix with `efs_`.
- Document purpose in relevant service documentation.
- Add option key to plugin uninstall/deactivation cleanup routines.

## 7. Legacy Prefix Migration

- Legacy prefixes (`b2e`, `B2E`, `Bricks2Etch`) remain in PHPCS configuration for backward compatibility.
- No active usage in current codebase.
- Remove legacy support only after verifying third-party integrations are updated.

## 8. References

- `phpcs.xml.dist` (prefix configuration)
- `scripts/verify-hook-prefixing.sh` (automation)
- `DOCUMENTATION.md` (Code Quality > Hook Prefixing)
- `docs/hook-prefixing-verification-report.md`
- WordPress Coding Standards: <https://developer.wordpress.org/coding-standards/>

---

For changes impacting naming patterns, update this document with the new conventions and timestamp the modification. Ensure accompanying verification updates (script, report) remain synchronized.
