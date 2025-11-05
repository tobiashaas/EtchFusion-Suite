# Etch Fusion Suite Documentation

**Updated:** 2025-11-04 21:22

## Table of Contents

1. [Overview](#overview)
2. [Development Environment](#development-environment)
3. [Configuration](#configuration)
4. [Helper Scripts](#helper-scripts)
5. [Testing](#testing)
6. [Troubleshooting](#troubleshooting)
7. [API Reference](#api-reference)

---

## Overview

Etch Fusion Suite is a comprehensive WordPress plugin that provides seamless migration from Bricks Builder to Etch Builder, along with enhanced development tools and utilities.

### Key Features

- **Bricks to Etch Migration**: Automated migration of Bricks layouts, styles, and settings to Etch
- **Development Environment**: Enhanced wp-env setup with health monitoring
- **Helper Scripts**: Comprehensive CLI tools for development, debugging, and maintenance
- **Testing Framework**: Playwright-based end-to-end testing with global setup/teardown
- **Database Management**: Backup and restore utilities with manifest tracking

---

## Development Environment

### Prerequisites

- Node.js 18+ 
- PHP 8.0+
- Docker and Docker Compose
- Composer
- npm or yarn

### Quick Start

```bash
# Clone and setup
git clone <repository-url>
cd etch-fusion-suite
composer install
npm install

# Start development environment
npm run dev

# Run tests
npm run test:playwright

# Check environment health
npm run health
```

### Environment Structure

The development environment uses wp-env to manage two WordPress instances:

- **Development (Bricks)**: `http://localhost:8888` - Runs Bricks Builder
- **Tests (Etch)**: `http://localhost:8889` - Runs Etch Builder

Both instances share the same plugin code but can have different configurations and databases.

---

## Configuration

### wp-env Configuration

#### `.wp-env.json`

Base configuration for both environments:

```json
{
  "core": "WordPress/WordPress#6.4",
  "phpVersion": "8.1",
  "plugins": [
    ".",
    "https://downloads.wordpress.org/plugin/bricks.zip",
    "https://downloads.wordpress.org/plugin/frames.zip",
    "https://downloads.wordpress.org/plugin/automatic-css.zip"
  ],
  "themes": [
    "https://downloads.wordpress.org/theme/bricks-child.zip",
    "https://downloads.wordpress.org/theme/etch-theme.zip"
  ],
  "port": 8888,
  "testsPort": 8889,
  "env": {
    "development": {
      "mysqlPort": 13306,
      "config": {
        "SCRIPT_DEBUG": true,
        "SAVEQUERIES": true,
        "WP_DEBUG": true,
        "WP_DEBUG_LOG": true
      }
    },
    "tests": {
      "mysqlPort": 13307,
      "config": {
        "SCRIPT_DEBUG": true,
        "SAVEQUERIES": true,
        "WP_DEBUG": true,
        "WP_DEBUG_LOG": true
      }
    }
  },
  "lifecycleScripts": {
    "afterStart": "node scripts/health-check.js --quiet"
  }
}
```

#### `.wp-env.override.json`

Create this file for local customizations (gitignored):

```json
{
  "port": 8080,
  "testsPort": 8081,
  "env": {
    "development": {
      "mysqlPort": 13308,
      "config": {
        "WP_DEBUG_DISPLAY": false
      }
    }
  },
  "mappings": {
    "wp-content/uploads": "./local-uploads"
  }
}
```

### Environment Variables

- `SKIP_HEALTH_CHECK`: Skip health checks during setup
- `SAVE_LOGS_ON_SUCCESS`: Save logs even when tests pass
- `SAVE_LOGS_ON_FAILURE`: Save logs when tests fail (default: true)
- `EFS_ENABLE_FRAMER`: Enable Framer template extraction features
- `SKIP_VENDOR_CHECK`: Skip vendor/autoload.php check in scripts

---

## Helper Scripts

All scripts are located in the `scripts/` directory and can be run via npm.

### Environment Management

#### `npm run dev`
Starts the development environment with enhanced setup:
- Pre-flight checks (Docker, ports, Node version)
- Composer installation with retry logic
- Plugin activation with verification
- Progress indicators and detailed summary

```bash
npm run dev                    # Full setup
npm run dev -- --skip-composer # Skip Composer install
npm run dev -- --skip-activation # Skip plugin activation
```

#### `npm run stop`
Stops all wp-env containers.

#### `npm run reset`
Soft reset (restarts containers) or hard reset (rebuilds environment).

```bash
npm run reset:soft   # Restart containers
npm run reset:hard   # Rebuild environment
```

### Health Monitoring

#### `npm run health`
Comprehensive health check of WordPress instances:

```bash
npm run health                    # Basic health check
npm run health -- --fix           # Attempt to fix issues
npm run health -- --save-report   # Save JSON report
npm run health -- --verbose       # Detailed output
```

Checks include:
- Docker container status
- WordPress endpoint availability
- Database connectivity
- Plugin activation status
- REST API health
- File permissions

#### `npm run env-info`
Display detailed environment information:

```bash
npm run env-info           # Human-readable format
npm run env-info -- --json # JSON output
npm run env-info -- --compare # Compare config vs reality
```

### Log Management

#### `npm run logs:follow`
Follow logs in real-time with filtering:

```bash
npm run logs:follow development error    # Development errors only
npm run logs:follow tests warning --follow # Follow test warnings
npm run logs:follow all notice --since 10m # Last 10 minutes
npm run logs:follow development "database" # Custom grep
```

Log levels:
- `error`: Fatal errors, exceptions, HTTP 5xx
- `warning`: Warnings, HTTP 4xx, deprecation notices
- `notice`: Notices, info messages
- `all`: All logs (no filtering)

#### `npm run logs:errors`
Show only error logs from both environments.

#### `npm run logs:save`
Save logs to files with optional compression:

```bash
npm run logs:save                    # Save last 1000 lines
npm run logs:save -- --lines 500     # Save last 500 lines
npm run logs:save -- --compress      # Save and compress with gzip
```

Output files:
- `logs/bricks-YYYY-MM-DD-HHmmss.log`
- `logs/etch-YYYY-MM-DD-HHmmss.log`
- `logs/combined-YYYY-MM-DD-HHmmss.log`

### Port Management

#### `npm run ports:check`
Check port availability and identify processes:

```bash
npm run ports:check                           # Check default ports
npm run ports:check -- --ports 8888,8889     # Check specific ports
npm run ports:check -- --kill --yes          # Kill processes using ports
npm run ports:check -- --wait                # Wait for ports to become available
```

### Database Management

#### `npm run db:backup`
Create database backups with manifest tracking:

```bash
npm run db:backup                        # Backup with timestamp name
npm run db:backup -- --name "pre-migration" # Custom backup name
npm run db:backup -- --compress             # Compress with gzip
npm run db:backup -- --list                 # List all backups
npm run db:backup -- --clean 7              # Remove backups older than 7 days
npm run db:backup -- --verbose              # Show debug information including target mapping
```

**Updated:** 2025-11-04 21:22

Backup files:
- `backups/bricks-<name>.sql[.gz]`
- `backups/etch-<name>.sql[.gz]`
- `backups/manifest.json` (metadata and index)

Environment Mapping:
- Logical names: `bricks` → wp-env target: `cli`
- Logical names: `etch` → wp-env target: `tests-cli`
- Both logical names and direct targets are accepted for robustness
- Use `--verbose` flag or `EFS_DEBUG=1` environment variable to see target mapping debug output

Metadata Accuracy:
- WordPress and plugin versions are now correctly retrieved from their respective wp-env instances
- Bricks metadata comes from the `cli` container
- Etch metadata comes from the `tests-cli` container
- Manifest shows distinct and correct versions under `environments.bricks` and `environments.etch`

#### `npm run db:restore`
Restore databases from backups with safety features:

```bash
npm run db:restore -- --all 2023-12-01T15-30-00     # Restore both
npm run db:restore -- --bricks pre-migration        # Restore Bricks only
npm run db:restore -- --etch backup1 --yes          # Restore Etch without confirmation
npm run db:restore -- --all latest --dry-run        # Preview restore
```

Safety features:
- Automatic backup before restore
- Confirmation prompts (unless `--yes` used)
- Cache clearing after restore
- Detailed error reporting

### Plugin Management

#### `npm run activate`
Activate plugins with enhanced error handling:

```bash
npm run activate                    # Standard activation
npm run activate -- --force         # Deactivate then reactivate
npm run activate -- --dry-run       # Preview changes
npm run activate -- --verbose       # Detailed output
npm run activate -- --skip-vendor-check # Skip vendor check
```

### Debugging

#### `npm run debug:full`
Comprehensive debugging information:

```bash
npm run debug:full              # Human-readable format
npm run debug:full -- --json    # JSON output
npm run debug:full -- --markdown # Markdown format
```

Includes:
- System information
- wp-env configuration
- Docker container health
- WordPress versions and status
- Active plugins and themes
- REST API health
- File permissions and disk space
- Recent error logs

---

## Testing

### Playwright Configuration

The project uses Playwright for end-to-end testing with enhanced setup:

#### Test Structure
```
tests/playwright/
├── global-setup.ts      # Environment health checks
├── global-teardown.ts   # Log capture and cleanup
├── auth.setup.ts        # Authentication setup
└── *.spec.ts           # Test files
```

#### Environment Variables
- `SKIP_HEALTH_CHECK`: Skip pre-test health checks
- `SAVE_LOGS_ON_SUCCESS`: Save logs on test success
- `SAVE_LOGS_ON_FAILURE`: Save logs on test failure
- `EFS_ENABLE_FRAMER`: Include Framer-specific tests

#### Running Tests

```bash
npm run test:playwright              # Run all tests
npm run test:playwright -- --headed  # Run with browser UI
npm run test:playwright -- --debug   # Debug mode
npm run test:playwright:ui          # Open Playwright UI
```

#### Test Projects

- **chromium**: Desktop Chrome tests
- **firefox**: Desktop Firefox tests  
- **webkit**: Desktop Safari tests
- **mobile-chrome**: Mobile Chrome tests
- **mobile-safari**: Mobile Safari tests

All test projects depend on the **setup** project which handles authentication.

---

## Troubleshooting

### Common Issues

#### Port Conflicts
```bash
# Check what's using ports
npm run ports:check

# Kill processes using ports
npm run ports:check -- --kill --yes

# Or use custom ports in .wp-env.override.json
```

#### Docker Issues
```bash
# Check Docker status
docker ps
docker system prune

# Restart environment
npm run reset:hard
```

#### Plugin Activation Failures
```bash
# Force reactivation
npm run activate -- --force

# Skip vendor check if needed
npm run activate -- --skip-vendor-check

# Check detailed status
npm run health -- --verbose
```

#### Database Issues
```bash
# Create backup before fixing
npm run db:backup -- --name "pre-fix"

# Restore from backup
npm run db:restore -- --all backup-name

# Check database connectivity
npm run health
```

### Debug Mode

Enable comprehensive debugging:

```bash
# Enable WordPress debug
WP_DEBUG=true WP_DEBUG_LOG=true npm run dev

# Run with verbose output
npm run dev -- --verbose

# Check full environment status
npm run debug:full -- --json > debug-info.json
```

### Log Analysis

```bash
# Follow error logs
npm run logs:follow development error

# Save recent logs for analysis
npm run logs:save -- --lines 1000 --compress

# Filter specific patterns
npm run logs:follow all "database error"
```

---

## API Reference

### Helper Scripts API

#### Health Check
```javascript
import { runHealthCheck } from './scripts/health-check.js';

const report = await runHealthCheck(fixIssues, verbose);
```

#### Environment Info
```javascript
import { getRunningEnvironmentInfo, loadWpEnvConfig } from './scripts/env-info.js';

const config = loadWpEnvConfig();
const info = await getRunningEnvironmentInfo();
```

#### Database Operations
```javascript
import { backupAllDatabases, listBackups, cleanOldBackups } from './scripts/backup-db.js';
import { restoreBackup } from './scripts/restore-db.js';

const backup = await backupAllDatabases(name, compress);
const result = await restoreBackup(identifier, options);
```

### Configuration API

#### wp-env Config Resolution
```javascript
// URL resolution priority:
// BRICKS_URL > BRICKS_HOST:BRICKS_PORT > localhost:8888
// ETCH_URL > ETCH_HOST:ETCH_PORT > localhost:8889
```

#### Environment Variables
All scripts support these environment variables:
- `CI`: Enable CI-specific behavior
- `DEBUG`: Enable debug output
- `QUIET`: Suppress non-error output
- `NO_COLOR`: Disable color output

---

## Contributing

### Development Workflow

1. **Setup**: `npm run dev`
2. **Make changes**: Edit code and test
3. **Health check**: `npm run health`
4. **Run tests**: `npm run test:playwright`
5. **Debug if needed**: `npm run debug:full`
6. **Backup before major changes**: `npm run db:backup`

### Code Standards

- Follow WordPress coding standards for PHP
- Use ESLint and Prettier for JavaScript
- Add comprehensive error handling
- Include detailed logging for debugging
- Write tests for new features

### Documentation Updates

- Update this file when adding new features
- Add inline comments for complex logic
- Update CHANGELOG.md for all changes
- Include usage examples for new scripts

---

**Last Updated:** 2025-10-29 15:30  
**Version:** Unreleased  
**Maintainers:** Etch Fusion Suite Team
