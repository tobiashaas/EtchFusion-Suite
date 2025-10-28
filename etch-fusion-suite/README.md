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

`npm run dev` provisions two WordPress instances via `@wordpress/env`:

- **Bricks (Source)** – <http://localhost:8888/wp-admin> (admin / password)
- **Etch (Target)** – <http://localhost:8889/wp-admin> (admin / password)

The command:

1. Starts wp-env for both environments
2. Waits for both sites to become reachable
3. Installs Composer dependencies inside the plugin
4. Activates all required plugins and themes
5. Generates an Etch application password for API access

### Required Plugin & Theme Archives

Place the provided ZIP archives in the test environment so that wp-env can install them automatically. The configuration no longer mounts local development folders via `mappings`, ensuring clean clones remain portable:

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

The base plugin directory is mounted automatically (`"."` in `.wp-env.json`), while all additional dependencies are installed from the ZIP archives listed above.

### Common npm Scripts

| Script | Description |
| --- | --- |
| `npm run dev` | Full setup – start environments, install Composer deps, activate plugins, create credentials |
| `npm run stop` | Stop both environments |
| `npm run destroy` | Tear down environments and data |
| `npm run wp [cmd]` | Run WP‑CLI against the Bricks site |
| `npm run wp:etch [cmd]` | Run WP‑CLI against the Etch site |
| `npm run logs` | Tail combined wp-env logs |
| `npm run create-test-content` | Seed Bricks with posts, pages, classes, and media |
| `npm run test:connection` | Validate API connectivity between Bricks and Etch |
| `npm run test:migration` | Execute an end-to-end migration smoke test |
| `npm run debug` | Collect diagnostic information into a timestamped report |

### Composer Dependencies

The `npm run dev` script automatically checks for Composer availability:
- If Composer is available in the wp-env container, it will be used to install dependencies
- If not available in the container, the script falls back to using Composer on the host machine
- You can manually run `npm run composer:install` at any time to refresh vendor files (requires Composer in container)

If neither option is present, install Composer locally before running the setup. In CI, provision Composer explicitly (e.g. via `shivammathur/setup-php` with `tools: composer`) so the fallback succeeds.

If you encounter Composer-related errors, ensure Composer is installed either locally or bootstrap it inside the container.

### Local Overrides

Copy `.wp-env.override.json.example` to `.wp-env.override.json` to customize ports, PHP version, or additional plugins without modifying the shared configuration. Git ignores the override file by default.

**PHP Version:** The default PHP version is 8.1. You can override this in `.wp-env.override.json` if you need a different version (e.g., `"phpVersion": "8.2"`).

**Parallel CI:** When wp-env runs in parallel jobs, use the override file to assign unique `port`/`testsPort` values or serialize the job to avoid port collisions.

### Production Installation

1. Ensure `npm run composer:install` has populated the `vendor/` directory (or run `composer install --no-dev` in your CI pipeline).
2. Bundle the plugin code together with the generated `vendor/` directory.
3. Upload and activate on the production site.

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

**Documentation:**
- [Auto-fixes Report](docs/phpcs-auto-fixes-2025-10-28.md)
- [Manual Fixes Backlog](docs/phpcs-manual-fixes-backlog.md)
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
- ✅ **Batch Processing:** Efficient migration of large sites
- 🔌 **Extensible Migrators:** Register custom migration modules via a hook-driven API
- ✅ **Migrator System:** Allows developers to extend and customize the migration process

## 🔧 Technical Details

The plugin implements comprehensive security measures to protect your migration process:

### CORS Whitelist

Cross-Origin Resource Sharing (CORS) uses a whitelist-based policy instead of wildcard access:

- **Default Origins**: `http://localhost:8888`, `http://localhost:8889` (development)
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
