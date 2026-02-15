# Wo liegen die Daten?

Übersicht aller Datenorte im Etch-Fusion-Suite-Projekt (wp-env + Plugin).

---

## 1. WordPress-Instanzen (wp-env / Docker)

Die **Bricks-** und **Etch-**WordPress-Instanzen laufen in Docker-Containern. Die eigentlichen Daten (Datenbank, Uploads, `wp-content`) liegen in **Docker-Volumes**, die wp-env anlegt.

| Was | Wo (innerhalb der Container) |
|-----|------------------------------|
| **WordPress-Core** | `/var/www/html/` |
| **Plugin (gemountet)** | `/var/www/html/wp-content/plugins/etch-fusion-suite/` (von deinem lokalen Ordner) |
| **Uploads (Bricks)** | Im WordPress-Container unter `/var/www/html/wp-content/uploads/` |
| **Uploads (Etch)** | Im Tests-Container unter `/var/www/html/wp-content/uploads/` |
| **Debug-Log** | `/var/www/html/wp-content/debug.log` (in jedem Container) |
| **Datenbank Bricks** | MariaDB-Volume `mysql` → Container **bricks-mysql** |
| **Datenbank Etch** | MariaDB-Volume `mysql-test` → Container **etch-mysql** |

Die Volumes werden von Docker verwaltet. Ihren physischen Speicherort siehst du z. B. mit:

```bash
docker volume ls
docker volume inspect <volume-name>
```

(wp-env nutzt typischerweise einen Cache-Ordner, z. B. im Benutzerverzeichnis oder unter dem Projekt – abhängig von der wp-env-Version.)

---

## 2. Projektordner (etch-fusion-suite/)

| Daten | Pfad (relativ zu etch-fusion-suite/) |
|-------|--------------------------------------|
| **DB-Backups** | `backups/` (SQL-Dumps + `manifest.json`) |
| **Exportierte Etch-Posts/Seiten (JSON)** | `validation-results/` (z. B. `post-1.json`, `page-home.json`) |
| **Gespeicherte Logs** | `logs/` (gitignored; wird von `npm run logs:save` befüllt) |
| **PHPUnit/CI-Logs** | `build/logs/` (z. B. `junit.xml`, `clover.xml`) |
| **Test-Reports (Markdown)** | `test-reports/` |
| **Playwright-Berichte** | `playwright-report/`, `test-results/` |
| **Env-Diagnose (optional)** | `env-info-output.txt` (wenn du `npm run env:info > env-info-output.txt` ausführst) |
| **Konfiguration wp-env** | `.wp-env.json`, optional `.wp-env.override.json` |

---

## 3. Kurzreferenz

- **WordPress-Daten (DB, Uploads, Logs)** → in den **Docker-Volumes** der wp-env-Container (Bricks/Etch).
- **Backups der Datenbanken** → `etch-fusion-suite/backups/`.
- **Exportierte Inhalte zur Validierung** → `etch-fusion-suite/validation-results/`.
- **Gespeicherte Logs** → `etch-fusion-suite/logs/`.
- **Plugin-Code** → lokal in `etch-fusion-suite/` und in den Containern unter `wp-content/plugins/etch-fusion-suite/` gemountet.

Backup erstellen: `npm run db:backup` (legt Dateien in `backups/` ab).  
Wiederherstellen: `npm run db:restore` (liest aus `backups/`).
