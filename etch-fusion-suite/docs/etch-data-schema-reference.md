# EtchData Schema Reference

This document describes the official etchData structure as defined in the Etch plugin (`Etch\Preprocessor\Data\EtchData.php`). It serves as the authoritative reference for verifying EtchFusion-Suite converter output.

## Source

- **File**: `E:\Github\etch\src\plugin-src\classes\Preprocessor\Data\EtchData.php`
- **Relevant methods**: `extract_data()` (lines 577-605), `extract_block_data()` (lines 613-654), `extract_string_array()` (lines 727-738), `extract_indexed_string_array()` (lines 747-758), `extract_nested_array()` (lines 767-779)

## Class Properties (EtchData.php lines 21-145)

| Property       | PHPDoc Type              | Description                    |
|----------------|--------------------------|--------------------------------|
| `type`         | `string\|null`           | Block type (e.g. 'html')       |
| `tag`          | `string`                 | HTML tag                       |
| `attributes`   | `array<string, string>`  | Block attributes               |
| `styles`       | `array<string>`          | Style IDs (indexed array)      |
| `origin`       | `string\|null`           | 'etch' or 'gutenberg'         |
| `hidden`       | `bool`                   | Whether block is hidden        |
| `name`         | `string\|null`           | Block name/label               |
| `removeWrapper`| `bool`                   | Whether to remove wrapper      |
| `misc`         | `array<string, mixed>`   | Miscellaneous data             |
| `script`       | `array{id, code}\|null`   | Script data                    |
| `nestedData`   | `array<string, EtchData>`| Nested EtchData instances      |
| `loop`         | `EtchDataLoop\|null`     | Loop data                      |
| `component`    | `int\|null`              | Component ID                   |
| `innerBlocks`  | `array\|null`            | Inner blocks                   |
| `condition`    | `EtchDataCondition\|null`| Condition data                 |
| `conditionString` | `string\|null`        | Condition string               |
| `slot`         | `string\|null`           | Slot name                      |
| `specialized`  | `string\|null`           | e.g. 'svg' for SVG blocks      |

## Type Enforcement

- **attributes**: `extract_string_array()` ensures only entries with `is_string($arrayKey) && is_string($value)` are kept. Result: `array<string, string>`.
- **styles**: `extract_indexed_string_array()` ensures only `is_string($value)` entries are kept. Result: `array<string>`.
- **block.type**, **block.tag**: Extracted via `extract_string()` (EtchTypeAsserter::to_string_or_null). Must be strings for HTML blocks.
- **nestedData**: Each value must be an array that validates as EtchData (origin 'etch' or 'gutenberg'); values become `EtchData` instances.

## Expected etchData Structure (JSON)

```json
{
  "origin": "etch",
  "name": "Element Name",
  "styles": ["style-id-1", "style-id-2"],
  "attributes": {
    "class": "class-name",
    "data-etch-element": "container"
  },
  "block": {
    "type": "html",
    "tag": "div"
  }
}
```

### Type Summary

| Path                      | Expected Type           | Notes                                    |
|---------------------------|-------------------------|------------------------------------------|
| `etchData.origin`         | `string`                | Literal `"etch"` (or `"gutenberg"`)      |
| `etchData.name`           | `string\|null`          | Element label                            |
| `etchData.styles`         | `array`                 | Indexed array                            |
| `etchData.styles[n]`      | `string`                | Style ID                                 |
| `etchData.attributes`     | `object`                | Associative array                        |
| `etchData.attributes.*`   | `string`                | All attribute values must be strings     |
| `etchData.block`          | `object`                | Block descriptor                         |
| `etchData.block.type`     | `string`                | e.g. `"html"`                             |
| `etchData.block.tag`      | `string`                | e.g. `"div"`, `"h2"`, `"section"`        |
| `etchData.nestedData`     | `object`                | Optional; keys are refs, values are etchData-shaped objects |
| `etchData.nestedData[key].attributes` | `array<string, string>` | Same as top-level attributes |
| `etchData.nestedData[key].block.type` | `string`         | e.g. `"html"`                             |
| `etchData.nestedData[key].block.tag`  | `string`         | e.g. `"img"`, `"a"`                       |

## Validity

A block is considered valid etch if `origin === 'etch'` or `origin === 'gutenberg'` (see `extract_data()` line 586). All other fields are extracted with type coercion/filtering; non-conforming values are dropped or defaulted.
