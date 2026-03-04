# Copilot Instructions for Etch Fusion Suite

**What is this?** A WordPress plugin that migrates Bricks Builder sites to Etch. It converts CSS/global classes, content (posts/pages/Gutenberg blocks), media, custom fields, and dynamic data between builders.

**Authoritative reference:** See `DOCUMENTATION.md` for all technical decisions, testing workflows, and detailed architecture. This file serves as a quick reference; complex questions should be checked against `DOCUMENTATION.md` first.

---

## Quick Reference: Essential Commands

All commands run from `etch-fusion-suite/` unless otherwise noted.

### Environment Setup
```bash
npm run dev              # Start both WordPress instances + full setup (required first)
npm run stop             # Stop instances without destroying data
npm run destroy          # Complete teardown (⚠️ deletes test suite and data)
```

### Testing (PHPUnit)
**CRITICAL:** Tests must run inside Docker to access the WordPress test suite at `/wordpress-phpunit`.
```bash
npm run test:unit        # Run 162 unit tests (recommended)
npm run test:unit:all    # Run all PHPUnit test suites

# Manual test suite installation (if needed)
npx wp-env run cli bash /var/www/html/wp-content/plugins/etch-fusion-suite/install-wp-tests.sh wordpress_test root password 127.0.0.1:3306 latest true
```

**Single test file example:**
```bash
npx wp-env run cli bash -c "cd wp-content/plugins/etch-fusion-suite && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit tests/Unit/MyTest.php"
```

### Linting & Code Quality
```bash
composer lint            # Run PHPCS (WordPress Coding Standards)
composer lint:fix        # Auto-fix PHPCS violations with PHPCBF
composer phpcs:report    # Summary report of issues

npm run lint             # ESLint for JavaScript
npm run typecheck        # TypeScript type checking
```

### E2E & Migration Testing
```bash
npm run test:migration   # Full end-to-end migration test (Bricks → Etch)
npm run validate:full-workflow  # Create content → migrate → export → validate

npm run test:playwright  # Playwright browser automation tests (local)
npm run test:playwright:ci  # CI mode (skips tests gracefully)
```

### Debugging & Inspection
```bash
npm run health           # Health check on both WordPress instances
npm run logs             # View logs from both instances
npm run logs:bricks      # Bricks instance logs
npm run logs:etch        # Etch instance logs
npm run shell:bricks     # SSH into Bricks Docker container
npm run shell:etch       # SSH into Etch Docker container

npm run wp -- <cmd>      # Run WP-CLI on Bricks instance
npm run wp:tests -- <cmd>  # Run WP-CLI on Etch instance
```

---

## Architecture Overview

### Dual WordPress Environment (Docker)
- **Port 8888** — Bricks Builder site (source, `development` environment)
- **Port 8889** — Etch site (target, `tests` environment)
- Both instances use the same plugin directory
- Login credentials: `admin` / `password`

### Core Namespace & Autoloading
- **Namespace:** `Bricks2Etch\` → `includes/` (PSR-4)
- **Autoloaders:** Composer + legacy `autoloader.php` for backward compatibility
- **Dependency Injection:** PSR Container in `includes/container/`
- Access services via: `etch_fusion_suite_container()->get('service_name')`

### Key Architectural Layers

| Layer | Directory | Purpose |
|-------|-----------|---------|
| **AJAX Handlers** | `includes/ajax/handlers/` | All extend `Base_Ajax_Handler`; must implement `verify_request()` for nonce verification |
| **Controllers** | `includes/controllers/` | Dashboard, Migration, Settings, Template controllers |
| **Services** | `includes/services/` | Business logic: Content, CSS, Media, Migration, Template Extractor |
| **Repositories** | `includes/repositories/` | Data access with WordPress transient caching (5-min TTL, targeted invalidation) |
| **Converters** | `includes/converters/elements/` | Element-level Bricks→Etch conversion. Factory pattern via `Element_Factory` |
| **Migrators** | `includes/migrators/` | Extensible migrator system with registry/discovery. Register custom migrators via hooks |
| **Security** | `includes/security/` | CORS, rate limiting, input validation, audit logging, security headers |

### Plugin Entry Point
- **File:** `etch-fusion-suite.php`
- **Constants:**
  - `ETCH_FUSION_SUITE_VERSION` (version number)
  - `ETCH_FUSION_SUITE_DIR` (plugin directory path)
  - `ETCH_FUSION_SUITE_URL` (plugin URL)
  - `ETCH_FUSION_SUITE_BASENAME` (plugin base name)

### Important Notes on Vendor Dependencies
- **Strauss vendor prefixing:** Firebase JWT and WooCommerce Action Scheduler are autoloaded via `vendor-prefixed/` with `EtchFusionSuite\Vendor\` namespace prefix
- **Manual PSR-4 registration:** Action Scheduler namespace is registered in `etch-fusion-suite.php` (Strauss generation issue)
- **Always require `vendor/autoload.php` before `vendor-prefixed/autoload.php`**

---

## Coding Standards & Conventions

### PHPCS (WordPress Coding Standards)
- **Config file:** `etch-fusion-suite/phpcs.xml.dist`
- **Customizations:**
  - Short array syntax `[]` is **allowed** (not WordPress long arrays)
  - PSR-4 file naming (not hyphenated)
  - Text domains: `etch-fusion-suite`, `bricks-etch-migration`
  - Global prefixes: `efs`, `etch_fusion_suite`, `b2e` (legacy)

### Security & Validation
- **Nonce:** Single nonce `'efs_nonce'` generated in `admin_interface.php`, exposed via `wp_localize_script`
- **AJAX handlers:** All must verify nonce via `verify_request()` method
- **Input validation:** Use WordPress sanitization functions (`sanitize_text_field`, `intval`, etc.)

### Comments & Documentation
Comments are **automatically stripped** from release ZIPs, so they exist only in source/GitHub:
- Every non-trivial function gets a docblock (`/** ... */`) explaining what it does, parameters, and return value
- Non-obvious logic gets inline `//` comments explaining *why*, not just *what*
- Architectural decisions and cross-system relationships must be documented at the point where confusion could arise
- Keep comments in **English**; technical identifiers stay as-is

---

## Directory Structure (Key Locations)

```
etch-fusion-suite/
├── includes/
│   ├── ajax/                    # AJAX handlers (extend Base_Ajax_Handler)
│   ├── controllers/             # Dashboard, Migration, Settings controllers
│   ├── services/                # Business logic layer
│   ├── repositories/            # Data access with caching (5-min TTL)
│   ├── converters/              # Element conversion (Bricks → Etch)
│   ├── migrators/               # Extensible migrator system
│   ├── security/                # CORS, rate limiting, audit logging
│   ├── container/               # Dependency injection (PSR Container)
│   ├── api/                     # API client & endpoints
│   ├── css/                     # CSS conversion logic
│   ├── admin/                   # Admin UI views
│   └── templates/               # Template extraction & conversion
├── assets/
│   ├── js/admin/                # Admin JavaScript (esbuild → dist/)
│   └── css/                     # Admin stylesheets
├── tests/
│   ├── phpunit/                 # PHPUnit bootstrap
│   ├── Unit/                    # Unit tests
│   ├── Integration/             # Integration tests
│   ├── WordPress/               # WordPress-specific tests
│   └── Playwright/              # E2E browser tests
├── vendor/                      # Composer dependencies (autoload.php)
├── vendor-prefixed/             # Strauss-namespaced dependencies
├── build/                       # Build artifacts & coverage reports
└── etch-fusion-suite.php        # Main plugin file
```

---

## Testing Strategy

### PHPUnit Test Suites
Organized in `tests/` with separate directories for test type:
- **Unit** — Tests individual classes/methods in isolation (no WordPress)
- **Integration** — Tests interaction between services with WordPress
- **WordPress** — Tests that require WordPress hooks, globals, DB
- **Playwright** — Browser automation E2E tests (local only, skipped in CI)

### Running a Single Test
```bash
npx wp-env run cli bash -c "cd wp-content/plugins/etch-fusion-suite && WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit tests/Unit/MyTest.php"
```

### Running Tests Without WordPress (Root-Level Only)
```bash
EFS_SKIP_WP_LOAD=1 php vendor/bin/phpunit -c phpunit.xml.dist
```

### CI/CD Pipeline
GitHub Actions (`.github/workflows/ci.yml`):
1. **Lint:** PHPCS + strict comparison/Yoda conditions/hook prefixing verification
2. **Test:** PHPUnit across PHP 7.4–8.4 with MySQL
3. **Node:** Playwright E2E (non-blocking in CI)

---

## Commercial Plugin Setup

The full `npm run dev` requires commercial plugin ZIPs in `local-plugins/`:
```
local-plugins/
├── bricks-*.zip            # Bricks Builder (required)
├── etch-<version>.zip      # Etch Plugin (required)
├── etch-theme-*.zip        # Etch Theme (required)
├── frames-*.zip            # Frames (optional)
└── automatic*.zip          # Automatic.css (optional)
```

**Setup script:** `node scripts/setup-commercial-plugins.js` auto-detects versioned ZIPs and creates `-latest.zip` symlinks.
**License keys:** Add to `.env` (copy from `.env.example`).

If plugins are missing, use `npx wp-env start` directly instead of the full `npm run dev`.

---

## Key Gotchas & Workarounds

1. **Composer --no-dev inside Docker:** The `--no-dev` flag is used in Docker containers. For local development testing with PHPUnit/PHPCS, always use `composer install` (with dev dependencies) in `etch-fusion-suite/`.

2. **patch-package on npm install:** The `postinstall` script runs `patch-package` to patch `@wordpress/env`. Use `npm install` (not `npm ci`) if the lockfile hasn't changed but patches need reapplying.

3. **Root vs. Plugin PHPUnit:** Root-level `phpunit.xml.dist` uses PHPUnit 10.5 with a different bootstrap than plugin-level PHPUnit 9.6. They test different things.

4. **npm run health pre-existing bug:** Has a SyntaxError in `health-check.js` (await outside async). Use direct `wp-env run cli wp ...` commands to verify environment health instead.

5. **Two separate ID systems:** Converters maintain both Bricks Builder IDs and Etch IDs. This dual mapping is documented in converter source code; confusion about which ID is being used is a common bug source.

---

## Release Process

**DO NOT run `gh release create` manually.** The CI workflow handles release creation automatically:

1. Bump version in `etch-fusion-suite.php` (header + constant) and `CHANGELOG.md`
2. Commit + push to `main`
3. Create and push the tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push origin vX.Y.Z`
4. GitHub Actions picks up the tag, builds the ZIP with checksums, and publishes the release

---

## MCP Servers

The repository includes configuration for MCP servers to enhance Copilot capabilities:

- **Playwright MCP** — Enables browser automation testing and E2E test analysis
- **Node.js MCP** — Provides JavaScript/TypeScript analysis and build script execution

These are configured in `.mcp-servers.json` and are available to Copilot when using Claude Code or compatible tools.

---

## Before You Code

- **Check `DOCUMENTATION.md` first** — It's the authoritative reference for technical decisions and system behavior
- **Review existing migration tests** — See `scripts/test-migration.js` for the expected flow
- **Understand the dual environment** — Changes to one site may need replication to the other
- **Run tests in Docker** — Local PHP won't have the WordPress test suite at `/wordpress-phpunit`
- **Verify PHPCS compliance** — `composer lint` before committing (CI will catch it)
