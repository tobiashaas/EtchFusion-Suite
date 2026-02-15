# PHP-Konfiguration für Etch Fusion Suite

Für Composer und lokale Entwicklung solltest du folgende Anpassungen in deiner `php.ini` vornehmen (z. B. `C:\php8.4.16\php.ini`).

## 1. Zip-Erweiterung aktivieren (wichtig für Composer)

In der Sektion **Dynamic Extensions** die Zeile **ohne** führendes Semikolon eintragen:

```ini
extension=zip
```

Falls bei dir `extension_dir = "ext"` gesetzt ist, muss `C:\php8.4.16\ext\php_zip.dll` existieren (bei PHP 8.x oft bereits dabei).

## 2. Empfohlene Werte für Entwicklung

| Direktive | Produktion (dein aktuell) | Empfohlen (Entwicklung) |
|-----------|---------------------------|---------------------------|
| `memory_limit` | 128M | **256M** |
| `max_execution_time` | 30 | **60** |
| `post_max_size` | 8M | **64M** |
| `upload_max_filesize` | 2M | **64M** |
| `display_errors` | Off | **On** (nur lokal) |
| `display_startup_errors` | Off | **On** (nur lokal) |
| `error_reporting` | E_ALL & ~E_DEPRECATED | **E_ALL** |

## 3. Zeitzone setzen (optional, vermeidet Warnungen)

Unter `[Date]`:

```ini
date.timezone = Europe/Berlin
```

(Oder `UTC` / andere Zeitzone nach Bedarf.)

## 4. Referenzdatei

Die Datei `docs/php.ini-development-recommended.ini` enthält alle genannten Einstellungen als Kopiervorlage. Du kannst die relevanten Blöcke daraus in deine `php.ini` übernehmen.

## 5. Prüfen

Nach dem Speichern der `php.ini` in einer **neuen** Konsole:

```powershell
php -m
```

In der Liste sollte **zip** vorkommen.

```powershell
php -r "echo extension_loaded('zip') ? 'zip OK' : 'zip FEHLT';"
```

Sollte `zip OK` ausgeben. Danach:

```powershell
cd E:\Github\EtchFusion-Suite\etch-fusion-suite
composer install
```

## Hinweis

Die wp-env-Container (Docker) haben eine **eigene** PHP-Konfiguration. Die hier beschriebenen Änderungen betreffen nur dein **lokales** PHP (z. B. für `composer install` auf dem Host).
