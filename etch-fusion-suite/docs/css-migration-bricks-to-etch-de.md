# CSS Migration Bricks -> Etch

Stand: 2026-03-08

Diese Anleitung beschreibt den kompletten CSS-Migrationspfad in Etch Fusion Suite:

- wo Bricks seine CSS-Daten ablegt
- wie daraus Etch-kompatible Styles erzeugt werden
- welche Daten auf Source und Target gespeichert werden
- wie die Builder-Zuordnung funktioniert
- woran man Fehler erkennt
- wie man sie systematisch debuggt

Die Datei ist bewusst technisch gehalten. Ziel ist nicht Marketing, sondern eine belastbare Arbeitsanleitung fuer Debugging, Wartung und Regression-Checks.

## 1. Kurzfassung

Die CSS-Migration besteht nicht nur aus "CSS rueberschicken".

Tatsaechlich muessen immer drei Dinge konsistent sein:

1. Der Bricks-Klassen-Identifier muss in `efs_style_map` vorhanden sein.
2. Die dort referenzierte Etch-Style-ID muss ein echter Key in `etch_styles` sein.
3. Dieselbe Etch-Style-ID muss spaeter im migrierten Etch-/Gutenberg-Block unter `styles[]` landen.

Wenn nur die sichtbare CSS-Klasse im Frontend-DOM steht, aber Punkt 2 oder 3 fehlt oder driftet, sieht das Frontend oft noch "okay" aus, der Etch Builder kann die Klasse aber nicht korrekt aufloesen.

Das ist die wichtigste Invariante der gesamten CSS-Migration.

## 2. Begriffe

Es gibt drei verschiedene ID-/Styling-Systeme, die nicht verwechselt werden duerfen.

### 2.1 Element-IDs

Beispiel:

- Bricks: `brxe-abc1234`
- Etch: `etch-abc1234`

Das sind HTML-IDs einzelner Elemente. Sie werden fuer element-spezifische Selektoren wie `#brxe-...` oder `#etch-...` verwendet.

### 2.2 Bricks Global Class IDs

Bricks speichert globale Klassen nicht nur ueber den Namen, sondern auch ueber eine interne ID.

Beispiel:

- Bricks Klassenname: `card`
- Bricks Klassen-ID: `efs-probe-card-id`

Diese ID ist der primaere Link zwischen Bricks-Content und `efs_style_map`.

### 2.3 Etch Style Manager IDs

Etch speichert wiederverwendbare Styles unter internen Schluesseln wie:

- `2af6e4f`
- `etch-section-style`

Die "normalen" migrierten Bricks-Klassen bekommen eine Etch-Style-ID wie `2af6e4f`.
Etch-Framework-Styles wie `etch-section-style` sind interne Read-only-Styles ohne Bricks-Gegenstueck.

## 3. End-to-End-Fluss

### 3.1 Source-Seite

1. Bricks-Klassen werden aus den WordPress-Daten gelesen.
2. Referenzierte Klassen werden optional gegen die ausgewaehlten Post Types gefiltert.
3. Strukturierte Bricks-Settings werden in CSS umgerechnet.
4. Rohes Custom CSS und Breakpoint-CSS werden zusaetzlich geparst und gemerged.
5. Daraus entsteht ein Payload:
   - `styles`
   - `style_map`
6. Dieser Payload wird an den Target-Endpoint `/wp-json/efs/v1/import/css-classes` gesendet.
7. Separat wird globales Bricks-CSS aus `bricks_global_settings['customCss']` an `/wp-json/efs/v1/import/global-css` gesendet.

### 3.2 Target-Seite

1. Der CSS-Payload wird importiert.
2. Styles werden nach Selector dedupliziert.
3. Vorhandene `etch_styles` werden mit den neuen Styles gemerged.
4. `efs_style_map` wird gespeichert.
5. Etch-Caches werden invalidiert und ein CSS-Rebuild wird angestossen.
6. Bei der spaeteren Content-Migration werden Style-IDs aus `efs_style_map` in Block-`styles[]` geschrieben.

## 4. Wo liegen die Daten?

## 4.1 Source: Bricks-Daten

Die zentralen Datenquellen fuer CSS auf der Bricks-Seite sind:

| Ort | Zweck |
| --- | --- |
| `bricks_global_classes` | globale Bricks-Klassen inklusive interner ID, Name und Settings |
| `bricks_global_settings['customCss']` | site-weites globales Bricks-CSS |
| `efs_active_migration['options']` | aktive Migrationsoptionen wie `selected_post_types` und `restrict_css_to_used` |
| Post Meta `_bricks_page_content_2` / `_bricks_page_content` | Bricks-Elementbaum, darin Klassenreferenzen |
| Post Meta `_bricks_settings` / `_bricks_page_settings` | Wrapper-/Seiteneinstellungen mit weiteren Klassenreferenzen |
| `efs_inline_css_*` | temporaere Inline-CSS-Optionen aus dem Parsing von Code-/Element-CSS |
| `efs_acss_inline_style_map` | Hilfsmap fuer ACSS-Utilities und Inline-Styles |

## 4.2 Target: Etch-Daten

Die relevanten Ziel-Daten sind:

| Ort | Zweck |
| --- | --- |
| `etch_styles` | Hauptregistry fuer Etch-Styles |
| `efs_style_map` | Mapping Bricks-Klassen-ID -> `{ id, selector }` |
| `etch_global_stylesheets` | globales Etch-CSS, hier landet Bricks `customCss` |
| `etch_svg_version` | Cache-Buster fuer Etch-Rebuild |
| `efs_receiving_migration` | Target-Fortschritt waehrend des Imports |

## 4.3 Repositories und Caches

Der Zugriff auf die CSS-Daten laeuft ueber `includes/repositories/class-wordpress-style-repository.php`.

Wichtige Caches/Transients:

- `efs_cache_etch_styles`
- `efs_cache_style_map`
- `efs_cache_global_stylesheets`
- `efs_cache_svg_version`
- `efs_cache_bricks_global_classes`

## 5. Welche Dateien sind fachlich wichtig?

| Datei | Aufgabe |
| --- | --- |
| `includes/services/class-css-service.php` | Orchestrierung der CSS-Migration und des Global-CSS-Transports |
| `includes/css_converter.php` | eigentliche Conversion von Bricks -> Etch |
| `includes/css/class-class-reference-scanner.php` | scannt referenzierte globale Klassen aus Bricks-Content |
| `includes/css/class-style-importer.php` | importiert `styles` + `style_map` in den Target |
| `includes/class-migration-endpoints.php` | REST-Endpunkte fuer CSS-Import und Global-CSS-Import |
| `includes/api_client.php` | Versand der CSS-Payloads an den Target |
| `includes/gutenberg_generator.php` | schreibt Style-IDs in die migrierten Blocks |
| `includes/repositories/class-wordpress-style-repository.php` | Speicherung von `etch_styles`, `efs_style_map`, `etch_global_stylesheets` |

## 6. Wie die Source Bricks-Klassen findet

Die Discovery startet nicht blind, sondern mit Auswahl- und Referenzlogik.

### 6.1 Ausgangspunkt

Die globale Klassenliste kommt aus:

```php
get_option( 'bricks_global_classes', array() )
```

Jeder Eintrag enthaelt typischerweise:

- `id`
- `name`
- `settings`

### 6.2 Restrict-to-used

Standardmaessig ist CSS-Migration auf tatsaechlich referenzierte Klassen eingeschraenkt.

Relevant:

- `efs_active_migration['options']['selected_post_types']`
- `efs_active_migration['options']['restrict_css_to_used']`

Wenn `restrict_css_to_used` aktiv ist, scannt der `Class Reference Scanner` alle relevanten Bricks-Posts und sammelt Referenzen aus:

- `_cssGlobalClasses`
- `_cssClasses`
- `_bricks_settings`
- `_bricks_page_settings`

Dadurch werden nur Klassen migriert, die in den ausgewaehlten Post Types wirklich vorkommen.

### 6.3 Warum das wichtig ist

Wenn die Vorschau/Init-Totals alle Bricks-Klassen zaehlen, die eigentliche Migration aber nur die verwendeten Klassen sendet, stimmen Progress und Receiving-Status nicht mehr. Genau dieser Fall war bereits ein realer Bug.

Darum muessen:

- Wizard-Vorschau
- Init-Totals
- Conversion-Payload
- Receiving-Counter

immer dieselbe Zaehllogik verwenden.

## 7. Wie die Conversion funktioniert

Die eigentliche Conversion laeuft in `convert_bricks_classes_to_etch()`.

## 7.1 Etch-Builtin-Styles zuerst

Zu Beginn werden Etch-interne Framework-Styles in `etch_styles` vorbefuellt.

Typische Keys:

- `etch-section-style`
- `etch-container-style`
- `etch-iframe-style`
- `etch-block-style`

Wichtig:

- diese Styles sind Read-only
- sie gehoeren nie in `efs_style_map`
- sie haben kein Bricks-Gegenstueck

## 7.2 ACSS-Sonderfall

ACSS-Utilities werden anders behandelt als "normale" Bricks-Klassen:

- es wird ein Inline-Style-Map in `efs_acss_inline_style_map` aufgebaut
- zugleich wird ein leerer Stub in `etch_styles` angelegt, damit die Klasse im Builder sichtbar bleibt
- fuer jede Bricks-Klassen-ID wird trotzdem ein `efs_style_map`-Eintrag erzeugt

Das bedeutet:

- manche ACSS-Klassen haben absichtlich leeres `css` in `etch_styles`
- das ist kein Fehler
- die Klasse ist trotzdem fuer Builder und Content-Mapping registriert

## 7.3 Strukturierte Settings -> CSS

Fuer normale Bricks-Klassen werden die strukturierten `settings` in CSS umgerechnet:

- Spacing
- Typography
- Background
- Border
- Flex/Grid
- Positioning
- Effects
- Responsive Varianten

Danach wird CSS normalisiert, zum Beispiel:

- Variablen
- logische Properties statt physischer Properties
- finale CSS-Korrekturen

## 7.4 Stabile Style-IDs

Ein entscheidender Punkt ist die Vergabe der Etch-Style-ID.

Die ID darf nicht bei jedem Lauf zufaellig neu sein, wenn dieselbe Bricks-Klasse bereits ein bekanntes Mapping hat. Sonst driftet spaeter:

- `efs_style_map`
- `etch_styles`
- block `styles[]`

Deshalb versucht die Conversion, vorhandene IDs aus einem bestehenden `style_map` wiederzuverwenden.

## 7.5 Rohes Custom CSS

Zusaetzlich zum strukturierten Settings-CSS sammelt die Conversion rohes Bricks-CSS:

- `_cssCustom`
- `_cssCustom:<breakpoint>`
- elementbezogenes Inline-CSS

Dieses CSS wird in eine temporaere Stylesheet-Zeichenkette geschrieben und dann erneut geparst.

Dabei werden:

- Klassenregeln auf Style-IDs gemappt
- ID-Regeln fuer element-spezifische Styles extrahiert
- verschachtelte Selektoren normalisiert
- logische Properties nachgezogen

## 7.6 Breakpoint-CSS

Breakpoint-CSS wird nicht sofort fest in die Basisregel gemischt, sondern zuerst separat gesammelt und spaeter an den richtigen Style angehaengt.

Dadurch bleiben:

- Responsive-Regeln erhalten
- Media Queries korrekt den migrierten Klassen zugeordnet

## 7.7 Nachbearbeitung

Vor dem Rueckgabepayload passiert noch:

- finale CSS-Normalisierung
- Injection von `display:flex` fuer bestimmte Block-Faelle
- Speichern von `efs_style_map`
- Speichern von `efs_acss_inline_style_map`

## 8. Wie das Payload aussieht

Die CSS-Migration arbeitet mit einem Payload im Format:

```json
{
  "styles": {
    "2af6e4f": {
      "type": "class",
      "selector": ".efs-probe-card",
      "collection": "default",
      "css": "..."
    },
    "etch-section-style": {
      "type": "class",
      "selector": "",
      "readonly": true,
      "css": "..."
    }
  },
  "style_map": {
    "efs-probe-card-id": {
      "id": "2af6e4f",
      "selector": ".efs-probe-card"
    }
  }
}
```

Wichtig:

- `styles` ist die Etch-Registry
- `style_map` ist die Bricks->Etch-Uebersetzung
- `style_map[*].id` muss auf einen echten Key in `styles` zeigen

## 9. API-Transfer

### 9.1 Klassen-CSS

Die Source sendet Klassen-CSS an:

```text
/wp-json/efs/v1/import/css-classes
```

Der Versand erfolgt ueber `EFS_API_Client::send_css_styles()`.

Fuer Progress wird die CSS-Menge nicht aus HTTP-Requests, sondern aus dem Payload bestimmt:

- bevorzugt `count(style_map)`
- fallback `count(styles)`

Das ist absichtlich so, weil fuer die Builder-Zuordnung die Anzahl der Klassen-Mappings relevant ist, nicht die Anzahl der Requests.

### 9.2 Global CSS

Bricks `customCss` wird separat an:

```text
/wp-json/efs/v1/import/global-css
```

gesendet.

Payload:

```json
{
  "id": "bricks-global",
  "name": "Migrated from Bricks",
  "css": "....",
  "type": "custom"
}
```

Dieses CSS landet nicht in `etch_styles`, sondern in `etch_global_stylesheets`.

## 10. Wie der Target importiert

Der Target-Endpoint `import_css_classes()` ruft intern `import_etch_styles()` auf.

Die Importlogik besteht aus vier Kernschritten.

### 10.1 Deduplication nach Selector

Wenn zwei eingehende Style-Eintraege denselben Selector haben, wird ihr CSS gemerged.

Beispiel:

- settings-basierter Style fuer `.card`
- `_cssCustom`-Nachschlag fuer `.card`

Beide werden auf einen zusammengefuehrten Eintrag konsolidiert.

### 10.2 Merge mit bestehenden `etch_styles`

Vorhandene `etch_styles` bleiben Basis.
Eingehende Styles ueberschreiben pro Selector die alten Eintraege.

Das ist wichtig fuer Re-Migrationen:

- alte Ziel-Stile werden aktualisiert
- unbetroffene manuelle Etch-Stile bleiben erhalten

### 10.3 Storage-Key bewahren

Der kritischste Teil des Imports:

`etch_styles` darf nicht als numerisch reindexiertes Array gespeichert werden.

Stattdessen muessen die Style-Keys erhalten bleiben, weil:

- `efs_style_map[*].id` auf diese Keys zeigt
- Block-`styles[]` ebenfalls diese IDs verwenden

Wenn importierte Styles ihre Keys verlieren und nur noch nach Selector oder numerischem Index gespeichert werden, entsteht genau der Fehler:

- Klasse sichtbar im Frontend-DOM
- Builder kann sie nicht korrekt zuordnen

### 10.4 Cache-Rebuild

Nach dem Speichern passiert:

- Invalidation von Style-Caches
- Erhoehung von `etch_svg_version`
- Hooks:
  - `etch_fusion_suite_styles_updated`
  - `etch_fusion_suite_rebuild_css`

Das sorgt dafuer, dass Etch das neue CSS neu rendert.

## 11. Wie Content spaeter dieselben Styles referenziert

Die CSS-Migration ist nur die halbe Arbeit. Die andere Haelfte ist die Content-Migration.

Wenn Bricks-Elemente spaeter in Etch-/Gutenberg-Blocks umgewandelt werden, werden Style-IDs ueber `efs_style_map` aufgeloest.

### 11.1 Von Bricks-ID zu Etch-Style-ID

Bricks-Elemente tragen oft:

- `_cssGlobalClasses`
- oder normale Klassen in `_cssClasses`

Die Content-Generatoren suchen dafuer:

- Bricks-Klassen-ID
- dann `efs_style_map[bricks_id].id`
- dann landet diese Etch-ID in `styles[]`

### 11.2 Von Style-ID zu sichtbarer Klasse

Fuer `attributes.class` oder vergleichbare sichtbare Klassen wird spaeter wieder rueckwaerts ueber `efs_style_map[*].selector` gearbeitet.

Das bedeutet:

- `styles[]` ist Builder-/Etch-intern
- `attributes.class` ist das sichtbare CSS-Klassenlabel im DOM

Beides muss zusammenpassen, aber sie sind nicht dasselbe.

## 12. Builder-Invariante: Was immer stimmen muss

Fuer jede migrierte Bricks-Klasse muessen diese drei Stellen denselben logischen Style abbilden:

```text
efs_style_map[bricks_class_id].id
==
Key in etch_styles
==
Eintrag in block.styles[]
```

Beispiel:

```json
{
  "efs_style_map": {
    "efs-probe-card-id": {
      "id": "2af6e4f",
      "selector": ".efs-probe-card"
    }
  },
  "etch_styles": {
    "2af6e4f": {
      "selector": ".efs-probe-card"
    }
  },
  "block": {
    "styles": ["etch-section-style", "2af6e4f"],
    "attributes": {
      "class": "efs-probe-card"
    }
  }
}
```

Wenn nur `attributes.class = "efs-probe-card"` stimmt, aber `styles[]` oder `etch_styles` nicht zu derselben ID passen, ist die Builder-Zuordnung defekt.

## 13. Global CSS: Sonderfall

Globales Bricks-CSS ist nicht Teil von `style_map`.

Das CSS lebt separat:

- Source: `bricks_global_settings['customCss']`
- Target: `etch_global_stylesheets['bricks-global']`

Deshalb gilt:

- fehlendes globales CSS ist ein anderer Fehler als fehlende Klassen-Zuordnung
- `customCss` beeinflusst nicht direkt die Builder-Aufloesung einzelner Klassen-IDs

## 14. Was bei Re-Migrationen passieren muss

Ein korrekter Re-Run darf nicht zu neuen, driftenden IDs fuehren.

Richtiges Verhalten:

- bestehende Style-ID fuer bekannte Bricks-Klasse wiederverwenden
- vorhandene `etch_styles` selektorbasiert aktualisieren
- `efs_style_map` konsistent erneuern

Falsches Verhalten:

- neue zufaellige Style-ID trotz bestaetigter alter Zuordnung
- `etch_styles` unter anderem Key speichern als `style_map` referenziert
- numerisches Reindexing waehrend des Imports

## 15. Typische Fehlerbilder

### 15.1 Klasse im Frontend sichtbar, aber nicht im Etch Builder

Fast immer eine dieser Ursachen:

- `efs_style_map[*].id` zeigt auf keinen echten Key in `etch_styles`
- `styles[]` im Block enthaelt eine andere ID als `efs_style_map`
- `style_map` wurde gespeichert, aber `etch_styles` mit falschen Keys importiert

### 15.2 CSS im Builder vorhanden, aber im Frontend nicht aktiv

Moegliche Ursachen:

- Etch-Caches nicht invalidiert
- CSS-Rebuild nicht getriggert
- Style existiert, wird aber vom Block nicht in `styles[]` referenziert
- globales CSS wurde nach `etch_global_stylesheets` geschrieben, aber die konkrete Klasse steckt eigentlich in `etch_styles`

### 15.3 CSS-Progress falsch

Typische Ursachen:

- Init-Totals zaehlen "alle Bricks-Klassen"
- der echte Payload sendet aber nur "verwendete Klassen"
- Receiving-State zaehlt Requests statt Style-Mappings

### 15.4 ACSS-Klasse sichtbar, aber `css` in `etch_styles` leer

Das kann korrekt sein.

Bei ACSS werden oft:

- Builder-Stubs in `etch_styles`
- echte Declarations in `efs_acss_inline_style_map`

verwendet.

## 16. Systematische Debug-Checkliste

Wenn CSS falsch aussieht oder Builder-Zuordnung fehlt, immer in dieser Reihenfolge pruefen.

### 16.1 Source: Gibt es ueberhaupt Klassen?

```powershell
docker exec bricks-cli wp option get bricks_global_classes --path=/var/www/html --format=json
```

Pruefen:

- existiert die erwartete Bricks-Klasse?
- hat sie `id`, `name`, `settings`?

### 16.2 Source: Gibt es globales Bricks-CSS?

```powershell
docker exec bricks-cli wp option get bricks_global_settings --path=/var/www/html --format=json
```

Pruefen:

- existiert `customCss`?
- ist es leer?

### 16.3 Source: Ist CSS auf verwendete Klassen eingeschraenkt?

```powershell
docker exec bricks-cli wp option get efs_active_migration --path=/var/www/html --format=json
```

Pruefen:

- `selected_post_types`
- `restrict_css_to_used`

### 16.4 Source: Wurde ein `style_map` erzeugt?

```powershell
docker exec bricks-cli wp option get efs_style_map --path=/var/www/html --format=json
```

Pruefen:

- gibt es den Bricks-Key?
- steht dort `id` + `selector`?

### 16.5 Target: Wurde dieselbe ID in `etch_styles` gespeichert?

```powershell
docker exec etch-cli wp option get etch_styles --path=/var/www/html --format=json
```

Pruefen:

- existiert der Key aus `efs_style_map[*].id` wirklich?
- passt der Selector?

### 16.6 Target: Wurde globales CSS gespeichert?

```powershell
docker exec etch-cli wp option get etch_global_stylesheets --path=/var/www/html --format=json
```

Pruefen:

- gibt es `bricks-global`?
- steht dort das erwartete CSS?

### 16.7 Wurde der CSS-Endpoint ueberhaupt getroffen?

```powershell
docker logs etch --since 10m 2>&1 | Select-String -Pattern '/wp-json/efs/v1/import/css-classes|/wp-json/efs/v1/import/global-css'
```

Pruefen:

- kam `200` fuer `import/css-classes`?
- kam `200` fuer `import/global-css`?

### 16.8 Stimmen Blockdaten und Style-IDs?

Den migrierten Post/Block im Target pruefen und sicherstellen:

- Block `styles[]` enthaelt die Etch-Style-ID
- `attributes.class` enthaelt die sichtbare CSS-Klasse
- dieselbe ID existiert in `etch_styles`

## 17. Sichere Reset-/Recovery-Schritte

Wenn die CSS-Migration bewusst komplett neu aufgesetzt werden soll:

1. Vorher aktuelle Optionen sichern:
   - `etch_styles`
   - `efs_style_map`
   - `etch_global_stylesheets`
2. Danach nur gezielt resetten, nicht blind die ganze Umgebung zerstoeren.
3. Erst nach dem Reset neu migrieren.
4. Direkt danach die Invariante pruefen:
   - `efs_style_map`
   - `etch_styles`
   - ein realer migrierter Block

Gefaehrlich ist:

- `etch_styles` loeschen, aber `efs_style_map` stehenlassen
- `efs_style_map` loeschen, aber alte Blockdaten behalten
- Target-CSS resetten, ohne Content neu zu migrieren

## 18. Was in Zukunft nie wieder kaputtgehen darf

Diese Regeln sollten bei jedem Fix und jedem Refactor gelten:

1. `style_map` ist kein Nice-to-have, sondern der zentrale Join zwischen Bricks und Etch.
2. Import darf Style-Keys nie verlieren oder numerisch neu indexieren.
3. Content-Generator und CSS-Importer muessen dieselbe Etch-Style-ID sehen.
4. Progress-Zaehlung muss auf denselben CSS-Einheiten basieren wie die echte Migration.
5. Globales CSS und Klassen-CSS sind zwei getrennte Datenstroeme.
6. ACSS darf nicht wie normale Bricks-Klassen behandelt werden.
7. Re-Migrationen muessen ID-stabil bleiben.

## 19. Empfohlene Regression-Checks nach Aenderungen

Nach jeder Aenderung im CSS-Pfad mindestens das hier pruefen:

1. Eine normale Bricks-Global-Class wird importiert.
2. `efs_style_map[bricks_id].id` existiert in `etch_styles`.
3. Ein migrierter Block referenziert dieselbe ID in `styles[]`.
4. Die sichtbare Klasse steht im DOM.
5. Die Klasse ist im Etch Builder korrekt aufloesbar.
6. Bricks `customCss` erscheint in `etch_global_stylesheets['bricks-global']`.
7. Re-Migration derselben Klasse erzeugt keine driftende neue ID.
8. ACSS-Klassen erscheinen weiterhin korrekt.

## 20. Weiterfuehrende Dateien

Fuer tiefere Architektur-Details:

- `docs/css-converter-architecture.md`
- `docs/etch-data-schema-reference.md`
- `docs/wo-liegen-die-daten.md`

Fuer den produktiven CSS-Migrationspfad im Code:

- `includes/services/class-css-service.php`
- `includes/css_converter.php`
- `includes/css/class-style-importer.php`
- `includes/class-migration-endpoints.php`
- `includes/api_client.php`
- `includes/gutenberg_generator.php`
- `includes/repositories/class-wordpress-style-repository.php`
