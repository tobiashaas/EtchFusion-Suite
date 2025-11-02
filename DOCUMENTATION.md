# Technical Documentation - Etch Fusion Suite

<!-- markdownlint-disable MD013 MD024 -->

**Last Updated:** 2025-10-31 10:15  
**Version:** 0.11.26

---

## 📋 Table of Contents

1. [Architecture](#architecture)
2. [GitHub Updater](#github-updater)
3. [Security Configuration](#security-configuration)
4. [CSS Migration](#css-migration)
    1. [CSS Converter](#css-converter)
5. [Content Migration](#content-migration)
6. [Media Migration](#media-migration)
7. [API Communication](#api-communication)
8. [Frontend Rendering](#frontend-rendering)
9. [Continuous Integration](#continuous-integration)
10. [Security](#security)
    1. [Security Architecture](#security-architecture)
    2. [Input Validation](#input-validation)
    3. [Output Escaping](#output-escaping)
    4. [Authentication & Authorization](#authentication--authorization)
    5. [Security Best Practices](#security-best-practices)
    6. [Security Verification](#security-verification)
11. [Core Infrastructure Files](#core-infrastructure-files)
12. [Admin Settings UI](#admin-settings-ui)
13. [Testing Coverage](#testing-coverage)
14. [PHPCS Standards & Compliance](#phpcs-standards--compliance)
    1. [Ruleset & Scope](#ruleset--scope)
    2. [Enabled Sniffs](#enabled-sniffs)
    3. [Running PHPCS & PHPCBF](#running-phpcs--phpcbf)
    4. [Composer Commands](#composer-commands)
    5. [Verification Scripts](#verification-scripts)
    6. [CI Enforcement](#ci-enforcement)
    7. [Git Hooks](#git-hooks)
    8. [Phase Reports & References](#phase-reports--references)
    9. [Developer Checklist](#developer-checklist)
    10. [Phase 12: Review & Validation](#phase-12-review--validation)
15. [References](#references)

---

## Architecture

**Updated:** 2025-10-23 23:40

### Plugin Structure

```text
etch-fusion-suite/
├── includes/
│   ├── container/               # Dependency injection
│   │   ├── class-service-container.php
│   │   └── class-service-provider.php
│   ├── repositories/            # Data access layer
│   │   ├── interfaces/
│   │   ├── class-wordpress-style-repository.php
│   │   ├── class-wordpress-settings-repository.php
│   │   └── class-wordpress-migration-repository.php
│   ├── api/                     # API communication
│   │   ├── api_client.php
│   │   └── api_endpoints.php
│   ├── parsers/                 # Data parsing
│   │   ├── css_converter.php
│   │   └── content_parser.php
│   ├── converters/              # Data conversion
│   │   └── gutenberg_generator.php
│   └── ...
└── etch-fusion-suite.php        # Main plugin file
```

### Service Container

**Updated:** 2025-10-23 23:40

The plugin uses a dependency injection container for service management:

**Key Services:**

- `css_converter` → `\Bricks2Etch\Parsers\EFS_CSS_Converter`
- `api_client` → `\Bricks2Etch\Api\EFS_API_Client`
- `style_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Style_Repository`
- `settings_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Settings_Repository`
- `migration_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository`

**Important:** All service bindings use fully qualified class names (FQCN) with correct namespaces.

### Autoloading & Namespaces

**Updated:** 2025-10-28 10:48

### Phase 10 PHPCS Cleanup (includes/)

**Updated:** 2025-10-28 10:48

- Completed Phase 10 of the PHPCS initiative across all remaining files inside `includes/`.
- Replaced short ternaries, enforced Yoda conditions, added strict `in_array()` checks, and normalised assignment alignment across migrators, services, generators, and views.
- Added missing `translators:` comments for progress strings and standardised container exceptions via anonymous classes to satisfy WPCS naming constraints.
- Verified a clean `vendor/bin/phpcs includes` run to close out the phase.

- Composer (`vendor/autoload.php`) wird eingebunden, sobald vorhanden.
- Zusätzlich bleibt der WordPress-optimierte Autoloader (`includes/autoloader.php`) immer aktiv, damit Legacy-Dateinamen (`class-*.php`) weiterhin funktionieren.
- Namespace-Mappings decken Sicherheitsklassen (`Bricks2Etch\Security\...`) sowie Repository-Interfaces (`Bricks2Etch\Repositories\Interfaces\...`) ab.
- Dateinamens-Erkennung schließt Interface-Dateien (`interface-*.php`) mit ein, damit Admin-Aufrufe ohne CLI-Kontext sauber funktionieren.

### Repository Pattern

**Updated:** 2025-10-23 23:40

All data access goes through repository interfaces:

**Style Repository Methods:**

- `get_etch_styles()` - Retrieve Etch styles with caching
- `save_etch_styles($styles)` - Save Etch styles
- `get_style_map()` - Get Bricks→Etch style ID mapping
- `save_style_map($map)` - Save style map
- `invalidate_style_cache()` - Clear style-related caches (targeted, not global)

**Cache Strategy:**

- Uses WordPress transients for 5-minute cache
- Targeted cache invalidation (no `wp_cache_flush()`)
- Prevents site-wide performance impact

### Data Flow

```text
Bricks Site                    Etch Site
    ↓                              ↓
1. CSS Converter          →   Etch Styles
2. Media Migrator         →   Media Library
3. Content Converter      →   Gutenberg Blocks
```

---

## GitHub Updater

**Updated:** 2025-10-30 08:18

The plugin includes a GitHub-based update system that integrates with WordPress's native plugin updater to fetch releases directly from the repository.

### Features

- **Automatic Update Checks**: Integrates with WordPress's `update_plugins` transient
- **GitHub Releases**: Fetches latest release from GitHub API
- **Version Comparison**: Semantic versioning with proper comparison logic
- **Secure Downloads**: HTTPS-only downloads with URL validation for both release assets and zipball fallback
- **PHP 7.4 Compatible**: Uses `substr_compare()` instead of PHP 8's `str_ends_with()`
- **Dynamic Requirements**: Reads `Requires at least` and `Tested up to` from plugin header
- **Error Handling**: Returns `WP_Error` on failures instead of caching invalid data
- **Transient Caching**: 12-hour cache to minimize API requests

### Implementation Details

**Location:** `includes/updater/class-github-updater.php`

**Key Methods:**

- `check_for_update()` - Checks for available updates and populates WordPress transient
- `plugin_info()` - Provides plugin details for the "View details" modal
- `get_remote_version()` - Fetches release data from GitHub API with caching
- `parse_version_from_tag()` - Extracts semantic version from Git tags (returns `WP_Error` on failure)
- `is_repo_url_for_this_plugin()` - Validates URLs belong to this plugin's repo on `github.com` or `api.github.com`
- `secure_download_handler()` - Validates download URLs and sets secure request parameters for both hosts
- `secure_download_request_args()` - Injects User-Agent and optional Authorization headers
- `read_plugin_headers()` - Reads WordPress version requirements from main plugin file

**Security Features:**

- HTTPS-only downloads (validates `https://github.com/` or `https://api.github.com/`)
- URL validation using `wp_parse_url()` to prevent false positives
- Supports both `github.com/{owner}/{repo}/` (release assets) and `api.github.com/repos/{owner}/{repo}/` (zipball URLs)
- Explicit User-Agent header for all download requests
- Optional GitHub token support via `efs_github_updater_token` filter
- Download URL validation before advertising updates
- No caching of invalid version data
- Consistent security posture regardless of download source (assets vs zipball fallback)

**Download URL Handling:**

The updater supports two types of download URLs:

1. **Release Assets** (`github.com`): When a release includes `.zip` assets, uses `browser_download_url`

   - Example: `https://github.com/tobiashaas/EtchFusion-Suite/releases/download/v1.0.0/plugin.zip`

2. **Zipball Fallback** (`api.github.com`): When no assets exist, uses `zipball_url`

   - Example: `https://api.github.com/repos/tobiashaas/EtchFusion-Suite/zipball/v1.0.0`

Both URL types receive identical security validation and header injection via the secure download handler.

**Filters:**

- `efs_github_updater_repo_owner` - Override repository owner (default: `tobiashaas`)
- `efs_github_updater_repo_name` - Override repository name (default: `EtchFusion-Suite`)
- `efs_github_updater_cache_expiration` - Override cache duration (default: 43200 seconds / 12 hours)
- `efs_github_updater_download_url` - Modify download URL before use
- `efs_github_updater_token` - Provide GitHub personal access token for private repos or rate limit increases

**Version Requirements:**

The updater dynamically reads version requirements from the main plugin file header:

- `Requires at least: 5.0` → Used in update response
- `Tested up to: 6.4` → Used in update response

This ensures version requirements stay synchronized between the plugin header and update metadata.

---

## Security Configuration

**Updated:** 2025-10-30 11:05

### CORS (Cross-Origin Resource Sharing)

The plugin implements whitelist-based CORS for secure cross-origin API requests with comprehensive enforcement across all REST endpoints.

#### Configuration via WP-CLI

```bash
# Get current CORS origins
wp option get b2e_cors_allowed_origins --format=json

# Set CORS origins
wp option update b2e_cors_allowed_origins '["http://localhost:8888","http://localhost:8889","https://yourdomain.com"]' --format=json

# Add single origin (append to existing)
wp option patch insert b2e_cors_allowed_origins end "https://newdomain.com"
```

#### Default Origins

If no origins are configured, the following development defaults are used:

- `http://localhost:8888`
- `http://localhost:8889`
- Bricks (Source): `http://127.0.0.1:8888`
- Etch (Target): `http://127.0.0.1:8889`

#### CORS Behavior

- **Allowed origins**: Receive proper CORS headers and can access the API
- **Disallowed origins**: Requests are denied with 403 status and logged as security violations
- **No Origin header**: Treated as same-origin request (allowed)

#### CORS Enforcement

**Updated:** 2025-10-27 20:52

The plugin enforces CORS validation at multiple levels:

1. **Per-endpoint checks**: Each endpoint handler calls `check_cors_origin()` early
2. **Global enforcement filter**: A `rest_request_before_callbacks` filter provides a safety net for all `/b2e/v1/*` routes
3. **Header injection**: The `B2E_CORS_Manager::add_cors_headers()` method sets appropriate headers via `rest_pre_serve_request`
4. **Preflight handling**: OPTIONS requests now short-circuit with HTTP 204, inherit the same header set, and respect a configurable `Access-Control-Max-Age`

Additional filters are available to customise behaviour without patching core services:

- `efs_cors_allowed_methods`
- `efs_cors_allowed_headers`
- `efs_cors_max_age`

**Public endpoints** (e.g., `/b2e/v1/migrate`, `/b2e/v1/validate`) now enforce CORS validation despite using `permission_callback => '__return_true'`. This ensures:

- Server actively rejects disallowed origins with 403 JSON error (not just browser-level blocking)
- All CORS violations are logged with route, method, and origin information
- Future endpoints cannot bypass origin validation

**Authenticated endpoints** continue to use CORS checks within their `permission_callback` for defense-in-depth.

#### Automated Coverage

**Updated:** 2025-10-26 23:20

The WordPress security test suite now asserts:

- `EFS_CORS_Manager` behaviour for trusted vs. untrusted origins.
- `EFS_Rate_Limiter` request accounting, reset, and integration within AJAX handlers.
- `EFS_Security_Headers` conditional header emission (OPTIONS bypass, admin CSP composition).
- `EFS_Input_Validator` structured error context surfaced via AJAX JSON payloads.

See `tests/unit/WordPress/SecurityTest.php` for the consolidated scenarios.

### Content Security Policy (CSP)

The plugin applies relaxed CSP headers to accommodate WordPress behavior.

#### Current Policy

The CSP header is now generated from directive maps for both contexts and can be extended via filters:

- `efs_security_headers_csp_directives`
- `efs_security_headers_admin_script_sources`
- `efs_security_headers_admin_style_sources`
- `efs_security_headers_frontend_script_sources`
- `efs_security_headers_frontend_style_sources`
- `efs_security_headers_csp_connect_src`

**Admin Pages (defaults):**

```text
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data: https:;
font-src 'self' data:;
connect-src 'self';
frame-ancestors 'self';
form-action 'self';
base-uri 'self';
object-src 'none'
```

**Bricks Builder Overrides:** Requests carrying `?bricks=run|preview|iframe` automatically extend `script-src` with `'unsafe-eval'` and allow Bricks' CDN font host (`https://r2cdn.perplexity.ai`) so the visual builder can bootstrap without CSP violations, while routine frontend/admin traffic remains locked down.

**Frontend (defaults):**

```text
default-src 'self';
script-src 'self' 'unsafe-inline';
style-src 'self' 'unsafe-inline';
img-src 'self' data: https:;
font-src 'self' data:;
connect-src 'self';
frame-ancestors 'self';
form-action 'self';
base-uri 'self';
object-src 'none'
```

> **Note:** Additional hosts or CDNs can be appended via the filters above without editing core code.

#### Configuration via Settings Repository

```php
// Get security settings
$settings_repo = b2e_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify settings
$security_settings['csp_enabled'] = true;
$settings_repo->save_security_settings($security_settings);
```

### Feature Flags

**Updated:** 2025-10-29 23:35

Feature flags provide runtime control over experimental or optional functionality. The system includes built-in sanitization, extensibility filters, and automatic cleanup on deactivation.

#### Core Implementation

**Sanitization (Repository Layer):**

- Keys sanitized via `sanitize_key()` before persistence
- Values cast to boolean to ensure type safety
- Implemented in `EFS_WordPress_Settings_Repository::save_feature_flags()`

**Extensibility (AJAX Layer):**

- Whitelist customizable via `efs_allowed_feature_flags` filter
- Default whitelist: `array('template_extractor')`
- Validation enforced in `EFS_Connection_Ajax_Handler::validate_feature_flag_name()`

**Cleanup (Deactivation):**

- `efs_feature_flags` option deleted on plugin deactivation
- Ensures no orphaned settings remain in database

#### Usage Examples

```php
// Check if a feature is enabled
if ( efs_feature_enabled( 'template_extractor' ) ) {
    // Feature-specific code
}

// Programmatically enable a feature
add_filter( 'efs_feature_enabled_template_extractor', '__return_true' );

// Extend the allowed feature flags whitelist
add_filter( 'efs_allowed_feature_flags', function( $flags ) {
    $flags[] = 'custom_feature';
    return $flags;
} );

// Disable all features in staging
add_filter( 'efs_feature_enabled', function( $enabled, $feature ) {
    return wp_get_environment_type() === 'staging' ? false : $enabled;
}, 10, 2 );
```

#### Available Filters

- `efs_allowed_feature_flags` — Customize the whitelist of valid feature flag names
- `efs_feature_enabled` — Global filter for all feature flag checks (receives flag name)
- `efs_feature_enabled_{$feature}` — Feature-specific filter (e.g., `efs_feature_enabled_template_extractor`)

### Rate Limiting

Rate limiting is applied to all AJAX and REST API endpoints.

#### Default Limits

**AJAX Endpoints:**

- Authentication: 10 requests/minute
- Read operations: 30-60 requests/minute
- Write operations: 20-30 requests/minute
- Sensitive operations (cleanup, logs): 5-10 requests/minute

**REST API Endpoints:**

- Authentication: 10 requests/minute
- Export (read): 30 requests/minute
- Import (write): 10-20 requests/minute

#### Configuration

Rate limiting settings can be configured via the settings repository:

```php
$settings_repo = b2e_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify rate limits
$security_settings['rate_limit_enabled'] = true;
$security_settings['rate_limit_requests'] = 60;
$security_settings['rate_limit_window'] = 60; // seconds

$settings_repo->save_security_settings($security_settings);
```

### Validation & Input Handling

**Updated:** 2025-10-26 22:57

The central `EFS_Input_Validator` now records machine-readable error codes together with sanitized context for every validation failure. This enables:

- Generic, PHPCS-compliant exception messages (e.g. "Value is required.")
- Richer feedback in the admin UI by looking up `get_user_error_message()` with the stored code/context
- AJAX responses that carry `code` and `details` keys so JavaScript can surface actionable toasts

**Key Behaviours:**

- `validate_request_data()` resets the error state before each field and stores the failing field name in the context when exceptions occur.
- `EFS_Base_Ajax_Handler::validate_input()` now reads the last error details, logs them, and exposes the structured payload in the JSON error response.
- Admin JavaScript (`assets/js/admin/api.js`) enriches thrown errors with `code` and `details` so callers can inspect root causes if needed.
- `validate_array()` sanitizes allowed keys before applying strict comparisons, ensuring only normalized keys pass validation.

### API Key Validation

API keys must meet the following requirements:

- **Minimum length**: 20 characters
- **Allowed characters**: Letters (a-z, A-Z), numbers (0-9), underscore (_), hyphen (-), dot (.)
- **Format**: Alphanumeric with common safe characters
- **Normalization**: Stripped of whitespace before validation

### Audit Logging

**Updated:** 2025-10-27 20:52

All security events are logged with severity levels:

- **Low**: Routine operations
- **Medium**: Authentication failures, rate limit exceeded
- **High**: Authorization failures, suspicious activity
- **Critical**: Destructive operations (cleanup, log clearing)

#### View Audit Logs

```bash
# Via WP-CLI
wp option get b2e_security_log --format=json

# Via PHP
$audit_logger = b2e_container()->get('audit_logger');
$logs = $audit_logger->get_security_logs(100); // Last 100 events
```

The logger now sanitizes event metadata, masks sensitive keys (API keys, tokens, secrets), enforces a configurable history limit via `efs_audit_logger_max_events`, and emits structured context for both success and failure cases to support richer UI feedback.

---

## Admin Settings UI

**Updated:** 2025-11-01 13:12

### Overview

The admin settings UI provides a centralized interface for configuring Etch Fusion Suite.

### Key Features

- **Target URL Normalization**: Docker hosts are automatically translated to `host.docker.internal` for seamless communication between containers.
- **Connection Endpoints**: The UI now normalizes Application Password input by stripping whitespace before validation, ensuring keys copied from WordPress can include spaces without failing.
- **Application Password Entry**: PIN inputs now render as plain-text boxes (no visibility toggle required), continue enforcing the 24-character length check before saving settings, and keep the settings form as the single source of truth for AJAX actions (connection tests, migration key generation). Hidden API key inputs remain scoped to the Bricks context so application passwords are not duplicated in the DOM for Etch instances.
- **Status Endpoint Details**: The Etch `/wp-json/efs/v1/status` endpoint now returns `status` and `version` fields alongside plugin activation state so automated connection checks can verify the target build before starting a migration.
- **Accessibility Enhancements**: Field labels focus the first PIN box, the input group now exposes `aria-labelledby`, and a `<noscript>` password fallback is available when JavaScript is disabled.
- **REST Validation Route**: `/wp-json/efs/v1/auth/validate` powers the connection test and returns a structured response when credentials are accepted, mirroring the AJAX handler.
- **CORS Defaults**: Server-origin requests (those without an `Origin` header) are accepted by the REST layer so container-to-container calls no longer fail with “Origin not allowed”.
- **Migration Key Endpoint**: The admin form now calls the target `/wp-json/efs/v1/generate-key` endpoint, returning the generated key payload directly from the Etch instance.
- **Service Container**: `token_manager` is registered in the plugin service container so REST endpoints can resolve `EFS_Migration_Token_Manager` without fatal errors. When the target URL resolves to the current site, migration keys are generated locally without issuing a loopback HTTP request.
- **Accordion Layout**: Bricks and Etch setup screens are organised into a shared accordion component with accessible headers (`aria-expanded`, `role="region"`). The first panel (connection or application password) opens by default, while other panels lazily reveal forms on demand to reduce visual noise.
- **Shared Migration Key Component**: A reusable partial renders migration key generation controls for both Bricks and Etch contexts, automatically inheriting nonce, target URL, and application password values from the primary settings form to eliminate duplicated markup and logic. Migration key textareas are now looked up within the active accordion panel to avoid collisions when both Bricks and Etch dashboards render simultaneously.
- **Feature Discovery**: The Template Extractor tab now remains visible even when disabled, presenting a locked state with a call-to-action that scrolls and expands the Feature Flags accordion section so administrators understand how to enable the feature.
- Tab navigation is keyboard accessible via `data-efs-tab` attributes and aria roles, matching the Playwright selectors in `tests/playwright/dashboard-tabs.spec.ts`.
- Dashboard accordions expose `data-efs-accordion-section`, `data-efs-accordion-header`, and `data-efs-accordion-content` attributes used by both Playwright and PHPUnit regression tests.

## Testing Coverage

**Updated:** 2025-10-31 10:15

- Hardened the admin UI PHPUnit suite (`tests/ui/AdminUITest.php`) to verify rendered markup instead of reflecting private controller methods, ensuring migration key components assert against actual dashboard output for both Bricks and Etch contexts.
- Documented expanded dashboard testing procedures in `etch-fusion-suite/TESTING.md`, covering accordion behaviour, feature flag states, responsive viewports, accessibility checks, and cross-browser Playwright projects (chromium, firefox, webkit, plus optional mobile).
- Centralised common commands for Playwright (`npm run test:playwright --project=<browser>`), responsive spec execution, and PHPUnit UI suite filters (`vendor/bin/phpunit -c phpunit.xml.dist --testsuite=ui`).
- Linked GitHub Actions workflow `etch-fusion-suite/.github/workflows/tests.yml` for CI parity; workflow now runs PHPUnit before Playwright to mirror local guidance.

### Configuration

Settings can be configured via the settings repository:

```php
$settings_repo = b2e_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify settings
$security_settings['csp_enabled'] = true;
$settings_repo->save_security_settings($security_settings);
```

---

## CSS Migration

**Updated:** 2025-10-29 09:05

### CSS Converter

The CSS Converter handles the end-to-end migration of Bricks global classes into Etch-compatible styles.

**File:** `etch-fusion-suite/includes/css_converter.php` (2001 lines)

**Purpose:**

- Convert Bricks global classes and custom CSS into Etch style definitions
- Translate physical properties to logical equivalents for RTL readiness
- Preserve responsive breakpoints and nested selectors during migration
- Persist converted styles and Bricks→Etch style map entries for later use

**Conversion Workflow:**

1. **Convert Bricks Classes** – Generate Etch-friendly styles and build a Bricks→Etch style map.
2. **Collect Breakpoint CSS** – Extract `_cssCustom:breakpoint` rules, convert breakpoint names to Etch media queries, and stage responsive declarations.
3. **Parse Custom CSS** – `parse_custom_css_stylesheet()` matches selectors against the style map, converts nested rules to `&` syntax, and merges custom CSS into generated styles.
4. **Add Breakpoint CSS** – Append stored media queries to the corresponding styles to retain responsive behaviour.

**Key Features:**

- **17 conversion helpers** covering layout, flexbox, grid, sizing, spacing, borders, typography, effects, responsive variants, and logical property translation.
- **Breakpoint mapping** converts Bricks desktop-first keys to Etch media queries using range syntax.
- **Selector nesting helpers** (`convert_nested_selectors_to_ampersand`, `convert_selectors_in_media_query`) rewrite descendant selectors to `&` syntax for CSS nesting.
- **Import strategy** updates the database directly when `bypass_api` is enabled, then invalidates caches and triggers the Etch CSS rebuild sequence.
- **Error handler integration** replaces `error_log()` with `EFS_Error_Handler::log_info()` for PHPCS-compliant diagnostics.
- **Verbose logging controls** route helper-level payload dumps through `log_debug_info()` so detailed CSS output is gated by the debug logging toggle, including nested selector and media query helper diagnostics.

**Documentation:**

- Detailed architecture: `etch-fusion-suite/docs/css-converter-architecture.md`
- Implementation reference: `etch-fusion-suite/includes/css_converter.php`

**PHPCS Compliance Improvements:**

- Replaced 49 `error_log()` calls with `log_info()` invocations.
- Corrected Yoda condition for selector matching (`$selector === '.' . $class_name`).
- Added inline comments describing the conversion workflow, custom CSS parsing strategy, import options, and rebuild triggers.

**Testing Recommendations:**

- Run CSS conversion tests (where available) to confirm no behavioural regressions.
- Validate output against representative Bricks projects that include custom CSS, responsive settings, and nested selectors.
- After imports, verify Etch cache invalidation and rebuild hooks regenerate updated styles.

For a full breakdown of helper methods, breakpoint mappings, logical property translations, and testing strategy, see `docs/css-converter-architecture.md`.

---

## Content Migration

**Updated:** 2025-10-21 23:40

### PHPCS Overview

Converts Bricks elements to Gutenberg blocks with Etch metadata.

### Element Types

#### 0. Listen (ul, ol, li)

**Updated:** 2025-10-21 23:40

**Block Type:** `core/group` (Container mit custom tag)

**Bricks:**

```php
'name' => 'container',
'settings' => ['tag' => 'ul']
```

**Etch Data:**

```json
{
  "tagName": "ul",
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "data-etch-element": "container",
      "class": "my-list-class"
    },
    "block": {
      "type": "html",
      "tag": "ul"
    }
  }
}
```

**Frontend:**

```html
<ul data-etch-element="container" class="my-list-class">
  <li>Item 1</li>
  <li>Item 2</li>
</ul>
```

**Unterstützte Tags:**

- `ul` - Unordered List
- `ol` - Ordered List
- `li` - List Item (via Div element)

#### 1. Headings (h1-h6)

**Block Type:** `core/heading`

**Etch Data:**

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "class": "my-heading-class"
    },
    "block": {
      "type": "html",
      "tag": "h2"
    }
  }
}
```

#### 2. Paragraphs

**Block Type:** `core/paragraph`

**Etch Data:**

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "class": "my-paragraph-class"
    },
    "block": {
      "type": "html",
      "tag": "p"
    }
  }
}
```

#### 3. Images

**Updated:** 2025-10-21 22:24

**Block Type:** `core/image`

**Important:** Use `block.tag = 'figure'`, not `'img'`!

**Etch Data:**

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "class": "my-image-class"
    },
    "block": {
      "type": "html",
      "tag": "figure"
    }
  }
}
```

**HTML:**

```html
<figure class="wp-block-image my-image-class">
  <img src="..." alt="...">
</figure>
```

#### 4. Sections

**Block Type:** `core/group`

**Etch Data:**

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "data-etch-element": "section",
      "class": "my-section-class"
    },
    "block": {
      "type": "html",
      "tag": "section"
    }
  }
}
```

#### 5. Containers

**Block Type:** `core/group`

**Etch Data:**

```json
{
  "etchData": {
    "styles": ["abc123"],
    "attributes": {
      "data-etch-element": "container",
      "class": "my-container-class"
    },
    "block": {
      "type": "html",
      "tag": "div"
    }
  }
}
```

---

## Media Migration

**Updated:** 2025-10-21 23:20

### Overview

Transfers images and attachments from Bricks to Etch site.

### Process

1. Get all media attachments from Bricks
2. Download media file
3. Upload to Etch via REST API
4. Map Bricks media ID → Etch media ID
5. Update image URLs in content

---

## API Communication

**Updated:** 2025-10-28 14:54

### Authentication

Uses WordPress Application Passwords for secure API access.

### Endpoints

#### Template Management (Etch Target)

All template endpoints require CORS-allowed origins and are rate limited. Requests without a JSON body gracefully fall back to empty arrays before validation.

```http
POST /wp-json/efs/v1/template/extract
GET  /wp-json/efs/v1/template/saved
GET  /wp-json/efs/v1/template/preview/{id}
DELETE /wp-json/efs/v1/template/{id}
POST /wp-json/efs/v1/template/import
```

Rules:

- `extract`: expects `{ "source": string, "source_type": "url"|"html" }`
- `import`: requires an associative `payload` array and optional `name`
- All endpoints rely on `EFS_Input_Validator::validate_request_data()` with consistent error handling that converts validation exceptions into `WP_Error` responses (`invalid_input`, HTTP 400)
- Rate limiting is enforced per action and attaches a `Retry-After` header when exceeded (HTTP 429)
- Permission callbacks route through the CORS manager to align with the global REST filter

#### 1. Validate Token

```http
POST /wp-json/efs/v1/validate-token
```

Request body is cast to an array before validation. `expires` must be a timestamp >= current time.

#### 2. Receive Post

```http
POST /wp-json/efs/v1/receive-post
```

#### 3. Receive Media

```http
POST /wp-json/efs/v1/receive-media
```

#### 4. Import Styles

```http
POST /wp-json/efs/v1/import-styles
```

---

## Frontend Rendering

**Updated:** 2025-10-21 22:24

### Key Insight

**Etch renders CSS classes from `etchData.attributes.class`, NOT from `etchData.styles`!**

### Correct Structure

```json
{
  "etchData": {
    "styles": ["abc123"],           // For CSS generation in <head>
    "attributes": {
      "class": "my-css-class"       // For frontend HTML rendering
    }
  }
}
```

### Frontend Output

```html
<div data-etch-element="container" class="my-css-class">
  Content
</div>
```

### CSS in `<head>`

```css
.my-css-class {
  /* Styles from Bricks */
  padding: 1rem;
  background: var(--bg-color);
}
```

---

## Continuous Integration

**Updated:** 2025-10-30 09:22

GitHub Actions provides automated linting, testing, and static analysis:

- **CI** workflow handles PHP linting (PHPCS), multi-version PHPUnit, and JS tooling checks
- **Release** workflow builds signed artifacts from version tags, now restricted to `v*.*.*` patterns and hardened for Ubuntu runners via POSIX-compliant version parsing.
- **CodeQL** workflow performs security scanning
- **dependency-review** workflow blocks insecure dependency updates on PRs

### CI Workflow Breakdown (2025-10-26 refresh)

- **Lint job:** Installs Composer dev dependencies inside `etch-fusion-suite` and runs `vendor/bin/phpcs --standard=phpcs.xml.dist` via `shivammathur/setup-php`
- **Test** – PHPUnit suite across PHP 7.4, 8.1, 8.2, 8.3, 8.4 with WordPress test library installed in `/tmp` and environment variables exported in the workflow
- **Node job:** Sets up Node 18 with npm cache and runs `npm ci`

### CI Environment Variables

- `WP_TESTS_DIR=/tmp/wordpress-tests-lib`
- `WP_CORE_DIR=/tmp/wordpress`
- `WP_ENV=testing`
- `WP_MULTISITE=0`

### Running checks locally

```bash
cd etch-fusion-suite
# Run PHPUnit tests
composer test

# Run specific suites
composer test:wordpress
composer test:integration
composer test:ui

# Generate coverage report
composer test:coverage
```

### Security & Dependency Automation

- **CodeQL** now scans both PHP and JavaScript with full history checkout (`fetch-depth: 0`)
- **Dependabot** monitors Composer, npm, and GitHub Actions within `etch-fusion-suite/`

### Composer Tooling

- GitHub Actions uses `shivammathur/setup-php` to install required PHP extensions, PHPStan, Composer, and the WPCS standard.
- Composer caches cover both legacy `~/.composer/cache` and Composer v2 default `~/.cache/composer` paths to maximise cache hits on GitHub-hosted runners.
- The local `npm run dev` script first tries `composer` inside the wp-env container and falls back to the host binary if unavailable. Install Composer locally when developing without container Composer support to avoid build failures.
- In CI environments, provision Composer explicitly (e.g., `tools: composer` via `shivammathur/setup-php`) to make the fallback deterministic.

### Dependency Management

- Composer updates target `etch-fusion-suite/composer.json`
- npm updates target `etch-fusion-suite/package.json`
- GitHub Actions updates cover `.github/workflows/*.yml`

### Testing Coverage

**Updated:** 2025-10-27 23:48

- Unit tests:
  - `tests/unit/TemplateExtractorServiceTest.php` validates payload shape and template validation edge cases via the service container.
  - `tests/unit/FramerHtmlSanitizerTest.php` ensures Framer scripts are removed and semantic conversions (sections, headings) apply as expected.
  - `tests/unit/FramerTemplateAnalyzerTest.php` checks section detection heuristics (`hero`, `features`, `footer`) and media source annotations for Framer CDN assets.
- Integration test: `tests/integration/FramerExtractionIntegrationTest.php` exercises the full DI-driven pipeline and asserts that Etch blocks, metadata, and CSS variable styles are generated end-to-end.
- UI tests: PHP-powered admin assertions moved to `tests/ui/AdminUITest.php` and execute under the `ui` PHPUnit suite to avoid confusion with browser automation.
- **Current workflow:** run unit tests inside the WordPress container

```bash
docker exec -w /var/www/html/wp-content/plugins/etch-fusion-suite \
  db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 \
  ./vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite=unit
```

#### WordPress Integration Tests

The WordPress test suite provides full WordPress core integration testing with database access and WordPress hooks.

**Setup (one-time)**:

```bash
# Install dependencies in wp-env container
docker exec db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 apt-get update
docker exec db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 apt-get install -y mariadb-client subversion

# Provision WordPress test suite
docker exec -w /var/www/html/wp-content/plugins/etch-fusion-suite \
  db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 \
  bash install-wp-tests.sh wordpress_test root password mysql latest true
```

**Run tests**:

```bash
docker exec -w /var/www/html/wp-content/plugins/etch-fusion-suite \
  db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 \
  ./vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite=wordpress
```

**Current coverage**: `tests/integration/PluginActivationTest.php` validates plugin loading, service container wiring, admin menu registration, and plugin detector functionality (6 tests, 13 assertions).

#### Playwright E2E Tests

**Status**: Partially functional with storage state authentication.

**Setup**:
Playwright uses storage state (saved browser sessions) to bypass login form issues. The `auth.setup.ts` project runs first and saves authenticated sessions to `.playwright-auth/`.

**Run tests**:

```bash
EFS_ADMIN_USER=admin EFS_ADMIN_PASS=password npm run test:playwright
```

**Current status**: 4/10 tests passing. Tests using `createEtchAdminContext()` work reliably with storage state. Remaining failures require migration to storage state pattern.

**Recommendation**: Prefer WordPress integration tests (PHPUnit) for backend functionality testing. Use Playwright only for critical UI/browser-specific scenarios.

### Configuration Files

- `.wp-env.json` – Canonical configuration for both environments (core `WordPress/WordPress#6.8`, PHP 8.1, required plugin/theme ZIPs, debug constants). Local source directories are no longer mapped; wp-env installs archives directly for portable setups and downloads WordPress from the official ZIP when the registry slug is unavailable.
- `.wp-env.override.json.example` – Template for local overrides (ports, PHP version, Xdebug, extra plugins). Copy to `.wp-env.override.json` to customize without affecting version control.
- `package.json` – Defines all npm scripts used to operate the environment (`dev`, `stop`, `destroy`, `wp`, `wp:etch`, `create-test-content`, `test:connection`, `test:migration`, `debug`, etc.).
- `scripts/` – Node-based automation utilities (WordPress readiness polling, plugin activation, test content creation, migration smoke tests, debug report generation).
- `test-environment/PLUGIN-SETUP.md` – Instructions for supplying proprietary plugin/theme archives required by wp-env.

### Required Assets

Place vendor ZIPs in the test-environment folders before running `npm run dev`:

```text
test-environment/
  plugins/
    frames-1.5.11.zip
    automatic.css-3.3.5.zip
    etch-1.0.0-alpha-5.zip
    automatic.css-4.0.0-dev-27.zip
  themes/
    bricks.2.1.2.zip
    bricks-child.zip
    etch-theme-0.0.2.zip
```

wp-env extracts these archives into the appropriate instance, while the base plugin mounts from the repository checkout. The activation script (`npm run activate`) ensures everything is enabled without relying on local filesystem mappings.

### Operational Considerations

- Always run `npm install` before `npm run dev` to ensure the helper scripts are available.
- Use `npm run stop` and `npm run destroy` to free ports and clean volumes when switching branches.
- When running multiple wp-env instances in parallel (e.g., CI matrix jobs), copy `.wp-env.override.json.example` to `.wp-env.override.json` and set unique `port`/`testsPort` values to avoid Docker port collisions. Alternatively, serialize the jobs.

### Operational Commands

| Command | Purpose |
| --- | --- |
| `npm run dev` | Start environments, wait for readiness, install Composer dependencies (container → host fallback), activate plugins/themes, create Etch application password |
| `npm run stop` | Stop all wp-env containers |
| `npm run destroy` | Remove environments and data (clean reset) |
| `npm run wp [cmd]` | WP-CLI against the Bricks instance |
| `npm run wp:etch [cmd]` | WP-CLI against the Etch instance |
| `npm run create-test-content` | Seed Bricks with posts, pages, global classes, optional media |
| `npm run test:connection` | Validate REST connectivity and token handling |
| `npm run test:migration` | End-to-end migration test with progress monitoring |
| `npm run debug` | Collects diagnostics into a timestamped report |
| `npm run test:playwright` | Executes Playwright scenarios against the admin UI. |

### Testing & Documentation

- `bricks-etch-migration/TESTING.md` – Step-by-step wp-env testing plan (pre-flight, setup validation, migration smoke tests, performance checks, failure scenarios).
- `test-environment/README.md` – Overview of the wp-env workflow, credentials, troubleshooting tips, and migration checklist.
- `test-environment/PLUGIN-SETUP.md` – Detailed instructions for sourcing and installing proprietary packages.

### Legacy Docker Resources

The previous Docker Compose setup remains in `test-environment/docker-compose.yml` and `test-environment/Makefile`, but both files are explicitly marked as deprecated and retained only for reference. **All new development must use the npm/wp-env workflow described above.** The legacy Docker scripts and configuration files are no longer maintained or supported.

---

## Security

**Updated:** 2025-10-29 09:26

### Security Architecture

Etch Fusion Suite applies a layered security model that consolidates nonce verification, capability checks, rate limiting, CORS validation, and audit logging. Full architecture details, including handler walkthroughs and rate limit tables, are tracked in [`docs/security-architecture.md`](etch-fusion-suite/docs/security-architecture.md).

### Input Validation

AJAX handlers use `EFS_Base_Ajax_Handler::validate_input()` while REST endpoints rely on the same `EFS_Input_Validator::validate_request_data()` ruleset. Supported validation types include `url`, `api_key`, `token`, `text`, `integer`, and recursive arrays. Refer to [`includes/ajax/class-base-ajax-handler.php`](etch-fusion-suite/includes/ajax/class-base-ajax-handler.php) and [`includes/security/class-input-validator.php`](etch-fusion-suite/includes/security/class-input-validator.php).

### Output Escaping

All responses flow through `wp_send_json_success()`, `wp_send_json_error()`, or `WP_REST_Response`. Admin data is localized via `wp_localize_script()`, avoiding direct echoes. See the new architecture document for examples of safe output patterns.

### Authentication & Authorization

`EFS_Base_Ajax_Handler::verify_request()` enforces nonce verification, capability checks (`manage_options`), rate limiting, and audit logging for every AJAX call. REST endpoints validate origins through `EFS_CORS_Manager` and tokens via `EFS_Migration_Token_Manager`. Review [`includes/ajax/class-base-ajax-handler.php`](etch-fusion-suite/includes/ajax/class-base-ajax-handler.php) and [`includes/api_endpoints.php`](etch-fusion-suite/includes/api_endpoints.php).

### Nonce Verification

Etch Fusion Suite protects every AJAX request with a centralized nonce architecture:

- **Single nonce action** — All handlers share `'efs_nonce'`, generated in `admin_interface.php::enqueue_admin_assets()` and localized to JavaScript as `efsData.nonce`.
- **Dual-layer verification** — `admin_interface.php::get_request_payload()` performs preflight verification (`$die = false`), while handlers call `verify_request()` as the first line to run nonce + capability checks and audit logging.
- **Comprehensive coverage** — All nine AJAX handler classes extend the base handler and invoke `verify_request()` before touching input, ensuring 100% compliance.

**Documentation:**

- [`docs/nonce-strategy.md`](etch-fusion-suite/docs/nonce-strategy.md) — Canonical nonce lifecycle, diagrams, testing guidance.
- [`docs/security-architecture.md`](etch-fusion-suite/docs/security-architecture.md) — Layered security overview.
- [`docs/security-best-practices.md`](etch-fusion-suite/docs/security-best-practices.md) — Implementation guidelines for new handlers.
- [`docs/security-verification-checklist.md`](etch-fusion-suite/docs/security-verification-checklist.md) — Nonce compliance checks and sign-off items.
- `tests/phpunit/BaseAjaxHandlerTest.php` — PHPUnit coverage for invalid nonce, missing capability, and success paths.
- `tests/phpunit/bootstrap.php` — Supports `EFS_SKIP_WP_LOAD=1` to bypass WordPress bootstrapping so security unit tests can execute without database connectivity.

### Security Best Practices

Development guidelines for new handlers, REST endpoints, and admin integrations are maintained in [`docs/security-best-practices.md`](etch-fusion-suite/docs/security-best-practices.md). Follow the documented patterns for sanitization, logging, and testing.

### Security Verification

Prior to release, complete the checklist in [`docs/security-verification-checklist.md`](etch-fusion-suite/docs/security-verification-checklist.md) covering AJAX handlers, REST endpoints, admin flows, services, and PHPCS audits.

---

## Core Infrastructure Files

**Updated:** 2025-10-29 09:26

Three core infrastructure files underpin the admin experience, error handling, and security telemetry. They require minimal, well-documented changes to preserve stability while remaining PHPCS compliant.

### Files

- `includes/admin_interface.php` – Admin menu/dashboard bootstrap, script localization, nonce generation.
- `includes/error_handler.php` – Centralized log routing, structured option storage, WordPress debug mirroring.
- `includes/security/class-audit-logger.php` – Security event pipeline with severity filtering, sanitization, and optional error handler delegation.

### Phase 9 Compliance Summary

- **Scope:** 1,187 lines reviewed under Phase 9 (Kleinere Core-Dateien).
- **PHPCS Fixes:** 4 Yoda comparisons corrected (`strpos` checks, `array_filter` callbacks). All intentional `error_log()` calls annotated with `phpcs:ignore` plus rationale.
- **Security Verification:** Confirmed nonce verification (`admin_interface.php::get_request_payload()`), recursive sanitization (payload + audit logger contexts), masked sensitive keys, `wp_send_json_*` responses, and strict `in_array()` usage.
- **Documentation:** See [`docs/phase9-core-files-compliance.md`](etch-fusion-suite/docs/phase9-core-files-compliance.md) for change log, rationale, and testing guidance.

### Why `error_log()` Remains

1. **Logging Infrastructure (`error_handler.php`)** – Cannot self-depend; mirrors structured logs to `debug.log` for operators.
2. **Admin Interface (`admin_interface.php`)** – Avoids injecting the error handler dependency; only logs missing assets and container resolution failures during development.
3. **Audit Logger (`class-audit-logger.php`)** – High/critical security events need immediate surfaced alerts in server logs in addition to structured storage.

### Testing Checklist

- Load admin dashboard and confirm assets/localized data.
- Exercise AJAX settings/validation flows; verify nonce failures are blocked.
- Trigger error handler logging paths and confirm `debug.log` mirrors entries.
- Generate security events (e.g., simulated suspicious activity) and verify dual logging (audit option + `debug.log`).

### Developer Guidance

- Treat these files as high-sensitivity; prefer surgical diff-sized changes.
- Document any future `phpcs:ignore` directives with explicit rationale.
- Re-run `vendor/bin/phpcs` on the trio and update [`docs/phase9-core-files-compliance.md`](etch-fusion-suite/docs/phase9-core-files-compliance.md) if changes occur.

---

## PHPCS Standards & Compliance

**Updated:** 2025-10-29 12:55

Etch Fusion Suite maintains a single PHPCS workflow spanning local development, verification scripts, and CI enforcement. Use this section as the authoritative reference for coding standards, tooling, and compliance artefacts.

### Ruleset & Scope

- **Ruleset:** [`etch-fusion-suite/phpcs.xml.dist`](etch-fusion-suite/phpcs.xml.dist)
- **Scanned paths:** `includes/`, `assets/` (non-minified), and `etch-fusion-suite.php`
- **Excluded paths:** `vendor/`, `node_modules/`, `tests/`, `scripts/`, minified assets (`*.min.js`, `*.min.css`), and tooling artefacts (e.g., `phpunit.xml.dist`)
- **Runtime flags:** parallelism (`--parallel=8`), UTF-8 encoding, progress output, and colorized reports

### Enabled Sniffs

The ruleset builds on `WordPress-Core` while enabling targeted sniffs for security, naming, and reliability:

- `WordPress.Security.EscapeOutput`
- `WordPress.Security.ValidatedSanitizedInput`
- `WordPress.Security.NonceVerification`
- `WordPress.PHP.StrictComparisons`
- `WordPress.PHP.YodaConditions`
- `WordPress.DateTime.RestrictedFunctions`
- `WordPress.NamingConventions.PrefixAllGlobals`
- `WordPress.WP.I18n`

Custom allowances include short array syntax, PSR-4 file naming, and a curated prefix list (`efs`, `etch_fusion_suite`, `EtchFusionSuite`, `Bricks2Etch`, etc.) to satisfy the prefixing sniff without impeding autoloading.

### Running PHPCS & PHPCBF

Use Composer aliases for day-to-day tasks or call the binaries directly:

```bash
# Lint the project using the configured ruleset
composer phpcs
vendor/bin/phpcs --standard=phpcs.xml.dist

# Auto-fix fixable issues
composer phpcbf
./etch-fusion-suite/scripts/run-phpcbf.sh [--php-only | --stash]

# Generate human-readable summaries
composer phpcs:report   # Summary report
composer phpcs:full     # Full report
```

### Composer Commands

| Command | Description |
| --- | --- |
| `composer phpcs` | Run PHPCS with the project ruleset |
| `composer phpcbf` | Run PHPCBF with the project ruleset |
| `composer phpcs:report` | Emit a summary (`--report=summary`) |
| `composer phpcs:full` | Emit a full report (`--report=full`) |
| `composer verify-strict` | Execute `scripts/verify-strict-comparison.sh` |
| `composer verify-yoda` | Execute `scripts/verify-yoda-conditions.sh` |
| `composer verify-hooks` | Execute `scripts/verify-hook-prefixing.sh` |
| `composer verify-datetime` | Execute `scripts/verify-datetime-functions.sh` |
| `composer verify-phpcs` | Aggregate verification via `scripts/verify-phpcs-compliance.sh` |
| `composer install-hooks` | Install Git hooks via `scripts/install-git-hooks.sh --force` |

### Verification Scripts

Verification scripts live in `etch-fusion-suite/scripts/` and are executable directly or via the Composer aliases above:

- `verify-phpcs-compliance.sh` — Runs PHPCS (`--report=json`), persists the last run to `build/phpcs-last-run.json`, and orchestrates all supplemental verification scripts. Supports `--report` to regenerate `docs/phpcs-final-verification-report.md`.
- `verify-strict-comparison.sh` — Confirms every `in_array()` call uses strict comparison and regenerates `docs/phpcs-strict-comparison-verification.md` when invoked with reporting flags.
- `verify-yoda-conditions.sh` — Detects non-Yoda comparisons, producing `docs/yoda-conditions-violations-report.md`.
- `verify-hook-prefixing.sh` — Audits hooks and globals against the prefix list, updating `docs/hook-prefixing-verification-report.md`.
- `verify-datetime-functions.sh` — Flags prohibited PHP time helpers (`date()`, `gmdate()`) and refreshes `docs/datetime-functions-verification-report.md`.

> Tip: `scripts/analyze-phpcs-violations.sh` remains available for exploratory analysis and backlog generation but is not part of the automated verification chain.

### CI Enforcement

- **Workflow:** `.github/workflows/ci.yml`
- **Lint job:** Installs Composer dependencies, runs `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`, and executes all four verification scripts followed by `verify-phpcs-compliance.sh`. Failing any step blocks the pipeline.
- **Artifacts:** Summary output surfaces in the GitHub Actions log; final verification reports are written under `etch-fusion-suite/docs/` when the aggregate script succeeds with `--report`.

### Git Hooks

- **Template:** `etch-fusion-suite/scripts/pre-commit`
- **Installer:** `etch-fusion-suite/scripts/install-git-hooks.sh` (non-interactive when invoked via `composer install-hooks`)
- **Behaviour:** Runs PHPCS on staged PHP/PHTML files using the project ruleset and optionally (`--verify-all`) chains the four supplemental verification scripts. Failing checks block the commit until resolved.
- **Manual install:**

  ```bash
  composer install-hooks
  # or
  ./etch-fusion-suite/scripts/install-git-hooks.sh
  ```

### Phase Reports & References

- `etch-fusion-suite/docs/phpcs-auto-fixes-2025-10-28.md`
- `etch-fusion-suite/docs/phpcs-strict-comparison-verification.md`
- `etch-fusion-suite/docs/yoda-conditions-strategy.md`
- `etch-fusion-suite/docs/hook-prefixing-verification-report.md`
- `etch-fusion-suite/docs/datetime-functions-verification-report.md`
- `etch-fusion-suite/docs/css-converter-architecture.md`
- `etch-fusion-suite/docs/phase9-core-files-compliance.md`
- `etch-fusion-suite/docs/phase10-remaining-files-compliance.md`
- `etch-fusion-suite/docs/phpcs-final-verification-report.md`
- `etch-fusion-suite/docs/phpcs-lessons-learned.md`

### Developer Checklist

1. Run `composer phpcs` (or the pre-commit hook) before staging commits.
2. Apply automated fixes with `composer phpcbf` or `./etch-fusion-suite/scripts/run-phpcbf.sh`.
3. Execute targeted verification scripts (`composer verify-*`) when touching security, comparison, or hook-sensitive code.
4. Use `composer verify-phpcs` before requesting reviews to refresh the final verification report.
5. Install or refresh Git hooks via `composer install-hooks` after pulling changes to tooling.
6. Archive compliance evidence by committing regenerated docs under `etch-fusion-suite/docs/`.

---

### Phase 12: Review & Validation

**Status:** ✓ Complete (2025-10-29 13:30)

**Updated:** 2025-10-29 13:55

**Review Activities:**

- PHPCS compliance verified: 0 violations (`vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary`)
- All verification scripts validated (`composer verify-phpcs`, `verify-strict`, `verify-yoda`, `verify-hooks`, `verify-datetime`)
- `phpcs:ignore` directives documented across `includes/ajax/class-base-ajax-handler.php`, `includes/error_handler.php`, `includes/admin_interface.php`, DOM utilities (`includes/templates/class-html-parser.php`, `includes/templates/class-template-analyzer.php`), and migrator discovery to justify nonce access, infrastructure logging, DOM property access, and legacy hook filters
- Test coverage documented (PHPUnit prerequisites, integration scripts; Playwright E2E automation maintained in external Etch Fusion Suite QA repository)
- Lessons learned consolidated in `docs/phpcs-lessons-learned.md`

**Documentation Created:**

- `etch-fusion-suite/docs/phase12-review-checklist.md` — Comprehensive verification checklist
- `etch-fusion-suite/docs/phpcs-quick-reference.md` — Developer quick reference guide
- `etch-fusion-suite/docs/test-execution-report.md` — Test status and coverage analysis

**Key Findings:**

- `phpcs:ignore` directives remain in security-critical files (`includes/ajax/class-base-ajax-handler.php`, `includes/error_handler.php`, `includes/admin_interface.php`, DOM utilities, migrator discovery) with documented rationales for nonce access, infrastructure logging, DOM API usage, and legacy compatibility hooks
- PHPUnit suite requires WordPress test environment (covers nonce verification in `BaseAjaxHandlerTest.php`)
- Playwright suite (4 admin interface E2E tests) resides under `etch-fusion-suite/tests/playwright/`; wp-env storage state setup required before execution
- Integration scripts available for CSS converter, AJAX handlers, and content migration
- Recommendation: Expand automated coverage for converters, parsers, migrators in future sprints

**Developer Guidance:**

1. Reference the quick guide (`docs/phpcs-quick-reference.md`) for day-to-day commands and patterns
2. Use the review checklist before releases to confirm compliance artefacts
3. Capture test execution outcomes in `docs/test-execution-report.md`
4. Continue documenting lessons in `docs/phpcs-lessons-learned.md` as processes evolve

---

## Development Workflow

**Updated:** 2025-10-29 12:55

### Code Quality Checks

The plugin enforces code quality through CI workflows. All checks run automatically on push and pull requests:

- **PHPCS (WordPress Coding Standards)** - Enforced in CI lint job
- **PHPCompatibility** - Enforced in CI compatibility job (PHP 7.4-8.4)
- **PHPUnit** - Enforced in CI test job with WordPress test suite

### Running PHPCBF

Refer to [PHPCS Standards & Compliance](#phpcs-standards--compliance) for full guidance on PHPCBF usage, logging outputs, and available flags.

### PHPCS Violation Analysis

The exploratory reporting workflow (`scripts/analyze-phpcs-violations.sh`) is documented under [PHPCS Standards & Compliance](#phpcs-standards--compliance). Use it after large refactors to regenerate the manual fixes backlog.

### Manual Git Hooks (Optional)

Local hook installation steps and behaviour are centralised in [Git Hooks](#git-hooks). Re-run `composer install-hooks` whenever the hook template changes.

---

## References

- [CHANGELOG.md](CHANGELOG.md) - Version history
- [README.md](README.md) - Main documentation
- [etch-fusion-suite/README.md](etch-fusion-suite/README.md) - Plugin setup and wp-env workflow
- [etch-fusion-suite/TESTING.md](etch-fusion-suite/TESTING.md) - Comprehensive testing documentation
- [test-environment/README.md](test-environment/README.md) - Test environment overview

---

**Last Updated:** 2025-10-28 12:58  
**Version:** 0.11.20
