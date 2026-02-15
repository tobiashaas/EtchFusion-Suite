# Etch Schema Compliance — Comparison Table

Structured comparison between the official Etch etchData schema and the current EtchFusion-Suite implementation.

| Property Path | Expected Type | Current Implementation | Status | Notes |
|---------------|---------------|-------------------------|--------|--------|
| `etchData.origin` | `string` | Set as string literal `'etch'` in `build_attributes()` (class-base-element.php line 131) and in Image/Button/Icon converters | ✅ Correct | Same in all converters. |
| `etchData.name` | `string\|null` | Set from `$label` (string from `get_label()`) in `build_attributes()` line 130; Image/Button set when non-empty | ✅ Correct | `get_label()` returns string (element label or ucfirst(element_type)). |
| `etchData.styles` | `array<string>` | Set as `$style_ids` in `build_attributes()` line 132; Image/Button set on root or nested | ✅ Correct | `get_style_ids()` returns array of strings; extract_indexed_string_array accepts only string elements. |
| `etchData.styles[n]` | `string` | Each element is a style ID string from style_map | ✅ Correct | Built from style_map values, no non-strings added. |
| `etchData.attributes` | `array<string, string>` | Set as `$etch_attributes` in `build_attributes()` line 133; each converter builds map with string values only | ✅ Correct | All converters assign only string values (class, data-etch-element, etc.). |
| `etchData.attributes.class` | `string` | Set from `get_css_classes()` which returns `implode(' ', $classes)` (class-base-element.php line 92) | ✅ Correct | Always a string (space-separated class names). |
| `etchData.attributes.data-etch-element` | `string` | Container: `'container'`, Section: `'section'`, Div: `'flex-div'` (string literals) | ✅ Correct | String literals in class-container, class-section, class-div. |
| `etchData.block.type` | `string` | Set as literal `'html'` in `build_attributes()` line 135; same in Image nestedData, Button nestedData and wrapper, Icon | ✅ Correct | Always `'html'` for HTML elements. |
| `etchData.block.tag` | `string` | Set from `$tag` parameter in `build_attributes()` line 136; `$tag` from `get_tag()` (fallback string) | ✅ Correct | get_tag returns settings['tag'] or fallback; fallback is string; Bricks typically stores string. |
| `etchData.nestedData` | `object` (nested etchData) | Image: `nestedData['img']` with origin, attributes, block (type/tag), styles; Button: `nestedData[$link_ref]` with full etchData shape | ✅ Correct | Structure matches EtchData extraction; nested entries validated as EtchData in Etch. |
| `etchData.nestedData.img.attributes.class` | `string` | Set from `$css_classes` (get_css_classes) in class-image.php line 54 | ✅ Correct | Same string source as top-level class. |
| `etchData.nestedData.img.block.type` | `string` | Literal `'html'` in class-image.php line 64 | ✅ Correct | Matches schema. |
| `etchData.nestedData.img.block.tag` | `string` | Literal `'img'` in class-image.php line 65 | ✅ Correct | Matches schema. |
| `etchData.nestedData[ref].block.type` (Button) | `string` | Literal `'html'` for both wrapper (line 61) and link (line 75) in class-button.php | ✅ Correct | Matches schema. |
| `etchData.nestedData[ref].block.tag` (Button) | `string` | Wrapper: `'p'` (line 62), link: `'a'` (line 76) | ✅ Correct | Matches schema. |

All listed properties match the official Etch schema. No deviations or issues identified in this comparison.
