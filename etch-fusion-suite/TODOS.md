# Etch Fusion Suite - TODO List

**Updated:** 2026-03-03 17:17 (Bricks Migration UX: Configuration Error Handling - Improved Error Messages)

## 🚀 Current Development

### 🔨 Active Stabilization & Audit Plan (Started: 2026-02-27)

**Context:** Plugin war funktionsfähig, ist aber instabil geworden. Action Scheduler Initialization wurde behoben, nun müssen systematisch Strauss-Prefixing, PSR-4 Autoloading, Migrator-System und CSS Converter stabilisiert werden.

#### Phase 1️⃣: Strauss & Vendor-Abhängigkeiten (4 Todos)
- [✅] **audit-strauss** - VERIFIED 2026-02-28: Alle drei Pakete korrekt in `vendor-prefixed/`. firebase/php-jwt → `EtchFusionSuite\Vendor\Firebase\JWT\`, psr/container → `EtchFusionSuite\Vendor\Psr\Container\`, action-scheduler → globale `ActionScheduler_*` Klassen (kein Namespace, AS-Design intentional). Bug gefixt: Autoloader hatte Groß-/Kleinschreibungs-Problem (`Migration` vs `migration`) auf Linux.
- [✅] **verify-action-scheduler-load** - FIXED: ActionScheduler global classes + DISABLE_WP_CRON timing
- [✅] **verify-firebase-jwt** - VERIFIED 2026-02-28: JWT ausschließlich unter `EtchFusionSuite\Vendor\Firebase\JWT\*` in `migration_token_manager.php`. Namespace-Prefixing korrekt, PSR-4 in `autoload_psr4.php` korrekt registriert.
- [✅] **verify-psr-container** - VERIFIED 2026-02-28: PSR Container unter `EtchFusionSuite\Vendor\Psr\Container\*` in `class-service-container.php`. Namespace-Prefixing korrekt, PSR-4 in `autoload_psr4.php` korrekt registriert. Shim für vendor-prefixed-loses Deployment vorhanden.

#### Phase 2️⃣: PSR-4 Autoloading (3 Todos)
- [✅] **audit-psr4** - VERIFIED 2026-02-28: Alle 125 Namespace→Datei-Mappings korrekt. namespace_map-Reihenfolge (spezifisch vor allgemein) korrekt. Beide Autoloader (Composer + manuell) koexistieren sauber.
- [✅] **check-autoload-fallback** - VERIFIED 2026-02-28: Legacy-Klassen (Core\\, Api\\, Parsers\\, Migrators\\ mit leerer dir → root) alle korrekt aufgelöst. `autoloader.php` deckt alle 125 Klassen ab.
- [✅] **test-psr4-autoload** - VERIFIED 2026-02-28: Live Docker-Test 129/129 PASS. Zwei Bugs gefunden+gefixt: (1) `efs_autoload_action_scheduler` zu spät registriert (nach action-scheduler.php) → Plugin-Aktivierung in WP-CLI crashte; (2) autoloader-audit.php + live-autoload-test.php als Regressions-Scripts in tests/ hinzugefügt.

#### Phase 3️⃣: Migrator System (4 Todos)
- [✅] **audit-migrator-registry** - FIXED 2026-02-28: (1) Registry `get_all()` + `get_supported()` mit try/catch Throwable gesichert — buggy Migrators crashen nicht mehr die gesamte Discovery. (2) Discovery `auto_discover_from_directory()` require_once in try/catch Throwable gewrappt — PHP-Parse-Fehler in Third-Party-Dateien brechen nicht mehr ab. (3) Dead code entfernt: `$is_cron_context` + `$is_ajax_context` in `class-batch-phase-runner.php` (gesetzt aber nie genutzt). (4) Null-Guard für `$this->batch_phase_runner` in `class-batch-processor.php` vor `run_phase()` — optionaler Konstruktor-Parameter kann nun nicht mehr fatal crashen.
- [✅] **refactor-migrator-base** - VERIFIED 2026-02-28: `Abstract_Migrator` bereits solid: `migrate()` und `validate()` sind abstrakt, Error Handler + API Client via DI, `is_required()` optional false. Keine Änderungen nötig.
- [✅] **implement-migrator-validation** - VERIFIED 2026-02-28: Pre-Validierung via `validate()` bereits in `EFS_Migrator_Executor` vor `migrate()` aufgerufen. Post-Validierung und Rollback by design nicht implementiert (out of scope für diese Phase). Kein Code geändert.
- [✅] **fix-batch-processor** - FIXED 2026-02-28: Null-Guard für `$this->batch_phase_runner` ergänzt (Fix 4, s.o.). Memory Management, Timeout-TTL (300s Lock), Progress Manager und Action Cleanup bereits korrekt implementiert (Lock via add_option + Transient, shutdown_handler registriert, finally-Block löscht Lock).

#### Phase 4️⃣: CSS Converter Testable (3 Todos)
- [✅] **audit-css-module-deps** - VERIFIED 2026-02-28: 5 Module vollständig WP-frei testbar (Normalizer stateless, BreakpointResolver mit function_exists-Fallback, AcssHandler, SettingsCssConverter, StylesheetParser). 2 Module brauchen WP-DB (ClassReferenceScanner, ElementIdStyleCollector). StyleImporter via Style_Repository_Interface mockbar. Detaillierte Tabelle in CHANGELOG.md.
- [✅] **isolate-css-converter** - VERIFIED 2026-02-28: Orchestrator akzeptiert alle 8 Module bereits als optionale nullable DI-Parameter im Konstruktor. Keine Refactoring-Änderungen nötig — Isolation ist bereits vollständig implementiert.
- [✅] **enable-css-converter-tests** - DONE 2026-02-28: `tests/unit/CSS/CssNormalizerTest.php` (28 Tests: Grid, HSL, Alpha, LogicalProps, IDSelectors, QuadShorthand, BorderWidth, GradientStop, ContentProperty) + `tests/unit/CSS/BreakpointResolverTest.php` (16 Tests: DefaultMap, EtchSyntax, PlainSyntax, NamedLookup, MediaConditionNorm). Beide Dateien laufen im `unit`-Test-Suite (WP_UnitTestCase, kein Docker nötig für pure Module).

#### Phase 5️⃣: Logging & Debugging (2 Todos)
- [✅] **add-debug-logging** - FIXED 2026-02-28: (1) Audit ergab 184 bestehende Logging-Aufrufe in 40 Dateien — Abdeckung bereits sehr gut. (2) Tote Variablen `$is_cron_context` + `$is_ajax_context` in `class-async-migration-runner.php` entfernt (identisches Pattern wie Phase 3 BatchPhaseRunner-Fix). (3) `debug_log('Background spawn accepted', ...)` für erfolgreichen Spawn in `class-background-spawn-handler.php` ergänzt (bisher nur Fehlerfall geloggt).
- [✅] **add-error-messages** - FIXED 2026-02-28: Audit ergab 12 verwendete aber undefinierte Codes die auf "Unknown Warning/Error" zurückfielen. 8 neue Codes in `error_handler.php` definiert: E106 (CSS Import Failed), E108 (Post Type Not Mapped), E905 (Media Service Exception), E906 (CSS Conversion Exception), E907 (CSS Element Style Exception), W013 (Background Spawn Fallback), W401 (Component Skipped), W900 (Migration Cancelled). Alle mit title + description + solution.

#### Phase 6️⃣: Testing & Verification (2 Todos)
- [✅] **test-full-migration-flow** - DONE 2026-02-28: PHP-Syntaxcheck aller 10 in Phases 1–5 geänderten Dateien: 10/10 PASS. Verifikationsskript `tests/phase-fixes-verification.php` erstellt — prüft 35 Conditions (AS-Klassen, PSR-4 Autoload, Migrator-Fixes, CSS-Module-Laufzeit, Error-Codes). Ausführung in Docker: `npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/phase-fixes-verification.php';"`. PHPUnit CSS-Tests (Phase 4) laufen mit `composer test:unit` in Docker.
- [✅] **performance-profile** - VERIFIED 2026-02-28: Statische Bottleneck-Analyse ohne xdebug (PHPStan nicht lokal verfügbar, läuft in CI). Findings: (1) Memory-Management in BatchPhaseRunner korrekt: `$memory_pressure` → Zeile 232 gesetzt, Zeile 379/410 zurückgegeben. (2) ClassReferenceScanner kein N+1: `get_posts()` via `WP_Query` primed Meta-Cache mit einer `IN()`-Query; nachfolgende `get_post_meta()`-Aufrufe = Cache-Hits. (3) `add_to_log()` 1 `get_option` + 1 `update_option` pro Eintrag — bounded durch 1000er-Limit; by design. (4) `should_exclude_class()` rebuildet Arrays pro Aufruf — Micro-Optimierung, kein Bottleneck. Keine Code-Änderungen nötig.

#### Session Tasks (2 Todos)
- [✅] **document-module-deps** - DONE 2026-02-28: Dependency-Tabelle in Phase 4 TODOS + CHANGELOG dokumentiert (alle 8 CSS-Module mit WP-Abhängigkeiten). Vollständige Architektur in MEMORY.md (CSS Converter Refactor-Sektion).
- [✅] **improve-service-provider** - VERIFIED 2026-02-28: Service Provider in `includes/container/class-service-provider.php` wurde in Phase 2 (audit-psr4) bereits auf korrekte Registrierung aller 8 CSS-Module überprüft. 129/129 Live-Test-PASS bestätigt korrekte DI-Registrierung.

**Dependency Chain:** Phase 1 → Phase 2 → Phases 3,4 → Phase 5 → Phase 6

### 🐛 Open Bugs

- [✅] **🟡 Bricks Migration Shows "Failed" With No Configuration** - **FIXED: 2026-03-03 17:17**
   - **Problem:** When user starts migration without completing Step 2 (Connect to Etch Site), the UI shows "Migration Failed" with no context
   - **Root Cause:** 
     - API was making requests with empty/NULL target_url and migration_key
     - Backend didn't validate configuration before attempting API calls
     - Frontend received blank responses, interpreted as "failed"
     - Error message was vague and not actionable
   
   - **Solution Implemented:**
     - ✅ **Backend validation:** Added configuration check in `get_progress()` method of `EFS_Migration_Controller`
     - ✅ **Error code:** Return `configuration_incomplete` WP_Error with helpful message
     - ✅ **Frontend enhancement:** Detect `configuration_incomplete` error code in `migration.js`
     - ✅ **User messaging:** Show actionable message: "Migration setup incomplete. Please go back to Step 2: Connect to Etch Site"
     - ✅ **Error propagation:** Enhanced error handling to forward error codes and data through API response
   
   - **Files Modified:**
     - `includes/controllers/class-migration-controller.php` (added pre-flight config check)
     - `assets/js/admin/migration.js` (enhanced error code detection and messaging)
   
   - **Tests Passed:**
     - Without configuration: Returns `configuration_incomplete` error ✅
     - With configuration: Migration proceeds normally ✅
     - Error message is actionable and clear ✅
   
   - **Status:** ✅ RESOLVED - Users now see helpful error when setup is incomplete

- [✅] **🔴 CRITICAL: Headless Migrations Stuck at "Pending"** - **FIXED: 2026-03-02 21:47**
   - **Verification (2026-03-02 21:41-21:52):** 369+ logs, 42 new in last 2 min, Media/CSS success ✅
   - **Status:** ✅ PRODUCTION READY

- [✅] **🔴 Etch Receiving: Elapsed-Zeit falsch (UTC-Bug)** - **FIXED: 2026-03-03**
   - **Problem:** Etch-Seite zeigte z.B. 69:29 statt ~09:35 — ca. 60 Minuten zu viel (UTC-Offset)
   - **Root Cause:**
     - PHP `touch_receiving_state()` speicherte `started_at` mit `current_time('mysql')` (Ortszeit) statt `current_time('mysql', true)` (UTC)
     - JavaScript parsete das Datum ohne `'Z'`-Suffix → Browser interpretierte es als lokale Zeit
   - **Solution:**
     - ✅ `api_endpoints.php:1216` — `current_time('mysql', true)` (UTC)
     - ✅ `class-wordpress-migration-repository.php:591` — `current_time('mysql', true)` in `normalize_receiving_state()`
     - ✅ `receiving-status.js` — `+ 'Z'` beim Date-Parsing (konsistent mit `bricks-wizard.js`)
   - **Status:** ✅ RESOLVED

- [✅] **🟡 Etch Receiving: Per-Post-Type Items-Aufschlüsselung** - **FIXED: 2026-03-03**
   - **Problem:** Etch-Seite zeigte nur `Items: 150/1409` (grand-total) ohne Aufschlüsselung
   - **Solution:**
     - ✅ Sender: `api_client.php` sendet `X-EFS-Phase-Total` + `X-EFS-PostType-Totals` Header
     - ✅ Sender: `class-batch-phase-runner.php` befüllt die Header aus `counts_by_post_type_totals` im Checkpoint
     - ✅ Empfänger: `touch_receiving_state()` für Media/CSS Phase updated `items_by_type[phase]`
     - ✅ Empfänger: neue Methode `touch_receiving_state_by_types()` für Posts-Batches (per-Type inkrementell)
     - ✅ Empfänger: `import_posts()` aggregiert Batch nach `post_type` und ruft `touch_receiving_state_by_types()`
     - ✅ Repository: `items_by_type` Feld in Schema + Normalisierung
     - ✅ AJAX: `items_by_type` in `efs_get_receiving_status` Response
     - ✅ JS: `receiving-status.js` rendert Aufschlüsselung als `Media: 150/897 · Posts: 3/200 · Pages: 1/150`
   - **Status:** ✅ RESOLVED

- [✅] **🟢 Etch Receiving: Prozent-Element initial mit hidden-Attribut** - **FIXED: 2026-03-03**
   - **Problem:** `etch-setup.php:97` hatte `hidden` am Prozent-Element obwohl JS das verwaltet
   - **Solution:** `hidden`-Attribut entfernt (konsistent mit Bricks-Seite)
   - **Status:** ✅ RESOLVED

- [ ] **🟡 Dashboard UI: Validation Errors During Headless Migration** - **IDENTIFIED: 2026-03-02 21:52**
   - **Problem:** UI shows `post_id=0` validation error (not blocking, cosmetic)
   - **Root Cause:** Headless vs interactive mode distinction not clear in UI
   - **Priority:** LOW 🔵 (non-blocking)

- [✅] **🔴 CRITICAL: Migration System Hangs After Discovery** - **FIXED: 2026-03-02**
   - **Root Cause (Found 2026-03-01):** Two interrelated issues prevented Action Scheduler from processing the queue:
     1. **Docker Loopback URL Issue:** Loopback requests sent `http://localhost:8888/admin-ajax.php` but inside Docker containers, `localhost` doesn't resolve to the host. The loopback runner wasn't using Docker-aware URL translation.
     2. **Hook Registration on Wrong Hook:** Queue handler was registered on `admin_init` hook, which **doesn't fire for AJAX requests**. Since loopback requests go to `admin-ajax.php`, the handler never executed.
     3. **Duplicate Handler Registration:** Handler was registered twice (once in class, once in config file), creating confusion.
   
   - **Solution Implemented (2026-03-02):**
     1. ✅ **Docker URL Translation:** Added call to `etch_fusion_suite_convert_to_internal_url()` in `trigger_loopback_request()` to convert `http://localhost:8888` → `http://wordpress` (container-internal networking)
     2. ✅ **Hook Change:** Changed handler registration from `admin_init` to `init` hook (fires for all request types including AJAX)
     3. ✅ **Removed Duplicate:** Deleted redundant handler registration from `action-scheduler-config.php`, keeping only the main filter definition
   
   - **Files Modified:**
     - `etch-fusion-suite/includes/services/class-action-scheduler-loopback-runner.php` (lines 29-39, 88-113)
     - `etch-fusion-suite/action-scheduler-config.php` (removed lines 26-37)
   
   - **Verification:** All 6 verification tests PASS ✅
     1. Docker URL helper function exists
     2. Docker URL conversion works (localhost → internal hostname)
     3. Loopback runner class exists and is callable
     4. Hook handler registered on 'init' hook (count: 1)
     5. No duplicate handler in action-scheduler-config.php
     6. Hook correctly uses 'init' instead of 'admin_init'
   
   - **Test Command:** `npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/verify-loopback-fixes.php';"`
   - **Documentation:** Updated `DOCUMENTATION.md` with full explanation of Docker loopback handling
   - **Status:** ✅ RESOLVED - Loopback requests now properly route through Docker networking and hook handlers execute correctly

- [✅] **VideoConverter: `test_video_css_classes_and_styles` schlägt fehl** - **FIXED: 2026-03-01**
   - **Problem:** Test erwartet dass CSS-Klasse nicht im `attributes`-JSON des iframes steht, aber Converter legte sie dort ab.
   - **Lösung:** Komplette Refaktorierung zu Privacy-Pattern (Poster + Play-Button). CSS-Klasse ist jetzt auf dem Wrapper-Container (nicht dem hidden iframe).
   - **Implementiert:** 
     - neue Methode `build_privacy_video_container()` für 3-Teil Struktur
     - neue Methode `detect_embed_type()` für YouTube/Vimeo-Erkennung
     - neue Methode `extract_video_id_from_url()` für Video-ID-Extraktion
     - neue Methode `get_embed_poster_url()` für Poster-Generierung
     - neue Methode `build_youtube_embed_url_nocookie()` für Datenschutz-URLs
   - **Tests:** 16 Testmethoden neu geschrieben, alle Szenarien abgedeckt
   - **Commit:** 7c9ada3e
  - **Problem:** Test erwartet dass CSS-Klasse nicht im `attributes`-JSON des iframes steht, aber Converter legte sie dort ab.
  - **Lösung:** Komplette Refaktorierung zu Privacy-Pattern (Poster + Play-Button). CSS-Klasse ist jetzt auf dem Wrapper-Container (nicht dem hidden iframe).
  - **Implementiert:** 
    - neue Methode `build_privacy_video_container()` für 3-Teil Struktur
    - neue Methode `detect_embed_type()` für YouTube/Vimeo-Erkennung
    - neue Methode `extract_video_id_from_url()` für Video-ID-Extraktion
    - neue Methode `get_embed_poster_url()` für Poster-Generierung
    - neue Methode `build_youtube_embed_url_nocookie()` für Datenschutz-URLs
  - **Tests:** 16 Testmethoden neu geschrieben, alle Szenarien abgedeckt
  - **Commit:** 7c9ada3e

### ✅ Completed Tasks

- [✅] **Fix Action Scheduler Initialization** - **Completed:** 2026-02-27
  - **Problem 1**: DISABLE_WP_CRON war zu spät definiert (nach vendor autoloader)
  - **Problem 2**: ActionScheduler global classes wurden nicht explizit required
  - **Problem 3**: Headless mode konnte nicht aktiviert werden
  - **Fix**: (1) DISABLE_WP_CRON at top of plugin file (before vendors), (2) Explicit `require_once` for action-scheduler.php (action-scheduler-config.php now only adds filters)
  - **Verification**: `npm run wp -- eval "echo class_exists('ActionScheduler') ? 'YES' : 'NO';"` → YES
  - **Verification**: `npm run wp -- eval "echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'YES' : 'NO';"` → YES
  - **Verification**: `as_schedule_single_action()` works, no fatal errors

- [✅] **Code-Review: 5 Bugfixes aus statischer Analyse** - **Completed:** 2026-02-25
  - `(string)`-Cast nach `preg_replace()` in `EFS_ACSS_Handler::register_acss_inline_style()` ergänzt (PHP 8 Kompatibilität)
  - CSS-Wrapping-Logik in `save_to_global_stylesheets()` von fragiler `strpos()`-Prüfung auf `startsWith(selector) + {` umgestellt
  - `uniqid()` auf `uniqid('', true)` für Style Manager IDs (reduziert Kollisionsrisiko)
  - `$wpdb->prepare()` für LIKE-Query in `convert_classes()` ergänzt (PHPCS-Compliance)
  - Zu langen `$acss_stub_index`-Kommentar auf zwei Zeilen aufgeteilt (PHPCS Line-Length)

- [✅] **Plugin-Review: 7 Bugfixes + Legacy-Präfix-Migration** - **Completed:** 2026-02-23
  - **Root cause Migration-Blocker gefunden und behoben**: Debug-Log zeigte dauerhaft `"code":"migration_in_progress"`. Ein vorheriger Run ließ `status: 'running'` in der DB. Behoben durch: (1) Stale-Detection-Fix für ungültige Timestamps, (2) Headless-TTL von 300s auf 120s reduziert, (3) explizite State-Bereinigung in `start_migration_async()` für stale-States, (4) HTTP 409 mit `existing_migration_id` im AJAX-Fehler.
  - **Cache-Key-Typo behoben**: `save_imported_data()` nutzte `b2e_cache_imported_` statt `efs_cache_imported_` → Cache wurde nie invalidiert.
  - **WP_Error-Handling in Connection-AJAX**: `is_wp_error()`-Check vor Array-Zugriff ergänzt, fehlendes `return` nach `wp_send_json_success()` hinzugefügt.
  - **Falsche Fehlermeldungen im Wizard-AJAX**: URL-Validierungsfehler gaben fälschlicherweise "Migration key"-Meldungen aus.
  - **Toter Code in `send_css_styles()`** entfernt.
  - **Legacy-Präfix `b2e_` → `efs_`** in 20+ Dateien migriert (Option-Namen, Post-Meta-Keys, WP_Error-Codes, Debug-Tags, Typ-Hints, Container-Referenz, REST-URL). Alle Reads haben Fallbacks auf alte `b2e_*`-Keys.
  - **29 Temp-/Log-Dateien gelöscht** und `.gitignore` um die entsprechenden Patterns erweitert.
  - PHPCS (128 Dateien) nach allen Änderungen fehlerfrei.

- [✅] **Fix Backup Metadata Version Retrieval** - **Completed:** 2025-11-04 21:22
  - Fixed `scripts/backup-db.js` version helper mis-mapping environments
  - Added `normalizeEnvironmentToTarget()` function for consistent environment mapping
  - Updated `getWordPressVersion()` and `getPluginVersion()` to accept both logical names and direct targets
  - Modified `backupAllDatabases()` to use logical names ('bricks'/'etch') for clarity
  - Added verbose debug logging with `--verbose` flag and `EFS_DEBUG` environment variable
  - Ensured all `runWpEnv(['run', ...])` calls use normalized targets

- [✅] **Enhance wp-env Configuration** - **Completed:** 2025-10-29 15:30
  - Added per-environment configuration in `.wp-env.json`
  - Created `.wp-env.override.json.example` with comprehensive examples
  - Implemented lifecycle scripts for health checks
  - Added debugging flags and environment-specific settings

- [✅] **Update Package Scripts** - **Completed:** 2025-10-29 15:30
  - Added 20+ new npm scripts for development tasks
  - Organized scripts by category (logs, health, database, etc.)
  - Added comprehensive script descriptions and usage examples
  - Implemented cross-platform compatibility

- [✅] **Enhance Development Scripts** - **Completed:** 2025-10-29 15:30
  - **dev.js**: Added pre-flight checks, retry logic, progress indicators
  - **wait-for-wordpress.js**: Enhanced with timeout, JSON output, WordPress verification
  - **debug-info.js**: Comprehensive system analysis with multiple output formats
  - **activate-plugins.js**: Added retry logic, force mode, verification, and detailed reporting

- [✅] **Create Helper Scripts** - **Completed:** 2025-10-29 15:30
  - **health-check.js**: Comprehensive environment monitoring with auto-fix capabilities
  - **filter-logs.js**: Advanced log filtering with color coding and real-time following
  - **save-logs.js**: Log capture with compression and automatic cleanup
  - **check-ports.js**: Port availability checker with process identification and termination
  - **env-info.js**: Environment information display with configuration comparison
  - **backup-db.js**: Database backup with manifest tracking and compression
  - **restore-db.js**: Database restore with safety features and automatic backups

- [✅] **Update Playwright Setup** - **Completed:** 2025-10-29 15:30
  - Added global setup/teardown scripts for environment health checks
  - Enhanced configuration with metadata and conditional test exclusion
  - Implemented log capture on test failures
  - Added retry logic for authentication setup

- [✅] **Update Documentation** - **Completed:** 2025-10-29 15:30
  - Created comprehensive `DOCUMENTATION.md` with usage examples
  - Updated `CHANGELOG.md` with detailed version history
  - Added troubleshooting guides and API reference
  - Documented all new scripts and configuration options

## 🧹 Repository Cleanup (COMPLETED: 2026-03-03)

### ✅ COMPLETED CLEANUP TASKS

**Temp/Debug Files (DELETED - 6 files):**
- ✅ `test.txt` (leere Test-Datei)
- ✅ `etch-page-929.html` (Debugging-Dump)
- ✅ `phase2-security.json` (alte Sicherheits-Analyse)
- ✅ `tmp_efs_posts/` (Verzeichnis - temporäre Daten)
- ✅ `tmp_efs_styles.json` (temporäre Stile)
- ✅ `test-environment/` (Verzeichnis - alte Test-Setup)

**Legacy Scripts (DELETED - 16 files):**
- ✅ `cleanup-etch.sh`
- ✅ `commit-and-push.py`
- ✅ `commit-fix.sh`
- ✅ `do-commit.cmd`
- ✅ `fix-class-aliases.ps1`
- ✅ `fix-double-backslash.ps1`
- ✅ `fix-service-provider.ps1`
- ✅ `fix-symlinks.cmd`
- ✅ `fix-symlinks.ps1`
- ✅ `migrate-post.ps1`
- ✅ `monitor-migration.ps1`
- ✅ `remove-large-files.sh`
- ✅ `run-phpcs.ps1`
- ✅ `run_git_commands.py`
- ✅ `update-references.ps1`
- ✅ `update-scripts.ps1`

**Documentation Review (DELETED - 3 files):**
- ✅ `DOCUMENTATION_DASHBOARD_LOGGING.md` - Outdated/redundant (content covered in DOCUMENTATION.md)
- ✅ `IMPLEMENTATION_DASHBOARD_LOGGING.md` - Outdated
- ✅ `TEST_RESULTS_DASHBOARD_LOGGING.md` - Outdated

### ✅ .gitignore Updates

- ✅ Added build artifacts: `build/`, `node_modules/`, `vendor/`, `.phpunit.result.cache`
- ✅ Verified IDE configs in .gitignore (kept for developers)
- ✅ Total cleanup: 22 items + 3 documentation files deleted

**Cleanup Summary:** Repository cleaned successfully on 2026-03-03. Removed 25 legacy/temp files and updated .gitignore.

---

## 🎯 Dashboard Progress UI Improvements (COMPLETED: 2026-03-03)

### ✅ COMPLETED DASHBOARD IMPROVEMENTS

**Phase 1: Time Calculation System**
- ✅ Added `enrich_progress_with_times()` method to `EFS_Progress_Manager` 
  - Calculates `elapsed_seconds` from `started_at` timestamp
  - Calculates `estimated_time_remaining` based on items_processed / total_items rate
- ✅ Updated `EFS_Migration_Orchestrator::get_progress()` to call enrichment
- ✅ Updated `EFS_Migration_Controller::get_progress()` to pass new fields to response
- ✅ Updated JavaScript `migration.js` to send `elapsed_seconds` and `estimated_time_remaining` to UI
- ✅ Updated JavaScript `ui.js` to import and use `formatElapsed()` and `formatEta()`
- ✅ Updated `updateProgress()` function to display "Elapsed: HH:MM:SS • ~Xm Ys remaining"
- ✅ Added `[data-efs-progress-time]` element to migration-progress.php view
- ✅ Added CSS styling for `.efs-progress-time` in admin.css

**Phase 2: Time Display on Dashboard**
- Implementation ready for immediate display of elapsed/remaining times during migration
- Formatting functions already available: `formatElapsed()` and `formatEta()` in time-format.js

**Files Modified:**
1. `includes/services/class-progress-manager.php` - Added time enrichment logic
2. `includes/services/class-migration-orchestrator.php` - Call time enrichment
3. `includes/controllers/class-migration-controller.php` - Pass elapsed/ETA to response
4. `assets/js/admin/migration.js` - Forward elapsed/ETA data from API
5. `assets/js/admin/ui.js` - Display elapsed/remaining times with formatters
6. `includes/views/migration-progress.php` - Add time display element
7. `assets/css/admin.css` - Style time display
8. `.gitignore` - Updated build artifacts entries

---

### 🎯 High Priority

⚠️ **NOTE (2026-02-27)**: Viele der folgenden Todos sehen bereits implementiert aus (Batch Processing, Progress Tracking, Error Logging, REST API, Admin Dashboard). Morgen systematisch überprüfen welche wirklich noch offen sind vs. welche schon erledigt sind aber nicht als [✅] markiert wurden.

- [ ] **Dashboard: Breakdown progress by post type** - **IDENTIFIED: 2026-03-02 21:58**
  - **Current State:** Dashboard shows only total count (470/1409 items)
  - **Needed:** Per-post-type breakdown:
    - Posts: X/Y (progress bar)
    - Pages: X/Y (progress bar)
    - bricks_template: X/Y (progress bar)
    - [Custom Post Types]: X/Y (each with count)
  - **Reason:** Better visibility into what's being migrated and where bottlenecks are
  - **Implementation:** Query wp_efs_migration_logs grouped by detected post type, show per-type progress
  - **Priority:** 🟡 HIGH (UX improvement for long migrations)

- [ ] **Dashboard: Fix elapsed/remaining time calculation** - **IDENTIFIED: 2026-03-02 21:58**
  - **Current State:** 
    - Elapsed: 155:38 (starts at ~130 instead of 0, clearly wrong)
    - Remaining: ~179m 22s (rough estimate, appears inaccurate)
  - **Needed:**
    - Store migration start timestamp (from first log entry)
    - Calculate actual elapsed: NOW() - migration_start_time
    - Calculate remaining: (elapsed / items_processed) * (total_items - items_processed)
    - Format HH:MM:SS consistently
  - **Reason:** Users need accurate time estimates for long migrations (3+ hours)
  - **Implementation:** Migration repository/service calculate from actual log timestamps
  - **Priority:** 🟡 HIGH (affects user experience)

- [ ] **Migration Performance Optimization**
  - Implement batch processing for large datasets
  - Add database transaction optimization
  - Create progress indicators for migration operations
  - Add memory usage monitoring and optimization

- [ ] **Advanced Error Handling**
  - Create centralized error logging system
  - Add error recovery mechanisms
  - Implement rollback functionality for failed migrations
  - Add detailed error reporting with suggested fixes

### 🔧 Medium Priority

- [ ] **Testing Infrastructure**
  - Add unit tests for helper scripts
  - Create integration tests for migration functionality
  - Implement visual regression testing for UI changes
  - Add performance testing for large-scale migrations

- [ ] **Developer Experience**
  - Create VS Code extension for migration management
  - Add interactive CLI for common operations
  - Implement hot reload for development environment
  - Add code generation tools for custom field mappings

- [ ] **Monitoring and Analytics**
  - Add usage analytics for migration operations
  - Create performance monitoring dashboard
  - Implement health monitoring with alerts
  - Add automated testing for environment health

### 🎨 Low Priority

- [ ] **UI/UX Improvements**
  - Create admin dashboard for migration management
  - Add visual migration progress indicators
  - Implement wizard-style migration setup
  - Add help system and documentation integration

- [ ] **Integration Enhancements**
  - Add support for additional page builders
  - Implement API for external integrations
  - Create webhook system for migration events
  - Add REST API endpoints for migration status

---

## 🐛 Known Issues

### 🔍 Current Issues

⚠️ **NOTE (2026-02-27)**: Einige dieser "Known Issues" könnten durch existierende Features bereits teilweise adressiert sein (Error Handling, Logging, Retry-Logik). Bitte morgen mit der Codebase abgleichen.

- [ ] **Memory Usage**: Large migrations may exceed memory limits on low-spec systems
  - **Impact**: Medium
  - **Workaround**: Use batch processing or increase PHP memory limit
  - **Fix needed**: Implement streaming processing for large datasets

- [ ] **Plugin Conflicts**: Some plugins may interfere with migration process
  - **Impact**: Medium
  - **Workaround**: Disable conflicting plugins during migration
  - **Fix needed**: Create plugin compatibility matrix and conflict detection

- [ ] **Database Performance**: MySQL configuration may affect migration speed
  - **Impact**: Low
  - **Workaround**: Optimize MySQL settings for large imports
  - **Fix needed**: Add database optimization recommendations

### 🔧 Technical Debt

- [ ] **Code Organization**: Some utility functions need better organization
  - **Impact**: Low
  - **Fix needed**: Refactor into dedicated utility classes

- [ ] **Error Messages**: Some error messages could be more descriptive
  - **Impact**: Low
  - **Fix needed**: Review and enhance error messaging throughout codebase

- [ ] **Test Coverage**: Unit test coverage needs improvement
  - **Impact**: Medium
  - **Fix needed**: Add comprehensive unit tests for core functionality

---

## 📚 Documentation Tasks

### 📖 User Documentation

- [ ] **Video Tutorials**
  - Create setup and configuration videos
  - Add migration walkthrough tutorials
  - Create troubleshooting video guides

- [ ] **User Guide**
  - Write comprehensive user manual
  - Add step-by-step migration guides
  - Create FAQ section for common issues

### 🔧 Developer Documentation

- [ ] **API Documentation**
  - Document all public APIs and functions
  - Add code examples for common use cases
  - Create architecture documentation

- [ ] **Contributing Guide**
  - Write detailed contribution guidelines
  - Add code style and standards documentation
  - Create development environment setup guide

---

## 🚀 Future Roadmap

### 📅 Short Term (Next 2-4 weeks)

- Add comprehensive error handling
- Implement performance optimizations
- Create initial test suite

### 📅 Medium Term (1-3 months)

- Add support for additional page builders
- Create admin dashboard
- Implement advanced monitoring
- Add API for external integrations

### 📅 Long Term (3-6 months)

- Create cloud-based migration service
- Add AI-powered migration suggestions
- Implement collaborative migration features
- Create enterprise-grade security features

---

---

## 🎯 Next Phase: Testing & Verification (2026-03-03)

### Custom Settings Table Implementation - COMPLETE ✅
**Status:** Fully implemented, documented, and Docker-verified  
**Commits:** 4ab265a7, 82509cfc, 6a5ec06e, f0a27571, f02910b3, f34fd16e

#### Completed Tasks
- [✅] `wp_efs_settings` custom table created with proper schema
- [✅] Settings Repository with helper methods (get_setting, save_setting, delete_setting)
- [✅] Migration Controller updated to use Settings Repository
- [✅] PSR Container conflict fixed (removed from Strauss vendor-prefixing)
- [✅] Docker environment verified working
- [✅] Tables created and data persistence tested
- [✅] Documentation updated (DOCUMENTATION.md, CHANGELOG.md)

#### Outstanding Tasks - High Priority 🔴

- [ ] **test-full-unit-suite** - Run complete PHPUnit test suite
  - Command: `npm run test:unit`
  - Expected: 162+ tests passing, no regressions
  - Verifies: Settings table, migration key persistence, all existing functionality
  - Blocks: Code review sign-off

- [ ] **test-end-to-end-migration** - Full migration workflow test
  - Steps: Start Docker → Begin migration → Dashboard shows breakdown → Time displays correctly
  - Verify: Items are grouped by post type (Posts, Pages, Templates, Custom)
  - Verify: Elapsed/Remaining time is accurate
  - Verify: Settings are persisted correctly
  - Verify: No console errors or database warnings
  - Blocks: Production readiness

- [ ] **run-phpcs-linting** - WordPress coding standards compliance
  - Command: `composer phpcs`
  - Expected: No violations in modified files
  - Files to check: class-db-installer.php, class-wordpress-settings-repository.php, class-migration-controller.php, class-service-container.php
  - Blocks: Code review sign-off

#### Optional Enhancements 🟢

- [ ] **refactor-specialized-data** - Move style_map, media_mappings, etc. to separate Repositories
  - Scope: 50+ remaining `get_option('efs_*')` calls
  - Files involved: css_converter.php, media_migrator.php, and 15+ others
  - Priority: Low (acceptable technical debt for now)
  - Effort: Medium (requires separate Repository classes)
  - Timeline: Future sprint

- [ ] **add-settings-cache-metrics** - Monitor transient cache hit rates
  - Goal: Verify 5-minute cache reduces database queries
  - Implementation: Add cache hit/miss logging
  - Priority: Low (optimization, not critical)

#### Known Issues & Notes

- **PSR Container (RESOLVED):** Strauss was vendor-prefixing PSR interfaces (incorrect). Solution: Removed psr/container from Strauss packages. PSR Standards should NEVER be vendor-prefixed as they are shared by all plugins/WordPress.

- **Schema Issue (RESOLVED):** Initial schema had both UNIQUE constraint AND separate KEY index (redundant). Fixed by removing redundant KEY - UNIQUE automatically creates index.

- **Legacy Settings:** Some data (efs_active_migration, efs_dismissed_migration_runs, etc.) still use wp_options. Design decision: Keep specialized data separate. Settings Table handles core plugin configuration only.

---

## 🔴 Kritische Stabilitäts-Fixes

> **Referenz:** `optimierungen.md` (Section 10) — alle Details, SQL-Beispiele und Abhängigkeitsketten dort.
> Implementierungsreihenfolge einhalten — 10b hängt von der Migrations-Tabelle ab, 10i hängt von 10a (✅ bereits done) ab.

### 🔴 KRITISCH

- [ ] **fix-idempotency** (optimierungen.md §10i) — **Priorität 1**
  - **Problem:** Post erfolgreich gesendet, HTTP-Response verloren → Post bleibt in `remaining_post_ids` → Duplikat auf Ziel-Site
  - **Fix:** `processed_post_ids`-Set im Checkpoint mitführen; vor jedem Send prüfen ob ID bereits enthalten
  - **Dateien:** `class-batch-phase-runner.php` (im Post-Batch-Loop), `class-wordpress-migration-repository.php`
  - **Abhängigkeit:** 10a (Checkpoint Locking) bereits ✅ done — Fix kann direkt beginnen
  - **Aufwand:** ~3-4h

- [ ] **fix-db-lock** (optimierungen.md §10b) — **Priorität 2**
  - **Problem:** `add_option()`-Lock in `class-batch-processor.php:104` ist fragil — TOCTOU-Fenster bei gleichzeitigen Requests, hängt bei Server-Crash bis Transient-Ablauf (300s)
  - **Fix:** `wp_efs_migrations` um `lock_uuid VARCHAR(36)` + `locked_at DATETIME` erweitern; atomares `UPDATE ... WHERE lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)`
  - **Dateien:** `core/class-db-installer.php` (Schema-Migration mit `DB_VERSION` bump), `class-batch-processor.php`
  - **Aufwand:** ~3h

- [ ] **fix-checkpoint-validation** (optimierungen.md §10g) — **Priorität 2**
  - **Problem:** Checkpoint wird in `class-batch-processor.php:123` nur auf `empty()` und `migrationId` geprüft — fehlende required keys führen zu Silent Failures
  - **Fix:** `validate_checkpoint(array $checkpoint): bool` als statische Methode; prüft required keys (`migrationId`, `phase`, `total_count`) und erlaubte Phase-Werte
  - **Dateien:** `class-batch-processor.php`
  - **Aufwand:** ~1h

### 🟡 HOCH

- [ ] **wire-stale-migrations** (optimierungen.md §10k) — **Priorität 3**
  - **Problem:** `EFS_DB_Migration_Persistence::get_stale_migrations()` existiert bereits in `class-db-migration-persistence.php:137`, wird aber nirgendwo aufgerufen — Auto-Resume nach Crash ist nur client-seitig
  - **Fix:** Im AJAX-Handler `efs_get_progress` (oder Batch-Einstieg) aufrufen; stale Migrationen auf `'stale'` setzen → Client kann gezielt resumieren
  - **Dateien:** `ajax/handlers/class-progress-ajax.php` oder `class-batch-processor.php`
  - **Aufwand:** ~1h (Methode existiert, nur verdrahten)

- [ ] **fix-atomic-heartbeat** (optimierungen.md §10h) — **Priorität 4**
  - **Problem:** Progress-Heartbeat nutzt Read-Modify-Write ohne Locking; 120s Transient-Cache gibt veraltete Daten zurück
  - **Fix:** Direktes `UPDATE wp_efs_migrations SET updated_at = %s WHERE migration_uid = %s` statt options-basierter Lese-Schreib-Zyklus
  - **Dateien:** `class-progress-manager.php` (`touch_progress_heartbeat()`)
  - **Abhängigkeit:** Optional nach 10b (selbe Tabelle)
  - **Aufwand:** ~2h

- [ ] **fix-atomic-checkpoint** (optimierungen.md §10c) — **Priorität 4**
  - **Problem:** Zwei gleichzeitige AJAX-Requests lesen denselben gecachten Checkpoint (120s TTL) und überschreiben sich gegenseitig
  - **Fix:** Via `checkpoint_version` in `wp_efs_migrations` + Optimistic Locking (`UPDATE ... WHERE checkpoint_version = %d`); siehe `optimierungen.md §10a` für Schema
  - **Dateien:** `class-db-installer.php` (2 neue Spalten), neue `EFS_DB_Checkpoint_Repository` implementiert `Checkpoint_Repository_Interface`
  - **Aufwand:** ~4h

### 🟢 MITTEL

- [ ] **fix-post-cache-clearing** (optimierungen.md §10e) — **Priorität 5**
  - **Problem:** WordPress Object Cache wächst bei langen Migrationen unbegrenzt (`get_post()`, `update_post_meta()` etc.)
  - **Fix:** Nach `save_checkpoint()` in `class-batch-phase-runner.php`: `clean_post_cache($id)` pro verarbeitetem Item
  - **Dateien:** `class-batch-phase-runner.php` (nach dem Batch-Loop)
  - **Aufwand:** ~30min (1-Zeiler)

- [ ] **fix-shutdown-lock-closure** (optimierungen.md §10f) — **Priorität 6**
  - **Problem:** `EFS_Batch_Processor::release_lock_on_shutdown()` ist statische Klassenmethode — bei Fatal Error könnte Klasse bereits entladen sein
  - **Fix:** `register_shutdown_function` auf Closure mit `use ($lock_key)` umstellen; statische Methode + Property entfernen
  - **Dateien:** `class-batch-processor.php`
  - **Aufwand:** ~30min (entfällt automatisch wenn 10b umgesetzt)

- [ ] **fix-db-transactions** (optimierungen.md §10j) — **Priorität 7**
  - **Problem:** Checkpoint + Status werden ohne Transaktion geschrieben — bei Fehler inkonsistenter State
  - **Fix:** `START TRANSACTION` / `COMMIT` / `ROLLBACK` nur für `wp_efs_*` eigene Tabellen; WordPress-Kern-Tabellen nicht wrappbar
  - **Dateien:** `class-batch-phase-runner.php` (Checkpoint-Save + Progress-Update)
  - **Abhängigkeit:** Sinnvoll erst nach 10c (DB-Checkpoint)
  - **Aufwand:** ~2h

---

## 🔵 Performance & Code-Qualität

> **Referenz:** `optimierungen.md` (Sections 2, 3, 5, 6, 8, 9) — Details und Code-Beispiele dort.

- [ ] **add-nonce-coverage** (optimierungen.md §5)
  - 22+ REST API-Endpunkte: nur Permission-Callbacks, keine Nonce-Checks. AJAX-Handler-Basis-Klasse `verify_request()` nicht durchgängig genutzt.
  - **Fix:** Alle AJAX-Handler durch Basis-Klasse routen; REST-Endpunkte bleiben via WP-Native Permission-Callback abgesichert

- [ ] **extend-migration-logging** (optimierungen.md §9)
  - Strukturierte Post-Type-Stats (success/failed/skipped/time), detailliertes Media-Tracking, Phase-Timing
  - Details in `optimierungen.md §9a-e` mit konkreten Array-Strukturen und Dashboard-Anpassungen

- [ ] **add-missing-db-indexes** (optimierungen.md §8)
  - `post_id` auf Migrations-Mapping-Tabelle, `migration_id` für Progress-Tracking bei großen Migrationen
  - `DB_VERSION` bump nötig, `dbDelta()` erledigt den Rest

- [ ] **optimize-json-logging** (optimierungen.md §6)
  - `gutenberg_generator.php:1763` + `error_handler.php:418,554`: `wp_json_encode()` unabhängig vom Log-Level
  - **Fix:** JSON nur mit `WP_DEBUG` oder im Fehlerfall kodieren

- [ ] **add-pagination-queries** (optimierungen.md §2) — niedrige Priorität
  - `posts_per_page => -1` in `media_migrator.php` + `css_converter.php` — bei großen Sites OOM-Risiko
  - Erfordert Refaktorierung des Migration-Runners für Batch-Pagination

- [ ] **refactor-large-files** (optimierungen.md §3) — niedrige Priorität
  - `api_endpoints.php` (2712 Zeilen), `gutenberg_generator.php` (1928 Zeilen), `converters/class-base-element.php` (1379 Zeilen)
  - Aufteilung nach Single-Responsibility-Prinzip

---

## 🎨 CSS Converter Review Action Items

> **Referenz:** `css-converter-review.md` — vollständige Analyse mit Test-Cases und Code-Beispielen dort.
> Gesamtbewertung: **9/10** — Architektur ist vorbildlich. Nur gezielte Fixes nötig.

### 🟡 HOCH

- [ ] **fix-id-selector-regex** (css-converter-review.md §Konvertierungs-Fehler 1) — **~1h**
  - ⚠️ **REVIEW 2026-03-04: Bug-Beschreibung ist veraltet — Code-Review vor Umsetzung zwingend erforderlich**
  - **Ursprüngliche Annahme:** Regex sei `/#brx[e]?-([a-z0-9]+)/i` — zu permissiv (optionales `e`, keine Bindestriche)
  - **Tatsächlicher Code** (`class-css-normalizer.php:689`): `'/#brxe-([a-zA-Z0-9_-]+)/i'`
    - `e` ist NICHT optional (kein `[e]?`) → `#brx-` wird NICHT akzeptiert
    - Bindestriche und Unterstriche sind bereits erlaubt (`[a-zA-Z0-9_-]+`)
    - Der vorgeschlagene Fix würde Regressionen einführen (`e` wieder optional, `_` entfernt)
  - **Nächster Schritt:** Zuerst `write-css-normalizer-tests` umsetzen (Edge-Cases für `#brxe-abc-def`, Groß-/Kleinschreibung etc.) — die Tests zeigen ob tatsächlich ein Bug existiert
  - **Dateien:** `includes/css/class-css-normalizer.php` (`convert_bricks_id_selector_to_etch()`)
  - **Tests:** `tests/unit/CSS/CssNormalizerTest.php` — neue Test-Cases hinzufügen

- [ ] **write-css-normalizer-tests** (css-converter-review.md §Empfohlene Test-Cases) — **~4h**
  - Fehlende Unit-Tests für kritische Konvertierungspfade. Bestehende: 28 Tests in `CssNormalizerTest.php`
  - **Zu ergänzen:**
    - Grid-Shorthand-Varianten: `span 2 / 5` → `2 / 5`, `1 / span 2` → unverändert
    - ID-Selector-Edge-Cases: `#brxe-abc-def`, `#brx-123`, Groß-/Kleinschreibung
    - HSL-Alpha-Konvertierung: `0.5` → `50%`, `''` → `100%`, `0.25` → `25%`
    - Physical → Logical Properties: alle 6 Mappings (top/bottom/left/right/width/height)
  - **Dateien:** `tests/unit/CSS/CssNormalizerTest.php`

### 🟢 MITTEL

- [ ] **test-grid-shorthand-variants** (css-converter-review.md §Konvertierungs-Fehler 2) — **~2h**
  - `grid-column: span 2 / 5` → erwartet `grid-column: 2 / 5` — aktuell nicht getestet (unbekannt ob korrekt)
  - Vollständige Testmatrix in `css-converter-review.md §2` — dort auch die existierenden ✅-Fälle dokumentiert
  - **Dateien:** `includes/css/class-css-normalizer.php` + `tests/unit/CSS/CssNormalizerTest.php`

- [ ] **add-breakpoint-constants** (css-converter-review.md §Verbesserungsmöglichkeiten 1) — **~1h**
  - Magic Numbers in `class-breakpoint-resolver.php:76-90`: `1199`, `991`, `767`, `478`
  - **Fix:** Als Klassen-Konstanten: `BREAKPOINT_TABLET_LANDSCAPE = 1199` etc.
  - **Dateien:** `includes/css/class-breakpoint-resolver.php`

### 🔵 NIEDRIG

- [ ] **add-post-repository-interface** (css-converter-review.md §Verbesserungsmöglichkeiten 5) — **~8h**
  - `EFS_Class_Reference_Scanner` + `EFS_Element_ID_Style_Collector` rufen `get_posts()` + `get_post_meta()` direkt auf → nicht ohne WordPress testbar
  - **Fix:** `Post_Repository_Interface` mit `get_posts_by_meta()` + `get_post_meta()` einführen; WP-Implementierung + Mock für Tests
  - **Dateien:** neues `repositories/interfaces/interface-post-repository.php`, beide CSS-Module refactoren
  - **Aufwand:** groß, niedrige Priorität da WordPress-Tests über Docker bereits möglich

---

## 🧹 Dead Code & Cleanup

> **Referenz:** `dead-code-analyse.md` — vollständige Dateiliste und Analyse dort.
> **Korrektur zur Analyse:** Button/Icon-Converter sind bereits implementiert und in der Factory registriert (`class-element-factory.php:55-56`). Die `-converter.php` Bridge-Dateien sind redundant, nicht die Converter selbst.

### 🔴 Sofort entfernen — eindeutige Duplikate

- [ ] **delete-duplicate-error-handler** — ~1min
  - `includes/error_handler.php` ist ein Duplikat von `includes/core/class-error-handler.php`
  - Die Root-Datei wird nirgendwo per `require` eingebunden — der aktive Handler ist ausschließlich `core/class-error-handler.php` (42 Usages via `use Bricks2Etch\Core\EFS_Error_Handler`)
  - **Aktion:** `rm includes/error_handler.php`

- [ ] **delete-duplicate-db-installer** — ~1min
  - `includes/db-installer.php` ist ein Duplikat von `includes/core/class-db-installer.php`
  - Root-Datei wird nicht per `require` eingebunden — aktiv ist nur `core/class-db-installer.php`
  - **Aktion:** `rm includes/db-installer.php`

- [ ] **delete-converter-bridge-files** — ~2min
  - `includes/converters/elements/class-button-converter.php` enthält nur `require_once './class-button.php'` — redundant, PSR-4 Autoloader übernimmt das
  - `includes/converters/elements/class-icon-converter.php` enthält nur `require_once './class-icon.php'` — identisches Problem
  - **Aktion:** Beide Bridge-Dateien löschen; sicherstellen dass Autoloader `class-button.php` und `class-icon.php` direkt findet

### 🟡 Button & Icon Converter — Review & Testen

- [ ] **review-button-converter** — ~2h
  - **Status-Korrektur:** `EFS_Button_Converter` ist bereits implementiert (`class-button.php`) und in der Factory registriert (`'button' => EFS_Button_Converter::class`). Die Analyse war falsch.
  - **Was noch fehlt:** Testen der Konvertierung auf echter Bricks-Migration
    - Bricks Button-Styles (`primary`, `secondary`, `outline`, `text`) → `btn--*` CSS-Klassen
    - Link-Handling: `settings['link']` als Array vs. String
    - `target="_blank"` + `rel="noopener noreferrer"` bei `newTab: true`
    - Klassen aus Style-IDs werden korrekt vererbt
  - **Dateien:** `includes/converters/elements/class-button.php`, `tests/unit/Converters/`

- [ ] **review-icon-converter** — ~2h
  - **Status-Korrektur:** `EFS_Icon_Converter` ist bereits implementiert (`class-icon.php`) und registriert. Gibt aktuell leere `<!-- wp:etch/svg -->` Blöcke aus (font-icon Attribute werden bewusst verworfen).
  - **Was zu klären:** Ist `wp:etch/svg` der korrekte Etch-Block für Icons? Oder gibt es einen dedizierten Icon-Block?
  - **Was noch fehlt:** Testen mit verschiedenen Bricks-Icon-Libraries (Font Awesome, Ionicons, etc.)
  - **Dateien:** `includes/converters/elements/class-icon.php`

### 🔴 Framer Feature entfernen — geplante Idee verworfen

> Das Framer-Feature ist hinter `efs_is_framer_enabled()` (immer `false`) vollständig deaktiviert, aber der Code ist tief verankert. Kompletter Cleanup nötig.

- [ ] **remove-framer-feature** — ~5-6h (Schätzung korrigiert, s.u.)
  - ⚠️ **REVIEW 2026-03-04: `class-etch-template-generator.php` ist KEIN reines Framer-File — Aufwand höher als ursprünglich geschätzt**
  - **Befund:** `EFS_Etch_Template_Generator` wird aktiv von `EFS_Template_Extractor_Service` genutzt (kein Framer-Only). Die Klasse hängt per DI von `framer_to_etch_converter` ab (`class-service-provider.php:381`). Datei kann nicht einfach gelöscht werden — sie muss refactored werden, um die Framer-Abhängigkeit zu entkoppeln.
  - **Zu löschende Dateien (4) — unverändert korrekt:**
    - `includes/templates/class-framer-html-sanitizer.php`
    - `includes/templates/class-framer-template-analyzer.php`
    - `includes/templates/class-framer-to-etch-converter.php`
    - `includes/views/template-extractor.php` (nur Framer-Disabled-Meldung)
  - **Zu refactoren statt zu löschen:**
    - `includes/templates/class-etch-template-generator.php` — `framer_to_etch_converter`-Abhängigkeit im Konstruktor entfernen; Framer-spezifische Methoden isolieren und löschen
    - `includes/templates/class-template-analyzer.php` — prüfen ob nur Framer nutzt oder auch anderes
  - **Code-Bereinigung in (5 Dateien):**
    - `includes/container/class-service-provider.php` — `framer_html_sanitizer`, `framer_template_analyzer`, `framer_to_etch_converter` Registrierungen entfernen (Zeilen ~360-381, ~928); `etch_template_generator` Singleton ohne Framer-DI neu verdrahten
    - `includes/ajax/handlers/class-template-ajax.php` — alle `efs_is_framer_enabled()` Guards + Framer-Response-Blöcke entfernen; prüfen ob die ganze Datei entfernt werden kann
    - `includes/controllers/class-template-controller.php` — alle Framer-Guards entfernen
    - `includes/controllers/class-dashboard-controller.php` — `framer_enabled` aus localized context entfernen
    - `includes/api_endpoints.php` — Framer-Endpunkte entfernen
  - **Reihenfolge:** (1) `EFS_Etch_Template_Generator` refactoren → Framer-Abhängigkeit lösen, (2) alle Usages entfernen, (3) Dateien löschen, (4) PHPCS laufen lassen

---

## ⚙️ Action Scheduler & Loopback Runner

> **Referenz:** `actionScheduler-review.md` — vollständige Architektur-Analyse dort.
> Gesamtbewertung: **9/10** — Implementierung ist ausgezeichnet. Nur 2 Kleinigkeiten offen.

- [ ] **add-cloud-ip-whitelist** (actionScheduler-review.md §5.3) — niedrige Priorität, ~1h
  - **Problem:** IP-Whitelist in `class-action-scheduler-loopback-runner.php:163` enthält kein `169.254.169.254` (AWS/GCP Instance Metadata Endpoint) — relevant für Cloud-Deployments
  - **Fix:** `169.254.169.254` zur `$is_localhost`-Prüfung oder als separates `$is_cloud_metadata` Flag hinzufügen
  - **Dateien:** `includes/services/class-action-scheduler-loopback-runner.php:163-165`

- [ ] **fix-headless-job-count-atomic** (actionScheduler-review.md §5.1) — niedrige Priorität, ~1h
  - **Problem:** `increment_headless_job_count()` in `class-progress-manager.php` nutzt Read-Modify-Write ohne Locking — bei parallelen Jobs kann der 50er-Schwellwert überschritten werden bevor die Warnung greift (kein kritischer Fehler, nur Warnung)
  - **Fix:** Atomares Inkrement via `$wpdb->query("UPDATE ... SET count = count + 1")` oder den Counter ganz entfernen wenn nur zur Diagnose genutzt
  - **Dateien:** `includes/services/class-progress-manager.php` (`increment_headless_job_count()`)

---

## 🏷️ Labels and Categories

### Priority Labels
- 🔴 **Critical**: Security issues, data loss, complete failure
- 🟡 **High**: Major functionality issues, performance problems
- 🟢 **Medium**: Feature gaps, usability issues
- 🔵 **Low**: Minor issues, enhancements, documentation

### Type Labels
- 🐛 **Bug**: Errors and unexpected behavior
- ✨ **Feature**: New functionality and capabilities
- 🔧 **Technical**: Code quality, performance, architecture
- 📚 **Documentation**: Guides, examples, API docs
- 🧪 **Testing**: Test coverage, test infrastructure
- 🎨 **UI/UX**: User interface and experience improvements

---

**Last Updated:** 2026-03-04 (Code-Review: fix-id-selector-regex veraltet, remove-framer-feature Aufwand korrigiert)
**Current Status:** Checkpoint Locking (10a) implementiert. Kritische Stabilitäts-Fixes (10b–10k) und CSS Converter Review Action Items stehen an. Detaillierte Analyse in `optimierungen.md` und `css-converter-review.md`. Zwei Todos nach Code-Review korrigiert: fix-id-selector-regex (Regex bereits korrekt im Code), remove-framer-feature (Aufwand 5-6h statt 3-4h).

**Maintainer:** Etch Fusion Suite Development Team
