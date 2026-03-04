# Dead Code & Cleanup Analyse

**Datum:** 2026-03-04  
**Umfang:** 145 PHP-Dateien in `/includes`

---

## 1. ✅ KORRIGIERT: Element-Converter Status

### Analyse-Korrektur (2026-03-04)

Die ursprüngliche Analyse war **falsch**. Button und Icon sind korrekt implementiert und registriert.

| Converter | Datei | Status | Aktion |
|-----------|-------|--------|--------|
| `EFS_Button_Converter` | `converters/elements/class-button.php` | ✅ Aktiv, in Factory registriert | Testen |
| `EFS_Icon_Converter` | `converters/elements/class-icon.php` | ✅ Aktiv, in Factory registriert | Testen |
| Bridge-Datei | `converters/elements/class-button-converter.php` | ❌ Nur `require_once` auf class-button.php | **ENTFERNEN** |
| Bridge-Datei | `converters/elements/class-icon-converter.php` | ❌ Nur `require_once` auf class-icon.php | **ENTFERNEN** |

**Factory-Registrierung** (`class-element-factory.php:55-56`):
```php
'button' => EFS_Button_Converter::class,  // ✅ Registriert
'icon'   => EFS_Icon_Converter::class,    // ✅ Registriert
```

---

## 2. 🔴 KRITISCH: Duplicate Error Handler

### Gefunden: 2 identische Error-Handler

| Datei | Namespace | Verwendet | Status |
|-------|-----------|-----------|--------|
| `core/class-error-handler.php` | `Bricks2Etch\Core` | **JA** (42 mal) | ✅ AKTIV |
| `error_handler.php` | `Bricks2Etch\Core` | **NEIN** | ❌ **TOT** |

**Prüfung:**
```bash
# Wird NICHT importiert in main plugin:
$ grep -r "require.*error_handler.php" .
# Keine Treffer!

# Wird importiert via Core:
$ grep -r "use Bricks2Etch\\Core\\EFS_Error_Handler" . --include="*.php" | wc -l
# 42 Treffer
```

**Empfehlung:** `error_handler.php` löschen (nur Duplikat)

---

## 3. 🟡 Duplikat: db-installer

| Datei | Verwendet | Status |
|-------|-----------|--------|
| `core/class-db-installer.php` | ✅ Ja (uninstall.php + tests) | AKTIV |
| `db-installer.php` | ❌ Nein | **TOT** |

**Empfehlung:** `db-installer.php` löschen

---

## 4. ✅ Fallbacks die korrekt sind

### 4.1 Action Scheduler
```php
// class-headlessness-migration-job.php:161
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
    return 0;
}
```
**Ergebnis:** Kann theoretisch entfernt werden da AS harte Dependency ist. **Aber:** defensive Prüfung ist harmlos - **NICHT entfernen** (schadet nicht).

### 4.2 Docker URL Helper
Alle 4 Fallback-Funktionen werden verwendet ✅

---

## 5. ✅ Kein WP-Cron - Korrekt implementiert

- `wp_schedule_event` → **NICHT verwendet** ✅
- `wp_cron` → **NICHT verwendet** ✅
- Loopback Runner → **Korrekt** ✅

---

## 6. Geprüfte und OK

### 6.1 Alle AJAX-Handler ✅
| Handler | Status |
|---------|--------|
| Migration | ✅ |
| Connection | ✅ |
| Logs | ✅ |
| Progress | ✅ |
| Pre-Flight | ✅ |
| Content | ✅ |
| CSS | ✅ |
| Media | ✅ |
| Wizard | ✅ |
| Template | ✅ |
| Debug | ✅ |
| Cleanup | ✅ |

### 6.2 Alle Interfaces ✅
- `Phase_Handler_Interface` ✅
- `Migrator_Interface` ✅
- `Migration_Repository_Interface` ✅
- `Checkpoint_Repository_Interface` ✅
- `Progress_Repository_Interface` ✅
- `Token_Repository_Interface` ✅
- `Style_Repository_Interface` ✅
- `Settings_Repository_Interface` ✅
- `Needs_Error_Handler` ✅
- `EFS_HTML_Sanitizer_Interface` ✅
- `EFS_Template_Analyzer_Interface` ✅
- `EFS_Template_Extractor_Interface` ✅

---

## 7. Zusammenfassung: Zu entfernende Dateien

### Sofort löschen (eindeutige Duplikate & Bridge-Dateien):

```bash
# 1. Redundante Bridge-Dateien (NUR die Bridge, NICHT die Converter selbst)
rm includes/converters/elements/class-button-converter.php
rm includes/converters/elements/class-icon-converter.php

# 2. Duplikat Error Handler
rm includes/error_handler.php

# 3. Duplikat DB Installer
rm includes/db-installer.php
```

**Summe: 4 Dateien sofort löschbar**

### Framer-Feature entfernen (aufwändiger, verworfen):

```bash
# Framer Template-Klassen (nach Cleanup der 5 abhängigen Dateien)
rm includes/templates/class-framer-html-sanitizer.php
rm includes/templates/class-framer-template-analyzer.php
rm includes/templates/class-framer-to-etch-converter.php
rm includes/views/template-extractor.php
# ggf. auch: class-etch-template-generator.php, class-template-analyzer.php
```

Vor dem Löschen: Framer-Referenzen aus `class-service-provider.php`, `class-template-ajax.php`, `class-template-controller.php`, `class-dashboard-controller.php`, `api_endpoints.php` entfernen.

---

## 8. Entscheidungen (2026-03-04)

1. **Button/Icon Converter** — Bereits implementiert und registriert. Bridge-Dateien entfernen. Converter testen.

2. **Framer Feature** — Verworfen. Vollständig entfernen (Klassen + alle Referenzen in 5 Dateien).

---

## 9. Empfohlene nächsten Schritte

| Schritt | Aktion | Aufwand |
|---------|--------|---------|
| 1 | `error_handler.php` + `db-installer.php` löschen | 2 min |
| 2 | Bridge-Dateien `class-button-converter.php` + `class-icon-converter.php` löschen | 2 min |
| 3 | Button/Icon Converter testen auf echter Migration | 2-4h |
| 4 | Framer-Referenzen aus 5 Dateien entfernen, dann Framer-Klassen löschen | 3-4h |
| 5 | PHPCS nach allen Löschungen laufen lassen | 10 min |
| 4 | Review ob Framer-Templates jemals aktiviert werden | 30 min |
| 5 | Nach weitere Duplikaten suchen | 2h |
