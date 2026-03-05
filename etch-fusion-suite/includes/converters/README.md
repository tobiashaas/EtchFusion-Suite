# Element Converters Documentation

**Last Updated:** 2025-02-08  
**Version:** 0.5.0

---

## 📋 Übersicht

Die Element Converters sind modulare Klassen, die Bricks-Elemente in Etch-kompatible Gutenberg-Blöcke konvertieren.

**Regel:** Alle Änderungen an Convertern müssen hier dokumentiert werden. Alte/überholte Informationen werden entfernt.

---

## 🏗️ Architektur

### **Factory Pattern**

```
Element Factory
    ↓
Converter auswählen basierend auf Element-Typ
    ↓
Converter konvertiert Element
    ↓
Gutenberg Block HTML
```

### **Vererbung**

```
B2E_Base_Element (Abstract)
    ↓
├── B2E_Element_Container
├── B2E_Element_Section
├── B2E_Element_Heading
├── B2E_Element_Paragraph
├── B2E_Element_Image
└── B2E_Element_Div
```

---

## 📁 Dateistruktur

```
converters/
├── README.md                       # Diese Datei
├── class-base-element.php          # Abstract base class
├── class-element-factory.php       # Factory für Converter-Auswahl
└── elements/
    ├── class-container.php         # Container (ul, ol support)
    ├── class-section.php           # Section
    ├── class-heading.php           # Headings (h1-h6)
    ├── class-paragraph.php         # Text/Paragraph
    ├── class-image.php             # Images (figure tag!)
    ├── class-div.php               # Div/Flex-Div (li support)
    ├── class-code.php              # Code (JS→script.code, CSS→style, PHP→warning)
    └── class-notes.php             # Notes → HTML comment (fr-notes)
```

---

## 🔧 Base Element Class

**Datei:** `class-base-element.php`

### **Zweck**

Abstract base class mit gemeinsamer Logik für alle Converter.

### **Wichtige Methoden**

#### `get_style_ids($element)`
Extrahiert Style IDs aus Bricks Global Classes.

```php
protected function get_style_ids($element)
```

**Input:** Bricks Element mit `settings._cssGlobalClasses`  
**Output:** Array von Etch Style IDs

#### `get_css_classes($style_ids)`
Konvertiert Style IDs zu CSS-Klassennamen.

```php
protected function get_css_classes($style_ids)
```

**Input:** Array von Style IDs  
**Output:** String mit space-separated CSS classes

**Wichtig:** Überspringt Etch-interne Styles (`etch-*`)

#### `get_tag($element, $default)`
Holt HTML-Tag aus Element Settings.

```php
protected function get_tag($element, $default = 'div')
```

**Input:** Element, Default-Tag  
**Output:** HTML Tag (z.B. 'ul', 'section', 'h2')

#### `build_attributes($label, $style_ids, $etch_attributes, $tag)`
Erstellt Gutenberg Block Attributes.

```php
protected function build_attributes($label, $style_ids, $etch_attributes, $tag = 'div')
```

**Output:**
```php
array(
    'metadata' => array(
        'name' => $label,
        'etchData' => array(
            'origin' => 'etch',
            'styles' => $style_ids,
            'attributes' => $etch_attributes,
            'block' => array('type' => 'html', 'tag' => $tag)
        )
    ),
    'tagName' => $tag  // Nur wenn $tag !== 'div'
)
```

### **Abstract Method**

```php
abstract public function convert($element, $children = array());
```

Muss von allen Convertern implementiert werden.

---

## 🏭 Element Factory

**Datei:** `class-element-factory.php`

### **Zweck**

Factory Pattern für automatische Converter-Auswahl basierend auf Element-Typ.

### **Element-Typ Mapping**

```php
'container'   => EFS_Element_Container
'section'     => EFS_Element_Section
'heading'     => EFS_Element_Heading
'text-basic'  => EFS_Element_Paragraph
'text'        => EFS_Element_Text   // Überschreibt text-basic für Rich Text
'image'       => EFS_Element_Image
'div'         => EFS_Element_Div
'block'       => EFS_Element_Div     // Bricks 'block' = Etch 'flex-div'
'html'        => EFS_Element_Html
'shortcode'   => EFS_Element_Shortcode
'text-link'   => EFS_Element_TextLink
```

### **Verwendung**

```php
// Factory initialisieren
$style_map = get_option('efs_style_map', array());
$factory = new B2E_Element_Factory($style_map);

// Element konvertieren
$html = $factory->convert_element($element, $children);
```

---

## 📦 Container Converter

**Datei:** `elements/class-container.php`

### **Zweck**

Konvertiert Bricks Container zu Etch Container.

### **Features**

- ✅ Unterstützt custom tags (`ul`, `ol`, etc.)
- ✅ Fügt `etch-container-style` hinzu
- ✅ CSS Klassen in `attributes.class`
- ✅ `tagName` für Gutenberg wenn nicht `div`

### **Beispiel**

**Input (Bricks):**
```php
array(
    'name' => 'container',
    'settings' => array(
        'tag' => 'ul',
        '_cssGlobalClasses' => array('bTySculwtsp')
    )
)
```

**Output (Gutenberg):**
```html
<!-- wp:group {"tagName":"ul","metadata":{"etchData":{...}}} -->
<div class="wp-block-group">
  <!-- children -->
</div>
<!-- /wp:group -->
```

**Frontend:**
```html
<ul data-etch-element="container" class="fr-feature-grid">
  <!-- children -->
</ul>
```

### **Wichtige Änderungen**

**2025-10-22 00:38:** Custom tag support hinzugefügt (ul, ol)

---

## 🎯 Section Converter

**Datei:** `elements/class-section.php`

### **Zweck**

Konvertiert Bricks Section zu Etch Section.

### **Features**

- ✅ Unterstützt custom tags (`section`, `header`, `footer`, etc.)
- ✅ Fügt `etch-section-style` hinzu
- ✅ CSS Klassen in `attributes.class`

### **Standard-Tag**

Default: `section`

### **Wichtige Änderungen**

**2025-10-22 00:38:** Initial implementation

---

## 📝 Heading Converter

**Datei:** `elements/class-heading.php`

### **Zweck**

Konvertiert Bricks Heading zu Gutenberg Heading.

### **Features**

- ✅ Unterstützt h1-h6
- ✅ Level-Attribut für Gutenberg
- ✅ Text-Content aus Bricks
- ✅ CSS Klassen

### **Beispiel**

**Input:**
```php
array(
    'name' => 'heading',
    'settings' => array(
        'text' => 'Your heading',
        'tag' => 'h2'
    )
)
```

**Output:**
```html
<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Your heading</h2>
<!-- /wp:heading -->
```

### **Wichtige Änderungen**

**2025-10-22 00:38:** Initial implementation

---

## 📄 Paragraph Converter

**Datei:** `elements/class-paragraph.php`

### **Zweck**

Konvertiert Bricks Text/Paragraph zu Gutenberg Paragraph.

### **Features**

- ✅ Text-Content aus Bricks
- ✅ HTML-Content mit `wp_kses_post()`
- ✅ CSS Klassen

### **Standard-Tag**

Default: `p`

### **Wichtige Änderungen**

**2025-10-22 00:38:** Initial implementation

---

## 🖼️ Image Converter

**Datei:** `elements/class-image.php`

### **Zweck**

Konvertiert Bricks Image zu Gutenberg Image.

### **Features**

- ✅ **WICHTIG:** Verwendet `figure` tag, nicht `img`!
- ✅ Image ID und URL aus Bricks
- ✅ Alt-Text Support
- ✅ CSS Klassen

### **Warum 'figure'?**

Etch rendert Images als `<figure>` Container mit `<img>` darin. Das `block.tag` muss daher `figure` sein!

### **Beispiel**

**Output:**
```html
<!-- wp:image {"metadata":{"etchData":{"block":{"tag":"figure"}}}} -->
<figure class="wp-block-image">
  <img src="..." alt="..." />
</figure>
<!-- /wp:image -->
```

### **Wichtige Änderungen**

**2025-10-22 00:38:** Initial implementation mit figure tag

---

## 🔲 Div/Flex-Div Converter

**Datei:** `elements/class-div.php`

### **Zweck**

Konvertiert Bricks Div/Block zu Etch Flex-Div.

### **Features**

- ✅ Unterstützt semantic tags (`li`, `span`, `article`, etc.)
- ✅ Fügt `etch-flex-div-style` hinzu
- ✅ Für Bricks `div` und `block` Elemente

### **Element-Typ Mapping**

- Bricks `div` → Etch Flex-Div
- Bricks `block` → Etch Flex-Div

### **Beispiel**

**Input (Bricks):**
```php
array(
    'name' => 'div',
    'settings' => array(
        'tag' => 'li',
        '_cssGlobalClasses' => array('bTySctnmzzp')
    )
)
```

**Frontend:**
```html
<li data-etch-element="flex-div" class="fr-feature-card">
  <!-- children -->
</li>
```

### **Wichtige Änderungen**

**2025-10-22 00:38:** Initial implementation mit semantic tag support

---

## 📌 Notes Converter

**Datei:** `elements/class-notes.php`

### **Zweck**

Konvertiert Frames fr-notes Elemente in HTML-Kommentare zur Migrations-Nachverfolgung. Notes sind nur im Builder sichtbar und werden im Frontend nicht gerendert.

### **Verhalten**

- Gibt einen **plain HTML-Kommentar** zurück, keinen Gutenberg-Block (Ausnahme unter den Convertern).
- Liest `notesContent` aus den Element-Settings, entfernt HTML per `wp_strip_all_tags()`, kürzt Leerzeichen.
- Format: `<!-- MIGRATION NOTE: {text} -->`
- Leerer oder fehlender Inhalt: `<!-- MIGRATION NOTE: (empty) -->`
- `--` im Text wird ersetzt, um den HTML-Kommentar nicht zu brechen.

### **Format**

```html
<!-- MIGRATION NOTE: Extracted note text here -->
```

### **Wichtige Änderungen**

**2025-02-08:** Initial implementation (fr-notes aus skip_elements entfernt, Converter in TYPE_MAP aufgenommen)

---

## 📄 HTML Converter

**Datei:** `elements/class-html.php`

**Zweck:** Konvertiert Bricks HTML-Elemente zu `etch/raw-html` Gutenberg-Blöcken.

**Features:**

- ✅ Extrahiert `settings.html` Content
- ✅ Generiert `etch/raw-html` Block
- ✅ `unsafe: 'false'` für Etch-Sanitization
- ✅ Keine CSS-Klassen (Raw HTML)

**Beispiel:**

**Input (Bricks):** Element mit `settings.html`

**Output (Gutenberg):** `wp:etch/raw-html` Block-Kommentar mit JSON-Attributen

**Wichtige Änderungen:** `2025-02-08: Initial implementation (Phase 2)`

---

## 🔖 Shortcode Converter

**Datei:** `elements/class-shortcode.php`

**Zweck:** Konvertiert Bricks Shortcode-Elemente zu `etch/raw-html` Blöcken.

**Features:**

- ✅ Extrahiert `settings.shortcode` Content
- ✅ Generiert `etch/raw-html` Block
- ✅ Shortcode-Format erhalten für WordPress-Runtime
- ✅ `unsafe: 'false'`

**Beispiel:**

**Input:** `[gallery ids="1,2,3"]`

**Output:** `wp:etch/raw-html` Block mit Shortcode-Content

**Wichtige Änderungen:** `2025-02-08: Initial implementation (Phase 2)`

---

## 💻 Code Converter

**Datei:** `elements/class-code.php`

**Zweck:** Konvertiert Bricks Code-Elemente mit maximaler nativer Etch-Integration. Statt alles pauschal in `etch/raw-html` zu dumpen, wird JS/CSS/HTML/PHP separat erkannt und optimal behandelt.

**Logik:**

1. **PHP-Erkennung** (`<?php` / `<?`) → Frühzeitiger Abbruch mit Warnungs-Block (`unsafe: false`, Original esc_html-escaped in HTML-Kommentar)
2. **`<script>` Tags** aus dem HTML-Feld extrahieren → mit `javascriptCode` Feld zusammenführen → `wp:etch/element` mit `script.code` base64 (Etch-nativ)
3. **`<style>` Tags** aus dem HTML-Feld extrahieren → mit `cssCode` Feld zusammenführen → `wp:etch/raw-html` mit `<style>` (kein natives Etch-CSS-Attribut)
4. **Rest-HTML** nach Extraktion → HTML-Kommentare/Whitespace entfernen → substantielles HTML als `wp:etch/raw-html`

**Output-Blöcke (bis zu 3 pro Element):**

| Quelle | Etch Block | Format |
|--------|-----------|--------|
| JS (Feld + `<script>` Tags) | `wp:etch/element` | `script.code` base64 |
| CSS (Feld + `<style>` Tags) | `wp:etch/raw-html` | `<style>...</style>`, Label `(CSS)` |
| Rest-HTML | `wp:etch/raw-html` | `content`, `unsafe` Flag |
| PHP | `wp:etch/raw-html` | HTML-Kommentar mit Warnung |

**Etch-Einschränkung:** Etch hat kein `style.code` Pendant zu `script.code`. Custom-CSS bleibt daher bei `etch/raw-html` mit `<style>` Tag.

**Hilfsmethoden (privat):**
- `extract_script_tags($html)` — Regex-Extraktion aller `<script>` Inhalte
- `extract_style_tags($html)` — Regex-Extraktion aller `<style>` Inhalte
- `contains_php($code)` — Prüft auf `<?` Tags
- `clean_remaining_html($html)` — Entfernt HTML-Kommentare + Whitespace
- `build_js_block($js, $element)` — `wp:etch/element` mit `script.code` base64
- `build_css_block($css, $element)` — `wp:etch/raw-html` mit `<style>`
- `build_html_block($html, $element)` — `wp:etch/raw-html` mit `unsafe` Flag
- `build_php_warning_block($code, $element)` — Warnungs-Block mit esc_html

**Wichtige Änderungen:**
- `2025-02-08: Initial implementation (Phase 2) — alles als etch/raw-html`
- `2026-02-16: Maximale native Integration — JS als script.code base64, CSS/HTML/PHP separat behandelt`

---

## 🔗 Text-Link Converter

**Datei:** `elements/class-text-link.php`

**Zweck:** Konvertiert Bricks Text-Link zu `etch/element` mit tag='a'.

**Features:**

- ✅ Link-URL aus `settings.link` (array oder string)
- ✅ newTab → `target="_blank"` + `rel="noopener noreferrer"`
- ✅ Text-Content aus `settings.text`
- ✅ CSS-Klassen von Bricks übernommen
- ✅ etchData mit `block.type='html'` und `block.tag='a'`

**Beispiel:**

**Input:** Element mit Link-Daten und newTab

**Output:** `wp:etch/element` Block mit Anchor-Tag

**Wichtige Änderungen:** `2025-02-08: Initial implementation (Phase 2)`

---

## 📝 Rich Text Converter (text)

**Datei:** `elements/class-text.php`

**Zweck:** Konvertiert Bricks Rich Text zu mehreren `etch/element` Blöcken.

**Features:**

- ✅ **KOMPLEX:** Ein Bricks-Element → Mehrere Etch-Blocks
- ✅ HTML-Parsing mit DOMDocument
- ✅ Top-Level-Elemente identifiziert (p, h1-h6, ul, ol, div, etc.)
- ✅ Verschachtelte Listen (ul/ol > li als innerBlocks)
- ✅ CSS-Klassen erhalten (von Bricks + aus HTML)
- ✅ Inline-Elemente in Paragraphs gewrappt
- ✅ **Fallback:** Wenn DOM-Parsing fehlschlägt oder stark kürzt (>20 % Textverlust), wird der komplette HTML-Text als ein Block ausgegeben (verhindert Abschneiden z. B. bei Anführungszeichen wie in „cutout“).

**Beispiel:**

**Input:** `<p>Text</p><ul><li>Item</li></ul><p>More</p>`

**Output:** 3 separate `wp:etch/element` Blöcke (p, ul mit list-items, p)

**Wichtige Änderungen:** `2025-02-08: Initial implementation (Phase 2) - HTML parsing with DOMDocument`

---

## 🔄 Workflow

### **1. Element wird verarbeitet**

```php
// In gutenberg_generator.php
$factory = new B2E_Element_Factory($style_map);
$html = $factory->convert_element($element, $children);
```

### **2. Factory wählt Converter**

```php
// In class-element-factory.php
$converter = $this->get_converter($element['name']);
```

### **3. Converter konvertiert**

```php
// In class-container.php (Beispiel)
public function convert($element, $children) {
    $style_ids = $this->get_style_ids($element);
    $css_classes = $this->get_css_classes($style_ids);
    $tag = $this->get_tag($element, 'div');
    // ... build block HTML
    return $html;
}
```

### **4. Output**

Gutenberg Block HTML mit Etch metadata

---

## ✅ Best Practices

### **1. Immer Base Class verwenden**

```php
class B2E_Element_MyElement extends B2E_Base_Element {
    protected $element_type = 'my-element';
    
    public function convert($element, $children = array()) {
        // Use parent methods
        $style_ids = $this->get_style_ids($element);
        $css_classes = $this->get_css_classes($style_ids);
        // ...
    }
}
```

### **2. Custom Tags berücksichtigen**

```php
$tag = $this->get_tag($element, 'div');  // Default: 'div'
```

### **3. CSS Klassen in attributes.class**

```php
$etch_attributes = array(
    'data-etch-element' => 'container',
    'class' => $css_classes  // WICHTIG!
);
```

### **4. tagName für non-div tags**

```php
$attrs = $this->build_attributes($label, $style_ids, $etch_attributes, $tag);
// Setzt automatisch 'tagName' wenn $tag !== 'div'
```

---

---

## ✅ Acceptance Criteria Verification

This section documents each ticket criterion for the Element Converters Phase 2 feature, marking satisfaction status or any limitation/deviation. Reference: CHANGELOG v0.12.0 (2025-02-08).

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | **EFS_Element_Html** converts Bricks HTML elements to `etch/raw-html` blocks | ✅ Satisfied | `elements/class-html.php`; outputs wp:etch/raw-html with content, unsafe, and metadata.etchData (block.type, block.tag) |
| 2 | **EFS_Element_Shortcode** converts Bricks shortcode elements to `etch/raw-html` blocks | ✅ Satisfied | `elements/class-shortcode.php`; same structure as HTML converter |
| 3 | **EFS_Element_TextLink** converts Bricks text-link to `etch/element` blocks with anchor tags | ✅ Satisfied | `elements/class-text-link.php`; etchData with block.type='html', block.tag='a' |
| 4 | **EFS_Element_Text** converts Bricks rich text to multiple `etch/element` blocks | ✅ Satisfied | `elements/class-text.php`; DOMDocument parsing; multiple blocks for p, ul, ol, etc. |
| 5 | Comprehensive unit tests for all Phase 2 converters | ✅ Satisfied | `tests/test-element-converters.php` – Tests 6–19 cover Phase 2 (HTML, Shortcode, Text-Link, Text, SVG, Video, Code, Factory, Style Map) |
| 6 | Factory registration for all 4 new converter types (html, shortcode, text-link, text) | ✅ Satisfied | `class-element-factory.php` TYPE_MAP includes html, shortcode, text-link, text |
| 7 | etchData schema compliance for `etch/raw-html` blocks (metadata.etchData, block.type, block.tag) | ✅ Satisfied | HTML and Shortcode converters output metadata; integration tests 13 & 14 assert presence |

**Deviations / Limitations:** None. All criteria met.

---

## 🧪 Testing

### **Unit Tests**

**Datei:** `tests/test-element-converters.php`

Testet jeden Converter einzeln:
- Container mit ul tag
- Div mit li tag
- Heading (h2)
- Image (figure tag)
- Section
- Phase 2: HTML, Shortcode, Text-Link, Rich Text (Tests 13–19)
- Phase 2 Edge Cases: leere Inhalte, fehlerhafte HTML, Link-Formate (Tests 20–28)
- Phase 2 Integration: Pipeline aller vier Elemente, Style-Map-Propagation (Tests 29–30)

### **Integration Tests**

**Datei:** `tests/test-integration.php`

Testet die Integration mit Gutenberg Generator:
- Factory wird korrekt initialisiert
- Elemente werden konvertiert
- Tags sind korrekt
- CSS Klassen sind vorhanden

### **Tests ausführen**

```bash
# Unit Tests
docker cp tests/test-element-converters.php b2e-bricks:/tmp/
docker exec b2e-bricks php /tmp/test-element-converters.php

# Integration Tests
docker cp tests/test-integration.php b2e-bricks:/tmp/
docker exec b2e-bricks php /tmp/test-integration.php
```

---

## 🐛 Troubleshooting

### **Problem: Element wird nicht konvertiert**

**Lösung:** Prüfe Factory Mapping in `class-element-factory.php`

```php
$type_map = array(
    'my-element' => 'B2E_Element_MyElement',  // Hinzufügen
);
```

### **Problem: CSS Klassen fehlen**

**Lösung:** Prüfe ob `get_css_classes()` aufgerufen wird und Style Map existiert

```php
$css_classes = $this->get_css_classes($style_ids);
if (!empty($css_classes)) {
    $etch_attributes['class'] = $css_classes;
}
```

### **Problem: Tag ist immer 'div'**

**Lösung:** Prüfe ob `get_tag()` verwendet wird

```php
$tag = $this->get_tag($element, 'div');  // Liest aus settings.tag
```

### **Problem: tagName fehlt in Gutenberg**

**Lösung:** `build_attributes()` setzt tagName automatisch wenn tag !== 'div'

```php
$attrs = $this->build_attributes($label, $style_ids, $etch_attributes, $tag);
```

### **Problem: Rich Text wurde abgeschnitten (z. B. Text nach „cutout“ fehlte)**

**Ursache:** `DOMDocument::loadHTML()` kann bei bestimmten HTML-Strukturen oder Anführungszeichen den Inhalt kürzen oder fehlerhaft parsen.

**Lösung:** In `class-text.php` gibt es einen Fallback: Schlägt das DOM-Parsing fehl oder liefert es deutlich weniger Text als das Original (<80 %), wird der gesamte HTML-String als ein einziger Paragraph-Block ausgegeben. So geht kein Text verloren.

---

## 🔮 Zukünftige Erweiterungen

### **Geplant:**

- [x] Button Converter
- [ ] Form Converter
- [ ] Video Converter
- [ ] Custom Element Support
- [ ] Dynamic Data Tag Converter (Bricks `{tag:param}` → Etch `{source.path}`)
- [ ] Loop Converter (Bricks `hasLoop`/`query` → `etch/loop` Wrapper-Block + `etch_loops` Presets)
- [ ] Condition Converter (Bricks `_conditions` → `etch/condition` Wrapper-Block)

### **Deferred (Blocked by Etch Feature Support):**

> Die folgenden Features werden zurückgestellt, bis Etch native Unterstützung dafür bietet.
> Sobald verfügbar, sollten diese Converter nachgezogen werden.

- [ ] **WooCommerce Dynamic Tags** — Bricks Tags wie `{woo_product_rating:value}`, `{woo_product_stock:value}`, `{woo_product_on_sale}`, `{woo_product_stock_status}`, `{woo_cart_remove_link:...}` haben kein Etch-Äquivalent. Etch hat aktuell keine native WooCommerce-Integration. Workaround via `this.meta._field` ist möglich aber unzuverlässig für berechnete WooCommerce-Felder.
- [ ] **WooCommerce Action Hooks** — Bricks `{do_action:woocommerce_*}` Tags (25 Instanzen in Prod-DB) rufen WooCommerce-Hooks direkt auf. Etch hat kein `do_action`-Äquivalent. Betrifft: Shop-Loop, Single-Product, Cart, Checkout, Account-Seiten.
- [ ] **WooCommerce Cart Loop** — Bricks `wooCart:default` Query-Typ hat kein Etch Loop-Äquivalent.
- [ ] **PHP Echo Tags** — Bricks `{echo:function_name}` führt beliebige PHP-Funktionen aus. Etch unterstützt das bewusst nicht (Sicherheitsmodell). 2 Instanzen in Prod-DB (`{echo:frames_get_current_post_type}`).

**Hinweis:** Stand 2026-02-16 betrifft dies 4 Prod-Posts (WooCommerce-Templates) und 2 Posts mit `{echo:...}`. Alle anderen Dynamic Data Tags, Loops und Conditions sind konvertierbar.

### **Button Converter** ✅

**Datei:** `elements/class-button.php` | **Status:** Implemented 2026-03-05 Phase 10

Konvertiert Bricks Button Elemente zu Etch Anchor Elementen mit CSS-Klassen-Priorität und Deduplication:
- CSS-Klassen (`btn--*`) haben Priorität über UI style-Setting
- Nur EINE primäre Klasse pro Element (Deduplication)
- Exception: `btn--outline` kann zusätzlich existieren
- ✨ **NEW:** Nested icon support - Icons in buttons convert to `wp:etch/svg` blocks
- 19 unit tests + 2 integration tests (100% coverage)

### **Wie neue Converter hinzufügen:**

1. Neue Datei in `elements/` erstellen
2. Von `B2E_Base_Element` erben
3. `convert()` Methode implementieren
4. In Factory Mapping hinzufügen
5. Tests schreiben
6. **Hier dokumentieren!**

---

## 🔄 Dynamic Data / Loops / Conditions — Mapping Reference

> Dokumentiert 2026-02-16 auf Basis der Prod-DB (1018 Posts, 80 Loops, 43 Dynamic Tags, 13 Conditions).

### Bricks → Etch: Dynamic Tag Mapping

| Bricks Tag | Etch Expression | Kontext |
|---|---|---|
| `{post_title:N}` | `{this.title}` | N = Char-Limit, in Etch via `.slice(0,N)` Modifier |
| `{post_excerpt:N}` | `{this.excerpt}` | Analog |
| `{post_content:N}` | `{this.content}` | Analog |
| `{post_date:FORMAT}` | `{this.date.format("FORMAT")}` | PHP-Datumsformat → Etch `.format()` Modifier |
| `{post_date:timestamp}` | `{this.date}` | ISO 8601 in Etch |
| `{post_terms_category:plain}` | `{this.categories}` | Etch liefert Array, nicht String |
| `{author_name:plain}` | `{this.author.name}` | Dot-Path |
| `{current_date:Y}` | `{site.currentDate}` | + `.format("Y")` |
| `{term_name:plain}` | `{item.name}` | Innerhalb Term-Loop |
| `{query_results_count:ID}` | — | Kein direktes Äquivalent, Custom-Lösung nötig |

### Bricks → Etch: Loop Mapping

**Strukturunterschied:** Bricks setzt Loop auf das Element selbst (`hasLoop` + `query`). Etch wickelt einen `etch/loop` Block **um** die Children.

```
Bricks:  div[hasLoop=true, query={post_type:["post"]}] → children
Etch:    etch/loop[loopId="uuid"] → etch/element[tag="div"] → children
```

| Bricks Query | Etch Loop Preset (`etch_loops` Option) |
|---|---|
| `{objectType:"post", post_type:["post"], posts_per_page:8}` | `{type:"wp-query", args:{post_type:"post", posts_per_page:8}}` |
| `{objectType:"post", post_type:["attachment"], tax_query:[...]}` | `{type:"wp-query", args:{post_type:"attachment", tax_query:[...]}}` |
| `{objectType:"post", post_type:["product"]}` | `{type:"wp-query", args:{post_type:"product"}}` |
| `{objectType:"post", post_type:["slider"]}` | `{type:"wp-query", args:{post_type:"slider"}}` |
| `{objectType:"post", post_type:["locations"]}` | `{type:"wp-query", args:{post_type:"locations"}}` |
| Term-Loop (`term:default`) | `{type:"wp-terms", args:{taxonomy:"category"}}` |
| ~~`wooCart:default`~~ | **DEFERRED** — Kein Etch-Äquivalent |

### Bricks → Etch: Condition Mapping

**Strukturunterschied:** Bricks packt Conditions als `_conditions` Property auf das Element. Etch wickelt einen `etch/condition` Block **um** das Element.

| Bricks Condition | Etch Condition AST |
|---|---|
| `{key:"user_logged_in"}` | `{leftHand:"user.loggedIn", operator:"isTruthy", rightHand:null}` |
| `{key:"featured_image"}` | `{leftHand:"this.featuredImage", operator:"isTruthy", rightHand:null}` |
| `{key:"dynamic_data", dynamic_data:"{woo_product_rating:value}", compare:"==", value:"0"}` | **DEFERRED** — WooCommerce |
| `{key:"dynamic_data", dynamic_data:"{woo_product_on_sale}", compare:"!="}` | **DEFERRED** — WooCommerce |
| `{key:"dynamic_data", dynamic_data:"{woo_product_stock_status}", compare:"==", value:"instock"}` | **DEFERRED** — WooCommerce |

### Prod-DB Statistik (Stand 2026-02-16)

| Feature | Gesamt | Konvertierbar | Deferred (WooCommerce/echo) |
|---|---|---|---|
| Dynamic Tags | 43 unique | 30 | 13 (alle `woo_*`, `do_action:woocommerce_*`, `echo:*`) |
| Loops | 80 | 79 | 1 (`wooCart:default`) |
| Conditions | 13 Elemente | 7 | 6 (alle WooCommerce-bezogen) |
| Hidden Elements | 389 | — | — (werden nicht migriert) |

---

## 📚 Siehe auch

- [REFACTORING-STATUS.md](../../../REFACTORING-STATUS.md) - Refactoring Übersicht
- [CHANGELOG.md](../../../CHANGELOG.md) - Version History
- [DOCUMENTATION.md](../../../DOCUMENTATION.md) - Technische Dokumentation

---

**Erstellt:** 2025-10-22 00:44
**Letzte Änderung:** 2026-02-16
**Version:** 0.11.0
