# Changelog

All notable changes to the Etch Fusion Suite project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.13.0] - 2026-02-26

### Changed
- **JS Bundling (esbuild):** All 15 admin ES6 modules are now compiled into a single IIFE bundle (`assets/js/dist/main.js`). WordPress enqueues only this one file; the `?ver=` cache-busting parameter covers the entire bundle on every update. Eliminates stale-sub-module caching that caused `etch-dashboard.js` to call the deprecated REST endpoint after 0.12.9. Added `build` / `watch` npm scripts and `esbuild ^0.25.0` devDependency. Removed `type="module"` and `script_loader_tag` filter from `class-admin-interface.php`.

## [0.12.8] - 2026-02-25

### Fixed
- **PHP 8 Kompatibilität: `(string)`-Cast nach `preg_replace()` in `EFS_ACSS_Handler::register_acss_inline_style()` (2026-02-25)**: `preg_replace()` gibt `string|null` zurück; fehlender Cast führte zu einem `ValueError` in `ltrim()` auf PHP 8, wenn PCRE unerwartet fehlschlug. Casts wurden ergänzt — konsistent mit der identischen Logik in `EFS_CSS_Converter` (`includes/css/class-acss-handler.php`).
- **CSS-Wrapping-Logik in `EFS_Style_Importer::save_to_global_stylesheets()` (2026-02-25)**: Die frühere `strpos()`-Prüfung zur Erkennung bereits gewrappter CSS-Blöcke erzeugte false positives, wenn der Selektorname in einem Property-Wert vorkam (z.B. `content: ".btn"`). Ersetzt durch eine `startsWith(selector) + {`-Prüfung (`includes/css/class-style-importer.php`).
- **`uniqid()` ohne Entropie für Style Manager IDs (2026-02-25)**: `uniqid()` ohne zweiten Parameter basiert ausschliesslich auf Mikrosekunden und ist theoretisch kollisionsanfällig bei schnellen Loops. Auf `uniqid('', true)` umgestellt, das einen zufälligen Floating-Point-Suffix anhängt (`includes/css_converter.php`).

### Security
- **PHPCS: `$wpdb->prepare()` für LIKE-Query in `convert_classes()` (2026-02-25)**: Die `SELECT`-Query für `efs_inline_css_*`-Options-Einträge verwendete keinen `prepare()`-Wrapper. Obwohl kein User-Input vorliegt, erfordern WordPress Coding Standards `prepare()` für jede Query mit LIKE-Klauseln (`includes/css_converter.php`).
- **SQL Injection: `$wpdb->prepare()` für alle LIKE-Queries in `deactivate()` (2026-02-24)**: Die drei rohen `$wpdb->query()`-Aufrufe in der Deaktivierungsroutine wurden durch `$wpdb->prepare()` mit `$wpdb->esc_like()` abgesichert (`etch-fusion-suite.php`).
- **Path Traversal: Pfadvalidierung in `EFS_Migration_Logger::get_log_path()` (2026-02-24)**: Defense-in-depth-Check mit `wp_normalize_path()` und `strpos()`-Vergleich stellt sicher, dass der aufgelöste Pfad innerhalb des Log-Verzeichnisses bleibt. Außerdem wird die `migration_id` im AJAX-Handler nun vor Verwendung auf das Format `[a-zA-Z0-9_-]{1,64}` validiert (`class-migration-logger.php`, `class-logs-ajax.php`).
- **CSRF: Nonce-Verifizierung im Debug-AJAX-Handler (2026-02-24)**: `efs_query_db` fehlte eine Nonce-Prüfung; `wp_verify_nonce()` gegen `efs_nonce` wurde ergänzt (`class-debug-ajax.php`).
- **CORS: Leerer Origin-Header wird nicht mehr zu CORS-Headern führen (2026-02-24)**: `add_cors_headers()` gibt jetzt früh zurück, wenn kein `Origin`-Header vorhanden ist, sodass keine CORS-Header für same-origin- oder Server-zu-Server-Anfragen gesetzt werden (`class-cors-manager.php`).
- **Information Disclosure: JWT-Payload-Felder aus Connection-Test-Response entfernt (2026-02-24)**: `issued_at` (`iat`) und `jwt_target` werden nicht mehr in der AJAX-Response von `efs_test_connection` zurückgegeben (`class-connection-ajax.php`).

### Fixed
- **Logs werden jetzt beim Seitenaufruf immer geladen (2026-02-24)**: `initLogs()` ruft nun einmalig `fetchLogs()` auf, damit Migration-Runs im Dashboard direkt sichtbar sind — auch wenn keine Migration aktiv ist. Vorher wurden Logs nur während aktiver Migrationen via Auto-Refresh aktualisiert (`assets/js/admin/logs.js`).

### Performance
- **Media-Batch-Size von 10 auf 30 erhöht (2026-02-24)**: `EFS_Media_Phase_Handler::BATCH_SIZE` wurde verdreifacht. Reduziert die Anzahl der HTTP-Round-Trips für Media-Migration erheblich (`includes/services/class-media-phase-handler.php`).

### Changed
- **Test-Migrator zählt jetzt alle Post-Types (2026-02-24)**: `collectStats()` berücksichtigt nun `post`, `page` und `bricks_template`/Target-Post-Type statt nur `post`. Zusätzlich werden Media-Items gezählt und Delta-Warnungen ausgegeben (`scripts/test-migration.js`). `resolveTemplateTargetPostType()` cached sein Ergebnis um doppelte WP-CLI-Aufrufe zu vermeiden.

## [0.12.7] - 2026-02-23

### Fixed
- **Cache-Key-Typo im Migration-Repository (2026-02-23)**: `save_imported_data()` verwendete den falschen Cache-Präfix `b2e_cache_imported_` statt `efs_cache_imported_`. Dadurch wurde der Transient-Cache nach dem Speichern nie invalidiert und veraltete Daten wurden für CPTs/ACF-Feldgruppen/Metabox-Konfigurationen zurückgegeben.
- **WP_Error-Handling in `test_export_connection` / `test_import_connection` (2026-02-23)**: Wenn `validate_migration_key_on_target()` einen `WP_Error` zurückgab, wurde er stillschweigend ignoriert (kein `is_wp_error()`-Check vor Array-Zugriff), und es wurde eine generische "Connection failed."-Meldung ausgegeben. Der echte Fehlergrund (z.B. Netzwerkfehler, ungültiger Key) wurde verloren. Jetzt wird `WP_Error` korrekt behandelt und die echte Fehlermeldung zurückgegeben. Außerdem fehlendes `return` nach `wp_send_json_success()` ergänzt.
- **Falsche Fehlermeldungen im Wizard-AJAX (2026-02-23)**: Bei fehlendem oder ungültigem URL in `validate_url()` wurden fälschlicherweise "Migration key is required." / "Migration key is invalid." ausgegeben statt der korrekten URL-bezogenen Meldungen.
- **Toter Code in `send_css_styles()` (2026-02-23)**: Leere `if/else`-Blöcke in `api_client.php` wurden entfernt.
- **Stale-Detection-Bug im Progress Manager (2026-02-23)**: Wenn `strtotime()` für `last_updated` `false` zurückgab (ungültiger/leerer Timestamp), wurde die Migration nie als "stale" erkannt, was zu dauerhaft feststeckenden Migrations-States führte. Nun wird ein ungültiger Timestamp direkt als stale behandelt.
- **Headless-Mode Stale-TTL von 300s auf 120s reduziert (2026-02-23)**: Headless-Migrationen wurden erst nach 5 Minuten als stale erkannt, was neue Migrations-Starts für zu lange blockierte.
- **Feststeckende stale Migrations-States werden jetzt in `start_migration_async()` bereinigt (2026-02-23)**: Wenn `get_progress_data()` einen Status bereits zu 'stale' transformiert hatte, wurde das alte State nicht explizit geleert. Jetzt wird auch in diesem Fall `delete_progress()` / `delete_steps()` aufgerufen, bevor eine neue Migration startet.
- **`migration_in_progress`-Fehler enthält jetzt die ID der hängenden Migration (2026-02-23)**: Die AJAX-Response für `migration_in_progress` gibt jetzt `existing_migration_id` und `existing_status` zurück (HTTP 409), damit das Frontend eine gezielte Abbruchmöglichkeit anbieten kann.

### Changed
- **Legacy-Präfix `b2e_` vollständig zu `efs_` migriert (2026-02-23)**:
  - Option-Namen: `b2e_post_mappings` → `efs_post_mappings`, `b2e_media_mappings` → `efs_media_mappings`, `b2e_component_map` → `efs_component_map`, `b2e_registered_cpts` → `efs_registered_cpts`, `b2e_inline_js_*` → `efs_inline_js_*`
  - Post-Meta-Keys: `_b2e_original_post_id` → `_efs_original_post_id`, `_b2e_migrated_from_bricks` → `_efs_migrated_from_bricks`, `_b2e_migration_date` → `_efs_migration_date`, `_b2e_template_*` → `_efs_template_*`
  - WP_Error-Codes: alle `b2e_*` → `efs_*`
  - Debug-Tags: `B2E_MIGRATOR` → `EFS_MIGRATOR`, `B2E_HTML_PARSER` → `EFS_HTML_PARSER`, etc.
  - `B2E_Migrator_Registry` Type-Hint → `EFS_Migrator_Registry`
  - `b2e_container()` Funktionsreferenz → `etch_fusion_suite_container()`
  - REST-API-Endpoint-URL in `gutenberg_generator.php`: `b2e/v1` → `efs/v1`
  - Alle Reads haben Fallbacks auf alte `b2e_*`-Keys für Rückwärtskompatibilität mit bestehenden Installationen
  - Legacy-Compat-Hooks `b2e_register_migrators` und `b2e_migrators_discovered` bleiben für Drittanbieter-Integration erhalten
  - Autoloader-Regex behält `b2e`-Präfix-Support für bestehende Klassen

### Removed
- **Alte Migrations-Logs und Temp-Dateien gelöscht (2026-02-23)**: `debug-*.log`, `tmp-*`, `migration-report-*.json`, `page-*.json`, `health-report-*.json`, `env-info-output.txt`, `test-output.log`, fehlerhafte Datei `revoke_current_migration_key())`
- **`.gitignore` erweitert (2026-02-23)**: Patterns für `tmp-*`, `migration-report-*.json`, `page-*.json`, `env-info-output.txt`, `test-output.log`, `.phpunit.result.cache` hinzugefügt

---

## [0.12.6] - 2026-02-23

### Security
- **`test_grid_span.php` Debug-Skript entfernt (2026-02-21)**: Datei gab rohe WordPress-Option-Daten (alle Bricks Global Classes) und CSS-Converter-Ergebnisse ohne Authentifizierungs- oder ABSPATH-Guard aus. Datei gelöscht; `test_*.php` in `.gitignore` aufgenommen, damit lokale Debug-Skripte nie versehentlich committed werden.

- **Loopback-Spawn: `sslverify => false` auf lokale/dev-Umgebungen beschränkt (2026-02-21)**: Die HEAD-Probe und der non-blocking POST in `spawn_migration_background_request()` setzten `sslverify => false` global. In Produktion besitzt `admin_url()` eine gültige HTTPS-URL mit echtem Zertifikat — dort muss TLS-Verifikation aktiv bleiben. Fix: `sslverify` wird jetzt über `wp_get_environment_type()` gesteuert (`local`/`development` → Verifikation überspringen, sonst aktiviert). Ein Filter `etch_fusion_suite_loopback_skip_ssl_verify` erlaubt atypischen Hosting-Setups eine Überschreibung.

### Fixed
- **Migration: `start_migration_async()` blockiert Browser ~11 s durch langsame HEAD-Probe + DNS-Lookups (2026-02-21)**
  - Root cause 1: Die blocking HEAD-Probe in `spawn_migration_background_request()` hatte einen Timeout von 4 s. In Docker-on-Windows-Umgebungen (wp-env) antwortet der Loopback teilweise erst nach 3–4 s → der Browser erhält die AJAX-Response erst nach 11 s und verlässt die Seite vorher.
  - Root cause 2: `etch_fusion_suite_resolve_bricks_internal_host()` löste `gethostbyname('wordpress')` und `gethostbyname('bricks')` bei jedem Request neu auf; in Umgebungen mit langsamen DNS-Timeouts kostet das mehrere Sekunden.
  - Fix 1: HEAD-Probe-Timeout von 4 s auf 1 s reduziert (`class-migration-service.php`). Ein Server, der in 1 s nicht antwortet, wird als nicht erreichbar behandelt → Fallback auf synchrone Ausführung.
  - Fix 2: Ergebnis von `etch_fusion_suite_resolve_bricks_internal_host()` wird jetzt in einer statischen PHP-Variable (request-level) **und** einem 5-Minuten-Transient (cross-request) gecacht. Wiederholte DNS-Lookups im gleichen oder nachfolgenden Requests sind damit sofort aus dem Cache bedient.

- **Migration resume: Seiten-Reload während media/posts-Phase startet Batch-Loop nicht sofort (2026-02-21)**
  - Root cause: `autoResumeMigration()` rief nach dem Progress-Fetch immer `startPolling()` auf, auch wenn `current_step` bereits `media` oder `posts` war (d. h. die Batch-Phase bereits lief). Dadurch wartete der Browser einen weiteren Polling-Intervall (3 s), bevor er in den Batch-Loop wechselte.
  - Fix: In `autoResumeMigration()` wird `current_step` aus dem Progress-Fetch ausgelesen. Ist er `media` oder `posts` (und Status `running`), wird `runBatchLoop()` direkt aufgerufen statt `startPolling()` zu starten (`bricks-wizard.js`).

## [0.12.4] - 2026-02-21

### Fixed
- **CSS converter: Media Query px-Werte werden nicht durchgängig zu `to-rem()` konvertiert (2026-02-21)**
  - Root cause: Drei Code-Pfade erzeugen Media Queries, aber nur einer nutzte konsequent die Etch-Syntax mit `to-rem()`:
    1. `convert_responsive_variants()` rief `get_breakpoint_media_query_map()` ohne `$etch_syntax = true` auf → px-basierte Queries (`min-width: 767px`).
    2. `extract_media_queries()` und `extract_media_queries_for_id()` lasen `@media`-Bedingungen direkt aus Bricks-Raw-CSS-Strings und gaben sie unverändert weiter → Bricks-px-Syntax blieb erhalten.
  - Fix:
    1. `convert_responsive_variants()`: `get_breakpoint_media_query_map( true )` (Etch-Syntax aktiviert).
    2. Neue Helper-Methode `normalize_media_condition_to_etch()` konvertiert `(min-width: Xpx)` → `(width >= to-rem(Xpx))` und `(max-width: Xpx)` → `(width <= to-rem(Xpx))`. Bedingungen die bereits `to-rem()` enthalten werden übersprungen.
    3. Beide `extract_media_queries*`-Funktionen rufen den Helper direkt nach dem `trim()` auf.

- **CSS converter: `grid-column/row: span full` erzeugt ungültiges CSS (2026-02-21)**
  - Root cause: Bricks speichert `_gridItemColumnSpan = 'full'` wenn ein Element alle Grid-Spalten überspannen soll. Der Converter übergab den Wert direkt → `grid-column: span full;` (ungültiges CSS).
  - Fix: In `css_converter.php::convert_grid_item_placement()` wird jeder nicht-numerische Wert als benannter Grid-Area-Name behandelt. `'full'` wird zu `grid-column: full;` konvertiert, was dem CSS-Grid-Named-Area-Shorthand für `full-start / full-end` entspricht. Gilt für Column- und Row-Span.

- **CSS converter: `& [data-row]` hat falsches Leerzeichen (Nachfahren- vs. Gleich-Element-Selektor) (2026-02-21)**
  - Root cause: `normalize_selector_suffix_with_ampersand()` rief `trim()` auf dem rohen Suffix auf, bevor es prüfte, ob ein führendes Leerzeichen vorhanden war. Dadurch wurde `.cls[data-row='1']` (kein Leerzeichen → Gleich-Element) und `.cls [data-row='1']` (Leerzeichen → Nachfahre) identisch behandelt und beide als `& [data-row='1']` ausgegeben.
  - Fix: Der rohe Suffix wird vor `trim()` auf führende Whitespace geprüft (`$is_descendant_context`). Attribute-/Klassen-/ID-Selektoren ohne führendes Leerzeichen werden jetzt korrekt als `&[attr]` (kein Leerzeichen) ausgegeben.

- **Paragraph converter: inline HTML rendered as literal text (2026-02-21)**
  - Root cause: `etch/text` block treats `content` as plain text — inline HTML tags like `<b>` or `<strong>` are rendered as literal `&lt;b&gt;` rather than formatted text.
  - Fix: in `class-paragraph.php::convert()`, detect when `$text !== strip_tags($text)` (i.e. the Bricks `text` field contains HTML markup). In that case, emit `etch/raw-html` with `unsafe:false` as the inner block instead of `etch/text`. Plain-text content continues to use `etch/text` unchanged.

- **import_post: wp_kses_post encodes block comments containing inline HTML (2026-02-21)**
  - Root cause: `wp_insert_post` internally applies `content_save_pre → wp_kses_post` even when the handler already bypassed it. `wp_kses_post` encodes `<!--` → `&lt;!--` when an Etch block comment's JSON attribute value contains allowed HTML tags (e.g. `<b>`), breaking the block grammar on the target site.
  - Fix: wrap `wp_insert_post` with `kses_remove_filters()` / `kses_init_filters()` when `$is_block_content` is true. Content was already sanitised by `wp_kses_post` on the source before conversion, so the bypass is safe.
  - Affected: any Bricks `text-basic` element whose `text` field contains inline HTML formatting.

- **Background migration spawn: bg_token corrupted by sanitize_text_field (2026-02-21)**
  - Root cause: `spawn_migration_background_request` generated the bg_token with `wp_generate_password(32, true)` which allows special characters including `%`. When the token contained `%XX` hex sequences (e.g. `%3b`, `%7B`), `sanitize_text_field` in `run_migration_background` stripped those sequences, causing the stored token to not match the received token → `invalid_token` WP_Error → HTTP 400 → migration never executed.
  - Fix: replaced `wp_generate_password(32, true)` with `bin2hex(random_bytes(16))` in `class-migration-service.php::spawn_migration_background_request`. Hex tokens (a-f 0-9 only) are never modified by `sanitize_text_field`.
  - Affected ~7% of migrations (those where the random token happened to contain `%XX` sequences).

### Security
- **Core migration flow fix: reverse token generation (2026-02-20)**
  - Root cause: token was generated and stored on the source site; target's `validate_migration_token()` checks its own stored token → mismatch → 401.
  - Fix: source dashboard now calls target's `/generate-key?source_url={source_home_url}` first. Token is generated and stored on the target; `domain` in the JWT payload is set to `source_url` so `check_source_origin()` validates `X-EFS-Source-Origin` on import endpoints.
  - `generate_migration_token()` gains optional `$source_url` parameter; when present, payload `domain` = source_url (backward-compatible, existing callers unaffected).
  - `/generate-key` route: `permission_callback` changed to `allow_public_request`; accepted HTTP methods extended to GET + POST so source browser can call it without an admin session on the target.
  - Handler adds `endpoints` map to response so the source can discover target REST paths without hardcoding.
  - `get_current_migration_display_data()` now always builds the migration URL from `home_url()` (target) rather than the token payload `domain` to prevent wrong URL in admin display when `domain` = source_url.
  - `bricks-wizard.js::validateConnectStep` updated: detects embedded-token URL (legacy) vs bare target URL (new flow) and fetches a fresh token from target when no embedded token is present. `window.efsData.home_url` used as source identifier; falls back to `window.location.origin`.

- **SEC-001 follow-up: /export/post-types made public (2026-02-20)**
  - Reverted Bearer token + X-EFS-Source-Origin checks from `export_post_types` handler; source dashboard JS fetches this endpoint for the post-type mapping dropdown before a migration token exists.
  - `permission_callback` changed to `__return_true`; endpoint remains rate-limited (60 req/min) and covered by global CORS enforcement.

- **REST authentication gate for import/validate routes (2026-02-20)**
  - Replaced `allow_public_request` permission callback with `require_migration_token_permission` on all `/import/*` routes and `/receive-media`: requests without a valid Bearer migration token are now rejected at the permission layer, before the handler executes.
  - Added `require_admin_or_body_migration_token_permission` callback for the `/validate` route: accepts either a logged-in user with `manage_options` (cookie+nonce or application password) or a verifiable `migration_key` in the JSON body; unauthenticated requests return HTTP 401.
  - `/generate-key` and `/migrate` already required `manage_options` and are unaffected.
  - Bearer-token and `X-EFS-Source-Origin` checks inside handlers are retained as the second validation layer (defence-in-depth).

- **SEC-001 resolution (2026-02-20)**
  - Reverted `permission_callback` to `allow_public_request()` on target import/migrate routes for usability (migration-only plugin, 8h token expiry, auto-cleanup on deactivate).
  - Added X-EFS-Source-Origin header check in all import/receive handlers: after token validation, request header must match token payload `domain` (timing-safe); 403 on mismatch.
  - CORS allowed headers extended with `X-EFS-Source-Origin` and `X-EFS-Items-Total`. Docs: tighten `cors_allowed_origins` to source domain only on target; recommend admin-only activation and deactivate after migration.

### Changed
- **Admin Dashboard Integration & Polish (2026-02-16)**
  - Added focused Playwright coverage for Bricks wizard integration flow in `tests/playwright/admin-dashboard-wizard.spec.ts`
  - Added focused Playwright coverage for Etch receiving-status transitions in `tests/playwright/admin-dashboard-receiving.spec.ts`
  - Added `npm run test:playwright:admin-dashboard` for redesign regression runs
  - Added deployment and rollback runbook in `docs/admin-dashboard-deployment-checklist.md`
  - Updated `README.md` and `DOCUMENTATION.md` with redesign integration test guidance

- **Code Converter:** Maximale native Etch-Integration statt pauschalem `etch/raw-html` Dump — **2026-02-16**
  - JS aus `javascriptCode` Feld und `<script>` Tags im HTML-Feld wird extrahiert und als `wp:etch/element` mit `script.code` base64 ausgegeben (Etch-nativ)
  - CSS aus `cssCode` Feld und `<style>` Tags im HTML-Feld wird extrahiert und als `wp:etch/raw-html` mit `<style>` ausgegeben (separater Block mit `(CSS)` Label)
  - PHP-Code (`<?php`/`<?`) wird erkannt und als nicht-ausführbarer Warnungs-Block mit `esc_html()`-escaped Original ausgegeben
  - HTML-Kommentare und Whitespace-only Reste nach Extraktion werden ignoriert
  - Verbleibender HTML-Content geht als `wp:etch/raw-html` mit `executeCode` → `unsafe` Flag

### Removed
- Deprecated `etch-flex-div-style` CSS style (no longer generated)

### Changed
- Migrated all element converters to Etch v1.1+ flat schema
- `.brxe-block` class now migrates as normal global class with flex CSS

### 🐛 Bug Fixes
- Fixed backup metadata version retrieval mis-mapping environments in `scripts/backup-db.js` - **2025-11-04 21:22**
  - Added `normalizeEnvironmentToTarget()` function for consistent environment mapping
  - Updated version helpers to accept both logical names ('bricks'/'etch') and direct targets ('cli'/'tests-cli')
  - Modified `backupAllDatabases()` to use logical names for clarity and consistency
  - Added verbose debug logging with `--verbose` flag and `EFS_DEBUG` environment variable
  - Ensured Bricks metadata comes from `cli` container and Etch metadata from `tests-cli` consistently

### 🚀 Features
- Enhanced wp-env development environment with comprehensive helper scripts
- Added health check system for monitoring WordPress instances
- Implemented log filtering and saving utilities
- Added port availability checker with process identification
- Created environment information display tool
- Added database backup and restore functionality with manifest tracking
- Enhanced Playwright setup with global health checks and log capture
- Improved error handling and retry logic across all scripts

### 🔧 Technical Changes
- Refactored structural Etch block generation to v1.1+ flat attrs (`metadata.name`, `tag`, `attributes`, `styles`) for base/container/section/div paths.
- Updated structural serialization from `wp:group` + wrapper HTML to comment-only `wp:etch/element` blocks in converters and generator structural path.
- Migrated generator fallback serializers (including `iframe`/semantic wrappers) away from nested `metadata.etchData` to flat comment-only Etch block output.
- Enhanced `.wp-env.json` with per-environment configuration and lifecycle scripts
- Added `.wp-env.override.json.example` for local customizations
- Updated `package.json` with 20+ new npm scripts for development tasks
- Improved `dev.js` with pre-flight checks, retry logic, and detailed summaries
- Enhanced `wait-for-wordpress.js` with timeout, JSON output, and WordPress verification
- Upgraded `debug-info.js` with comprehensive system analysis and multiple output formats
- Enhanced `activate-plugins.js` with retry logic, force mode, and verification
- Added global setup/teardown to Playwright configuration
- Integrated health checks into Playwright test execution

### 🛠️ Development Experience
- Added structured logging with color coding and severity levels
- Implemented cross-platform compatibility for all helper scripts
- Added progress indicators and spinners for long-running operations
- Created comprehensive error messages with actionable troubleshooting steps
- Added dry-run modes for destructive operations
- Implemented automatic cleanup of temporary files and old logs

### 📋 Documentation
- Added inline documentation for all new helper scripts
- Enhanced configuration examples with detailed comments
- Added usage examples and troubleshooting guides
- Documented all new npm scripts with descriptions

---

## [0.4.1] - 2025-10-21 (23:20)

### 🐛 Bug Fixes
- Fixed Custom CSS migration not merging with existing styles
- Updated `parse_custom_css_stylesheet()` to use existing style IDs

---

## [0.4.0] - 2025-10-21 (22:45)

### 🚀 Features
- Added comprehensive Bricks to Etch migration system
- Implemented support for custom field mapping
- Added batch processing for large datasets
- Introduced progress tracking and reporting

### 🔧 Technical Changes
- Refactored migration engine for better performance
- Added database transaction support
- Implemented error recovery mechanisms
- Enhanced logging and debugging capabilities

---

## [0.3.0] - 2025-10-20 (18:30)

### 🚀 Features
- Added initial WordPress integration
- Implemented basic data migration
- Added configuration management system

### 🔧 Technical Changes
- Set up project structure and build system
- Added Composer and npm package management
- Implemented basic testing framework

---

## [0.2.0] - 2025-10-19 (14:15)

### 🚀 Features
- Created project foundation
- Added basic plugin architecture
- Implemented core functionality

### 🔧 Technical Changes
- Initial project setup
- Added basic file structure
- Set up development environment

---

## [0.1.0] - 2025-10-18 (10:00)

### 🎉 Initial Release
- Project inception
- Basic concept implementation
- Development environment setup

