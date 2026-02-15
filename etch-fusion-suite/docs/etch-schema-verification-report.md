# Etch Schema Verification Report

Verification of EtchFusion-Suite element converters against the official Etch etchData schema.

**Reference**: `E:\Github\etch\src\plugin-src\classes\Preprocessor\Data\EtchData.php`  
**Base implementation**: `etch-fusion-suite/includes/converters/class-base-element.php`  
**Supporting docs**: `etch-data-schema-reference.md`, `etch-schema-compliance-comparison.md`

---

## Section 1: Schema Compliance

### Properties that match the official schema

- **etchData.origin**: Set as string `'etch'` in all converters (base `build_attributes()` line 131; Image, Button, Icon set explicitly). ✅
- **etchData.name**: Set from `get_label()` (string) in base and optionally in Image/Button. ✅
- **etchData.styles**: Set from `$style_ids` (array of strings from `get_style_ids()`). ✅
- **etchData.attributes**: Set from `$etch_attributes`; all converters assign only string values (e.g. `class`, `data-etch-element`). ✅
- **etchData.block.type**: Always string `'html'` (base line 135; Image nestedData line 64; Button lines 61, 75; Icon lines 63–64). ✅
- **etchData.block.tag**: Set from `$tag` (string) in base; Image uses `'img'`; Button uses `'p'` and `'a'`; Icon uses `'p'`. ✅
- **etchData.nestedData**: Image and Button build nested structures that match the EtchData shape (origin, attributes, block, styles where needed); Etch’s `extract_nested_array()` validates each nested value as EtchData. ✅

**Code references**:

- Base: `class-base-element.php` lines 125–148 (`build_attributes`), 72–93 (`get_css_classes`), 113 (`get_tag`).
- Heading: `class-heading.php` lines 50, 54.
- Paragraph: `class-paragraph.php` lines 50, 54.
- Container: `class-container.php` lines 49, 53, 57.
- Section: `class-section.php` lines 48, 52, 56.
- Div: `class-div.php` lines 49, 53, 57.
- Image: `class-image.php` lines 50–51, 54, 57–69, 72.
- Button: `class-button.php` lines 61–62, 65–79, 71–72.

### Properties that deviate from the schema

None. All checked properties conform to the types and structure expected by EtchData (string attributes, indexed string array for styles, string block.type and block.tag, valid nestedData shape).

---

## Section 2: Type Safety Analysis

- **block.type**: Always the string `'html'` wherever set (base and all converters that build block data). No other type or non-string value is assigned.
- **block.tag**: Always a string: from `get_tag($element, $fallback_tag)` (fallback is string); Image/Button/Icon use string literals (`'img'`, `'a'`, `'p'`). If Bricks ever stored a non-string in `settings['tag']`, it would be passed through; recommendation: cast to string if desired (see Section 4).
- **attributes.class**: Always a string. Set from `get_css_classes()`, which returns `implode(' ', $classes)` (base line 92); never an array.
- **styles**: Always an array of strings. Built from `get_style_ids()` and optional literals (e.g. `'etch-container-style'`); no non-string elements are added.

No converter passes an array as an attribute value; all attribute values are strings (class, data-etch-element, href, src, alt, etc.).

---

## Section 3: Converter-Specific Notes

- **Image converter** (`class-image.php`): Does not use `build_attributes()`. Builds custom etchData with `nestedData['img']` containing origin, attributes (src, alt, class), block (type: 'html', tag: 'img'), and optionally styles. Figure wrapper has no etchData on the figure itself; styles and classes apply to the inner img. Matches Etch’s expectation for nested HTML elements.
- **Button converter** (`class-button.php`): Does not use `build_attributes()`. Builds etchData with `removeWrapper => true`, wrapper block (type: 'html', tag: 'p'), and `nestedData[$link_ref]` with full etchData for the link (origin, name, styles, attributes href/class, block type/tag 'html'/'a'). Uses dynamic ref for the nested link; structure is compatible with Etch’s nestedData handling.
- **Icon converter** (`class-icon.php`): Placeholder implementation. Returns minimal etchData (origin, block type/tag only) and placeholder HTML. Icon conversion is not yet implemented; structure used is schema-compliant for the fields present.

---

## Section 4: Recommendations

- **Compliance**: The current implementation is correct with respect to the official Etch schema. All verified properties have the expected types and structure; migration output is compatible with Etch’s preprocessor (EtchData extraction and HtmlBlock usage).
- **Optional hardening**: For maximum robustness, consider casting `get_tag()` return value to string (e.g. `return (string) ( $element['settings']['tag'] ?? $fallback_tag );`) so that non-string values from Bricks never reach etchData.
- **Type safety**: Optional improvement: add PHPDoc to `build_attributes()` and converter methods specifying that `$etch_attributes` is `array<string, string>`, `$style_ids` is `array<string>`, and `$tag` is `string`, to align with the schema and aid static analysis.

No mandatory changes are required for schema compliance.

---

## Cross-Reference with Official Etch Examples

Etch’s own tests (`E:\Github\etch\src\plugin-src\classes\Preprocessor\Tests\HtmlBlock.test.php`) use etchData in the same shape as produced by EtchFusion-Suite:

- **Minimal**: `{"origin":"etch","block":{"type":"html","tag":"div"}}` (test_converting_tag_to_clean_div).
- **With attributes**: `"attributes":{"aria-label":"...","data-etch-element":"flex-div"}`, `"styles":["etch-flex-div-style"]` (test_rendering_div_with_custom_attributes).
- **Heading**: `"block":{"type":"html","tag":"h2"}`, `"attributes":{"class":"red"}`, `"styles":["cvxxzi9"]` (test_heading_with_custom_attributes).

Naming and structure (origin, name, styles, attributes, block.type, block.tag) match. The Etch preprocessor uses `EtchData::from_block()` and `HtmlBlock` with these fields; our migration output is compatible. No differences in structure or naming conventions were found. nestedData was not exercised in the reviewed tests but follows the same EtchData shape and is handled by `extract_nested_array()` in EtchData.php.
