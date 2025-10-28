# Technical Documentation - Etch Fusion Suite

<!-- markdownlint-disable MD013 MD024 -->

**Last Updated:** 2025-10-28 12:58  
**Version:** 0.11.20

---

## 📋 Table of Contents

1. [Architecture](#architecture)
2. [Security Configuration](#security-configuration)
3. [CSS Migration](#css-migration)
4. [Content Migration](#content-migration)
5. [Media Migration](#media-migration)
6. [API Communication](#api-communication)
7. [Frontend Rendering](#frontend-rendering)
8. [Continuous Integration](#continuous-integration)
9. [Development Workflow](#development-workflow)
10. [References](#references)

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

## Security Configuration

**Updated:** 2025-10-27 20:52

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

## CSS Migration

**Updated:** 2025-10-21 23:20

### Overview

Converts Bricks Global Classes to Etch Styles with CSS class names in `etchData.attributes.class`.

### Key Components

#### 1. CSS Converter (`css_converter.php`)

**Function:** `convert_bricks_classes_to_etch()`

**Process:**

1. Fetch Bricks Global Classes
2. Convert CSS properties to logical properties
3. Collect custom CSS from `_cssCustom`
4. Generate Etch style IDs
5. Create style map with selectors
6. Merge custom CSS with normal styles

**Style Map Format:**

```php
[
  'bricks_id' => [
    'id' => 'etch_id',
    'selector' => '.css-class'
  ]
]
```

#### 2. Custom CSS Migration

**Updated:** 2025-10-21 23:20

**Function:** `parse_custom_css_stylesheet()`

**Process:**

1. Extract class name from custom CSS
2. Find existing style ID from style map
3. Use existing ID (not generate new one)
4. Store entire custom CSS as-is
5. Merge with existing styles

**Example:**

```css
/* Custom CSS from Bricks */
.my-class {
  --padding: var(--space-xl);
  padding: 0 var(--padding);
  border-radius: calc(var(--radius) + var(--padding) / 2);
}

.my-class > * {
  border-radius: var(--radius);
  overflow: hidden;
}
```

#### 3. CSS Class Extraction

**Function:** `get_css_classes_from_style_ids()`

**Process:**

1. Get style IDs for element
2. Skip Etch-internal styles (`etch-section-style`, etc.)
3. Look up selectors in style map
4. Extract class names (remove leading dot)
5. Return space-separated string

**Example:**

```php
Input:  ['abc123', 'def456']
Output: "my-class another-class"
```

---

## Content Migration

**Updated:** 2025-10-21 23:40

### Overview

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

**Updated:** 2025-10-21 23:20

### Authentication

Uses WordPress Application Passwords for secure API access.

### Endpoints

#### 1. Validate Token

```http
POST /wp-json/efs/v1/validate-token
```

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

**Updated:** 2025-10-27 23:48

GitHub Actions provides automated linting, testing, and static analysis:

- **CI** workflow handles PHP linting (PHPCS), multi-version PHPUnit, and JS tooling checks
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

## Development Workflow

**Updated:** 2025-10-28 12:58

### Code Quality Checks

The plugin enforces code quality through CI workflows. All checks run automatically on push and pull requests:

- **PHPCS (WordPress Coding Standards)** - Enforced in CI lint job
- **PHPCompatibility** - Enforced in CI compatibility job (PHP 7.4-8.4)
- **PHPUnit** - Enforced in CI test job with WordPress test suite

### PHPCS Auto-Fixes

**Updated:** 2025-10-28 12:58

- Reference report: [`docs/phpcs-auto-fixes-2025-10-28.md`](etch-fusion-suite/docs/phpcs-auto-fixes-2025-10-28.md)
- Records each PHPCBF execution (timestamps, version, sniff breakdown, diff stats)
- Use it to validate whether further manual fixes are necessary before progressing through additional phases

### Running PHPCBF

**Updated:** 2025-10-28 12:58

- Primary command: `./scripts/run-phpcbf.sh`
- Optional flags:
  - `--php-only` limits the post-run diff summary to PHP files so changes unrelated to PHPCBF stay hidden
  - `--stash` temporarily stashes existing work, guaranteeing the reported diff only contains PHPCBF changes (stashes are restored automatically)
- Outputs:
  - `${LOG_DIR}/phpcbf-output-<timestamp>.log`
  - `${LOG_DIR}/phpcs-post-cbf-<timestamp>.log`
  - Console summary with the diff scope and stash mode used

### PHPCS Violation Analysis

**Updated:** 2025-10-28 12:58

- Command: `./scripts/analyze-phpcs-violations.sh`
- Generates JSON + Markdown backlog regardless of PHPCS exit code (warnings included)
- Stdout summarises the top 10 files and sniff totals; backlog is written to [`docs/phpcs-manual-fixes-backlog.md`](etch-fusion-suite/docs/phpcs-manual-fixes-backlog.md) with the current timestamp
- Stderr is written to `${LOG_DIR}/phpcs-analyze-<timestamp>.stderr.log`, keeping JSON output clean for `jq`

### Manual Git Hooks (Optional)

While CI enforces all checks, you can optionally set up local Git hooks for faster feedback:

**Pre-commit hook** (`.git/hooks/pre-commit`):

```bash
#!/bin/bash
# Run PHPCS on staged PHP files

STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM | grep ".php$")

if [ -z "$STAGED_FILES" ]; then
    exit 0
fi

cd bricks-etch-migration
vendor/bin/phpcs --standard=phpcs.xml.dist $STAGED_FILES

if [ $? -ne 0 ]; then
    echo "PHPCS failed. Fix errors before committing."
    exit 1
fi
```

Make it executable: `chmod +x .git/hooks/pre-commit`

**Note:** Husky is not used in this project. Manual Git hooks are optional since CI enforces all checks.

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
