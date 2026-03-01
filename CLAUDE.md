---
description: 
alwaysApply: true
---

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Etch Fusion Suite is a WordPress plugin that migrates sites from **Bricks Builder** to **Etch**. It converts CSS/global classes, content (posts/pages/Gutenberg blocks), media, components, custom fields (ACF/metaboxes), and dynamic data between the two builders.

The main plugin code lives in `etch-fusion-suite/`. The root directory contains project-level config, CI/CD, and the root PHPUnit config.

## Development Environment

Uses `@wordpress/env` (Docker) with a **dual-instance** setup:
- **Port 8888** — Bricks site (source, `development` environment)
- **Port 8889** — Etch site (target, `tests` environment)

Both instances mount the same plugin directory. WP CLI access:
- `npm run wp -- <command>` (Bricks)
- `npm run wp:tests -- <command>` (Etch)

## Essential Commands

All npm commands run from `etch-fusion-suite/`:

```bash
# Environment
npm run dev              # Start both WordPress instances + full setup
npm run stop             # Stop instances
npm run destroy          # Complete teardown (⚠️ deletes test suite!)
npm run health           # Health check on both instances

# PHP linting (PHPCS - WordPress Coding Standards)
cd etch-fusion-suite
composer lint            # Run PHPCS
composer lint:fix        # Auto-fix with PHPCBF
composer phpcs:report    # Summary report

# PHP tests (PHPUnit) — MUST RUN FROM DOCKER
# `npm run dev` installs the test suite automatically every time it runs.
# Use test:setup only when you need to reinstall without a full dev cycle:
npm run test:setup       # Reinstall test suite only (no plugin activation, no Composer)

# Run unit tests in Docker (recommended):
npm run test:unit        # Run 162 unit tests
npm run test:unit:all    # Run all PHPUnit tests

# Manual Docker test suite installation (last resort fallback):
npx wp-env run cli bash /var/www/html/wp-content/plugins/etch-fusion-suite/install-wp-tests.sh wordpress_test root password 127.0.0.1:3306 latest true

# JavaScript linting
npm run lint             # ESLint on assets/js
npm run typecheck        # TypeScript check

# E2E tests (Playwright)
npm run test:playwright

# Migration testing
npm run test:migration   # Full end-to-end migration test
npm run validate:full-workflow  # Create content → migrate → export → validate
```

Root-level PHPUnit config (`phpunit.xml.dist`) bootstraps from `tests/phpunit/bootstrap.php` and covers the full `etch-fusion-suite/` directory.

## Architecture

### Namespace & Autoloading
PSR-4: `Bricks2Etch\` → `etch-fusion-suite/includes/`. Composer autoloader + a legacy `autoloader.php` for older classes.

### Dependency Injection
PSR Container in `includes/container/`. Service provider registers all services. Access via `etch_fusion_suite_container()->get('service_name')`.

### Key Layers (in `includes/`)

| Layer | Directory | Purpose |
|-------|-----------|---------|
| **AJAX Handlers** | `ajax/handlers/` | All extend `Base_Ajax_Handler`, must implement `verify_request()` for nonce verification |
| **Controllers** | `controllers/` | Dashboard, Migration, Settings, Template controllers |
| **Services** | `services/` | Business logic: Content, CSS, Media, Migration, Template Extractor |
| **Repositories** | `repositories/` | Data access with WordPress transient caching. Three domains: Settings, Migration, Style |
| **Converters** | `converters/elements/` | Element-level Bricks→Etch conversion. Factory pattern via `Element_Factory` |
| **Migrators** | `migrators/` | Extensible migrator system with registry/discovery. Custom migrators register via hooks |
| **Security** | `security/` | CORS, rate limiting, input validation, audit logging, security headers |

### Entry Point
`etch-fusion-suite.php` — defines constants (`ETCH_FUSION_SUITE_VERSION`, `ETCH_FUSION_SUITE_DIR`, etc.), loads the PSR container, and initializes the plugin.

### Authentication
JWT-based migration tokens for cross-site API calls. `migration_token_manager.php` handles token generation/verification. `docker-url-helper.php` translates localhost URLs to Docker container addresses.

## Coding Standards

**PHPCS** enforces WordPress-Core with these customizations (see `etch-fusion-suite/phpcs.xml.dist`):
- Short array syntax `[]` is allowed (not WordPress long arrays)
- PSR-4 file naming is used (WordPress hyphenated naming is disabled)
- Text domains: `etch-fusion-suite`, `bricks-etch-migration`
- Global prefixes: `efs`, `etch_fusion_suite`, `b2e` (legacy), `EFS`, `Bricks2Etch`
- Yoda conditions, strict comparisons (`===`), and hook prefixing are verified by additional scripts

**Nonce strategy**: Single nonce `'efs_nonce'` generated in `admin_interface.php`, exposed to JS via `wp_localize_script`, verified by all AJAX handlers.

## CI/CD

GitHub Actions (`.github/workflows/ci.yml`):
1. **Lint** — PHPCS + verification scripts (strict, Yoda, hooks, datetime)
2. **Test** — PHPUnit across PHP 7.4, 8.1, 8.2, 8.3, 8.4 with MySQL
3. **Node** — Playwright E2E (non-blocking)

Release workflow (`.github/workflows/release.yml`) builds ZIP with checksums on `v*.*.*` tags.

**Release process — do it in this order:**
1. Bump version in `etch-fusion-suite.php` (header + constant) and `CHANGELOG.md`
2. Commit + push to `main`
3. Create and push the tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z" && git push origin vX.Y.Z`
4. CI picks up the tag, builds the ZIP, and publishes the GitHub Release automatically

**Never run `gh release create` manually before pushing the tag** — the CI workflow handles release creation. If a release already exists when CI runs it causes a 422 conflict (the workflow deletes and recreates it, but manual pre-creation is unnecessary noise).

## Documentation as Single Source of Truth

`DOCUMENTATION.md` is the **authoritative reference** for all technical decisions, commands, workflows, and system behaviour.

**Rules — enforced without exception:**
- Every code change that affects behaviour, commands, or architecture **must** update `DOCUMENTATION.md` in the same session — no exceptions
- No duplicated content: CLAUDE.md and MEMORY.md reference `DOCUMENTATION.md`; they do not repeat it
- Stale content must be removed immediately — outdated docs cause regressions
- Before changing any established workflow (e.g. `npm run dev` startup sequence), verify the current state in `DOCUMENTATION.md` first
- `DOCUMENTATION.md` is the first thing to read when something breaks unexpectedly

**What belongs in `DOCUMENTATION.md`:**
- Full npm script reference with correct, tested descriptions
- Complete `npm run dev` startup sequence (what it does, in order)
- Architecture decisions and their rationale
- All migration workflows with exact commands
- Known gotchas and their fixes

## Documentation & Comments

**Comments are required** — they are stripped automatically from the distribution ZIP by `build-release.sh` (via `strip_php_comments()`), so they only exist in source/GitHub and never reach end users.

### When to comment
- Every non-trivial function or method gets a docblock (`/** ... */`) explaining what it does, its parameters, and return value
- Any logic that is not immediately obvious gets an inline `//` comment explaining *why*, not just *what*
- Architectural decisions, gotchas, and cross-system relationships (e.g. the two separate ID systems in converters) must be explained at the point where confusion could arise
- When a guard/check exists for a specific reason, that reason must be stated in the comment

### When comments must be updated
- **Renaming** a function, parameter, or variable → update all docblocks referencing it
- **Changing behaviour** of a function → update its docblock and any callers that have explaining comments
- **Removing** a guard or branch → remove its comment too (no orphaned comments)
- **Adding** a new system, pattern, or non-obvious interaction → document it before or alongside the code

### Comment language
- All comments and docblocks must be written in **English**
- Technical identifiers (function names, class names, etc.) stay as-is

## Project Conventions

- Update `CHANGELOG.md` with timestamps when making changes
- Update `DOCUMENTATION.md` for technical documentation changes
- Track work in `TODOS.md` with timestamps
- Converter changes must be documented in `includes/converters/README.md`
- Do not create new files without asking first — prefer editing existing files
- Test scripts go in `tests/` with naming pattern `test-[feature].php`
