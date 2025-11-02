# Etch Fusion Suite Changelog

<!-- markdownlint-disable MD013 MD024 -->

## [Unreleased]

### ✨ Improvements

- (2025-11-01 13:15) **Admin PIN Input Visibility & Status Endpoint:** Updated `assets/js/admin/pin-input.js` to render application password boxes as plain text and remove the toggle control, refreshed `assets/css/admin.css` styles accordingly, and documented the change in `DOCUMENTATION.md` together with the enhanced `/wp-json/efs/v1/status` response (now returning `status` and `version`).

### ✨ Improvements

- (2025-10-31 10:15) **Admin Dashboard Testing Coverage:** Reworked `tests/ui/AdminUITest.php` to assert rendered dashboard markup, expanded `etch-fusion-suite/TESTING.md` with accordion, accessibility, responsive, and cross-browser guidance, and documented CI/test command parity in `DOCUMENTATION.md`.
- (2025-10-30 23:25) **Logs UI Styling:** Consolidated duplicate `.efs-log-entry` selectors into a single definition in `assets/css/admin.css` to prevent style overrides and keep log presentation consistent.
- (2025-10-30 20:30) **Migration Accordion & Key Handling:** Scoped migration key lookups to the active Bricks accordion panel, refreshed accordion transitions to use computed max-height animations with reduced-motion support, limited hidden API key fields to the Bricks context, and consolidated duplicate admin CSS rules for clearer maintenance.
- (2025-10-30 13:45) **Admin Dashboard Accordion Refactor:** Consolidated Bricks/Etch setup screens into an accessible accordion layout, introduced a shared migration key component, surfaced disabled Template Extractor tabs with guided enablement, and updated supporting JS/CSS to streamline key generation, migration controls, and feature discovery.

### 🐛 Bug Fixes

- (2025-10-30 11:05) **Bricks Builder CSP Overrides:** Allow `'unsafe-eval'` and the Bricks CDN font host (`https://r2cdn.perplexity.ai`) when loading builder surfaces (`?bricks=run|preview|iframe`) so the visual editor boots without console errors while keeping the stricter policy for normal admin/frontend traffic.
- (2025-10-30 10:45) **Connection Form Accessibility & Validation:** Updated the Bricks setup settings UI to mask application password PIN boxes with an optional show/hide toggle, enforce a 24-character validation gate before saving settings, align the Test Connection action with the latest settings form values, and improve accessibility with proper label focus handling plus a `<noscript>` fallback input.
- (2025-10-30 09:22) **Release Workflow Version Parsing:** Updated `scripts/build-release.sh` and `.github/workflows/release.yml` to use POSIX `[[:space:]]` classes for version extraction, ensuring Ubuntu runners correctly detect plugin header and constant versions for tags including pre-release suffixes. Tightened tag trigger to `v*.*.*`, validated parsing with sample tag `v0.10.3-beta.1`, and adjusted Composer cache paths plus `.distignore` to retain future runtime shell assets.
- (2025-10-30 08:18) **GitHub Updater Secure Download Enhancement:** Enhanced secure download handling in `includes/updater/class-github-updater.php` to support both `github.com` release assets and `api.github.com` zipball URLs:
  - Added `is_repo_url_for_this_plugin()` helper method using `wp_parse_url()` to validate URLs on both hosts
  - Updated `secure_download_handler()` to recognize URLs from both `github.com/{owner}/{repo}/` and `api.github.com/repos/{owner}/{repo}/`
  - Updated `secure_download_request_args()` to inject User-Agent and optional Authorization headers for both URL types
  - Ensures consistent HTTPS validation and header injection regardless of download source (release assets vs zipball fallback)
  - Prevents security bypass when GitHub releases contain no assets and `zipball_url` is used
- (2025-10-30 01:07) **GitHub Updater Critical Fixes:** Fixed 7 critical issues in `includes/updater/class-github-updater.php`:
  - Fixed constructor type hint to use correct namespace `Bricks2Etch\Core\EFS_Error_Handler` (prevents TypeError during dependency injection)
  - Replaced PHP 8-only `str_ends_with()` with PHP 7.4-compatible `substr_compare()` for `.zip` extension check
  - Aligned WordPress version requirements by reading from plugin header (`Requires at least: 5.0`, `Tested up to: 6.4`) instead of hardcoded `6.0`
  - Improved version parsing to return `WP_Error` on invalid tags instead of caching fallback `0.0.0` for 12 hours
  - Added download URL validation before advertising updates (prevents broken update notifications)
  - Implemented secure download handler with HTTPS validation, explicit User-Agent, and optional GitHub token authorization
  - Added `read_plugin_headers()` method to derive `requires` and `tested` values from main plugin file

- (2025-10-29 11:45) Fixed `verify-strict-comparison.sh` grep regex error causing false positives by simplifying pattern and making grep checks informational only (PHPCS is authoritative).
- (2025-10-29 11:45) Fixed `install-git-hooks.sh` interactive prompt issue when called via Composer by adding `--force` flag to composer script.
- (2025-10-29 14:18) Normalised AJAX URL conversion by centralising `convert_to_internal_url()` in `EFS_Base_Ajax_Handler`, ensuring both HTTP and HTTPS localhost URLs resolve to `http://efs-etch` consistently across handlers.
- (2025-10-29 16:58) Normalised connection Application Password input by stripping whitespace before validation so keys copied from WordPress admin no longer fail AJAX requests.
- (2025-10-29 19:25) Extended connection target URL normalisation to translate `localhost:8888/8889` to Docker-accessible hosts, allowing containerised WordPress instances to communicate without manual URL changes.
- (2025-10-29 20:33) Allowed REST requests without an Origin header to bypass CORS checks, avoiding false "Origin not allowed" errors during container-to-container connection tests.
- (2025-10-29 20:58) Wired migration key generation to call the target `/wp-json/efs/v1/generate-key` endpoint so the admin UI receives the resulting key payload directly from Etch.

### 🔧 Code Quality

- (2025-10-29 23:35) **Feature Flags Hardening:** Added `efs_feature_flags` to deactivation cleanup list, implemented key/value sanitization in repository save method, and added extensibility filter `efs_allowed_feature_flags` for whitelist customization.

### 🧰 Tooling

- (2025-10-29 12:35) **PHPCS Aggregate Verification:** Updated `scripts/verify-phpcs-compliance.sh` to generate reports only when checks succeed (or when `--report` is passed) and to derive report dates dynamically from the run timestamp.
- (2025-10-29 12:32) **Git Hooks Installation:** Normalised staged file paths in `scripts/pre-commit` so PHPCS runs against plugin-relative paths after `pushd` into `etch-fusion-suite/`.
- **Git Hooks Installation:** Introduced `scripts/pre-commit` template and `scripts/install-git-hooks.sh` installer with Composer alias `install-hooks`, enabling local PHPCS enforcement on staged PHP/PHTML files and optional `--verify-all` execution of supplemental scripts.
- **CI Note:** `.github/workflows/ci.yml` now caches Composer dependencies keyed on `etch-fusion-suite/composer.lock`, runs PHPCS with a summary report, and executes the four verification scripts to surface compliance regressions during CI.

### 🛡️ Security & Hardening

- Verified centralized nonce enforcement across all AJAX handlers (validation, connection, migration, cleanup, logs, CSS, media, content, template). Confirmed dual-layer verification in `admin_interface.php::get_request_payload()` and `EFS_Base_Ajax_Handler::verify_request()` with audit logging.
- `tests/phpunit/bootstrap.php` honors the `EFS_SKIP_WP_LOAD` flag (including env var parsing) so security-focused PHPUnit suites can execute without needing a WordPress database.
- Achieved 100% strict `in_array()` compliance across `includes/security/`, `includes/repositories/`, and core files via `scripts/verify-strict-comparison.sh` verification run.
- Converted remaining non-Yoda comparisons to WordPress-compliant style in security and converter layers (`class-cors-manager.php`, `class-audit-logger.php`, `css_converter.php`, `gutenberg_generator.php`). Added `scripts/verify-yoda-conditions.sh` and Composer `verify-yoda` alias for automated enforcement.

### 🔧 Code Quality

- **CSS Converter PHPCS Compliance:** Reworked `includes/css_converter.php` to replace 49 `error_log()` diagnostics with `EFS_Error_Handler::log_info()`, ensuring WordPress.Security.EscapeOutput compliance. Corrected selector comparisons to follow Yoda conditions, added inline documentation for conversion workflow, custom CSS parsing, import strategy, and rebuild triggers, and introduced `EFS_Error_Handler::log_info()` helper to support structured info logging.
- Routed verbose CSS converter helper logs through `log_debug_info()` so detailed selector payloads only emit when debug logging is enabled, reducing production log noise.
- (2025-10-29 10:44) Replaced remaining `error_log()` diagnostics in `includes/css_converter.php` and `includes/migration_token_manager.php` with structured `log_debug_info()` / `log_info()` / `log_error()` routing; re-verified PHPCS (WordPress.DateTime warnings only).
- (2025-10-29 10:51) Replaced `current_time('timestamp')` usage in `EFS_Migration_Token_Manager` with native `time()` math for expiration handling to satisfy `WordPress.DateTime.CurrentTimeTimestamp` guidance.
- (2025-10-29 09:05) Completed gating of nested selector and media query helper logs by switching remaining info-level diagnostics to `log_debug_info()`.
- (2025-10-29 09:26) **Core Files PHPCS Compliance:** Achieved full PHPCS compliance for `includes/admin_interface.php`, `includes/error_handler.php`, and `includes/security/class-audit-logger.php` (1,187 lines). Fixed 4 Yoda condition violations and documented 6 intentional `error_log()` usages with `phpcs:ignore` annotations. Confirmed nonce verification, recursive sanitization, and output escaping remain intact. Added `docs/phase9-core-files-compliance.md` summarising rationale for retaining `error_log()` in infrastructure contexts.

### 🧰 Tooling

- Added Composer `verify-yoda` script hook and made `scripts/verify-yoda-conditions.sh` tolerant of missing `jq`, still running PHPCS and regex scans while generating reports.
- Extended the Yoda verification unit suite (`tests/unit/test-yoda-conditions.php`) to cover CSS converter selector mapping and Gutenberg generator style map resolution.
- Introduced `scripts/verify-hook-prefixing.sh` with Composer alias `verify-hooks`, generating automated inventory/reporting for hook prefixes, parsing PHPCS configuration to ensure compliance, and providing optional `--report` regeneration of `docs/hook-prefixing-verification-report.md`.

### 📚 Documentation

- (2025-10-29 12:55) Consolidated PHPCS documentation into a dedicated "PHPCS Standards & Compliance" section with CI, tooling, verification scripts, and hook guidance referenced from a single location. Updated Development Workflow pointers accordingly.
- (2025-10-29 12:10) **Phase 12 Complete:** Created comprehensive lessons learned document (`docs/phpcs-lessons-learned.md`) covering all 12 PHPCS compliance phases, including challenges, solutions, prevention strategies, developer workflow recommendations, and tool reference. Updated TODOS.md with Phase 12 completion details.
- (2025-10-29 14:19) Updated Phase 12 documentation and test reports to detail intentional `phpcs:ignore` directives and clarify Playwright suite location under `etch-fusion-suite/tests/playwright/`.
- (2025-10-29 11:30) Added consolidated "PHPCS Standards & Compliance" section to `DOCUMENTATION.md`, updated manual Git hook instructions, and documented new Composer scripts and final verification artefacts for Phase 11 completion.
- Added `docs/nonce-strategy.md` as the canonical nonce lifecycle reference, including sequence diagrams, testing guidance, and PHPCS rationale.
- Expanded `docs/security-architecture.md`, `docs/security-verification-checklist.md`, and `docs/security-best-practices.md` with detailed nonce workflow guidance and cross-references to the new strategy document.
- Updated `DOCUMENTATION.md`, `etch-fusion-suite/README.md`, and `TODOS.md` to surface nonce compliance status, documentation links, and completion timestamps for Phase 3 of the PHPCS cleanup initiative.
- Documented the PHPUnit skip flag workflow (`EFS_SKIP_WP_LOAD=1 composer phpunit`) so unit tests can run in isolation without WordPress connectivity, including a 3-test verification run on 2025-10-28 18:30.
- Added `docs/phpcs-strict-comparison-verification.md` capturing Phase 4 strict comparison audit results and file-level findings (2025-10-28 20:30).
- Added `docs/yoda-conditions-strategy.md` outlining conversion rules, testing workflow, and prevention measures. Generated baseline violation report at `docs/yoda-conditions-violations-report.md` and reopened TODO Phase 5 with accurate status tracking.
- Documented hook prefix strategy and verification deliverables: `docs/naming-conventions.md`, `docs/hook-prefixing-verification-report.md`, updated `DOCUMENTATION.md`, `docs/security-best-practices.md`, `etch-fusion-suite/README.md`, and refreshed `TODOS.md` Phase 6 entry (all timestamped 2025-10-28 21:55).
- Updated `DOCUMENTATION.md`, `docs/datetime-functions-strategy.md`, and `docs/datetime-functions-verification-report.md` with the expanded Phase 7 audit (18× `current_time('mysql')`, 2× `current_time('Y-m-d H:i:s')`, 9× `wp_date()`), including the new token-based verification workflow and reporting guidance (2025-10-29 00:10).
- Added `docs/css-converter-architecture.md` capturing the four-step conversion workflow, 17 helper methods, breakpoint mapping, logical property conversion, import strategy, and testing guidance. Updated `DOCUMENTATION.md` with a dedicated CSS Converter section and refreshed TODO Phase 8 notes with implementation details.
- Added `tests/unit/test-strict-comparison.php` to cover validator strict comparisons and URL scheme normalization.
- Added `scripts/verify-strict-comparison.sh` with Composer `verify-strict` alias for ongoing automation.
- (2025-10-29 09:26) **Phase 9 Core Files Documentation:** Added `docs/phase9-core-files-compliance.md` detailing PHPCS fixes, rationale for intentional `error_log()` usage in infrastructure files, security verification results, and testing guidance. Updated `DOCUMENTATION.md` and `TODOS.md` with Phase 9 completion notes and timestamps.

## [0.11.27] - 2025-11-02 (12:15)

### 🧹 Maintenance
- Completed PHPCS remediation across feature flags, AJAX handlers, security headers, view templates, and the GitHub updater.
- Prefixed legacy `efs_*` hooks/functions with the `etch_fusion_suite_*` namespace, introducing deprecated wrappers to maintain third-party compatibility.
- Replaced short ternary fallbacks with explicit conditionals and wrapped view-level data in prefixed variables to satisfy `PrefixAllGlobals` sniffs.
- Normalised nonce detection in security headers via `filter_input()` and confirmed a clean `vendor/bin/phpcs --standard=phpcs.xml.dist --report=summary` run.

## [0.11.21] - 2025-10-28 (13:31)

### 🛡️ Security & Hardening

- Added inline security commentary to `includes/ajax/class-base-ajax-handler.php`, `includes/api_endpoints.php`, and `includes/admin_interface.php` clarifying nonce enforcement, sanitized superglobal usage, REST parameter handling, and rate limiting patterns.

### 📚 Documentation

- Introduced dedicated security documentation: `docs/security-architecture.md`, `docs/security-verification-checklist.md`, and `docs/security-best-practices.md`.
- Expanded `DOCUMENTATION.md` with a comprehensive security section referencing validation, escaping, authentication, and PHPCS guidance.
- Updated `TODOS.md` to mark Phase 2 security fixes complete with supporting references and verification notes.

-## [0.11.22] - 2025-10-28 (14:54)

### 🛠 Technical Changes

- Implemented missing REST route registration in `includes/api_endpoints.php`, aligning endpoints with the Etch `/efs/v1` namespace and ensuring permission callbacks respect the CORS manager.
- Added static `validate_request_data()`, `check_rate_limit()`, and `enforce_template_rate_limit()` helpers that consistently delegate to the service container, provide sanitized `WP_Error` responses, and set `Retry-After` headers on 429 responses.
- Hardened REST handlers to cast `get_json_params()` results to arrays before validation, preventing `null` payload notices and improving validator ergonomics.
- Enforced integer minimum validation for migration token `expires` fields, returning HTTP 400 when the timestamp is in the past.

### 📚 Documentation

- Updated `DOCUMENTATION.md` with refreshed API endpoint listings, validation expectations, and rate-limit behaviour for template management routes.
- Marked TODO entry for the Oct 28 REST endpoint compliance review as completed with timestamped audit trail.

### 🧪 Testing

- Verified REST endpoint registration and rate-limit helpers via local WP-REST checks (manual).

## [0.11.20] - 2025-10-28 (12:58)

### 🛠 Technical Changes

- Stabilised `scripts/analyze-phpcs-violations.sh` by eliminating command substitutions inside the backlog heredoc, separating stdout/stderr cleanly, and adding deterministic file hotspot generation for the PHPCS backlog.
- Enhanced `scripts/run-phpcbf.sh` with `--php-only` and `--stash` options, automatic stash restoration, and clearer run summaries (including diff scope, stash mode, and PHPCBF artefact locations).
- Removed stale PHPCS stderr artefacts produced by the previous analyze script failure to keep `docs/` tidy.

### 📚 Documentation

- Expanded the "Development Workflow" section in `DOCUMENTATION.md` with dedicated PHPCS Auto-Fixes, Running PHPCBF, and PHPCS Violation Analysis subsections, including timestamps and references to the updated scripts.
- Refreshed documentation metadata to version 0.11.20 with the current timestamp.

---

## [0.11.19] - 2025-10-28 (10:48)

### 🛠 Technical Changes

- Completed Phase 10 of the PHPCS cleanup by refactoring the remaining files under `includes/` (migrators, services, converters, views) to remove short ternaries, enforce Yoda conditions, and apply strict `in_array()` checks.
- Standardised container exception handling with anonymous classes to satisfy WPCS naming constraints and kept binding helpers aligned with the DI container.
- Normalised assignment/double-arrow alignment across migration token manager, AJAX views, and service helpers.

### 🧪 Testing

- Confirmed `vendor/bin/phpcs includes` passes with no warnings after the Phase 10 fixes.

### 📚 Documentation

- Updated `DOCUMENTATION.md` and TODOs with Phase 10 completion details and refreshed timestamps.

---

## [0.11.15] - 2025-10-27 (23:48)

### 🛠 Technical Changes

- CI PHPUnit matrix now exports `WP_TESTS_DIR=/tmp/wordpress-tests-lib` and `WP_CORE_DIR=/tmp/wordpress` prior to running `install-wp-tests.sh`, avoiding path drift with cached runners and eliminating the need for `WP_PHPUNIT__DIR` fallbacks.
- `etch-fusion-suite/phpunit.xml.dist` delegates WordPress UI coverage to the new `tests/ui` suite and no longer hardcodes WordPress test paths, keeping local overrides driven by environment variables.
- Composer testing scripts add dedicated `test:wordpress` and `test:ui` targets while aggregating coverage across unit, wordpress, integration, ui, and performance suites.
- `phpcs.xml.dist` explicitly scans `./etch-fusion-suite.php`, ensuring the main plugin bootstrap remains linted during WordPress Coding Standards runs.

### 🧪 Testing

- PHP-based admin UI tests relocated from `tests/e2e` to `tests/ui` to better reflect their WordPress-backed coverage. CI and local commands reference the updated suite names.
- Confirmed Playwright browser specs remain the source of end-to-end coverage; README now differentiates PHP UI assertions from browser automation.

### 📚 Documentation

- Updated README and DOCUMENTATION to clarify the UI test directory rename, composer script additions, and Playwright usage.
- Added `.github/workflows/README.md` to describe the lint/test/node pipelines alongside newly referenced workflow YAML files.

---

## [0.11.14] - 2025-10-27 (20:52)

### 🛠 Technical Changes

- Exposed `EFS_Framer_To_Etch_Converter::get_element_children()` and `build_block_metadata()` for reuse by the template generator, resolving fatal errors during template extraction.
- Reordered sanitiser pipeline so semantic conversions occur before wrapper removal and section tagging, ensuring Framer headings receive the correct `<h1>`/`<h2>` semantics.
- Hardened CSP configuration to use directive maps with filter hooks for script/style/connect sources and extended CORS manager with configurable methods, headers, and max-age handling.
- Audit logger now sanitizes context, masks sensitive keys, and enforces a filterable retention cap while preserving structured metadata for success/failure events.

### 🧪 Testing

- Ran PHPUnit unit suite inside wp-env container: `docker exec -w /var/www/html/wp-content/plugins/etch-fusion-suite db8ac3ea4e961d5c0f32acfe0dd1fa3f-wordpress-1 ./vendor/bin/phpunit --configuration=phpunit.xml.dist --testsuite=unit` (4 tests, 19 assertions, passing).
- Documented current WordPress integration suite limitations; requires `install-wp-tests.sh` provisioning before execution under wp-env.

### 📚 Documentation

- Updated `DOCUMENTATION.md` with refreshed timestamps, CSP/CORS filter references, audit logger behaviour, and container-based PHPUnit invocation instructions.

## [0.11.13] - 2025-10-26 (23:20)

### 🛠 Technical Changes

- Added exponential backoff, timeout handling, and abortable requests to the migration progress polling UI to prevent runaway intervals.
- Parameterised Playwright base URLs to respect granular environment variables (host/protocol/port) with wp-env fallbacks for parallel CI runs.

### 🧪 Testing

- Expanded `SecurityTest` coverage to assert validator error context, rate limiter behaviour, CORS enforcement, security headers, and AJAX handler integrations.

### 📚 Documentation

- Documented polling resiliency, Playwright port configuration strategy, and the new security regression coverage in `DOCUMENTATION.md`.

## [0.11.12] - 2025-10-26 (22:57)

### 🐛 Bug Fixes

- Prevented `EFS_Framer_HTML_Sanitizer::convert_text_components()` from exiting early when encountering non-div nodes, ensuring all text components receive semantic tags.
- Updated `EFS_Input_Validator::validate_array()` to compare sanitized keys against sanitized allow-lists, keeping validation strict without rejecting normalized inputs.

### 📚 Documentation

- Documented the sanitizer loop behaviour and allowed-key normalization adjustments with refreshed timestamps.

## [0.11.11] - 2025-10-26 (22:41)

### 🧰 Tooling

- Clarified Composer fallback behaviour for `npm run dev`, including local installation requirements and CI provisioning guidance.

### 📚 Documentation

- Updated README and DOCUMENTATION to describe the container → host Composer fallback and to outline options for avoiding wp-env port collisions in parallel CI jobs.

## [0.11.9] - 2025-10-26 (21:42)

### 🧰 Tooling

- Updated `.wp-env.json` to remove local filesystem mappings and rely solely on ZIP archives for required plugins and themes, ensuring portable wp-env setups.

### 📚 Documentation

- Clarified archive placement workflow in `etch-fusion-suite/README.md` and highlighted the archive-based provisioning model.
- Documented the wp-env portability change, PHP 8.1 baseline, and activation flow in `DOCUMENTATION.md` with refreshed metadata.

## [0.11.8] - 2025-10-26 (20:20)

### 🛡️ Validation & Error Handling

- Refactored `EFS_Input_Validator` to capture machine-readable codes with sanitized context while emitting PHPCS-compliant generic messages.
- Added `get_user_error_message()` mapping to provide layman-friendly guidance based on recorded validation codes.
- Standardised AJAX failure responses to include codes and contextual details for client-side rendering.

### 💻 Admin UX

- Introduced richer toast feedback in admin JS by surfacing validator error codes/context so users receive actionable guidance during validation failures.
- Added helper to display field names from validation context across settings, validation, and migration admin flows for clearer troubleshooting.

## [0.11.7] - 2025-10-26 (16:30)

### 🔧 Technical Changes

- Hardened `EFS_Input_Validator` to apply Yoda conditions, sanitize array recursion helpers, and escape exception messages to satisfy WPCS security sniffs.
- Introduced new `efs_suite_*` migrator hooks while retaining legacy `b2e_*`/`efs_*` aliases under `phpcs:ignore` for compatibility.

### 🧰 Tooling

- Replaced legacy multi-job CI workflow with focused lint, multi-version PHPUnit, and Node verification jobs using pinned actions and full-history checkout.
- Updated CodeQL workflow to analyze both PHP and JavaScript sources with fetch-depth `0` for accurate scanning.
- Corrected Dependabot directories to monitor Composer, npm, and GitHub Actions updates under `etch-fusion-suite/`.

### 📚 Documentation

- Documented refreshed CI pipeline, dependency automation, and testing coverage in `DOCUMENTATION.md` with updated timestamps.

## [0.11.6] - 2025-10-26 (15:58)

### 🧪 Testing

- GitHub Actions now installs the WordPress test suite automatically by provisioning Subversion, running the bundled `install-wp-tests.sh`, and executing PHPUnit with the shared `phpunit.xml.dist` configuration.

### 🧰 Tooling

- `.wp-env.json` references the registry-hosted `WordPress/6.8` build for portable development setups, with `.wp-env.override.json.example` highlighting how to point to local archives when needed.
- Updated README and test environment documentation to clarify the new wp-env core source and local override workflow.

## [0.11.5] - 2025-10-26 (13:20)

### 🔒 Validation & AJAX Hardening

- Routed CSS and media AJAX handlers through container-managed services, avoiding direct instantiation while reusing shared error handling and response summaries.
- Updated content batch migration to reuse `EFS_Content_Service::convert_bricks_to_gutenberg()` and the cached `EFS_API_Client`, improving nonce alignment and target URL handling.
- Added helper in `EFS_CSS_Service` for dispatching style payloads to the Etch REST API, ensuring consistent request formatting.
- Adjusted helper scripts (`scripts/test-connection.js`, `scripts/test-migration.js`, `tests/test-production-migration.sh`) to use `efs_*` endpoints, containers, and CLI hooks.
- Modernised `cleanup-etch.sh` to detect `efs-*` containers automatically, delete both legacy (`b2e_*`) and current (`efs_*`) migration options, and reference the updated admin URL.

## [0.11.3] - 2025-10-25 (23:25)

### 🧪 Testing & CI

- Added comprehensive `CI` workflow covering WPCS linting, PHPCompatibility across 7.4–8.4, multi-version PHPUnit with coverage artifacts, and Composer security scans.
- Introduced CodeQL analysis, dependency review gate, and tag-triggered release automation for stable builds.

### 🔧 Technical Changes

- Release workflow now validates plugin metadata via `scripts/validate-plugin-headers.sh`, packages production ZIPs, and publishes GitHub releases.
- PHPUnit configuration and Composer scripts now store Clover reports under `build/logs/` to align with new CI artifact paths.

## [0.11.2] - 2025-10-25 (21:55)

### 🎨 UI

- Tokenized the admin loading spinner borders to use `--e-*` design tokens, keeping visual alignment with the dark theme.

### 🧪 Testabdeckung

- Updated the PHPUnit bootstrap to favour `WP_PHPUNIT__DIR`, ensure the Etch Fusion Suite plugin loads, and retain strict error handling during tests.
- Strengthened `ServiceContainerTest` and `MigrationIntegrationTest` assertions to cover container wiring, registry discovery, and CSS converter behaviour through `efs_container()`.

### 🔧 Technical Changes

- Hardened `scripts/validate-plugin-headers.sh` with `set -euo pipefail` to surface release validation failures consistently.

## [0.11.1] - 2025-10-25 (21:26)

### ✨ Features

- Composer scripts now expose dedicated `test:*` targets and aggregate `composer test` runs unit, integration, E2E, and performance suites.

### 🧪 Testing

- Added PHPUnit E2E (`tests/e2e/AdminUITest.php`) and performance (`tests/performance/MigrationPerformanceTest.php`) coverage for admin workflows, template extraction, audit logging, and synthetic migration benchmarking.
- Updated CI workflow to run linting, PHPCompatibility, multi-version PHPUnit, LocalWP regression, and Composer security audit jobs with latest pinned actions.
- Confirmed LocalWP regression suite completes successfully (`tests/run-local-tests.php`).

### 🔧 Technical Changes

- PHPUnit bootstrap, integration, and unit tests now rely on `efs_container()` and `EFS_*` naming, removing residual `b2e_*` references.
- Release validation script resolves paths relative to the script directory and enforces the `etch-fusion-suite` text domain.
- Plugin bootstrap loads only the new text domain, dropping legacy `bricks-etch-migration` fallback.

## [0.11.0] - 2025-10-25 (16:37) - Complete EFS Rebrand Implementation

### 🎨 Rebranding (Phase 2 - Code Implementation)

- **REST API**: Migrated namespace from `/b2e/v1/` to `/efs/v1/` across all endpoints
- **Options & Transients**: Updated all WordPress options from `b2e_*` to `efs_*` prefix
  - Settings: `efs_settings`, `efs_api_key`, `efs_api_username`
  - Migration: `efs_migration_progress`, `efs_migration_steps`, `efs_migration_stats`
  - Cache: `efs_cache_*` transients for repositories
  - Inline Code: `efs_inline_css_*`, `efs_inline_js_*`
  - Rate Limiting: `efs_rate_limit_*` transients
  - Tokens: `efs_token_*`, `efs_short_*`
- **Text Domain**: All translatable strings migrated to `etch-fusion-suite`
- **API Key Generation**: Updated prefix from `b2e_` to `efs_`
- **Security Components**: CORS Manager, Rate Limiter, Input Validator rebranded
- **Container Functions**: Updated from `b2e_container()` to `efs_container()`

### 🔧 Technical Changes

- **Services**: Migration, CSS, Media, Content services fully rebranded
- **Repositories**: WordPress Migration, Settings, Style repositories updated
- **Core Components**: Error Handler, Plugin Detector, Content Parser, CSS Converter
- **API Client**: Request URLs, authentication headers, option storage updated
- **Token Manager**: Migration token storage and validation rebranded
- **Logging**: All error logs updated from "B2E" to "EFS" or "Etch Fusion Suite"
- **AJAX Hooks**: Admin interface and handler registrations now use `wp_ajax_efs_*` (legacy alias retained for `efs_migrate_css`).
- **Autoloader**: Enhanced converter namespace mapping to resolve `EFS_Element_*` classes post-rebrand.
- **Local Testing**: Added LocalWP regression scripts (`tests/run-local-tests.php`, `tests/test-ajax-handlers-local.php`) and documentation for running 25 AJAX/CSS checks.

### 🗑️ Cleanup

- **Legacy Aliases Removed**: All `class_alias()` backward compatibility removed
  - Services: `B2E_Migration_Service`, `B2E_CSS_Service`, etc.
  - Repositories: `B2E_WordPress_Migration_Repository`, etc.
  - Security: `B2E_CORS_Manager`, `B2E_Rate_Limiter`, etc.
  - API: `B2E_API_Endpoints`, `B2E_API_Client`
- **Debug Logging**: Removed verbose B2E debug statements from API client

### ⚠️ Breaking Changes

- **REST API Namespace**: Clients must update from `/b2e/v1/` to `/efs/v1/`
- **Option Keys**: All `b2e_*` options renamed to `efs_*` (migration required)
- **Class Names**: All `B2E_*` classes renamed to `EFS_*` (no backward compatibility)
- **Container Function**: `b2e_container()` renamed to `efs_container()`

### 📝 Notes

- This completes the core rebrand implementation
- Remaining: Migrator classes, Converter classes, JS/CSS assets, Tests, Workflows
- Migration script needed for existing installations to rename options

## [0.10.2] - 2025-10-25 (14:55) - Framer Extractor Test Coverage

### 🧪 Testing

- Added Framer extractor fixtures and PHPUnit suites covering sanitizer semantics, template analyzer heuristics, and full pipeline validation (`tests/fixtures/framer-sample.html`, `tests/unit/*`, `tests/integration/FramerExtractionIntegrationTest.php`).
- Updated `TemplateExtractorServiceTest` to assert payload structure and validation results using the DI container.

### 📚 Documentation

- Documented new fixture and test coverage in `DOCUMENTATION.md`, including instructions for running the suites via `composer test`.

## [0.10.1] - 2025-10-25 (14:41) - Template Extractor Public API

### ✨ New Features

- Added REST API endpoints under `/b2e/v1/template/*` for extracting, listing, previewing, importing, and deleting Etch templates generated from Framer sources, complete with rate limiting, CORS enforcement, and input validation.
- Embedded the Template Extractor interface directly into the Etch dashboard with saved-template context, providing a single entry point for Framer imports.

### 🧪 Testing

- Introduced `TemplateExtractorServiceTest` to cover `EFS_Template_Extractor_Service` validation helpers and supported-source metadata using PHPUnit mocks.

### 📚 Documentation

- Authored `docs/FRAMER-EXTRACTION.md` with architecture, pipeline steps, REST usage, troubleshooting, and testing guidance.
- Updated `README.md` and `DOCUMENTATION.md` to reference the new extractor documentation and summarize REST/AJAX capabilities.

## [0.10.0] - 2025-10-25 (11:05) - Framer Template Extraction

### ✨ New Features

- **Framer Template Extraction Framework**: Complete pipeline for importing Framer website templates into Etch
  - HTML Parser: DOMDocument-based robust HTML parsing with error handling
  - HTML Sanitizer: Removes Framer-specific markup, semanticizes DOM structure
  - Template Analyzer: Detects sections (hero, features, CTA, footer), components, layout structure
  - Etch Template Generator: Converts sanitized DOM to Etch-compatible Gutenberg blocks
  - Template Extractor Service: Orchestrates complete extraction pipeline

### 🎨 UI

- **New 'Template Extractor' Tab**: Admin dashboard integration for template import
- **Dual Input Methods**: Support for URL-based extraction and HTML string paste
- **Live Progress Updates**: Real-time extraction progress with step-by-step status
- **Template Preview**: Metadata display with complexity scoring and block preview
- **Saved Templates Management**: Save, delete, and import extracted templates

### 🔌 API

- **AJAX Handlers**: Complete AJAX integration for template extraction workflow
  - `b2e_extract_template`: Initiates extraction from URL or HTML
  - `b2e_get_extraction_progress`: Polls extraction progress
  - `b2e_save_template`: Persists extracted template as draft
  - `b2e_get_saved_templates`: Retrieves saved templates list
  - `b2e_delete_template`: Removes saved template
- **Rate Limiting**: Configured limits (10 req/min extraction, 60 req/min progress)
- **Security**: Capability checks, nonce validation, audit logging for all operations

### 🏗️ Architecture

- **Four Core Interfaces**: `Template_Extractor`, `HTML_Sanitizer`, `Template_Analyzer`, `Etch_Template_Generator`
- **Two Framer Implementations**: `Framer_HTML_Sanitizer`, `Framer_Template_Analyzer`
- **Service Layer Integration**: Registered in DI container with autowiring
- **Controller Pattern**: `Template_Controller` delegates to `Template_Extractor_Service`
- **Reusable Components**: Leverages existing `Element_Factory` and `Gutenberg_Generator` patterns

### 🔧 Technical Details

- **HTML Parsing**: DOMDocument + DOMXPath for robust invalid HTML handling
- **Framer-Specific Sanitization**:
  - Removes `data-framer-*` attributes and hash classes (`framer-xxxxx`)
  - Unwraps unnecessary single-child div wrappers
  - Semanticizes generic divs to `<header>`, `<nav>`, `<section>`, `<footer>`
  - Converts `data-framer-component-type` to appropriate HTML tags
- **Semantic Analysis**: Heuristic-based section detection (hero, features, CTA recognition)
- **Etch Block Generation**: Gutenberg block HTML with `etchData` metadata structure
- **Complexity Scoring**: 0-100 scale based on DOM depth, component count, layout complexity
- **CSS Variable Extraction**: Captures `--framer-*` inline styles for style mapping

## [0.9.0-beta] - 2025-10-25 (08:55) - Legacy Alias Cleanup

### 🐛 Bug Fixes

- Resolved remaining `B2E_*` class alias warnings across admin interface, security services, and migrator components to restore backward compatibility.

### 🔧 Technical Changes

- Standardized `class_alias()` calls so each legacy alias points to its corresponding `EFS_*` class, covering admin bootstrap, validator/logger services, and all core migrators.

## [0.9.0-beta] - 2025-10-24 (16:25) - Etch Fusion Suite Rebrand

### 🎨 Rebranding

- **Plugin Name**: Renamed from "Bricks to Etch Migration" to "Etch Fusion Suite"
- **Repository**: Moved to <https://github.com/tobiashaas/EtchFusion-Suite>
- **Description**: Updated to reflect expanded capabilities as end-to-end migration and orchestration toolkit
- **Text Domain**: Changed from `bricks-etch-migration` to `etch-fusion-suite` (with backward compatibility)

### 🔧 Technical Changes

- **Class Prefix**: All 55+ classes renamed from `B2E_*` to `EFS_*` (Etch Fusion Suite)
- **Constants**: Updated to `EFS_PLUGIN_*` prefix
- **Helper Functions**: Renamed to `efs_container()`, `efs_debug_log()`
- **Backward Compatibility**: All old `B2E_*` names preserved via `class_alias()` for seamless upgrades
- **Main Class**: `EFS_Plugin` (formerly `Bricks_Etch_Migration`)
- **Container**: `EFS_Service_Container`, `EFS_Service_Provider`

### 📚 Documentation

- **README**: Updated with new branding and repository links
- **CHANGELOG**: Rebranded header and added rebrand entry
- **Plugin Header**: Updated metadata for WordPress.org compatibility

### 📁 File Structure

- **Plugin Folder**: Renamed from `bricks-etch-migration/` to `etch-fusion-suite/`
- **Main File**: Renamed from `bricks-etch-migration.php` to `etch-fusion-suite.php`
- **All References**: Updated across scripts, workflows, and documentation

---

## [0.8.0-beta] - 2025-10-24 (14:07) - CI/CD Integration & Beta Release

### 🐛 CI/CD Fixes

- **Fixed PHPCS jobs**: Include dev dependencies in lint and compatibility jobs (vendor/bin/phpcs now available)
- **Fixed PHPUnit tests**: Added MySQL 8 service and WordPress test suite installation to test job
- **Fixed cache paths**: Updated Composer cache paths from `vendor` to `bricks-etch-migration/vendor`
- **Fixed PHPCompatibility**: Removed from phpcs.xml.dist to avoid double-running (kept dedicated CI job)
- **Fixed release validation**: Set working directory for validate-plugin-headers.sh script
- **Fixed changelog extraction**: Use awk instead of sed to handle EOF properly
- **Enhanced validation**: readme.txt validation now enforced (fails on missing/mismatched versions)
- **Updated plugin version**: Bumped to 0.8.0-beta for beta release

### 🧹 Cleanup

- **Removed Husky references**: Cleaned up `.husky/` from `.gitattributes` and `release.yml` (Husky not used, CI enforces all checks)

### 📚 Documentation

- **Git Hooks**: Documented manual Git hooks approach (Husky not used, CI enforces all checks)
- **Development Workflow**: Added section in DOCUMENTATION.md with code quality checks and optional pre-commit hook

### 🚀 CI/CD

- **GitHub Actions Workflows**: Automated code quality checks and testing
  - `ci.yml`: WordPress Coding Standards (WPCS), PHPCompatibilityWP across PHP 7.4-8.4, PHPUnit tests
  - `codeql.yml`: Security scanning with CodeQL for PHP (weekly schedule + PR/push triggers)
  - `dependency-review.yml`: Dependency security and license compliance checks on PRs
  - `release.yml`: Automated plugin packaging and GitHub Release creation on Git tags
- **Security Hardening**: All actions pinned to commit SHAs (not tags), least-privilege permissions
- **Multi-PHP Testing**: Test matrix across PHP 7.4, 8.1, 8.2, 8.3, 8.4 for compatibility

### 🔧 Development

- **PHPUnit Setup**: WordPress Test Suite integration with unit and integration test suites
  - `phpunit.xml.dist`: Configuration for unit/integration tests with coverage reporting
  - `tests/bootstrap.php`: WordPress test environment bootstrap
  - `tests/unit/ServiceContainerTest.php`: Example unit test for DI container
  - `tests/integration/MigrationIntegrationTest.php`: Example integration test
- **PHPCS Configuration**: WordPress Coding Standards compliance
  - `phpcs.xml.dist`: WordPress-Extra ruleset with PHPCompatibilityWP checks
  - Custom rules for text domain, global prefixes, security checks
- **Composer Scripts**: Convenient commands for local development
  - `composer lint`: Run PHPCS checks
  - `composer lint:fix`: Auto-fix PHPCS violations
  - `composer test`: Run PHPUnit tests
  - `composer test:coverage`: Generate coverage report

### 📊 Code Quality

- **WordPress Coding Standards**: Enforced via PHPCS with WordPress-Extra ruleset
- **PHP Compatibility**: Validated across PHP 7.4-8.4 using PHPCompatibilityWP
- **Security Scanning**: CodeQL analysis for vulnerability detection
- **Dependency Security**: Automated checks for vulnerable dependencies and license issues

### 🤖 Automation

- **Dependabot**: Automated dependency updates for Composer, npm, and GitHub Actions
  - Weekly schedule (Mondays)
  - Grouped minor/patch updates to reduce PR noise
  - Ignores PHP major version updates (manual review required)
- **Release Automation**: Plugin ZIP creation and GitHub Release on Git tags
  - Validates plugin headers match tag version
  - Extracts changelog for release notes
  - Excludes dev files from release ZIP

### 📚 Documentation

- **Workflow Documentation**: `.github/workflows/README.md` with complete CI/CD guide
  - Local reproduction commands
  - Troubleshooting common issues
  - Security best practices
  - Badge integration for README
- **Updated DOCUMENTATION.md**: New "Continuous Integration" section
- **Updated README.md**: CI/CD badges and development workflow

### 🔒 Security

- **Action Pinning**: All GitHub Actions pinned to specific commit SHAs
  - `actions/checkout@08eba0b` (v4.3.0)
  - `shivammathur/setup-php@bf6b4fb` (2.35.5)
  - `actions/cache@0057852` (v4.3.0)
  - `github/codeql-action/*@4221315` (v3.30.9)
  - `actions/dependency-review-action@40c09b7` (v4.8.1)
- **Minimal Permissions**: Each workflow uses least-privilege permission model
- **CodeQL Configuration**: Custom config excludes vendor/test files, uses security-extended queries

### 📦 Release Process

- **Automated Packaging**: Creates clean plugin ZIP excluding dev files
- **Version Validation**: Script validates plugin headers match Git tag
- **Changelog Integration**: Automatically extracts relevant changelog section for release notes

### 🛠️ Technical Details

- **Composer Dev Dependencies**: Added WPCS, PHPCompatibilityWP, PHPUnit, Mockery, Yoast PHPUnit Polyfills
- **Git Attributes**: Configured for clean releases (export-ignore patterns, line endings, linguist settings)
- **CodeQL Config**: Custom configuration for PHP security scanning with path filtering

## [0.7.0] - 2025-10-24 (09:05) - Extensible Migrator Framework

### 🐛 Bug Fixes - **Updated:** 2025-10-24 12:00

- Ensured manual autoloader remains registered even when Composer's autoloader is present so security classes (e.g. `B2E_CORS_Manager`) load correctly in WordPress wp-admin ohne CLI-Kontext.
- Ergänzte Namespace-Zuordnung für `Bricks2Etch\Security\...`, `Bricks2Etch\Repositories\Interfaces\...` sowie `Bricks2Etch\Migrators\Interfaces\...`, damit entsprechende Klassen im Admin zuverlässig geladen werden.
- Erweiterte Dateinamens-Erkennung (z.B. `interface-settings-repository.php`, `abstract-class-*.php`), sodass Interface- und Abstract-Dateien ebenfalls automatisch eingebunden werden.
- Fixed parse error in `api_endpoints.php` by removing stray closing brace and adding missing class closing brace.
- Fixed `gutenberg_generator` und `dynamic_data_converter` Service-Bindings sowie zugehörige `use`-Imports auf den korrekten Namespace `Bricks2Etch\Parsers`.

### ✨ New Features

- Introduced unified migrator contract (`Migrator_Interface`) and `Abstract_Migrator` base class for shared helpers.
- Added migrator registry (`B2E_Migrator_Registry`) with discovery workflow, priority management, and WordPress hook integration (`b2e_register_migrators`, `b2e_migrators_discovered`).
- Implemented discovery bootstrap on `plugins_loaded` to load built-in migrators and prepare registry before migrations start.
- Exposed new REST API endpoints:
  - `GET /b2e/v1/export/migrators` lists registered migrators with support status.
  - `GET /b2e/v1/export/migrator/{type}` exports data payload and stats for specific migrators.

### 🔧 Refactoring

- Refactored core migrators (CPT, ACF Field Groups, MetaBox, Custom Fields) to extend `Abstract_Migrator` and implement the interface while retaining existing helper methods and class aliases.
- Updated service container bindings to inject the API client into migrators and register registry/discovery singletons.
- Reworked `B2E_Migration_Service` to pull migrators dynamically from the registry, execute them in priority order, and generate progress steps based on registered types.

### 📚 Documentation

- Added `docs/MIGRATOR-API.md` with complete developer guidance, interface reference, hooks, REST usage, and sample implementation.
- Updated `DOCUMENTATION.md` with a dedicated "Migrator Plugin System" section covering architecture, hooks, registry utilities, and workflow.
- Enhanced root `README.md` to advertise migrator extensibility and link to the developer documentation.

### 🔄 Backward Compatibility

- Preserved existing migrator class names via `class_alias` for legacy code paths.
- Legacy REST endpoints (`/export/cpts`, `/export/acf-field-groups`, `/export/metabox-configs`) continue to operate using registry-backed migrators.
- Migration workflow maintains previous behaviour while supporting new extensibility hooks.

## [0.6.2] - 2025-10-24 - Repository Cleanup

### 🧹 Cleanup

- ✅ **Deleted entire archive/ directory**
  - Removed 40+ outdated documentation files (status reports, test guides, analysis documents)
  - Removed complete plugin backup in `bricks-etch-migration-backup/` subdirectory
  - Removed 10+ obsolete shell scripts (monitoring, verification, update scripts)
  - Removed 5+ PHP debug scripts
  - All relevant information consolidated into current documentation

- ✅ **Consolidated test scripts in tests/ folder**
  - Removed 18 redundant test files
  - Kept 11 active, non-redundant tests:
    - `test-cors-enforcement.sh` - CORS validation
    - `test-element-converters.php` - Element conversion
    - `test-css-converter.php` - CSS conversion
    - `test-content-conversion.php` - Content migration
    - `test-api-comprehensive.sh` - API endpoints
    - `test-ajax-handlers.php` - AJAX handlers
    - `test-etch-api.php` - Etch API integration
    - `test-integration.php` - Integration tests
    - `test-complete-migration.sh` - Complete migration flow
    - `test-production-migration.sh` - Production migration
    - `test-token-validation.sh` - Token validation

- ✅ **Removed deprecated shell scripts from test-environment/**
  - Deleted 8 Docker-based scripts (setup.sh, start.sh, stop.sh, reset.sh, sync-plugin.sh, watch-plugin.sh, dev-helper.sh, test-plugin.sh)
  - Deleted 8 PowerShell scripts (install-wordpress.ps1, install-wp-cli.ps1, install-wp.ps1, copy-plugins.ps1, setup.ps1, run-setup.ps1, test-plugin.ps1, test-migration.ps1)
  - Deleted 4 PHP utility scripts (check-api-keys.php, sync-api-keys.php, create-test-content.php, create-real-test-content.php)
  - Deleted php.ini configuration file
  - All replaced by npm-based wp-env workflow

- ✅ **Removed unnecessary root markdown files**
  - Deleted TODOS.md (completed tasks, no longer maintained)
  - Deleted PROJECT-RULES.md (internal development rules, not user-facing)
  - Deleted CORS-ENFORCEMENT-SUMMARY.md (implementation detail, integrated into CHANGELOG and DOCUMENTATION)

### 📝 Documentation

- ✅ **Updated README.md**
  - Removed reference to deleted archive/ folder
  - Updated Docker section with deprecation notice pointing to wp-env workflow
  - Removed references to deleted documentation files (CSS-CLASSES-FINAL-SOLUTION.md, CSS-CLASSES-QUICK-REFERENCE.md, MIGRATION-SUCCESS-SUMMARY.md)
  - Clarified cleanup-etch.sh as the only remaining manual cleanup script

- ✅ **Updated DOCUMENTATION.md**
  - Updated Testing section with consolidated test script list
  - Removed references to deleted archive files
  - Emphasized wp-env as the only supported development workflow
  - Updated References section to reflect current documentation structure

- ✅ **Updated test-environment/README.md**
  - Added prominent deprecation notice for Docker Compose setup
  - Emphasized wp-env workflow as the current standard
  - Updated all references to point to npm-based commands

- ✅ **Added deprecation notices**
  - docker-compose.yml: Added comment block marking file as deprecated
  - Makefile: Added comment block marking file as deprecated
  - Both files retained for reference only

### 🎯 Impact

- Repository size reduced significantly
- Clearer project structure with only active files
- Improved maintainability by removing obsolete code and documentation
- Single source of truth for development workflow (wp-env)
- Reduced confusion for new developers

### 📊 Statistics

- **Deleted:** 40+ markdown files, 18+ test scripts, 20+ shell/PowerShell scripts, 4 PHP scripts, 1 config file
- **Retained:** 11 active test scripts, essential documentation (README, DOCUMENTATION, CHANGELOG)
- **Updated:** 4 documentation files with cleanup references

-

## [0.6.3] - 2025-10-24 (08:25) - wp-env Troubleshooting Alignment

### 📝 Documentation

- Updated root `README.md` troubleshooting commands to use npm wp-env scripts (`logs:*`, `shell:*`, `wp:*`) instead of legacy Docker `docker exec` commands for Bricks/Etch instances.
- Refreshed troubleshooting guidance to recommend `npm run wp:bricks -- <command>` / `npm run wp:etch -- <command>` for WP-CLI usage.
- Added deprecation banner to `test-environment/docker-compose.override.yml.example` directing developers to the npm-based workflow and plugin README.

### 🔄 Consistency

- Ensured all troubleshooting references align with the standardized wp-env workflow and removed legacy container names.

---

## [0.6.1] - 2025-10-24 (07:56) - CORS Enforcement Hardening

### 🔒 Security

- ✅ **Enforced CORS validation on all REST endpoints**
  - Added CORS origin check to `handle_key_migration()` (GET /b2e/v1/migrate)
  - Added CORS origin check to `validate_migration_token()` (POST /b2e/v1/validate)
  - Implemented global `rest_request_before_callbacks` filter for centralized CORS enforcement
  - All endpoints now actively reject disallowed origins with 403 response
  - Enhanced logging includes route and method information for CORS violations
  - Prevents future endpoints from bypassing origin validation

### 🐛 Bug Fixes

- ✅ **Fixed CORS bypass vulnerability** in public endpoints
  - Two public endpoints previously processed requests from unauthorized origins
  - Server now returns 403 JSON error (not just browser-level blocking)
  - Maintains backward compatibility with existing authenticated endpoints

## [0.6.0] - 2025-10-24 (00:45) - wp-env Development Workflow

### 🚀 Features

- ✅ **Introduced npm-based wp-env tooling** (`bricks-etch-migration/package.json`, `scripts/`)  
  - `npm run dev` provisions Bricks (8888) and Etch (8889) environments via `@wordpress/env`  
  - Automated readiness polling, Composer installation, plugin/theme activation, and credential setup  
  - Added rich command set (logs, shell access, database exports, migration smoke tests, debug collection)

### 📦 Configuration

- ✅ **Created `.wp-env.json` and override template**  
  - Defines core/PHP versions, plugin & theme ZIP mappings, debug constants  
  - Example override file supports port changes, PHP upgrades, Xdebug, extra plugins
- ✅ **Added helper scripts** (`scripts/wait-for-wordpress.js`, `activate-plugins.js`, `create-test-content.js`, `test-connection.js`, `test-migration.js`, `debug-info.js`) for environment automation

### 📝 Documentation

- ✅ **Updated plugin README** with wp-env quick start, script catalog, and archive placement instructions  
- ✅ **Rewrote `test-environment/README.md`** to describe the new workflow and troubleshooting steps  
- ✅ **Added `test-environment/PLUGIN-SETUP.md`** for proprietary asset handling  
- ✅ **Published `bricks-etch-migration/TESTING.md`** covering wp-env testing procedures  
- ✅ **Refreshed `DOCUMENTATION.md` Test Environment section** for wp-env details and legacy notes

### 🧹 Legacy

- ✅ Marked Docker Compose (`test-environment/docker-compose.yml`) and Makefile as deprecated references while retaining them for archival purposes

## [0.5.8] - 2025-10-24 (00:01) - Docker Environment Fixes & Portability Improvements

### 🐛 Bug Fixes

- ✅ **Fixed WP-CLI container plugin access** (`docker-compose.yml`)
  - Added plugin bind mounts to `wpcli` service for both Bricks and Etch paths
  - Enables Composer installation and plugin activation to work correctly
  - Fixes: `make composer-install` and `make setup` now run successfully

- ✅ **Fixed database readiness check hang** (`setup-wordpress.sh`)
  - Replaced `wp db check` loop with raw MySQL connectivity check using `mysqladmin ping`
  - Prevents indefinite hanging before WordPress is installed
  - Added max attempts limit (30) with proper error handling

- ✅ **Improved shell command portability** (`create-test-content.sh`)
  - Wrapped `test -d` directory check in `sh -c` for compatibility
  - Works across different container images regardless of builtin availability

- ✅ **Replaced ping with curl for network diagnostics** (`debug-info.sh`)
  - Changed from `ping` to `curl` for better portability
  - Avoids dependency on ping binary which may be missing in containers

- ✅ **Relaxed REST API status endpoint validation** (`test-connection.sh`, `validate-setup.sh`)
  - Changed 404 responses from failure to warning
  - Acknowledges that `/b2e/v1/status` endpoint may not be implemented yet
  - Prevents false negatives during setup validation

### 📝 Documentation

- ✅ **Updated README mount mode documentation** (`test-environment/README.md`)
  - Corrected plugin mount description from "read-only" to "read-write"
  - Clarified that Composer can install dependencies directly in container
  - Aligns documentation with actual docker-compose.yml configuration

### ✅ Technical Verification

- ✅ **Verified autoloader bootstrap** (`bricks-etch-migration.php`)
  - Confirmed autoloader is required early (line 34) before any namespaced classes
  - Verified namespace-to-directory mapping matches actual file layout
  - No changes needed - implementation is correct

- ✅ **Verified path consistency**
  - All scripts use consistent paths: `/var/www/html/bricks` and `/var/www/html/etch`
  - Docker compose, Makefile, and all shell scripts aligned
  - No changes needed - paths are consistent

## [0.5.7] - 2025-10-23 (23:50) - Docker Test Environment Validation & Debugging

### 🚀 Features

- ✅ **Added comprehensive setup validation script** (`validate-setup.sh`)
  - 9 automated validation checks covering all critical components
  - Color-coded output (✓ green, ✗ red, ⚠ yellow)
  - Validates: Docker containers, MySQL databases, WordPress installation, plugin activation, Composer autoloader, service container, REST API, Application Passwords
  - Provides actionable troubleshooting tips on failure
  - Accessible via `make validate`

- ✅ **Added debug information collection script** (`debug-info.sh`)
  - Collects 12 sections of comprehensive debug data
  - Includes: Docker environment, WordPress versions, active plugins, PHP environment, Composer packages, plugin configuration, debug logs, container logs, network connectivity, file permissions, disk space, database connection
  - Saves timestamped debug report to file
  - Accessible via `make debug`

- ✅ **Added quick connection test script** (`test-connection.sh`)
  - 6 connection tests without full migration
  - Tests: Application Password retrieval, REST API endpoints, migration token generation/validation, CORS headers, container-to-container communication
  - Color-coded results with detailed troubleshooting
  - Accessible via `make quick-test`

- ✅ **Added comprehensive testing documentation** (`TESTING.md`)
  - 8 major test categories with step-by-step procedures
  - Covers: Pre-flight checks, setup tests, unit tests, integration tests, end-to-end tests, performance tests, error handling tests, rollback tests
  - Includes expected results, validation commands, and troubleshooting tips
  - Provides test summary template for documentation

### 🐛 Bug Fixes

- ✅ **Fixed WP-CLI volume mounting in docker-compose.yml**
  - Removed duplicate plugin mounts from WP-CLI service
  - Plugins are already mounted in WordPress containers
  - WP-CLI accesses them via `/var/www/html/bricks` and `/var/www/html/etch` paths

- ✅ **Enhanced setup-wordpress.sh error handling**
  - Added WordPress directory existence checks before operations
  - Added Composer autoloader verification before plugin activation
  - Added debug output showing active plugins and site URLs
  - Improved error messages with actionable troubleshooting steps

- ✅ **Improved install-composer-deps.sh robustness**
  - Added pre-installation checks for plugin directory and composer.json
  - Added fallback Composer installation method (wget)
  - Added autoloader verification after installation
  - Better error messages for internet connectivity issues

- ✅ **Enhanced test-migration.sh with pre-flight checks**
  - Added `check_prerequisites()` function validating all requirements
  - Enhanced `poll_progress()` with 5-minute timeout and detailed status
  - Added `check_errors()` function to retrieve and display error logs
  - Improved progress monitoring with migration steps display

- ✅ **Enhanced autoloader.php fallback**
  - Added `Repositories` and `Converters` namespace mappings
  - Improved file pattern matching with multiple naming conventions
  - Better support for all plugin class structures

### 🔧 Technical Changes

- ✅ **Updated Makefile with new targets**
  - Added `validate`, `debug`, `quick-test` targets
  - Improved `setup` target with validation steps and error handling
  - Better error propagation with exit codes

- ✅ **Enhanced create-test-content.sh**
  - Improved Bricks content structure with proper parent-child relationships
  - Added progress indicators (✓ symbols)
  - Better error handling with container status checks

### 📝 Documentation

- ✅ **Completely rewrote README.md Troubleshooting section**
  - Added Quick-Start-Checkliste for common issues
  - Added 6 detailed troubleshooting scenarios:
    1. Plugin nicht aktivierbar
    2. WP-CLI-Befehle schlagen fehl
    3. Migration startet nicht
    4. Container starten nicht
    5. Composer-Installation schlägt fehl
    6. Volume-Permissions & MySQL-Connection-Fehler
  - Each scenario includes problem description, step-by-step solutions, and validation commands

- ✅ **Created IMPLEMENTATION-SUMMARY.md**
  - Complete overview of all changes
  - File statistics and change summary
  - New commands documentation
  - Testing checklist
  - Success criteria

### 🎯 Impact

- Docker test environment is now fully validated and debuggable
- Comprehensive troubleshooting guides for all common issues
- Automated validation catches setup problems early
- Debug tools provide detailed information for issue resolution
- Testing documentation enables systematic validation

## [0.5.6] - 2025-10-23 (23:40) - Container & Repository Fixes

### 🐛 Bug Fixes

- ✅ **Fixed CSS Converter FQCN in service container**
  - Changed from `\Bricks2Etch\Converters\B2E_CSS_Converter` to `\Bricks2Etch\Parsers\B2E_CSS_Converter`
  - Resolves class not found error at runtime
  
- ✅ **Fixed API Client FQCN in service container**
  - Changed from `\Bricks2Etch\Core\B2E_API_Client` to `\Bricks2Etch\Api\B2E_API_Client`
  - Resolves incorrect namespace registration
  
- ✅ **Replaced direct option access with repository pattern**
  - CSS Converter now uses `$this->style_repository->save_style_map()` instead of `update_option()`
  - API Endpoints now uses `self::$style_repository->get_style_map()` instead of `get_option()`
  - Ensures consistent data access layer
  
- ✅ **Removed global cache flush from style repository**
  - Removed `wp_cache_flush()` call from `invalidate_style_cache()`
  - Prevents site-wide cache clearing side effects
  - Keeps targeted cache invalidation for style-related keys only

### 🔧 Technical Changes

- Updated service provider container bindings for correct class resolution
- Improved repository pattern consistency across codebase
- Reduced cache invalidation scope to prevent performance issues

## [0.5.5] - 2025-10-23 (23:00) - Migration Test Script Automation

### 🚀 Features

- ✅ **Automated migration triggering via REST API**
  - `test-migration.sh` now generates migration token via Etch REST endpoint
  - Triggers migration via AJAX endpoint on Bricks site
  - Falls back gracefully to manual instructions if automation fails
  - Script continues to poll and summarize even if trigger fails

### 🐛 Bug Fixes

- ✅ **Fixed `start_migration()` return code**
  - Now returns `0` instead of `1` to prevent script exit under `set -e`
  - Script no longer aborts before `poll_progress()` and `compare_counts()`
  - Implements proper error handling with fallback to manual migration

### 📝 Documentation

- ✅ **Updated test-environment/README.md**
  - Documented new automated migration trigger flow
  - Added clear explanation of fallback behavior
  - Updated migration test steps to reflect REST/AJAX implementation

## [0.5.4] - 2025-10-23 (22:50) - Test Environment Infrastructure Fixes

### 🐛 Docker & WP-CLI Fixes

#### Docker Compose Configuration

- ✅ **Plugin mounts added to wpcli service**
  - Plugin now mounted at `/var/www/html/bricks/wp-content/plugins/bricks-etch-migration`
  - Plugin now mounted at `/var/www/html/etch/wp-content/plugins/bricks-etch-migration`
  - Enables WP-CLI to see and activate the plugin
  - Enables Composer to run in wpcli container

#### Read-Write Plugin Mounts

- ✅ **Removed `:ro` flags from all plugin mounts**
  - `bricks-wp` plugin mount now read-write
  - `etch-wp` plugin mount now read-write
  - `wpcli` plugin mounts are read-write
  - Allows Composer to write `vendor/` directory

#### WP-CLI Standardization

- ✅ **All scripts now use wpcli service consistently**
  - `create-test-content.sh` uses wpcli with correct paths
  - `test-migration.sh` uses wpcli for all WP commands
  - `sync-plugin.sh` uses wpcli for plugin activation
  - Removed direct `wp` calls from WordPress containers

#### Makefile WP Targets

- ✅ **Updated to use wpcli service**
  - `make wp-bricks` → `docker-compose exec wpcli wp --path=/var/www/html/bricks`
  - `make wp-etch` → `docker-compose exec wpcli wp --path=/var/www/html/etch`

### 🔧 Script Improvements

#### Plugin Activation Error Handling

- ✅ **Removed `|| true` from activation commands**
  - Proper error messages when activation fails
  - Script exits with error code on failure
  - Clear instructions for troubleshooting

#### MySQL Wait Script

- ✅ **Simplified and improved reliability**
  - Removed host `mysqladmin` dependency
  - Only uses `docker-compose exec` method
  - More reliable in containerized environments

#### Composer Installation

- ✅ **Fixed installation without curl**
  - Uses PHP's `copy()` function instead of curl
  - More reliable across different environments
  - Added error checking for installation success

#### Migration Test Script

- ✅ **Updated to reflect current capabilities**
  - Documented that `wp b2e migrate` is not yet implemented
  - Script notes migration must be triggered via admin UI
  - README updated with current limitations

### 📚 Documentation Updates

- ✅ **README.md updated**
  - Documented WP-CLI command limitation
  - Added notes about manual migration trigger
  - Updated test-migration instructions

## [0.5.3] - 2025-10-22 (23:24) - Media Queries, Missing Properties & Element Converters

### 🎯 Media Query Fixes

#### Breakpoint-spezifisches CSS

- ✅ **Breakpoint CSS wird jetzt korrekt migriert**
  - Bricks Breakpoints (`_cssCustom:mobile_portrait`, etc.) werden zu Media Queries konvertiert
  - CSS Properties werden direkt in Media Query eingefügt (ohne zusätzliche Wrapper)
  - Breakpoint CSS wird nach Custom CSS Merge hinzugefügt

#### Media Query Extraktion

- ✅ **Verschachtelte Media Queries funktionieren jetzt**
  - Neue Funktion: `extract_media_queries()` mit manuellem Klammern-Zählen
  - Regex konnte verschachtelte Regeln nicht handhaben
  - Alle Regeln innerhalb von Media Queries werden jetzt korrekt extrahiert

#### Etch's moderne Media Query Syntax

- ✅ **Bricks Breakpoints → Etch Range Syntax**
  - `mobile_portrait`: `@media (width <= to-rem(478px))`
  - `mobile_landscape`: `@media (width >= to-rem(479px))`
  - `tablet_portrait`: `@media (width >= to-rem(768px))`
  - `tablet_landscape`: `@media (width >= to-rem(992px))`
  - `desktop`: `@media (width >= to-rem(1200px))`
  - Desktop-First mit Kaskadierung nach unten
  - `to-rem()` Funktion wird von Etch automatisch verarbeitet

#### Logical Properties in Media Queries

- ✅ **Media Queries werden NICHT zu Logical Properties konvertiert**
  - `@media (min-width: 768px)` bleibt `min-width` (nicht `min-inline-size`)
  - Logical Properties nur für CSS Properties, nicht für Media Queries
  - Media Queries werden vor Konvertierung extrahiert und geschützt

### 🔧 Fehlende CSS Properties

#### Neue Properties hinzugefügt

- ✅ `_direction` → `flex-direction` (Alias für `_flexDirection`)
- ✅ `_cursor` → `cursor`
- ✅ `_mixBlendMode` → `mix-blend-mode`
- ✅ `_pointerEvents` → `pointer-events`
- ✅ `_scrollSnapType` → `scroll-snap-type`
- ✅ `_scrollSnapAlign` → `scroll-snap-align`
- ✅ `_scrollSnapStop` → `scroll-snap-stop`

### 🆕 Element Converters

#### Button Element Converter

- ✅ **Bricks Button → Etch Link (Paragraph mit nested Link)**
  - Text aus `settings.text` extrahiert
  - Link aus `settings.link` extrahiert (Array und String Format)
  - Style Mapping: `btn--primary`, `btn--secondary`, `btn--outline`
  - Converter gibt STRING zurück (nicht Array)
  - CSS Klassen werden korrekt kombiniert

#### Image Element Converter

- ✅ **Bricks Image → Gutenberg Image mit Etch metadata**
  - Styles und Klassen auf `nestedData.img` (nicht auf `figure`)
  - `figure` ist nur Wrapper
  - Keine `wp-image-XX` Klasse auf `<img>` Tag
  - `size-full` und `linkDestination: none` hinzugefügt
  - Space vor `/>` für Gutenberg Validierung

#### Icon Element Converter

- ✅ **Placeholder erstellt** (zeigt `[Icon: library:name]`)
- ⏸️ **TODO:** Richtige Icon Konvertierung implementieren

#### Skip-Liste für nicht unterstützte Elemente

- ✅ **Elemente werden still übersprungen** (keine Logs)
  - `fr-notes` - Bricks Builder Notizen (nicht frontend)
  - `code` - Code Blocks (TODO)
  - `form` - Forms (TODO - Etch hat keine)
  - `map` - Maps (TODO - Etch hat keine)

### 📝 Technical Changes

- **Neue Dateien:**
  - `includes/converters/elements/class-button.php` - Button Converter
  - `includes/converters/elements/class-icon.php` - Icon Converter (Placeholder)
- **CSS Converter:**
  - `convert_to_logical_properties()` - Media Queries werden geschützt
  - `get_media_query_for_breakpoint()` - Etch Range Syntax mit `to-rem()`
  - `extract_media_queries()` - Klammern-Zählung für verschachtelte Regeln
  - `convert_flexbox()` - `_direction` Alias Support
  - `convert_effects()` - Cursor, Mix-Blend-Mode, Pointer-Events, Scroll-Snap
- **Element Factory:**
  - Skip-Liste für nicht unterstützte Elemente
  - Icon Converter registriert
- **Image Converter:**
  - Komplett umgebaut: nestedData.img Struktur
  - Keine wp-image-XX Klasse mehr

---

## [0.5.2] - 2025-10-22 (21:08) - Custom CSS & Nested CSS

### 🎨 Custom CSS Migration - FIXED

#### Problem gelöst

- **Custom CSS wurde nicht migriert** - Nur normale CSS Properties kamen in Etch an
- **Ursache 1:** Custom CSS wurde für ALLE Klassen gesammelt (auch Blacklist), aber Blacklist-Klassen wurden beim Konvertieren übersprungen → keine Zuordnung im `$style_map`
- **Ursache 2:** `parse_custom_css_stylesheet()` verarbeitete nur die ERSTE Klasse im Stylesheet, alle anderen wurden ignoriert

#### Lösung

1. ✅ **Custom CSS nur für erlaubte Klassen sammeln**
   - Blacklist-Check VOR dem Sammeln von Custom CSS
   - Nur Klassen die konvertiert werden, bekommen Custom CSS

2. ✅ **Alle Klassen im Stylesheet verarbeiten**
   - Neue Funktion: `extract_css_for_class()` - Extrahiert CSS für jede Klasse separat
   - `parse_custom_css_stylesheet()` findet ALLE Klassen und verarbeitet jede einzeln

### 🎯 Nested CSS mit & (Ampersand)

#### Feature: Automatisches CSS Nesting

- **Konvertiert mehrere Regeln** für die gleiche Klasse zu Nested CSS
- **Intelligente & Syntax:**
  - `& > *` - Leerzeichen bei Combinators (>, +, ~)
  - `& .child` - Leerzeichen bei Descendant Selectors
  - `&:hover` - Kein Leerzeichen bei Pseudo-Classes
  - `&::before` - Kein Leerzeichen bei Pseudo-Elements

#### Beispiel

**Input (Bricks):**

```css
.my-class {
    padding: 1rem;
}
.my-class > * {
    color: red;
}
```

**Output (Etch):**

```css
padding: 1rem;

& > * {
  color: red;
}
```

### 🚫 CSS Class Blacklist

#### Ausgeschlossene Klassen

- **Bricks:** `brxe-*`, `bricks-*`, `brx-*`
- **WordPress/Gutenberg:** `wp-*`, `wp-block-*`, `has-*`, `is-*`
- **WooCommerce:** `woocommerce-*`, `wc-*`, `product-*`, `cart-*`, `checkout-*`

#### Logging

- Zeigt Anzahl konvertierter Klassen
- Zeigt Anzahl ausgeschlossener Klassen

### 📊 Statistik

- ✅ **1134 Klassen** erfolgreich migriert
- ✅ **1 Klasse** ausgeschlossen (Blacklist)
- ✅ **Custom CSS** mit Nested Syntax funktioniert
- ✅ **Alle Tests** bestanden

### 🧪 Tests

- ✅ `tests/test-nested-css-conversion.php` - 5/5 Tests bestanden
- ✅ Live Migration Test erfolgreich
- ✅ Custom CSS im Frontend verifiziert

---

## [0.5.1] - 2025-10-22 (19:20) - Phase 2: AJAX Handlers

### 🔧 Refactoring

#### Modulare AJAX-Handler Struktur

- **Neue Ordnerstruktur:**
  - `includes/ajax/` - AJAX Handler
  - `includes/ajax/handlers/` - Individual AJAX Handlers
  
#### AJAX-Handler (NEU)

- ✅ `class-base-ajax-handler.php` - Abstract base class
- ✅ `class-ajax-handler.php` - Main AJAX handler (initialisiert alle)
- ✅ `handlers/class-css-ajax.php` - CSS migration handler
- ✅ `handlers/class-content-ajax.php` - Content migration handler
- ✅ `handlers/class-media-ajax.php` - Media migration handler
- ✅ `handlers/class-validation-ajax.php` - API key & token validation

### 📝 Features

- **Base Handler:** Gemeinsame Logik für alle AJAX-Handler
  - Nonce verification
  - Capability checks
  - URL sanitization
  - Logging
- **Modulare Struktur:** Jeder Handler in eigener Datei
- **Docker URL Conversion:** Automatische localhost → b2e-etch Konvertierung

### 🔄 Integration

- Plugin-Hauptdatei lädt AJAX-Handler automatisch
- Alle Handler werden bei Plugin-Initialisierung registriert
- Alte AJAX-Handler in admin_interface.php bleiben vorerst (Kompatibilität)

### ⚠️ Status

- Phase 2: AJAX-Handler ✅ COMPLETE (19:20)
- Phase 3: Admin-Interface - PENDING
- Phase 4: Utilities - PENDING
- Phase 5: Integration & Testing - PENDING

---

## [0.5.0] - 2025-10-22 (00:22) - REFACTORING (IN PROGRESS)

### 🔧 Refactoring

#### Modulare Element-Converter Struktur

- **Neue Ordnerstruktur:**
  - `includes/converters/` - Conversion Logic
  - `includes/converters/elements/` - Individual Element Converters
  - `includes/core/` - Core Functionality
  - `includes/admin/` - Admin Interface
  - `includes/ajax/` - AJAX Handlers
  - `includes/api/` - API Communication
  - `includes/utils/` - Utilities

#### Element-Converter (NEU)

- ✅ `class-base-element.php` - Abstract base class for all converters
- ✅ `class-container.php` - Container element (supports ul, ol, etc.)
- ✅ `class-section.php` - Section element
- ✅ `class-heading.php` - Heading element (h1-h6)
- ✅ `class-paragraph.php` - Paragraph/Text element
- ✅ `class-image.php` - Image element (uses figure tag!)
- ✅ `class-div.php` - Div/Flex-Div element (supports li, span, etc.)
- ✅ `class-element-factory.php` - Factory for creating converters

### 📝 Vorteile

- **Ein Element = Eine Datei** - Einfacher zu warten
- **Änderungen nur an einer Stelle** - z.B. Container-Tag-Support
- **Wiederverwendbarer Code** - Base class mit gemeinsamer Logik
- **Bessere Testbarkeit** - Jedes Element einzeln testbar

### ⚠️ Status

- Phase 1: Element-Converter ✅ COMPLETE (00:38)
- Phase 2: AJAX-Handler - PENDING
- Phase 3: Admin-Interface - PENDING
- Phase 4: Utilities - PENDING
- Phase 5: Integration & Testing - PENDING

### 📄 Dokumentation

- ✅ `REFACTORING-STATUS.md` erstellt - Umfassender Refactoring-Bericht
- ✅ `includes/converters/README.md` erstellt - Converter-Dokumentation (00:44)
- ✅ `PROJECT-RULES.md` aktualisiert - Converter-Dokumentations-Regel hinzugefügt
- ✅ Alle Tests dokumentiert und bestanden
- ✅ Cleanup-Script gefixed - Löscht jetzt alle Styles

---

## [0.4.1] - 2025-10-21 (23:40)

### 🐛 Bug Fixes

#### Listen-Elemente (ul, ol, li) Support

- **Problem:** Container und Div mit custom tags (ul, ol, li) wurden als `<div>` gerendert
- **Lösung:**
  - `process_container_element()` berücksichtigt jetzt `tag` Setting aus Bricks
  - `convert_etch_container()` verwendet custom tag in `etchData.block.tag`
  - Gutenberg `tagName` Attribut wird gesetzt für non-div tags
- **Geänderte Dateien:**
  - `includes/gutenberg_generator.php` - Zeilen 1512-1520, 236-269

### 🔧 Technische Details

**Container mit custom tags:**

```php
// Bricks
'settings' => ['tag' => 'ul']

// Etch
'etchData' => [
  'block' => ['tag' => 'ul']
]
'tagName' => 'ul'  // For Gutenberg
```

**Frontend Output:**

```html
<ul data-etch-element="container" class="my-class">
  <li>...</li>
</ul>
```

---

## [0.4.0] - 2025-10-21 (22:24)

### 🎉 Major Release: CSS-Klassen Frontend-Rendering

**Durchbruch:** CSS-Klassen werden jetzt korrekt im Frontend-HTML gerendert!

### ✨ Neue Features

#### CSS-Klassen in etchData.attributes.class

- **Kern-Erkenntnis:** Etch rendert CSS-Klassen aus `etchData.attributes.class`, nicht aus Style-IDs
- Alle Element-Typen unterstützt: Headings, Paragraphs, Images, Sections, Containers, Flex-Divs
- Neue Funktion: `get_css_classes_from_style_ids()` konvertiert Style-IDs → CSS-Klassen

#### Erweiterte Style-Map

- Style-Map enthält jetzt: `['bricks_id' => ['id' => 'etch_id', 'selector' => '.css-class']]`
- Ermöglicht CSS-Klassen-Generierung auf Bricks-Seite
- Backward-kompatibel mit altem Format

#### Custom CSS Migration Fix

- Custom CSS (`_cssCustom`) wird jetzt korrekt mit normalen Styles zusammengeführt
- `parse_custom_css_stylesheet()` verwendet existierende Style-IDs
- Unterstützt komplexe Selektoren (`.class > *`, Media Queries, etc.)

#### Image-Rendering Fix

- Images verwenden jetzt `block.tag = 'figure'` statt `'img'`
- CSS-Klassen auf `<figure>`, nicht auf `<img>`
- Verhindert doppelte `<img>`-Tags im Frontend

### 🐛 Bug Fixes

#### Kritischer Fix: unset($attributes['class'])

- Entfernt `unset()` das CSS-Klassen gelöscht hat
- Betraf alle Container/Section-Elemente
- Klassen werden jetzt korrekt in `etchData.attributes` behalten

#### Etch-interne Styles überspringen

- `etch-section-style`, `etch-container-style` werden bei Klassen-Suche übersprungen
- Verhindert leere Klassen-Strings

### 📚 Dokumentation & Hinweise

Neue Dokumentations-Dateien:

- `CSS-CLASSES-FINAL-SOLUTION.md` - Vollständige technische Dokumentation
- `CSS-CLASSES-QUICK-REFERENCE.md` - Schnell-Referenz
- `MIGRATION-SUCCESS-SUMMARY.md` - Projekt-Zusammenfassung
- `REFERENCE-POST.md` - Referenz-Post (3411) Dokumentation
- (2025-10-29 13:30) **Phase 12 Review Documentation:** Captured final PHPCS verification artefacts and review resources. Added `etch-fusion-suite/docs/phase12-review-checklist.md` (comprehensive review checklist), `etch-fusion-suite/docs/phpcs-quick-reference.md` (developer quick reference guide), and `etch-fusion-suite/docs/test-execution-report.md` (test status and coverage analysis). Documented `phpcs:ignore` audit findings (planned: 13 comments, actual: 0 due to full `EFS_Error_Handler` adoption) and consolidated links across README, DOCUMENTATION.md, and TODOS.md.

### 🔧 Technische Änderungen

**Geänderte Dateien:**

- `includes/gutenberg_generator.php`
  - Neue Funktion: `get_css_classes_from_style_ids()`
  - Headings, Paragraphs, Images: CSS-Klassen in `etchData.attributes.class`
  - Sections, Containers: `process_*_element()` verwendet neue Funktion
  - Images: `block.tag = 'figure'`, Klasse auf `<figure>`
  - Entfernt: `unset($etch_data_attributes['class'])`
  
- `includes/css_converter.php`
  - Erweiterte Style-Map: ID + Selector
  - `parse_custom_css_stylesheet()` mit `$style_map` Parameter
  - Custom CSS verwendet existierende Style-IDs

### 🎯 Erfolgs-Kriterien

✅ Alle Element-Typen rendern CSS-Klassen im Frontend
✅ Custom CSS wird korrekt zusammengeführt
✅ Images ohne doppelte `<img>`-Tags
✅ Referenz-Post (3411) bleibt bei Cleanup erhalten

### 🚀 Migration-Workflow

1. Cleanup: `./cleanup-etch.sh` (behält Post 3411)
2. Migration: "Start Migration" Button
3. Verifizierung: CSS-Klassen im Frontend prüfen

---

## [0.3.9] - 2025-10-17 (20:50)

### 🐛 Critical Fix: API-Key nicht bei Migration verwendet

**Problem:** Obwohl die Token-Validierung funktionierte und den API-Key zurückgab, wurde dieser nicht bei der tatsächlichen Migration verwendet. Stattdessen wurde der Token fälschlicherweise als API-Key gesendet, was zu 401-Fehlern bei allen `/receive-post` und `/receive-media` Requests führte.

**Lösung:**

- API-Key wird jetzt aus `sessionStorage` gelesen (wurde dort bei Token-Validierung gespeichert)
- `startMigrationProcess()` verwendet den echten API-Key statt des Tokens
- Validierung vor Migration-Start: Fehler wenn kein API-Key in sessionStorage

**Geänderte Dateien:**

- `includes/admin_interface.php` - Zeilen 542-577

---

## [0.3.8] - 2025-10-17 (20:45)

### 🎉 Major Fix: Token-Based Validation System

**Problem gelöst:** Migration Keys enthielten fälschlicherweise den Token als API-Key, was zu 401-Fehlern führte.

### ✨ Neue Features

#### Token-Validierung statt API-Key in URL

- Migration Keys enthalten jetzt nur noch `domain`, `token` und `expires`
- API-Key wird **nicht mehr** in der URL übertragen
- Sicherer und sauberer Ansatz

#### Automatische API-Key-Generierung

- API-Key wird automatisch auf der Etch-Seite generiert
- Bei Token-Validierung wird der API-Key in der Response zurückgegeben
- Bricks-Seite speichert den API-Key automatisch in sessionStorage

### 🔧 Technische Änderungen

#### Frontend (`includes/admin_interface.php`)

- **Neue AJAX-Action:** `b2e_validate_migration_token`
  - Ersetzt die fehlerhafte `b2e_validate_api_key` für Migration-Keys
  - Sendet `token`, `domain` und `expires` statt `api_key`
  - Extrahiert API-Key aus Response und speichert in sessionStorage

- **Verbesserte UI-Meldungen:**
  - "Migration token validated successfully!" statt "API key validated"
  - Zeigt Token-Ablaufzeit an
  - Klarere Fehlermeldungen

#### Backend (`includes/api_client.php`)

- **Neue Methode:** `validate_migration_token()`
  - Sendet POST-Request an `/wp-json/b2e/v1/validate`
  - Überträgt Token-Daten als JSON
  - Gibt vollständige Response mit API-Key zurück

#### API Endpoints (`includes/api_endpoints.php`)

- **Erweitert:** `validate_migration_token()`
  - Generiert automatisch API-Key falls nicht vorhanden
  - Verwendet `B2E_API_Client::create_api_key()`
  - Gibt API-Key in Response zurück
  - Logging für Debugging

### 📊 Validierungs-Flow

```text
1. Etch-Seite: Migration Key generieren
   ↓
   URL: http://localhost:8081?domain=...&token=...&expires=...
   
2. Bricks-Seite: Migration Key validieren
   ↓
   AJAX: b2e_validate_migration_token
   ↓
   POST /wp-json/b2e/v1/validate
   {
     "token": "...",
     "source_domain": "...",
     "expires": 1234567890
   }
   
3. Etch-Seite: Token validieren + API-Key generieren
   ↓
   Response:
   {
     "success": true,
     "api_key": "b2e_...",
     "message": "Token validation successful",
     "target_domain": "...",
     "site_name": "...",
     "etch_active": true
   }
   
4. Bricks-Seite: API-Key speichern
   ↓
   sessionStorage.setItem('b2e_api_key', api_key)
   ↓
   ✅ Bereit für Migration
```

### 🧪 Testing

- **Automatisiertes Test-Script:** `test-token-validation.sh`
  - Generiert Token
  - Speichert in Datenbank
  - Testet Validierung
  - Verifiziert API-Key-Rückgabe

- **Manuelles Test-Script:** `test-migration-flow.sh`
  - Prüft WordPress-Sites
  - Testet API-Endpoints
  - Zeigt Test-Checkliste

### 🐛 Behobene Bugs

1. **401 Unauthorized bei Token-Validierung**
   - Ursache: Token wurde als API-Key behandelt
   - Lösung: Separater Validierungs-Endpoint mit Token-Parameter

2. **API-Key-Mismatch**
   - Ursache: Jeder Migration Key hatte anderen "API-Key" (war eigentlich Token)
   - Lösung: API-Key wird serverseitig generiert und übertragen

3. **Fehlende API-Key-Synchronisation**
   - Ursache: Keine automatische Übertragung des API-Keys
   - Lösung: API-Key in Validierungs-Response enthalten

### 📝 Migrations-Hinweise

**Für bestehende Installationen:**

1. Plugin auf Version 0.3.8 aktualisieren
2. Alte Migration Keys sind ungültig
3. Neue Migration Keys auf Etch-Seite generieren
4. Token-Validierung auf Bricks-Seite durchführen

**Wichtig:** Die alte `b2e_validate_api_key` AJAX-Action existiert noch für Kompatibilität, wird aber nicht mehr für Migration-Keys verwendet.

### 🔒 Sicherheit

- Token-Validierung mit Ablaufzeit (8 Stunden)
- API-Key wird nicht in URL übertragen
- Sichere Token-Generierung mit `wp_generate_password(64, false)`
- API-Key wird nur bei erfolgreicher Token-Validierung zurückgegeben

### 🚀 Performance

- Keine Änderungen an der Performance
- Zusätzlicher API-Call für Token-Validierung (einmalig)
- API-Key wird in sessionStorage gecacht

### 📚 Dokumentation

- `todo.md` aktualisiert mit gelöstem Problem
- Test-Scripts für automatisierte Validierung
- Detaillierte Changelog-Einträge

---

## [0.3.7] - 2025-10-16

### Vorherige Version

- Basis-Implementierung der Migration
- AJAX-Handler für verschiedene Aktionen
- REST API Endpoints
- Docker-Setup für Testing

---

**Hinweis:** Vollständige Versionshistorie in Git verfügbar.
