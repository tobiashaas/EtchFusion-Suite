# Etch Fusion Suite

## Developing the Plugin

### Prerequisites

- Node.js ≥ 18
- npm ≥ 9
- Docker Desktop (required by `@wordpress/env`)
- Composer (optional – the setup script will attempt to use Composer from the container if available, otherwise it will fall back to a local Composer installation on the host)

**Note:** The development environment uses PHP 8.1 by default. You can override this in `.wp-env.override.json` if needed.

**WordPress Core:** The shared `.wp-env.json` pulls the official `WordPress/WordPress#6.8` release directly from the wp-env registry. To use a custom archive (for example, a locally patched ZIP stored in `test-environment/wordpress.zip`), copy `.wp-env.override.json.example` to `.wp-env.override.json` and adjust the `core` path there.

### Quick Start

```bash
npm install
npm run dev
```

### Required Plugin & Theme Archives

Place the provided ZIP archives in the test environment. wp-env will only auto-install additional ZIPs if they are specified in `plugins`/`themes` configuration (e.g., in `.wp-env.override.json`):

```text
test-environment/
  plugins/
    bricks.2.1.2.zip
    frames-1.5.11.zip
    automatic.css-3.3.5.zip
    etch-1.0.0-alpha-5.zip
    automatic.css-4.0.0-dev-27.zip
  themes/
    bricks-child.zip
    etch-theme-0.0.2.zip
```

**Auto-install Configuration Examples**:

Add local ZIP paths or remote URLs to `.wp-env.override.json`:

```json
{
  "plugins": [
    ".",
    "test-environment/plugins/bricks.2.1.2.zip",
    "test-environment/plugins/frames-1.5.11.zip",
    "https://downloads.wordpress.org/plugin/query-monitor.latest-stable.zip"
  ],
  "themes": [
    "test-environment/themes/bricks-child.zip",
    "test-environment/themes/etch-theme-0.0.2.zip"
  ]
}
```

**Alternative WP-CLI Installation**:

Install additional plugins manually before running `npm run activate`:

```bash
# Install from local ZIP files
npm run wp:bricks -- plugin install test-environment/plugins/bricks.2.1.2.zip --activate
npm run wp:etch -- plugin install test-environment/plugins/etch-1.0.0-alpha-5.zip --activate

# Install from remote URLs
npm run wp:bricks -- plugin install <https://downloads.wordpress.org/plugin/query-monitor.latest-stable.zip> --activate
```

The base plugin directory is mounted automatically (`"."` in `.wp-env.json`), while all additional dependencies should be installed using one of the methods above before running `npm run activate`.

### Comprehensive npm Scripts Reference

#### Environment Management

| Script | Description |
| --- | --- |
| `npm run dev` | Full setup with pre-flight checks, health validation, and error handling |
| `npm run stop` | Stop both WordPress instances |
| `npm run destroy` | Complete teardown - removes containers, networks, and data |
| `npm run reset` | Clean data and restart (equivalent to `reset:soft`) |
| `npm run reset:soft` | Clean databases and uploads without destroying containers |
| `npm run reset:hard` | Complete teardown and rebuild from scratch |

#### Logging & Debugging
| Script | Description |
| --- | --- |
| `npm run logs` | Show combined logs from both instances |
| `npm run logs:all` | Show all logs from both instances (same as above) |
| `npm run logs:bricks` | Show Bricks site logs (development environment) |
| `npm run logs:bricks:follow` | Tail Bricks site logs in real-time (Ctrl+C to stop) |
| `npm run logs:bricks:errors` | Filter Bricks logs for errors only |
| `npm run logs:etch` | Show Etch site logs (tests environment) |
| `npm run logs:etch:follow` | Tail Etch site logs in real-time (Ctrl+C to stop) |
| `npm run logs:etch:errors` | Filter Etch logs for errors only |
| `npm run logs:save` | Capture logs to timestamped files in logs/ directory |
| `npm run debug` | Generate diagnostic report with system information |
| `npm run debug:full` | Generate verbose diagnostic report with all details |

#### Health & Diagnostics
| Script | Description |
| --- | --- |
| `npm run health` | Run comprehensive health checks on both WordPress instances |
| `npm run health:bricks` | Health check for Bricks site only |
| `npm run health:etch` | Health check for Etch site only |
| `npm run ports:check` | Verify required ports are available and identify conflicts |
| `npm run env:info` | Display environment configuration and status information |

#### Database Operations
| Script | Description |
| --- | --- |
| `npm run db:backup` | Export both databases to timestamped SQL files in backups/ directory |
| `npm run db:restore` | Import databases from backup files |
| `npm run db:export:bricks` | Export Bricks database only |
| `npm run db:export:etch` | Export Etch database only |

#### WP-CLI Access
| Script | Description |
| --- | --- |
| `npm run wp -- <command>` | Run WP-CLI command on Bricks site |
| `npm run wp:tests -- <command>` | Run WP-CLI command on Etch site |
| `npm run wp:bricks -- <command>` | Alias for Bricks site WP-CLI |
| `npm run wp:etch -- <command>` | Alias for Etch site WP-CLI |
| `npm run shell:bricks` | Open interactive bash shell in Bricks container |
| `npm run shell:etch` | Open interactive bash shell in Etch container |

#### Testing
| Script | Description |
| --- | --- |
| `npm run test:connection` | Verify API connectivity between sites |
| `npm run test:migration` | Run end-to-end migration smoke test |
| `npm run test:playwright` | Run Playwright browser tests |
| `npm run test:playwright:ci` | Run tests in CI mode with line reporter |
| `npm run create-test-content` | Seed Bricks site with test posts, pages, and media |

#### Development Tools
| Script | Description |
| --- | --- |
| `npm run composer:install` | Install PHP dependencies in container |
| `npm run activate` | Activate required plugins on both sites |
| `npm run plugin:list` | List active plugins on both sites |
| `npm run lint` | Run ESLint on JavaScript files |
| `npm run typecheck` | Run TypeScript type checking |

### Dual-Instance Setup

The wp-env configuration creates two isolated WordPress instances for optimal migration testing:

#### Architecture Overview
- **Bricks Site** (development environment): Runs on port 8888, serves as the migration source
- **Etch Site** (tests environment): Runs on port 8889, serves as the migration target
- **Shared Plugin Code**: Both instances mount the same plugin directory from your local filesystem
- **Independent Data**: Each instance has its own database, uploads directory, and configuration

#### Benefits of Dual-Instance Architecture
1. **Isolation**: Test migrations without affecting source data
2. **Realistic Testing**: Simulates real-world migration between separate sites
3. **Convenience**: Both sites run simultaneously with a single command
4. **Debugging**: Easy to compare before/after states side by side
5. **Development**: Hot-reload plugin changes affect both sites instantly

#### Environment Naming
- **Development** = Bricks site (source)
- **Tests** = Etch site (target)

This naming convention aligns with wp-env's built-in environment handling and is reflected in the npm script aliases (`wp:bricks`, `wp:etch`).

## Development Workflows

### Starting Fresh Development Session
```bash
npm run reset:hard    # Complete teardown and rebuild
npm run health         # Verify everything is working
npm run create-test-content  # Add test data for migration
```

### Quick Restart Between Tests
```bash
npm run reset:soft     # Clean data without destroying containers
npm run create-test-content  # Recreate test data
```

### Debugging Migration Issues
```bash
npm run test:connection      # Verify API connectivity
npm run logs:bricks:errors   # Check Bricks site for errors
npm run logs:etch:errors     # Check Etch site for errors
npm run debug:full           # Generate comprehensive diagnostic report
npm run logs:save            # Save logs for sharing or analysis
```

### Database Backup Before Risky Changes
```bash
npm run db:backup            # Export current state
# Make your changes...
npm run db:restore            # Restore if needed
```

### Investigating Plugin Issues
```bash
npm run shell:bricks         # Open interactive shell
ls -la wp-content/plugins/etch-fusion-suite/vendor/
cat wp-content/debug.log | tail -n 50
exit
```

### Running WP-CLI Commands
```bash
npm run wp:bricks -- plugin list      # List plugins on Bricks site
npm run wp:etch -- cache flush        # Clear cache on Etch site
npm run wp:bricks -- option get etch_styles  # Check migrated styles
npm run wp:etch -- db query "SELECT * FROM wp_options WHERE option_name LIKE 'efs_%'"  # Inspect settings
```

### Performance Monitoring
```bash
npm run health               # Check both instances' health
npm run env:info             # Show environment configuration
npm run ports:check          # Verify port availability
```

## Troubleshooting

### Environment Won't Start

**Symptoms**: Docker errors, port conflicts, resource issues

**Solutions**:
1. Verify Docker is running: `docker ps`
2. Check port availability: `npm run ports:check`
3. Ensure Docker has enough resources (4GB+ memory recommended)
4. Try clean start: `npm run destroy && npm run dev`
5. Check Docker logs: `docker logs <container-id>`

### Port Conflicts

**Symptom**: "Error: Port 8888 already in use"

**Solutions**:
1. Identify conflicting process: `npm run ports:check`
2. Stop conflicting service or kill process
3. Use custom ports: Copy `.wp-env.override.json.example` to `.wp-env.override.json` and modify `port` and `testsPort`
4. Restart: `npm run dev`

### Composer Installation Fails

**Symptoms**: "Composer not found", missing vendor directory

**Solutions**:
1. Check Composer availability: `npm run shell:bricks` then `composer --version`
2. Install Composer locally if needed
3. Manually run: `npm run composer:install`
4. Verify vendor directory: `npm run shell:bricks` then `ls -la wp-content/plugins/etch-fusion-suite/vendor/`

### Plugin Activation Fails

**Symptoms**: "Plugin could not be activated", fatal errors

**Solutions**:
1. Check autoloader: `npm run shell:bricks` then `ls wp-content/plugins/etch-fusion-suite/vendor/autoload.php`
2. Regenerate autoloader: `npm run composer:install`
3. Check PHP errors: `npm run logs:bricks:errors`
4. Verify plugin files: `npm run shell:bricks` then `ls -la wp-content/plugins/etch-fusion-suite/`

### Health Checks Fail

**Symptoms**: `npm run health` reports failures, sites not responding

**Solutions**:
1. Generate full diagnostic: `npm run debug:full`
2. Check specific instance: `npm run health:bricks` or `npm run health:etch`
3. Review logs: `npm run logs:bricks:errors` and `npm run logs:etch:errors`
4. Verify WordPress is responding: Visit <http://localhost:8888> and <http://localhost:8889>
5. Restart if needed: `npm run reset:soft`

### Migration Fails

**Symptoms**: Connection refused, API errors, migration timeouts

**Solutions**:
1. Test connectivity: `npm run test:connection`
2. Verify both sites are healthy: `npm run health`
3. Check migration logs: `npm run logs:save` and review saved files
4. Verify JWT migration key is valid (not expired)
5. Check REST API: Visit <http://localhost:8889/wp-json/efs/v1/status>
6. Review security logs in WordPress admin

### Slow Performance

**Symptoms**: Environment is slow or unresponsive

**Solutions**:
1. Check Docker resource allocation in Docker Desktop settings
2. Increase memory limit: Add `WP_MEMORY_LIMIT: "512M"` to `.wp-env.override.json`
3. Disable Xdebug if not needed
4. Clean up old containers: `docker system prune`
5. Restart Docker Desktop

### Getting Help

When reporting issues, include:
1. Diagnostic report: `npm run debug:full > debug-report.txt`
2. Saved logs: `npm run logs:save`
3. Environment info: `npm run env:info > env-info.txt`
4. Health check results: `npm run health > health-check.txt`
5. Steps to reproduce the issue

## Composer Dependencies

- If Composer is available in the wp-env container, it will be used to install dependencies
- If not available in the container, the script falls back to using Composer on the host machine
- You can manually run `npm run composer:install` at any time to refresh vendor files (requires Composer in container)

If neither option is present, install Composer locally before running the setup. In CI, provision Composer explicitly (e.g. via `shivammathur/setup-php` with `tools: composer`) so the fallback succeeds.

If you encounter Composer-related errors, ensure Composer is installed either locally or bootstrap it inside the container.

### Local Overrides

Copy `.wp-env.override.json.example` to `.wp-env.override.json` to customize your development environment without modifying the shared configuration. The override file is gitignored and takes precedence over `.wp-env.json`.

#### Common Customizations

**Change Ports to Avoid Conflicts**:
```json
{
  "port": 9888,
  "testsPort": 9889
}
```

**Different PHP Version**:
```json
{
  "phpVersion": "8.2"
}
```

**Expose MySQL Ports for Database GUI Tools**:
```json
{
  "env": {
    "development": {
      "mysqlPort": 13306
    },
    "tests": {
      "mysqlPort": 13307
    }
  }
}
```

**Add Debugging Plugins**:
```json
{
  "plugins": [
    ".",
    "https://downloads.wordpress.org/plugin/query-monitor.latest-stable.zip"
  ]
}
```

**Enable Xdebug for Step Debugging**:
```json
{
  "config": {
    "XDEBUG_MODE": "debug,develop"
  }
}
```

**Increase Memory Limits**:
```json
{
  "config": {
    "WP_MEMORY_LIMIT": "512M",
    "WP_MAX_MEMORY_LIMIT": "1024M"
  }
}
```

**Parallel CI Configuration**:
When wp-env runs in parallel jobs, use unique ports to avoid collisions:
```json
{
  "port": 8888,
  "testsPort": 8889
}
```

#### Complete Example
See `.wp-env.override.json.example` for a comprehensive example with all available options.

## Browser Testing with Playwright

The plugin includes a comprehensive Playwright test suite for end-to-end testing of migration workflows, admin interfaces, and cross-site functionality.

### Test Architecture

**Dual-Site Testing**: Tests run against both WordPress instances to verify complete migration workflows:
- **Bricks Site**: Test migration setup, configuration, and initiation
- **Etch Site**: Test migration reception, processing, and results
- **Cross-Site**: Test API communication, authentication, and data transfer

### Authentication Setup

Tests use storage state authentication to avoid logging in for every test:
- **Auth Files**: Stored in `.playwright-auth/` directory (gitignored)
- **Separate Credentials**: Different auth files for Bricks and Etch sites
- **Auto-Generation**: Created by `auth.setup.ts` before test runs
- **Auto-Refresh**: Automatically regenerated when expired

### Running Tests

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

### Environment Variables

Customize test behavior with environment variables:

```bash
# WordPress credentials (default: admin/password)
EFS_ADMIN_USER=admin
EFS_ADMIN_PASS=password

# Site URLs (auto-detected from wp-env)
BRICKS_URL=http://localhost:8888
ETCH_URL=http://localhost:8889

# Note: WP_ENV_PORT and WP_ENV_TESTS_PORT are recognized fallback ports

# Debug mode
DEBUG=pw:api
```

### Configuration

Playwright configuration in `playwright.config.ts` includes:
- **URL Resolution**: Automatic detection from environment variables
- **Separate Projects**: Different projects for Bricks and Etch tests
- **Auth Setup**: Dedicated project that runs before all tests
- **Retry Logic**: Automatic retries for flaky tests
- **Error Capture**: Screenshots and videos on failure
- **Parallel Execution**: Optimized test performance

### Global Setup/Teardown

**Global Setup** (`global-setup.ts`):
- Runs health checks before tests
- Creates auth directory structure
- Validates both sites are accessible
- Sets up test data if needed

**Global Teardown** (`global-teardown.ts`):
- Saves logs if tests fail
- Cleans up temporary files
- Generates test reports

### Writing Tests

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

### Production Installation

**JWT-Based Migration Authentication**: The plugin now uses JSON Web Tokens (JWT) for migration authentication, replacing the previous application password system.

**Simplified Workflow**:
1. **Generate Migration Key** on Etch site - Creates a JWT token containing the target URL and credentials
2. **Paste Migration Key** on Bricks site - Single JWT contains everything needed
3. **Start Migration** - Token is used as Bearer token for API requests

**Installation Steps**:
1. Ensure `npm run composer:install` has populated the `vendor/` directory (or run `composer install --no-dev` in production)
2. Bundle the plugin code together with the generated `vendor/` directory
3. Upload and activate on both WordPress sites
4. Configure migration using the JWT-based authentication system

## Framer Template Extraction

The plugin includes experimental support for extracting templates from Framer websites and converting them to Etch-compatible formats.

### PHP-Level Feature Flag

Framer template extraction is disabled by default and must be enabled at the PHP level:

**Enable via wp-config.php**:
```php
define( 'EFS_ENABLE_FRAMER', true );
```

**Enable via Filter**:
```php
add_filter( 'efs_enable_framer', '__return_true' );
```

### Feature Behavior

- **Deployment-Level Decision**: This is a deployment-level configuration, not a user-facing toggle
- **Templates Tab**: When enabled, a "Templates" tab appears in the Etch admin dashboard
- **Security**: All endpoints are protected by authentication and CORS validation
- **Experimental**: This feature is experimental and may change in future releases

### Testing Locally

To test Framer integration locally, add to your `.wp-env.override.json`:
```json
{
  "extra": {
    "notes": "Set EFS_ENABLE_FRAMER=true to test Framer integration locally"
  }
}
```

Then add to your wp-config.php or use the filter approach above.

### Usage

1. Enable the feature using one of the methods above
2. Navigate to **Etch Dashboard** → **Etch Fusion Suite** → **Templates** tab
3. Use the template extraction interface to import Framer designs
4. Extracted templates will be converted to Etch-compatible formats

## Code Quality

### WordPress Coding Standards

The plugin enforces [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) via PHPCS. The configuration is defined in [`phpcs.xml.dist`](phpcs.xml.dist) and enforced automatically in the CI workflow (`.github/workflows/ci.yml`).

### Running PHPCS

Check for coding standards violations:

```bash
composer phpcs
# or
vendor/bin/phpcs --standard=phpcs.xml.dist
```

### Auto-Fixing Violations

PHPCBF can automatically fix many formatting issues:

```bash
composer phpcbf
# or
vendor/bin/phpcbf --standard=phpcs.xml.dist
```

**What PHPCBF can fix:**

- Indentation and spacing
- Array syntax standardization
- Line ending normalization
- Trailing whitespace
- Control structure formatting

**What requires manual fixes:**

- Security violations (escaping, sanitization, nonce verification)
- Yoda conditions
- Strict comparisons
- Date function replacements
- Hook prefixing
- I18n issues

**Important:** Always review changes before committing and run tests after auto-fixes.

### Hook Prefixing

- **Status:** 100% compliant (verified 2025-10-28)
- **Verification:** `composer verify-hooks` (runs `scripts/verify-hook-prefixing.sh`; use `--report` to regenerate `docs/hook-prefixing-verification-report.md`).
- **Documentation:** Detailed naming guidance in `docs/naming-conventions.md`.
- **Allowed Prefixes:** Configured via `WordPress.NamingConventions.PrefixAllGlobals` in `phpcs.xml.dist` (`efs`, `etch_fusion_suite`, subsystem prefixes, and legacy tags).
- **Best Practice:** Use `efs_` for AJAX/options/transients and `etch_fusion_suite_` for public hooks/global helpers. Document intentional exceptions with inline `phpcs:ignore` rationale.

### Date/Time Functions

100% compliant with `WordPress.DateTime.RestrictedFunctions`.

```bash
composer verify-datetime
vendor/bin/phpcs --standard=phpcs.xml.dist --sniffs=WordPress.DateTime.RestrictedFunctions includes/
```

- [Date/Time Strategy](docs/datetime-functions-strategy.md)
- [Verification Report](docs/datetime-functions-verification-report.md)

### Helper Scripts

Use the provided scripts for comprehensive PHPCS workflows:

**Run PHPCBF with detailed reporting:**

```bash
./scripts/run-phpcbf.sh
```

This script:

- Creates a backup branch
- Runs pre/post PHPCS checks
- Generates diff statistics
- Saves detailed logs to `docs/`

**Analyze remaining violations:**

```bash
./scripts/analyze-phpcs-violations.sh
```

This generates:

- Violation counts by category
- Top 10 files by violation count
- Updated backlog at `docs/phpcs-manual-fixes-backlog.md`

### PHPCS Cleanup Initiative

The project follows a phased approach to achieve full PHPCS compliance:

- **Phase 1:** PHPCBF Auto-Fixes ✅ (completed)
- **Phase 2-12:** Manual fixes for security, Yoda conditions, strict comparisons, etc.

### Phase 12: Review & Validation

- ✓ 100% PHPCS compliance re-verified (2025-10-29 13:30)
- ✓ All verification scripts passing (`composer verify-*`)
- ✓ `phpcs:ignore` audit documented intentional directives in `includes/ajax/class-base-ajax-handler.php`, `includes/error_handler.php`, `includes/admin_interface.php`, and DOM/migrator utilities (nonce access, infrastructure logging, DOM property access)
- ✓ Test coverage documented (PHPUnit prerequisites, integration scripts, and Playwright E2E suite under `etch-fusion-suite/tests/playwright/` with wp-env storage state setup)
- ✓ Developer quick reference and review checklist published

**Quick Links:**

- [Phase 12 Review Checklist](docs/phase12-review-checklist.md)
- [PHPCS Quick Reference](docs/phpcs-quick-reference.md)
- [Test Execution Report](docs/test-execution-report.md)
- [Lessons Learned](docs/phpcs-lessons-learned.md)

## 📚 Documentation

- [Development Workflow](../DOCUMENTATION.md)
- [PHPCS Auto-Fixes Report](docs/phpcs-auto-fixes-2025-10-28.md)
- [PHPCS Manual Fixes Backlog](docs/phpcs-manual-fixes-backlog.md)
- [Nonce Strategy](docs/nonce-strategy.md)
- [Security Architecture](docs/security-architecture.md)
- [Security Verification Checklist](docs/security-verification-checklist.md)
- [PHPCS Quick Reference](docs/phpcs-quick-reference.md)
- [Phase 12 Review Checklist](docs/phase12-review-checklist.md)
- [Test Execution Report](docs/test-execution-report.md)
- [TODOS.md](../TODOS.md) – Detailed phase tracking

### Composer Scripts

| Script | Description |
| --- | --- |
| `composer phpcs` | Check for coding standards violations |
| `composer phpcbf` | Auto-fix violations |
| `composer phpcs:report` | Generate summary report |
| `composer phpcs:full` | Generate detailed report with all violations |

### CI Enforcement

The CI workflow automatically runs PHPCS on all pull requests and pushes. The lint job must pass before merging.

## 🎯 Features

- ✅ **CSS Migration:** Converts 1135+ Bricks Global Classes to Etch Styles
- ✅ **Content Migration:** Migrates posts, pages, and Gutenberg content
- ✅ **Media Migration:** Transfers images and attachments
- ✅ **CSS Classes:** Frontend rendering with correct class names
- ✅ **Custom CSS:** Supports custom CSS from Global Classes
- ✅ **Nonce Verification:** Centralized WordPress nonce protection across all AJAX handlers
- ✅ **Batch Processing:** Efficient migration of large sites
- 🔌 **Extensible Migrators:** Register custom migration modules via a hook-driven API
- ✅ **Migrator System:** Allows developers to extend and customize the migration process

## 🔒 Security

### Nonce Verification

- **Centralized architecture:** All AJAX actions share the `'efs_nonce'` token generated in `admin_interface.php` and exposed to JavaScript via `efsData.nonce`.
- **Dual-layer verification:** `get_request_payload()` pre-validates nonce tokens (`$die = false` for JSON errors) before each handler re-validates with `verify_request()` and capability checks.
- **Complete coverage:** Every AJAX handler extends `EFS_Base_Ajax_Handler` and invokes `verify_request()` as the first instruction, ensuring consistent CSRF protection.

Learn more in the [Nonce Strategy documentation](docs/nonce-strategy.md) and supporting security guides.

## 🔧 Technical Details

The plugin implements comprehensive security measures to protect your migration process:

### CORS Whitelist

Cross-Origin Resource Sharing (CORS) uses a whitelist-based policy instead of wildcard access:

- **Default Origins**: `<http://localhost:8888>`, `<http://localhost:8889>` (development)
- **Configuration**: Add production domains via Settings Repository
- **Security**: Only whitelisted origins can access the REST API
- **Logging**: CORS violations are logged in the security audit log

**Configure CORS Origins:**

```php
$settings_repository = $container->get('settings_repository');
$settings_repository->save_cors_allowed_origins([
    'https://yourdomain.com',
    'https://www.yourdomain.com'
]);
```

### Rate Limiting

Protects endpoints from abuse using WordPress transients with sliding window algorithm:

- **AJAX Endpoints**: 60 requests/minute (general), 30 req/min (migrations), 10 req/min (auth), 5 req/min (sensitive)
- **REST API**: 30 requests/minute (general), 10 req/min (authentication)
- **Per-Identifier**: Limits tracked by IP address or user ID
- **Automatic**: Rate limits enforced automatically, no configuration needed

**Rate Limit Exceeded Response:**

```json
{
  "success": false,
  "data": {
    "message": "Rate limit exceeded. Please try again later.",
    "code": "rate_limit_exceeded"
  }
}
```

### Input Validation

Comprehensive validation for all endpoint parameters:

- **URL Validation**: Checks format and allowed protocols (http/https only)
- **API Key Validation**: Minimum 32 characters, alphanumeric format
- **Token Validation**: Minimum 64 characters, validates expiration
- **Integer Validation**: Range checking with min/max values
- **Array Validation**: Structure and allowed keys validation
- **JSON Validation**: Format checking and decoding

**Validation Errors:**
Invalid input returns descriptive error messages and logs security events.

### Security Headers

HTTP security headers protect against common web vulnerabilities:

- **X-Frame-Options**: `SAMEORIGIN` (prevents clickjacking)
- **X-Content-Type-Options**: `nosniff` (prevents MIME sniffing)
- **X-XSS-Protection**: `1; mode=block` (enables XSS filter)
- **Referrer-Policy**: `strict-origin-when-cross-origin`
- **Permissions-Policy**: Disables geolocation, microphone, camera
- **Content-Security-Policy**: Environment-aware (relaxed for admin, strict for frontend)

### Audit Logging

Structured logging of security-relevant events:

- **Event Types**: `auth_success`, `auth_failure`, `rate_limit_exceeded`, `invalid_input`, `cors_violation`, `suspicious_activity`
- **Severity Levels**: `low`, `medium`, `high`, `critical`
- **Context**: User ID, IP address, user agent, request URI
- **Storage**: Last 1000 events in `b2e_security_log` option
- **Export**: JSON export available via Audit Logger API

**Access Security Logs:**

```php
$audit_logger = $container->get('audit_logger');
$logs = $audit_logger->get_security_logs(100, 'high'); // Last 100 high-severity events
```

### Application Password Handling

Environment-aware HTTPS requirement for Application Passwords:

- **Local/Development**: HTTPS not required (for ease of development)
- **Production**: HTTPS required (enforced automatically)
- **Detection**: Uses `WP_ENVIRONMENT_TYPE`, `WP_DEBUG`, domain patterns
- **Security**: Prevents insecure password transmission in production

### Security Best Practices

**For Development:**

- Use default localhost origins for CORS
- Rate limits are relaxed for local testing
- Security logging helps debug issues

**For Production:**

1. **Configure CORS**: Add your production domains to the whitelist
2. **Use HTTPS**: Always use HTTPS for API communication
3. **Monitor Logs**: Regularly review security audit logs
4. **Rotate Keys**: Periodically rotate API keys
5. **Limit Access**: Use Application Passwords with appropriate user roles
6. **Review Events**: Check for `rate_limit_exceeded` and `cors_violation` events

**Security Settings:**

```php
$settings_repository = $container->get('settings_repository');
$settings_repository->save_security_settings([
    'rate_limit_enabled' => true,
    'rate_limit_requests' => 60,
    'rate_limit_window' => 60,
    'audit_logging_enabled' => true,
    'require_https' => true // Production only
]);
```

### Environment Detection

The plugin automatically detects your environment:

- **Local**: localhost, .local, .test, .dev domains, Docker containers
- **Development**: `WP_DEBUG` enabled or `WP_ENVIRONMENT_TYPE=development`
- **Production**: Everything else (requires HTTPS)

**Check Environment:**

```php
$env_detector = $container->get('environment_detector');
$is_local = $env_detector->is_local_environment();
$env_type = $env_detector->get_environment_type(); // 'local', 'development', 'staging', 'production'
```
