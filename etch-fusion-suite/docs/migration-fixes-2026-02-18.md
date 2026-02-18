# Migration Fixes (2026-02-18)

Kurze Zusammenfassung der umgesetzten Fixes rund um Bricks -> Etch.

## CSS/Struktur

- Klassen-Parsing für Custom CSS robuster gemacht:
  - exakte Klassen-Grenzen statt Prefix-Match, damit z. B. `__controls` nicht falsch in Basisklassen landet.
- Escaped Kommentar-/Selektor-Folgen in Custom-CSS-Pfaden stabilisiert (kein fehlerhaftes Selector-Matching mehr).
- Shorthand-Verbesserungen:
  - `padding`/`padding-inline`/`padding-block` werden konsistent zusammengefasst.
  - `margin`/`margin-inline`/`margin-block` ebenso.
- `_background` aus Bricks-Settings wird wieder korrekt gelesen (fehlende Background-Farben behoben).
- Deprecated `*-hsl` Token werden zu ACSS4-kompatiblen Werten normalisiert (`color-mix` / neue Variablenform).

## Klassen-Migration

- Referenced-only CSS-Migration als Standard aktiviert (verhindert „alle Klassen werden importiert“).
- Referenz-Scan erweitert: neben `_cssGlobalClasses` jetzt auch `_cssClasses` berücksichtigt.
  - Dadurch bleiben Klassen wie `fr-accent-heading` erhalten, wenn sie direkt als Klassenname verwendet werden.
- Ausschluss-/Transferlogik aktualisiert:
  - gewünschte Utility-Ausschlüsse (`fr-note`, `text--l`, etc.) bleiben wirksam.
  - `fr-accent-heading` wurde wieder zugelassen.

## `brxe-block` / Flex-Fallback

- Fallback-Logik auf `brxe-block` korrigiert (statt `brx-block`).
- Regelwerk:
  - bei genau 1 Nachbarklasse: fehlende Layout-Properties werden auf diese Klasse gemerged.
  - bei 0 oder >=2 Nachbarklassen: Inline-Fallback.
- Layout-Entscheidung differenziert:
  - `grid` wird respektiert (kein erzwungenes flex),
  - `display:flex` + fehlende `flex-direction` wird ergänzt (`column`),
  - unnötige Inline-Styles werden reduziert.

## Image/Wrapper-Fix

- Für bildbezogene Wrapper-Klassen werden `object-fit` und `aspect-ratio` in einen verschachtelten `img { ... }` Block verschoben.
  - Hintergrund: Bricks legt diese Werte oft am `figure`-Wrapper ab, Etch rendert das eigentliche Bild als inneres `img`.

## Responsive-Fix

- Breakpoint-Suffixe wie `_gridTemplateColumns:mobile_landscape` werden wieder korrekt erkannt.
  - Fehlende mobile `var(--grid-1)` Overrides sind damit wieder da.

## Loop-Standardisierung

- Loop-Migration auf Etch-Standard vereinheitlicht:
  - `etch/loop` nutzt standardmäßig `loopId: "posts"`.
  - Bricks-spezifische Query-Parameter werden bewusst nicht mehr 1:1 übertragen.

## Media-Migration

- Media-ID-Chunking/Zuordnung stabilisiert.
- Media-Migration auf ausgewählte Post Types begrenzt (keine unnötigen Nebenläufe).
