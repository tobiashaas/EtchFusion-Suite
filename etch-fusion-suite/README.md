# Etch Fusion Suite

## Developing the Plugin

### Prerequisites

- Node.js ≥ 18
- npm ≥ 9
- Docker Desktop (required by `@wordpress/env`)
- Composer (optional – the setup script will attempt to use Composer from the container if available, otherwise it will fall back to a local Composer installation on the host)

**Note:** The development environment uses PHP 8.1 by default. You can override this in `.wp-env.override.json` if needed.

**WordPress Core:** The shared `.wp-env.json` pulls the official `WordPress/WordPress#6.8` release directly from the wp-env registry. To use a custom archive (for example, a locally patched ZIP stored in `test-environment/wordpress.zip`), copy `.wp-env.override.json.example` to `.wp-env.override.json` and adjust the `core` path there.

### Memory Requirements

- Minimum recommended PHP memory: `256M`
- Default project configuration: `512M` for `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT`
- Configuration source: `etch-fusion-suite/.wp-env.json`

The dual-environment setup uses memory-intensive operations during Bricks setup, plugin activation, and migration runs. Memory limits are configured automatically in `.wp-env.json` for both Bricks (`cli`) and Etch (`tests-cli`).

Verify current memory limit:

```bash
npm run wp:etch -- eval "echo WP_MEMORY_LIMIT;"
```

### Quick Start

```bash
npm install
npm run dev
```

### Commercial Plugins Setup

The project supports commercial plugins for local testing without committing them to Git.

#### Required Plugins

- **Bricks Theme**: Download from https://bricksbuilder.io/
- **Etch Plugin**: Download from https://etchwp.com/
- **Etch Theme**: Download from https://etchwp.com/

#### Optional Plugins

- **Frames Plugin**: Download from https://getframes.io/
- **Automatic.css**: Download from https://automaticcss.com/

Optional add-ons (including `frames-latest.zip`, `acss-latest.zip`, and `wpvivid-latest.zip`) are not referenced by default in `.wp-env.json`. Add them in `.wp-env.override.json` if you want `wp-env` to auto-install them.

#### Setup Process

1. Download plugins from their respective websites
2. Place plugin ZIP files in `local-plugins/` directory
3. Run `npm run setup:commercial-plugins`
4. The script will automatically detect versions and create `-latest.zip` copies
5. Start development with `npm run dev`

#### Version Management

The setup script supports version-agnostic plugin detection:

- `bricks.2.2.zip`, `bricks.2.3.zip` -> `bricks-latest.zip`
- `frames-1.5.11.zip`, `frames-1.5.12.zip` -> `frames-latest.zip`
- `automatic.css-4.0.0-beta-2.zip` -> `acss-latest.zip`
- `etch-1.0.1.zip`, `etch-1.0.2.zip` -> `etch-latest.zip`

When multiple versions are found, the latest version is automatically selected.

#### License Configuration

Create a `.env` file from `.env.example` and add your license keys:

```bash
cp .env.example .env
# Edit .env and add your license keys
```

### WPvivid Custom Content Backup Import

Import real Bricks site data for testing without affecting users, plugins, or themes.

#### Creating a Custom Content Backup

1. Install WPvivid Backup Plugin on your live Bricks site
2. Go to WPvivid Backup -> Backup & Restore
3. Select "Custom Backup"
4. Choose these items:
   - `wp_posts`, `wp_postmeta`, `wp_options`
   - `uploads`
5. Do not select users, plugins, or themes
6. Click "Backup Now"
7. Download all backup parts (may be multiple files)

#### Importing Backup

1. Place all backup parts in `local-backups/` directory
2. Run `npm run import:wpvivid`
3. Follow the on-screen instructions
4. The script will detect multi-part backups and guide you through import

#### Backup Management

```bash
npm run backup:list      # List all available backups
npm run backup:info      # Show detailed backup information
npm run import:wpvivid   # Import latest backup
```

#### Real Data Migration Validation

After restoring a WPvivid backup in the Bricks instance, run:

```bash
npm run test:migration
npm run analyze:elements
npm run report:migration-quality
```

These commands provide:
- element type distribution from `_bricks_page_content_2`
- supported vs unsupported converter coverage
- CSS/component/content migration rates
- JSON + Markdown report files under `reports/`

#### For Other Developers

To work with this project, you'll need to obtain commercial plugins:

1. Purchase or obtain trial licenses for required plugins
2. Download plugin ZIP files
3. Place them in `local-plugins/` directory
4. Run `npm run setup:commercial-plugins`
5. Start development with `npm run dev`

The `.gitignore` configuration ensures commercial files are never committed to the repository.

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

**Docker container names:** The wp-env stack uses readable container names: **bricks** (Bricks WordPress, port 8888), **etch** (Etch WordPress, port 8889), **bricks-mysql**, **etch-mysql**, **bricks-cli**, **etch-cli**. This is applied via a patch to `@wordpress/env`; after `npm install`, `patch-package` reapplies it automatically. To see the new names, run `npm run destroy` then `npm run dev` (or `npx wp-env start`) once.

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

**Health check coverage (`npm run health`)**:
- WordPress core reachability for both environments
- Plugin activation status (`etch-fusion-suite` on both, `etch` on tests-cli)
- Memory limit validation (`WP_MEMORY_LIMIT`, minimum `256M`, configured `512M`)
- Theme configuration validation (Bricks environment availability and Etch environment cleanup)
- Composer autoloader validation (`vendor/autoload.php`) in both environments
- REST API reachability checks in both environments

Example health output patterns:
- `OK Bricks memory limit: WP_MEMORY_LIMIT is 512M`
- `OK Etch theme configuration: Active theme etch-theme on tests-cli`
- `WARN` or `FAIL` status when memory is below threshold or Bricks appears in tests-cli

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
| `npm run analyze:elements` | Analyze Bricks element type usage in real post/page metadata |
| `npm run report:migration-quality` | Generate migration KPIs and a Markdown quality report |
| `npm run test:playwright` | Run Playwright browser tests |
| `npm run test:playwright:ci` | Run tests in CI mode with line reporter |
| `npm run create-test-content` | Seed Bricks site with test posts, pages, and media |

#### Development Tools
| Script | Description |
| --- | --- |
| `npm run composer:install` | Install PHP dependencies in both containers (cli + tests-cli) |
| `npm run composer:install:cli` | Install PHP dependencies in Bricks (cli) container only |
| `npm run composer:install:tests-cli` | Install PHP dependencies in Etch (tests-cli) container only |
| `npm run composer:install:both` | Same as `composer:install` (runs both envs) |
| `npm run composer:check` | Verify `vendor/autoload.php` in both envs (PASS/FAIL per env, exit 1 if any fail) |
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

### Verifying Memory Configuration
```bash
npm run health
npm run wp:bricks -- eval "echo WP_MEMORY_LIMIT;"
npm run wp:etch -- eval "echo WP_MEMORY_LIMIT;"
```

Expected result:
1. No memory failures in `npm run health`
2. Both environments report `512M`

### Fixing Theme Issues
```bash
npm run wp:bricks -- theme list
npm run wp:etch -- theme list
npm run wp:etch -- theme delete bricks --force
npm run wp:etch -- theme activate etch-theme
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

### Memory Exhaustion Errors

**Symptom**: `PHP Fatal error: Allowed memory size ... exhausted`

Memory limits are configured automatically in `etch-fusion-suite/.wp-env.json`:
- `WP_MEMORY_LIMIT: "512M"`
- `WP_MAX_MEMORY_LIMIT: "512M"`

Verification steps:
1. Run `npm run health`
2. Confirm both environments report memory checks at or above `256M` (expected `512M`)
3. Confirm direct values:
   `npm run wp:bricks -- eval "echo WP_MEMORY_LIMIT;"`
   `npm run wp:etch -- eval "echo WP_MEMORY_LIMIT;"`

Fallback if automatic configuration is not applied:
1. Restart with clean rebuild: `npm run destroy && npm run dev`
2. Re-run `npm run health`
3. Review advanced scenarios in `etch-fusion-suite/docs/wp-env-etch-memory.md`

### Theme Management

Theme behavior is environment-specific:
- Bricks/development (`cli`) may include Bricks-related themes
- Etch/tests (`tests-cli`) should use `etch-theme`
- Bricks theme should not be present in tests-cli

Verification:
1. `npm run wp:bricks -- theme list`
2. `npm run wp:etch -- theme list`

Cleanup command for tests-cli:
`npm run wp:etch -- theme delete bricks --force`

### Composer troubleshooting and manual fix flow

**Relevant npm commands:**

| Command | Description |
| --- | --- |
| `npm run composer:install` | Install PHP dependencies in the container (populates `vendor/`) |
| `npm run activate` | Activate required plugins on both Bricks and Etch sites |

**Verifying dependencies:** After installing, confirm the autoloader exists: run `npm run shell:bricks` (or `npm run shell:etch` for the Etch site), then `ls -la wp-content/plugins/etch-fusion-suite/vendor/autoload.php`.

**After importing Bricks backups:** To ensure the plugin works after a restore or import of Bricks backups:

1. Ensure the environment is running (`npm run dev` or `npx wp-env start`).
2. Install Composer dependencies so the plugin has its `vendor/` directory:  
   `npm run composer:install`
3. Reactivate the plugin on both sites so WordPress loads the plugin and its autoloader:  
   `npm run activate`
4. Optionally verify: `npm run health` and `npm run plugin:list`.

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

### Critical Error on Etch Site ("There has been a critical error on this website")

**Symptoms**: White screen or generic WordPress critical error message on the **Etch** (target) site.

**Immediate recovery** (to regain access):
1. Disable the plugin: rename the folder `wp-content/plugins/etch-fusion-suite` to `etch-fusion-suite.disabled` (via FTP, file manager, or wp-env: `npm run shell:etch` then `mv wp-content/plugins/etch-fusion-suite wp-content/plugins/etch-fusion-suite.disabled`).
2. Reload the site; the error should disappear.

**Find the actual error** (in `wp-config.php` on the Etch site):
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Then check `wp-content/debug.log` for the fatal error message.

**Common causes and fixes**:
1. **Missing Composer dependencies** – The plugin needs `vendor/` (e.g. `psr/container`). On the Etch site, run `composer install` inside the plugin directory:  
   `cd wp-content/plugins/etch-fusion-suite && composer install --no-dev`  
   If you use wp-env: `npm run shell:etch` then `cd wp-content/plugins/etch-fusion-suite && composer install --no-dev`.  
   After the fix (from version 0.10.2 onward), a missing `vendor/` shows an admin notice instead of a white screen.
2. **Missing PHP extension** – Ensure required extensions are enabled (e.g. `mysqli`, `dom`, `json`). Check with `php -m` or your host’s PHP info.

### Health Checks Fail

**Symptoms**: `npm run health` reports failures, sites not responding

**Solutions**:
1. Generate full diagnostic: `npm run debug:full`
2. Check specific instance: `npm run health:bricks` or `npm run health:etch`
3. Review logs: `npm run logs:bricks:errors` and `npm run logs:etch:errors`
4. Verify WordPress is responding: Visit <http://localhost:8888> and <http://localhost:8889>
5. Restart if needed: `npm run reset:soft`

`npm run health` now validates:
- Memory limits for Bricks (`cli`) and Etch (`tests-cli`)
- Theme isolation (Bricks removed from tests-cli, `etch-theme` active in tests-cli)
- Core and plugin health for both environments

### Migration Fails

**Symptoms**: Connection refused, API errors, migration timeouts

**Solutions**:
1. Test connectivity: `npm run test:connection`
2. Verify both sites are healthy: `npm run health`
3. Check migration logs: `npm run logs:save` and review saved files
4. Verify JWT migration key is valid (not expired)
5. Check REST API: Visit <http://localhost:8889/wp-json/efs/v1/status>
6. Review security logs in WordPress admin

**"Target site returned Not Found" (404)**  
Migration runs from Etch (8889) and calls the Bricks site (8888). If the target returns 404:
- **Activate the plugin on both sites**: `npm run activate` (activates Etch Fusion Suite on both development and tests).
- **Bricks (target) Permalinks**: On <http://localhost:8888>, go to **Settings → Permalinks**. Do not use "Plain"; choose e.g. **Post name** and save (so `/wp-json/efs/v1/...` is available).
- **Quick check**: Open <http://localhost:8888/wp-json/efs/v1/status> in the browser; you should see JSON, not a 404 page.
- If you hit "Rate limit exceeded" after many retries, wait about a minute before trying again.

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

- If Composer is available in the wp-env container, it will be used to install dependencies in **both** `cli` (Bricks) and `tests-cli` (Etch) environments.
- If not available in the container, the script falls back to using Composer on the host machine.
- You can manually run `npm run composer:install` (or `npm run composer:install:both`) at any time to refresh vendor files in both envs.
- **Full recovery:** `npm run composer:install:both && npm run composer:check` installs in both envs and verifies `vendor/autoload.php`; `composer:check` reports PASS/FAIL per env and exits with code 1 if any env is missing the autoloader.

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

# Run admin dashboard redesign integration tests only
npm run test:playwright:admin-dashboard

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

**Admin Dashboard Redesign Integration Specs**:
- `tests/playwright/admin-dashboard-wizard.spec.ts`
- `tests/playwright/admin-dashboard-receiving.spec.ts`

These cover the Bricks 4-step wizard flow and Etch receiving-status states (receiving, completed, stale), including minimize/expand and dismiss controls.
Use `docs/admin-dashboard-deployment-checklist.md` for rollout and rollback verification.

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

**Windows (PowerShell):** If `composer` or `vendor/bin/phpcs` is not in your PATH, use the helper script:

```powershell
# From project root (E:\Github\EtchFusion-Suite)
.\scripts\run-phpcs.ps1
# or
.\run-phpcs.ps1

# From etch-fusion-suite
.\scripts\run-phpcs.ps1
```

PHP must be in your PATH. The script uses `vendor` from either `etch-fusion-suite/` or the project root. Run `composer install` in one of those directories if PHPCS is not found.

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
- ✅ **Component Migration:** Migrates Bricks components to Etch `wp_block` (reusable blocks) with props, slots, and ID mapping
- 🔌 **Extensible Migrators:** Register custom migration modules via a hook-driven API
- ✅ **Migrator System:** Allows developers to extend and customize the migration process

### Component Migration

Bricks components (from the `bricks_components` option) are migrated before content so that component references can be resolved:

- **Components** are converted to Gutenberg block HTML and sent to the Etch site via REST API (`POST /wp-json/efs/v1/components`).
- **ID mapping** is stored in the `b2e_component_map` option: each Bricks component ID is mapped to the created Etch `wp_block` post ID. During content migration, component instances (`cid`) are replaced with Etch `ref` (post ID).
- **Props** are mapped from Bricks property types (text, toggle, number, class) to Etch property schema.
- **Slots** are converted to `etch/slot` blocks; slot children are handled during content migration.
- **Execution order:** The component migrator runs with priority 15 (after CPTs at 10, before other migrators), so mappings are available when converting template elements to `etch/component` blocks.

**Example:** A Bricks component "Card" with ID `abc123` is migrated and created as `wp_block` post ID `42` on Etch. The option `b2e_component_map` stores `['abc123' => 42]`. When content that uses the Card component is migrated, each instance’s `cid: 'abc123'` becomes `ref: 42` in the generated `etch/component` block.

## 🔒 Security

### Nonce Verification

- **Centralized architecture:** All AJAX actions share the `'efs_nonce'` token generated in `admin_interface.php` and exposed to JavaScript via `efsData.nonce`.
- **Handler-level verification:** Each AJAX handler validates nonce tokens with `verify_request()` and capability checks; nonces are created in `admin_interface.php` and passed to JavaScript via `wp_localize_script()`.
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
