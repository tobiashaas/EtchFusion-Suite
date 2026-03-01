# Technical Documentation - Etch Fusion Suite

<!-- markdownlint-disable MD013 MD024 -->

**Last Updated:** 2026-03-01
**Version:** 0.14.2

---

## 📋 Table of Contents

1. [Architecture](#architecture)
2. [Development Environment Setup](#development-environment-setup)
3. [GitHub Updater](#github-updater)
4. [Security Configuration](#security-configuration)
5. [CSS Migration](#css-migration)
6. [Migration Execution Architecture](#migration-execution-architecture)
7. [Content Migration](#content-migration)
8. [Media Migration](#media-migration)
9. [API Communication](#api-communication)
10. [Frontend Rendering](#frontend-rendering)
11. [Testing Coverage](#testing-coverage)
12. [Continuous Integration](#continuous-integration)
13. [PHPCS Standards & Compliance](#phpcs-standards--compliance)
14. [Security](#security)
15. [Admin Settings UI](#admin-settings-ui)
16. [Migration Testing Workflow](#migration-testing-workflow)
17. [References](#references)

---

## Architecture

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

The plugin uses a dependency injection container for service management:

**Key Services:**

- `css_converter` → `\Bricks2Etch\Parsers\EFS_CSS_Converter`
- `api_client` → `\Bricks2Etch\Api\EFS_API_Client`
- `style_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Style_Repository`
- `settings_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Settings_Repository`
- `migration_repository` → `\Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository`

**Important:** All service bindings use fully qualified class names (FQCN) with correct namespaces.

### Autoloading & Namespaces

PSR-4 autoloading is managed by Composer. The plugin includes `vendor/autoload.php` as the primary autoloader. A legacy `includes/autoloader.php` remains active for backward compatibility with older class names (`class-*.php` files).

### Repository Pattern

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

---

## Development Environment Setup

### Overview

The plugin uses `@wordpress/env` (wp-env) to provide a Docker-based development environment with two WordPress instances:

- **Bricks Site** (development environment): Source site running Bricks Builder on port 8888
- **Etch Site** (tests environment): Target site running Etch on port 8889

This dual-instance setup allows testing migrations in isolation without affecting source data.

### Prerequisites

- Node.js ≥ 18
- npm ≥ 9
- Docker Desktop (required by wp-env)
- Composer (optional - can use container Composer)

### Quick Start for New Developers

#### 1. Clone the repository

```bash
git clone https://github.com/tobiashaas/EtchFusion-Suite.git
cd EtchFusion-Suite/etch-fusion-suite
```

#### 2. Install dependencies

```bash
npm install
```

#### 3. Start the environment

```bash
npm run dev
```

This command performs:
- Pre-flight checks (Docker running, ports available)
- Starts both WordPress instances
- Waits for WordPress to be ready
- Installs Composer dependencies
- Activates required plugins and themes
- Runs health checks
- Displays access URLs and credentials

#### 4. Access the sites

- Bricks: http://localhost:8888/wp-admin (admin / password)
- Etch: http://localhost:8889/wp-admin (admin / password)

#### 5. Verify setup

```bash
npm run health
npm run test:connection
```

### Configuration Files

#### `.wp-env.json` - Shared configuration

This file contains the base configuration for both instances:

- WordPress core version (6.8)
- PHP version (8.1 by default)
- Port configuration (8888, 8889)
- Debug settings (WP_DEBUG, SCRIPT_DEBUG, SAVEQUERIES)
- Lifecycle scripts (health checks after startup)
- Per-environment configuration (development vs tests)

#### `.wp-env.override.json` - Local customizations

This file is gitignored and allows local overrides:

- Custom ports to avoid conflicts
- Different PHP version
- Additional plugins/themes for testing
- Exposed MySQL ports for database GUI tools
- Custom config values (memory limits, Xdebug)

Copy `.wp-env.override.json.example` to `.wp-env.override.json` and customize as needed.

### npm Scripts Reference

#### Environment Management

| Script | Description | Example |
| --- | --- | --- |
| `npm run dev` | Full setup with health checks | `npm run dev` |
| `npm run stop` | Stop both instances | `npm run stop` |
| `npm run destroy` | Tear down completely | `npm run destroy` |
| `npm run reset` | Clean data and restart | `npm run reset` |
| `npm run reset:soft` | Clean without destroying containers | `npm run reset:soft` |
| `npm run reset:hard` | Complete teardown and rebuild | `npm run reset:hard` |

#### Logging & Debugging

| Script | Description | Example |
| --- | --- | --- |
| `npm run logs` | Combined logs from both instances | `npm run logs` |
| `npm run logs:all` | All logs (same as above) | `npm run logs:all` |
| `npm run logs:bricks` | Bricks site logs only | `npm run logs:bricks` |
| `npm run logs:bricks:follow` | Tail Bricks logs in real-time | `npm run logs:bricks:follow` |
| `npm run logs:bricks:errors` | Filter Bricks logs for errors only | `npm run logs:bricks:errors` |
| `npm run logs:etch` | Etch site logs only | `npm run logs:etch` |
| `npm run logs:etch:follow` | Tail Etch logs in real-time | `npm run logs:etch:follow` |
| `npm run logs:etch:errors` | Filter Etch logs for errors only | `npm run logs:etch:errors` |
| `npm run logs:save` | Capture logs to timestamped files | `npm run logs:save` |
| `npm run debug` | Generate diagnostic report | `npm run debug` |
| `npm run debug:full` | Generate verbose diagnostic report | `npm run debug:full` |

#### Health & Diagnostics

| Script | Description | Example |
| --- | --- | --- |
| `npm run health` | Comprehensive health checks | `npm run health` |
| `npm run health:bricks` | Health check for Bricks site only | `npm run health:bricks` |
| `npm run health:etch` | Health check for Etch site only | `npm run health:etch` |
| `npm run ports:check` | Verify required ports are available | `npm run ports:check` |
| `npm run env:info` | Display environment configuration | `npm run env:info` |

#### Database Operations

| Script | Description | Example |
| --- | --- | --- |
| `npm run db:backup` | Export both databases to backups/ | `npm run db:backup` |
| `npm run db:restore` | Import databases from backup files | `npm run db:restore` |
| `npm run db:export:bricks` | Export Bricks database only | `npm run db:export:bricks` |
| `npm run db:export:etch` | Export Etch database only | `npm run db:export:etch` |

#### WP-CLI Access

| Script | Description | Example |
| --- | --- | --- |
| `npm run wp -- <command>` | Run WP-CLI on Bricks site | `npm run wp -- plugin list` |
| `npm run wp:tests -- <command>` | Run WP-CLI on Etch site | `npm run wp:tests -- cache flush` |
| `npm run wp:bricks -- <command>` | Alias for Bricks site | `npm run wp:bricks -- option get home` |
| `npm run wp:etch -- <command>` | Alias for Etch site | `npm run wp:etch -- db query "..."` |
| `npm run shell:bricks` | Open interactive shell in Bricks | `npm run shell:bricks` |
| `npm run shell:etch` | Open interactive shell in Etch | `npm run shell:etch` |

#### Testing

| Script | Description | Example |
| --- | --- | --- |
| `npm run test:connection` | Verify API connectivity | `npm run test:connection` |
| `npm run test:migration` | Run end-to-end migration test | `npm run test:migration` |
| `npm run test:playwright` | Run Playwright browser tests | `npm run test:playwright` |
| `npm run test:playwright:ci` | Run tests in CI mode | `npm run test:playwright:ci` |
| `npm run create-test-content` | Seed Bricks site with test data | `npm run create-test-content` |

#### Development Tools

| Script | Description | Example |
| --- | --- | --- |
| `npm run composer:install` | Install PHP dependencies | `npm run composer:install` |
| `npm run activate` | Activate required plugins | `npm run activate` |
| `npm run plugin:list` | List active plugins | `npm run plugin:list` |
| `npm run lint` | Run ESLint on JavaScript | `npm run lint` |
| `npm run typecheck` | Run TypeScript type checking | `npm run typecheck` |

### Common Development Workflows

#### Starting a Fresh Development Session

```bash
npm run dev                  # Start environment
npm run health               # Verify everything is working
npm run create-test-content  # Add test data
```

#### Debugging Migration Issues

```bash
npm run test:connection      # Verify connectivity
npm run logs:bricks:errors   # Check for errors
npm run logs:etch:errors     # Check target site
npm run debug:full           # Generate diagnostic report
npm run logs:save            # Save logs for sharing
```

#### Resetting After Failed Migration

```bash
npm run reset:soft           # Clean data
npm run create-test-content  # Recreate test data
npm run test:migration       # Try again
```

#### Database Backup Before Risky Changes

```bash
npm run db:backup            # Export current state
# Make changes...
npm run db:restore           # Restore if needed
```

#### Investigating Plugin Issues

```bash
npm run shell:bricks         # Open shell
ls -la wp-content/plugins/etch-fusion-suite/vendor/
cat wp-content/debug.log | tail -n 50
exit
```

#### Running WP-CLI Commands

```bash
npm run wp:bricks -- plugin list
npm run wp:bricks -- cache flush
npm run wp:etch -- option get etch_styles
npm run wp:etch -- db query "SELECT * FROM wp_options WHERE option_name LIKE 'efs_%'"
```

### Troubleshooting Guide

#### Environment Won't Start

**Symptom**: `npm run dev` fails with Docker errors

**Solutions**:
1. Verify Docker is running: `docker ps`
2. Check port availability: `npm run ports:check`
3. Check Docker resources: Ensure Docker has enough memory (4GB+) and disk space
4. Try clean start: `npm run destroy && npm run dev`
5. Check Docker logs: `docker logs <container-id>`

#### Port Conflicts

**Symptom**: "Error: Port 8888 already in use"

**Solutions**:
1. Identify conflicting process: `npm run ports:check`
2. Stop conflicting service or kill process
3. Use custom ports: Copy `.wp-env.override.json.example` to `.wp-env.override.json` and change `port` and `testsPort`
4. Restart: `npm run dev`

#### Composer Installation Fails

**Symptom**: "Composer not found" or vendor directory missing

**Solutions**:
1. Check if Composer is in container: `npm run shell:bricks` then `composer --version`
2. Install Composer locally if needed
3. Manually run: `npm run composer:install`
4. Verify vendor directory: `npm run shell:bricks` then `ls -la wp-content/plugins/etch-fusion-suite/vendor/`

#### Plugin Activation Fails

**Symptom**: "Plugin could not be activated" or fatal errors

**Solutions**:
1. Check autoloader exists: `npm run shell:bricks` then `ls wp-content/plugins/etch-fusion-suite/vendor/autoload.php`
2. Regenerate autoloader: `npm run composer:install`
3. Check PHP errors: `npm run logs:bricks:errors`
4. Verify plugin files: `npm run shell:bricks` then `ls -la wp-content/plugins/etch-fusion-suite/`

#### Health Checks Fail

**Symptom**: `npm run health` reports failures

**Solutions**:
1. Generate full diagnostic: `npm run debug:full`
2. Check specific instance: `npm run health:bricks` or `npm run health:etch`
3. Review logs: `npm run logs:bricks:errors` and `npm run logs:etch:errors`
4. Verify WordPress is responding: Visit http://localhost:8888 and http://localhost:8889
5. Restart if needed: `npm run reset:soft`

#### Migration Fails

**Symptom**: Migration errors or connection refused

**Solutions**:
1. Test connectivity: `npm run test:connection`
2. Verify both sites are healthy: `npm run health`
3. Check migration logs: `npm run logs:save` and review saved files
4. Verify JWT migration key is valid (not expired)
5. Check REST API: Visit http://localhost:8889/wp-json/efs/v1/status
6. Review security logs in WordPress admin

#### Slow Performance

**Symptom**: Environment is slow or unresponsive

**Solutions**:
1. Check Docker resource allocation in Docker Desktop settings
2. Increase memory limit: Add `WP_MEMORY_LIMIT: "512M"` to `.wp-env.override.json`
3. Disable Xdebug if not needed
4. Clean up old containers: `docker system prune`
5. Restart Docker Desktop

#### Database Issues

**Symptom**: Database connection errors or corrupted data

**Solutions**:
1. Backup current state: `npm run db:backup`
2. Check database connection: `npm run wp:bricks -- db check`
3. Repair database: `npm run wp:bricks -- db repair`
4. Reset if needed: `npm run reset:hard`
5. Restore from backup: `npm run db:restore`

#### Getting Help

When reporting issues, include:
1. Diagnostic report: `npm run debug:full > debug-report.txt`
2. Saved logs: `npm run logs:save`
3. Environment info: `npm run env:info > env-info.txt`
4. Health check results: `npm run health > health-check.txt`
5. Steps to reproduce the issue

---

## GitHub Updater

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

### CORS (Cross-Origin Resource Sharing)

The plugin implements whitelist-based CORS for secure cross-origin API requests with comprehensive enforcement across all REST endpoints.

#### Configuration via WP-CLI

```bash
# Get current CORS origins
wp option get efs_cors_allowed_origins --format=json

# Set CORS origins
wp option update efs_cors_allowed_origins '["http://localhost:8888","http://localhost:8889","https://yourdomain.com"]' --format=json

# Add single origin (append to existing)
wp option patch insert efs_cors_allowed_origins end "https://newdomain.com"
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

The plugin enforces CORS validation at multiple levels:

1. **Per-endpoint checks**: Each endpoint handler calls `check_cors_origin()` early
2. **Global enforcement filter**: A `rest_request_before_callbacks` filter provides a safety net for all `/efs/v1/*` routes
3. **Header injection**: The `EFS_CORS_Manager::add_cors_headers()` method sets appropriate headers via `rest_pre_serve_request`
4. **Preflight handling**: OPTIONS requests now short-circuit with HTTP 204, inherit the same header set, and respect a configurable `Access-Control-Max-Age`

Additional filters are available to customise behaviour without patching core services:

- `efs_cors_allowed_methods`
- `efs_cors_allowed_headers`
- `efs_cors_max_age`

**Public endpoints** (e.g., `/efs/v1/migrate`, `/efs/v1/validate`) now enforce CORS validation despite using `permission_callback => '__return_true'`. This ensures:

- Server actively rejects disallowed origins with 403 JSON error (not just browser-level blocking)
- All CORS violations are logged with route, method, and origin information
- Future endpoints cannot bypass origin validation

**Authenticated endpoints** continue to use CORS checks within their `permission_callback` for defense-in-depth.

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
$settings_repo = etch_fusion_suite_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify settings
$security_settings['csp_enabled'] = true;
$settings_repo->save_security_settings($security_settings);
```

### Feature Flags

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
add_filter( 'efs_feature_enabled', '__return_true' );

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
$settings_repo = etch_fusion_suite_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify rate limits
$security_settings['rate_limit_enabled'] = true;
$security_settings['rate_limit_requests'] = 60;
$security_settings['rate_limit_window'] = 60; // seconds

$settings_repo->save_security_settings($security_settings);
```

### Validation & Input Handling

The central `EFS_Input_Validator` records machine-readable error codes together with sanitized context for every validation failure. This enables:

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

All security events are logged with severity levels:

- **Low**: Routine operations
- **Medium**: Authentication failures, rate limit exceeded
- **High**: Authorization failures, suspicious activity
- **Critical**: Destructive operations (cleanup, log clearing)

#### View Audit Logs

```bash
# Via WP-CLI
wp option get efs_security_log --format=json

# Via PHP
$audit_logger = etch_fusion_suite_container()->get('audit_logger');
$logs = $audit_logger->get_security_logs(100); // Last 100 events
```

The logger now sanitizes event metadata, masks sensitive keys (API keys, tokens, secrets), enforces a configurable history limit via `efs_audit_logger_max_events`, and emits structured context for both success and failure cases to support richer UI feedback.

---

## Admin Settings UI

### Migration key & token alignment

- Bricks setup view now exposes a dedicated Migration Key textarea (`#efs-migration-key`) outside the Start Migration form and keeps the Migration Token textarea (`#efs-migration-token`) inside the form with a readonly attribute.
- Added `#efs-migration-key-section` wrapper so Playwright and PHPUnit tests can locate the section without relying on structural changes.
- Updated labels to read “Paste Migration Key from Etch” and ensured copy-to-clipboard attributes remain intact for both key and token fields.
- Confirmed `assets/js/admin/migration.js` pulls values from the distinct selectors and writes returned tokens back to `#efs-migration-token` after start.

### Accordion removal & simplified sections

- Replaced the Bricks and Etch setup accordions with always-visible card sections to improve accessibility and reduce JS complexity.
- Updated admin JavaScript to remove accordion initialisation, scroll helpers, and expanded state management.
- Added `.efs-card__section` styling for consistent spacing and headings now that sections are no longer collapsible.
- Adjusted migration key validation, feature flag scroll targets, and copy/toast helpers to use the flattened markup.

### Previous accordion implementation (2025-10-30 13:45)

### Overview

The admin settings UI provides a centralized interface for configuring Etch Fusion Suite.

### Key Features

- **Target URL Normalization**: Docker hosts are automatically translated to `host.docker.internal` for seamless communication between containers.
- **Connection Flow**: Settings, validation, and migration key generation now operate solely on JWT migration keys—legacy application password inputs and PIN UI have been removed, including the client-side `pin-input.js` module and associated styles.
- **Target Validation**: Test Connection coordinates with `EFS_API_Client::validate_migration_key_on_target()` to call the Etch `/efs/v1/validate` REST endpoint, logging responses and surfacing verified payload metadata in the admin toast.
- **Status Endpoint Details**: The Etch `/wp-json/efs/v1/status` endpoint now returns `status` and `version` fields alongside plugin activation state so automated connection checks can verify the target build before starting a migration.
- **Accessibility Enhancements**: Field labels continue to expose `aria-labelledby` relationships, accordion headers manage `aria-expanded`, and non-JavaScript fallbacks ensure target URL and migration key inputs remain usable when scripting is disabled.
- **REST Validation Route**: `/wp-json/efs/v1/auth/validate` powers the connection test and returns a structured response when the migration token is accepted, mirroring the AJAX handler feedback.
- **CORS Defaults**: Server-origin requests (those without an `Origin` header) are accepted by the REST layer so container-to-container calls no longer fail with “Origin not allowed”.
- **Migration Key Endpoint**: The admin form now calls the target `/wp-json/efs/v1/generate-key` endpoint, returning the generated key payload directly from the Etch instance.
- **Service Container**: `token_manager` is registered in the plugin service container so REST endpoints can resolve `EFS_Migration_Token_Manager` without fatal errors. When the target URL resolves to the current site, migration keys are generated locally without issuing a loopback HTTP request.
- **Shared Migration Key Component**: A reusable partial renders migration key generation controls for both Bricks and Etch contexts, automatically inheriting nonce and target URL values from the primary settings form to eliminate duplicated markup and logic. Migration key textareas are now looked up within the active accordion panel to avoid collisions when both dashboards render simultaneously.
- **Feature Discovery**: The Template Extractor tab now remains visible even when disabled, presenting a locked state with a call-to-action that scrolls and expands the Feature Flags accordion section so administrators understand how to enable the feature.
- Tab navigation is keyboard accessible via `data-efs-tab` attributes and aria roles, matching the Playwright selectors in `tests/playwright/dashboard-tabs.spec.ts`.
- Dashboard accordions expose `data-efs-accordion-section`, `data-efs-accordion-header`, and `data-efs-accordion-content` attributes used by both Playwright and PHPUnit regression tests.

## Testing Coverage

The plugin includes comprehensive testing infrastructure with PHPUnit unit tests, integration tests, and Playwright end-to-end browser tests.

### Testing Architecture

**Dual-Site Testing**: Tests run against both WordPress instances to verify complete migration workflows:
- **Bricks Site**: Test migration setup, configuration, and initiation
- **Etch Site**: Test migration reception, processing, and results
- **Cross-Site**: Test API communication, authentication, and data transfer

**PHPUnit Suite**: Unit and integration tests cover:
- Admin interface rendering and interactions
- Security components (CORS, rate limiting, input validation)
- Repository pattern and data access
- CSS conversion logic and style mapping
- API endpoints and authentication

**Playwright Suite**: End-to-end browser tests cover:
- Complete migration workflows
- Admin dashboard interactions
- Cross-site communication
- Real-time progress updates
- Authentication flows

### Browser Testing with Playwright

#### Overview

Playwright tests run against both WordPress instances to verify end-to-end functionality including authentication, migration workflows, and UI interactions.

#### Authentication Setup

Tests use storage state authentication to avoid logging in for every test:
- **Auth Files**: Stored in `.playwright-auth/` directory (gitignored)
- **Separate Credentials**: Different auth files for Bricks and Etch sites
- **Auto-Generation**: Created by `auth.setup.ts` before test runs
- **Auto-Refresh**: Automatically regenerated when expired

#### Running Tests

```bash
# Run all tests
npm run test:playwright

# Run in headed mode (see browser)
npx playwright test --headed

# Run specific test file
npx playwright test tests/playwright/migration.spec.ts

# Debug mode with Playwright Inspector
npx playwright test --debug

# Run in CI mode
npm run test:playwright:ci

# Run specific browser
npm run test:playwright --project=chromium
npm run test:playwright --project=firefox
npm run test:playwright --project=webkit
```

#### Environment Variables

Customize test behavior with environment variables:

```bash
# WordPress credentials (default: admin/password)
EFS_ADMIN_USER=admin
EFS_ADMIN_PASS=password

# Site URLs (auto-detected from wp-env)
EFS_BRICKS_URL=http://localhost:8888
EFS_ETCH_URL=http://localhost:8889

# Debug mode
DEBUG=pw:api
```

#### Configuration

Playwright configuration in `playwright.config.ts` includes:
- **URL Resolution**: Automatic detection from environment variables
- **Separate Projects**: Different projects for Bricks and Etch tests
- **Auth Setup**: Dedicated project that runs before all tests
- **Retry Logic**: Automatic retries for flaky tests
- **Error Capture**: Screenshots and videos on failure
- **Parallel Execution**: Optimized test performance

#### Global Setup/Teardown

**Global Setup** (`global-setup.ts`):
- Runs health checks before tests
- Creates auth directory structure
- Validates both sites are accessible
- Sets up test data if needed

**Global Teardown** (`global-teardown.ts`):
- Saves logs if tests fail
- Cleans up temporary files
- Generates test reports

#### Writing Tests

Reference existing test files in `tests/playwright/` for examples:

**Key Patterns**:
- Use storage state for authentication
- Navigate between Bricks and Etch sites
- Test migration workflows end-to-end
- Verify UI elements and interactions
- Check API responses and data integrity

**Test Categories**:
- **Dashboard Tests**: Admin interface functionality
- **Migration Tests**: End-to-end migration workflows
- **API Tests**: Cross-site communication
- **Authentication Tests**: JWT-based login flows

### Configuration

Settings can be configured via the settings repository:

```php
$settings_repo = etch_fusion_suite_container()->get('settings_repository');
$security_settings = $settings_repo->get_security_settings();

// Modify settings
$security_settings['csp_enabled'] = true;
$settings_repo->save_security_settings($security_settings);
```

---

## CSS Migration

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

**Testing Recommendations:**

- Run CSS conversion tests (where available) to confirm no behavioural regressions.
- Validate output against representative Bricks projects that include custom CSS, responsive settings, and nested selectors.
- After imports, verify Etch cache invalidation and rebuild hooks regenerate updated styles.

For a full breakdown of helper methods, breakpoint mappings, logical property translations, and testing strategy, see `docs/css-converter-architecture.md`.

---

## Migration Execution Architecture

### Overview

The migration runs across two separate HTTP requests so that the initial AJAX call returns immediately to the browser without hitting PHP execution time limits.

```
Browser
  │
  ├─► POST wp-admin/admin-ajax.php?action=efs_start_migration
  │       │
  │       ├─ Validate key, init progress (status=running, 0%)
  │       ├─ Store active migration record
  │       ├─ [headless] Enqueue Action Scheduler job → return
  │       └─ [browser] Fire non-blocking loopback POST → return migrationId
  │
  │   [Second PHP process — loopback POST]
  │       │
  │       └─► wp-admin/admin-ajax.php?action=efs_run_migration_background
  │               │
  │               ├─ Validate bg_token transient (120 s TTL, one-time)
  │               ├─ Validation phase (EFS_Plugin_Detector)
  │               ├─ Content analysis
  │               ├─ Migrator execution (CPTs, ACF, etc.)
  │               ├─ CSS class migration
  │               ├─ Global CSS migration
  │               ├─ Collect media IDs + post IDs
  │               └─ Save checkpoint {phase, remaining_ids, …}
  │
  │   [JS-driven batch loop]
  │       │
  │       └─► Poll efs_get_migration_progress until step = 'media' or 'posts'
  │               └─► POST efs_migrate_batch (repeated) until completed
```

### Key Classes

| Class | File | Role |
|-------|------|------|
| `EFS_Migration_Starter` | `services/class-migration-starter.php` | Entry point; sync (REST) and async (AJAX) paths |
| `EFS_Background_Spawn_Handler` | `services/class-background-spawn-handler.php` | Fires the loopback POST; sync fallback on failure |
| `EFS_Async_Migration_Runner` | `services/class-async-migration-runner.php` | Runs all pre-batch phases; saves checkpoint |
| `EFS_Batch_Processor` | `services/class-batch-processor.php` | Processes one JS-driven batch (media or posts) |
| `EFS_Progress_Manager` | `services/class-progress-manager.php` | Reads/writes progress state |

### Two Migration Entry Points

`EFS_Migration_Starter` contains two completely separate paths:

| Method | Triggered by | Does |
|--------|-------------|------|
| `start_migration()` | REST `/efs/v1/migrate` | Runs entire migration synchronously in one request |
| `start_migration_async()` | AJAX `efs_start_migration` | Initialises state, spawns background process, returns immediately |

The browser-mode Wizard always uses the async AJAX path.

### Background Spawn Security

The loopback POST is authenticated by a short-lived transient instead of a nonce (because it is a server-to-server request, not a browser request):

- **Token**: `bin2hex(random_bytes(16))` — 32-character hex string (128 bits)
- **Storage**: WordPress transient `efs_bg_{migration_id}`, TTL 120 seconds
- **Validation**: Controller checks exact match, then deletes the transient immediately
- **Fallback**: If `wp_remote_post()` returns a `WP_Error`, `EFS_Async_Migration_Runner::run_migration_execution()` is called synchronously in the same request

### Headless Mode

When mode is `headless`, `start_migration_async()` skips the loopback spawn and instead calls `EFS_Headless_Migration_Job::enqueue_job()`, which registers an Action Scheduler task. This is required for environments where loopback requests are blocked (e.g. some managed hosts).

---

## Content Migration

### Overview

Converts Bricks elements to Gutenberg blocks with Etch metadata. The Element Factory supports 18 converter types in total, including converters: HTML (`etch/raw-html`), Shortcode (`etch/raw-html`), Text-Link (`etch/element` with anchor), and Rich Text (`etch/element`, multiple blocks via DOMDocument). See `etch-fusion-suite/includes/converters/README.md` for full converter documentation.

### Element Types

#### 0. Lists (ul, ol, li)

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

**Supported Tags:**

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

### Authentication & Migration Keys

**JWT-Based Migration Keys**: The plugin uses JSON Web Tokens (JWT) for migration authentication, replacing the previous application password system.

#### Token Structure

- **Header**: Algorithm (HS256) and type (JWT)
- **Payload**: `target_url`, `iat` (issued at), `exp` (expiration), `domain`
- **Signature**: HMAC-SHA256 signature for verification

#### Token Generation (Etch site)

1. User clicks "Generate Migration Key" in Etch admin
2. `migration_token_manager.php` generates JWT with embedded URL and credentials
3. Token is displayed as a single string to copy
4. Token is valid for 24 hours by default

#### Token Validation (Bricks site)

1. User pastes JWT migration key in Bricks admin
2. `migration_token_manager.php` decodes JWT and verifies signature
3. Expiration is checked against `exp` claim
4. Target URL is extracted from payload
5. Token is used as Bearer token for API requests

#### API Authentication

- All API requests use `Authorization: Bearer {jwt_token}` header
- No separate API key or Basic Auth required
- JWT is validated on every request
- Expired tokens return 403 Forbidden

#### Security Benefits

- **Single value to copy/paste**: Better UX than separate URL and password
- **Embedded URL**: Prevents misconfiguration
- **Time-limited tokens**: Reduce exposure (24-hour default)
- **Signature verification**: Prevents tampering
- **No plaintext passwords**: Enhanced security

#### Helper Function

```php
// Check if JWT is valid
$token_manager = etch_fusion_suite_container()->get('migration_token_manager');
$is_valid = $token_manager->validate_migration_token($jwt_string);
```

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

GitHub Actions provides automated linting, testing, and static analysis:

- **CI** workflow handles PHP linting (PHPCS), multi-version PHPUnit, and JS tooling checks
- **Release** workflow builds signed artifacts from version tags, restricted to `v*.*.*` patterns
- **CodeQL** workflow performs security scanning
- **dependency-review** workflow blocks insecure dependency updates on PRs

### Release Process

**Do not create GitHub Releases manually.** The release workflow handles everything automatically when a version tag is pushed.

**Correct order:**

1. Bump `* Version:` header and `ETCH_FUSION_SUITE_VERSION` constant in `etch-fusion-suite/etch-fusion-suite.php`
2. Add a `## [X.Y.Z] - YYYY-MM-DD` entry to `etch-fusion-suite/CHANGELOG.md`
3. Commit both files: `git commit -m "chore(release): bump version to X.Y.Z"`
4. Create and push an annotated tag:
   ```bash
   git tag -a vX.Y.Z -m "Release vX.Y.Z"
   git push origin main
   git push origin vX.Y.Z
   ```
5. CI detects the tag, runs `build-release.sh`, and publishes the GitHub Release with the ZIP + SHA256 checksum automatically.

**Why not `gh release create` manually?** If a release for the tag already exists when CI runs, `softprops/action-gh-release` returns 422 and retries until it aborts. The workflow includes a "delete existing release" step as a safety net, but the correct workflow is to always let CI create the release.

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

- Unit tests:
  - `tests/unit/TemplateExtractorServiceTest.php` validates payload shape and template validation edge cases via the service container.
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

Etch Fusion Suite applies a layered security model that consolidates nonce verification, capability checks, rate limiting, CORS validation, and audit logging.

### Security Architecture

Full architecture details, including handler walkthroughs and rate limit tables, are tracked in `docs/security-architecture.md`.

### Input Validation

AJAX handlers use `EFS_Base_Ajax_Handler::validate_input()` while REST endpoints rely on the same `EFS_Input_Validator::validate_request_data()` ruleset. Supported validation types include `url`, `api_key`, `token`, `text`, `integer`, and recursive arrays.

### Output Escaping

All responses flow through `wp_send_json_success()`, `wp_send_json_error()`, or `WP_REST_Response`. Admin data is localized via `wp_localize_script()`, avoiding direct echoes.

### Authentication & Authorization

`EFS_Base_Ajax_Handler::verify_request()` enforces nonce verification, capability checks (`manage_options`), rate limiting, and audit logging for every AJAX call. REST endpoints validate origins through `EFS_CORS_Manager` and tokens via `EFS_Migration_Token_Manager`.

### Nonce Verification

Etch Fusion Suite protects every AJAX request with a centralized nonce architecture:

- **Single nonce action** — All handlers share `'efs_nonce'`, generated in `admin_interface.php::enqueue_admin_assets()` and localized to JavaScript as `efsData.nonce`.
- **Handler-level verification** — All AJAX handlers call `verify_request()` as the first line to run nonce + capability checks and audit logging.
- **Comprehensive coverage** — All AJAX handler classes extend the base handler and invoke `verify_request()` before touching input.

**Documentation:**

- `docs/nonce-strategy.md` — Canonical nonce lifecycle, diagrams, testing guidance.
- `docs/security-architecture.md` — Layered security overview.
- `docs/security-best-practices.md` — Implementation guidelines for new handlers.
- `docs/security-verification-checklist.md` — Nonce compliance checks and sign-off items.
- `tests/phpunit/BaseAjaxHandlerTest.php` — PHPUnit coverage for invalid nonce, missing capability, and success paths.

### Security Best Practices

Development guidelines for new handlers, REST endpoints, and admin integrations are maintained in `docs/security-best-practices.md`. Follow the documented patterns for sanitization, logging, and testing.

### Security Verification

Prior to release, complete the checklist in `docs/security-verification-checklist.md` covering AJAX handlers, REST endpoints, admin flows, services, and PHPCS audits.

---

## Admin Settings UI

The admin settings UI provides a centralized interface for configuring Etch Fusion Suite.

### Key Features

- **Target URL Normalization**: Docker hosts are automatically translated to `host.docker.internal` for seamless communication between containers.
- **Connection Flow**: Settings, validation, and migration key generation operate solely on JWT migration keys.
- **Target Validation**: Test Connection coordinates with `EFS_API_Client::validate_migration_key_on_target()` to call the Etch `/efs/v1/validate` REST endpoint.
- **Status Endpoint Details**: The Etch `/wp-json/efs/v1/status` endpoint returns `status` and `version` fields alongside plugin activation state.
- **Accessibility Enhancements**: Field labels expose `aria-labelledby` relationships, and non-JavaScript fallbacks ensure usability when scripting is disabled.
- **REST Validation Route**: `/wp-json/efs/v1/auth/validate` powers the connection test and returns structured responses.
- **CORS Defaults**: Server-origin requests are accepted by the REST layer so container-to-container calls succeed.
- **Migration Key Endpoint**: The admin form calls the target `/wp-json/efs/v1/generate-key` endpoint.
- **Service Container**: `token_manager` is registered in the plugin service container so REST endpoints can resolve `EFS_Migration_Token_Manager` without fatal errors.
- **Feature Discovery**: The Template Extractor tab remains visible even when disabled, presenting a locked state with a call-to-action.
- Tab navigation is keyboard accessible via `data-efs-tab` attributes and aria roles.

---

## PHPCS Standards & Compliance

Etch Fusion Suite maintains a single PHPCS workflow spanning local development, verification scripts, and CI enforcement. Use this section as the authoritative reference for coding standards, tooling, and compliance artefacts.

### Ruleset & Scope

- **Ruleset:** `etch-fusion-suite/phpcs.xml.dist`
- **Scanned paths:** `includes/`, `assets/` (non-minified), and `etch-fusion-suite.php`
- **Excluded paths:** `vendor/`, `node_modules/`, `tests/`, `scripts/`, minified assets, and tooling artefacts
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

Custom allowances include short array syntax, PSR-4 file naming, and a curated prefix list (`efs`, `etch_fusion_suite`, `EtchFusionSuite`, `Bricks2Etch`, etc.).

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

- `verify-phpcs-compliance.sh` — Runs PHPCS, persists results, and orchestrates supplemental verification scripts
- `verify-strict-comparison.sh` — Confirms every `in_array()` call uses strict comparison
- `verify-yoda-conditions.sh` — Detects non-Yoda comparisons
- `verify-hook-prefixing.sh` — Audits hooks and globals against the prefix list
- `verify-datetime-functions.sh` — Flags prohibited PHP time helpers

### CI Enforcement

- **Workflow:** `.github/workflows/ci.yml`
- **Lint job:** Installs Composer dependencies, runs PHPCS, and executes verification scripts. Failing any step blocks the pipeline.
- **Artifacts:** Summary output surfaces in the GitHub Actions log; verification reports are written under `etch-fusion-suite/docs/`.

### Git Hooks

- **Template:** `etch-fusion-suite/scripts/pre-commit`
- **Installer:** `etch-fusion-suite/scripts/install-git-hooks.sh`
- **Behaviour:** Runs PHPCS on staged PHP/PHTML files and optionally chains verification scripts. Failing checks block the commit.

### Developer Checklist

1. Run `composer phpcs` before staging commits.
2. Apply automated fixes with `composer phpcbf`.
3. Execute targeted verification scripts when touching security-sensitive code.
4. Use `composer verify-phpcs` before requesting reviews.
5. Install or refresh Git hooks via `composer install-hooks` after pulling changes.
6. Archive compliance evidence by committing regenerated docs under `etch-fusion-suite/docs/`.

---

## Migration Testing Workflow

### Validated Standard Test

```bash
# In etch-fusion-suite/
SKIP_BASELINE=1 npm run test:migration
```

Migrates `post` and `page` types from Bricks (port 8888) to Etch (port 8889). Uses the full headless flow — the only correct way to run a migration.

### What the Headless Flow Does (Order Matters)

The script `scripts/test-migration.js` calls these steps in sequence:

1. `ensureEnvironmentReady()` — checks plugins/themes are active, auto-repairs if needed
2. `generateMigrationKey()` — generates JWT token on Etch side (`tests-cli`)
3. `triggerMigration()` — calls `start_migration(mode: headless)` on Bricks side (`cli`)
4. `driveHeadlessMigration()` — calls `run_headless_job()` synchronously via WP-CLI

`run_headless_job()` internally runs `run_migration_execution()` which does:
- **CSS phase first**: `css_converter->convert_bricks_classes_to_etch()` generates Etch styles, populates `efs_style_map`
- **Content phase second**: for each post, `_cssGlobalClasses` entries are looked up in `efs_style_map` to produce styling in block output

**CSS must run before content.** If content runs without CSS, blocks get `"styles":[]` and `"class":""`.

### How Styles Are Stored

| Storage | Location | Format | Used for |
|---------|----------|--------|----------|
| `etch_styles` | Etch side | numerically-indexed array with `selector`, `css` | CSS file generation by Etch |
| `efs_style_map` | Both sides | `{bricks_id: {id, selector}}` | Bricks→Etch ID lookup during migration |

The 7-char style IDs in blocks (e.g. `"styles":["9359405"]`) match entries in `efs_style_map` via `id` field. The HTML `class` attribute carries the CSS class name that Etch renders.

### Resetting Before a Test

```bash
# Reset Etch side (styles + pages)
node scripts/reset-etch-migration-state.js

# Also clear Bricks migration state
npx wp-env run cli wp option delete efs_style_map efs_migration_progress efs_migration_checkpoint efs_active_migration
```

### Post-Type Mappings

| Bricks type | Etch target |
|-------------|-------------|
| `post` | `post` |
| `page` | `page` |
| `bricks_template` | `wp_block` or `page` |

`test-migration.js` resolves `bricks_template` target dynamically. The standard test does **not** include `bricks_template` in `selected_post_types`.

### What NOT to Do

Do not use these methods for targeted migration — they bypass the CSS phase:

- `migration_service->migrate_single_post()` — no `post_type_mappings`, no CSS
- `content_service->convert_bricks_to_gutenberg()` called directly — no CSS setup

Always go through `start_migration()` + `run_headless_job()` so CSS and content run in the correct order.

### Debugging

```bash
# Check migration progress (Bricks side)
npx wp-env run cli wp option get efs_migration_progress --format=json

# Check style map is populated
npx wp-env run cli wp eval '$m=get_option("efs_style_map",[]); echo count($m)." entries";'

# Check styles reached Etch
npx wp-env run tests-cli wp eval '$s=get_option("etch_styles",[]); echo count($s)." styles";'

# Filter error logs
npm run logs:bricks:errors
```

---

## References

- [CHANGELOG.md](CHANGELOG.md) - Version history
- [README.md](README.md) - Main documentation
- [etch-fusion-suite/README.md](etch-fusion-suite/README.md) - Plugin setup and wp-env workflow
- [etch-fusion-suite/TESTING.md](etch-fusion-suite/TESTING.md) - Comprehensive testing documentation
- [test-environment/README.md](test-environment/README.md) - Test environment overview
