# CSS Converter Architecture

**Updated:** 2025-10-29 00:45

## 1. Overview

- **Purpose:** Convert Bricks global classes to Etch-compatible styles during migration.
- **Primary File:** `includes/css_converter.php` (2001 lines)
- **Dependencies:**
  - `Bricks2Etch\Core\EFS_Error_Handler`
  - `Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface`

## 2. Conversion Workflow

### Entry Points

- `convert()` – Backwards-compatible wrapper used by legacy integrations.
- `convert_bricks_classes_to_etch()` – Main conversion routine.

### Four-Step Process

1. **Convert Bricks Classes (lines 151-256)**
   - Load Bricks global classes from WordPress options.
   - Filter excluded prefixes (Bricks, WordPress core, WooCommerce).
   - Convert each class via `convert_bricks_class_to_etch()` and generate Etch style IDs.
   - Build `style_map` linking Bricks IDs to Etch style IDs and selectors.

2. **Collect Breakpoint CSS (lines 183-207)**
   - Extract `_cssCustom:breakpoint` values from class settings.
   - Translate breakpoint keys to Etch media queries via `get_media_query_for_breakpoint()`.
   - Store breakpoint-specific declarations in `breakpoint_css_map` for later merging.

3. **Parse Custom CSS (lines 259-290)**
   - Aggregate class-level custom CSS into a temporary stylesheet.
   - `parse_custom_css_stylesheet()` matches selectors to style map entries.
   - `convert_nested_selectors_to_ampersand()` rewrites nested selectors to `&` syntax.
   - Merge custom CSS into generated styles, converting physical properties to logical ones.

4. **Add Breakpoint CSS (lines 293-309)**
   - Append stored media queries to the corresponding Etch styles.
   - Preserve responsive behaviour by merging breakpoint declarations after base styles.

## 3. Conversion Methods

Key helpers invoked by `convert_bricks_settings_to_css()` and related routines:

1. `convert_background()` – Background color, image, size, position, repeat.
2. `convert_gradient()` – Linear/radial gradients with color stops.
3. `convert_border()` – Width, style, color, radius (string and array formats).
4. `convert_typography()` – Font family, size, weight, line-height, spacing, decorations.
5. `convert_spacing()` – Margin/padding shorthand from spacing objects.
6. `convert_layout()` – Display, overflow, visibility, z-index, opacity.
7. `convert_flexbox()` – Container and item properties (direction, wrap, gaps, order).
8. `convert_grid()` – Template definitions, gaps, alignment, auto-flow.
9. `convert_sizing()` – Width/height min/max translated to logical properties.
10. `convert_margin_padding()` – Margin/padding logical property translation.
11. `convert_position()` – Position, inset conversion (top/right/bottom/left).
12. `convert_effects()` – Transform, transition, filters, object-fit, shadows, blend modes.
13. `convert_responsive_variants()` – Media query wrappers for breakpoint settings.
14. `convert_bricks_settings_to_css()` – Orchestrates all property converters.
15. `convert_to_logical_properties()` – Regex-based replacement outside media queries.
16. `convert_nested_selectors_to_ampersand()` – Normalises nested selectors.
17. `convert_selectors_in_media_query()` – Handles nested selectors within media queries.

## 4. CSS Parsing Methods

- `parse_custom_css_stylesheet()` – Extracts class-specific rules, maps to style IDs, and converts nested selectors.
- `extract_css_for_class()` – Retrieves rule blocks (including media queries) for individual classes.
- `extract_media_queries()` – Isolates media query blocks with brace counting.

## 5. Import & Save Methods

### `import_etch_styles()`

- Accepts either `{ styles, style_map }` payload or a legacy styles array.
- Merges incoming styles with existing Etch styles from the repository.
- **Import Strategy:**
  - `bypass_api = true` (default) – Direct `update_option()` save with cache invalidation and rebuild triggers.
  - `bypass_api = false` – Intended future path to call Etch API endpoints.
- Logs progress via `EFS_Error_Handler::log_info()` for PHPCS-compliant diagnostics.

### `trigger_etch_css_rebuild()`

1. Increment `etch_svg_version` to invalidate caches.
2. Call `Style_Repository_Interface::invalidate_style_cache()`.
3. Fire WordPress hooks `etch_fusion_suite_styles_updated` and `etch_fusion_suite_rebuild_css`.

### `save_to_global_stylesheets()`

- Converts Etch style entries to `{ name, css }` objects for frontend rendering.
- Merges with existing global stylesheets and saves via repository.

## 6. Utility Methods

- `generate_style_hash()` – Generates 7-character IDs mirroring Etch behaviour.
- `validate_css_syntax()` – Basic bracket/quote validation with `EFS_Error_Handler::log_error()` reporting.
- `clean_css()` – Fixes double semicolons and normalises whitespace for custom CSS blocks.
- `clean_custom_css()` – Removes redundant class wrappers while retaining media queries.

## 7. Breakpoint Mapping

| Bricks Breakpoint   | Etch Media Query                     |
|---------------------|--------------------------------------|
| `desktop`           | `@media (width >= to-rem(1200px))`   |
| `tablet_landscape`  | `@media (width >= to-rem(992px))`    |
| `tablet_portrait`   | `@media (width >= to-rem(768px))`    |
| `mobile_landscape`  | `@media (width >= to-rem(479px))`    |
| `mobile_portrait`   | `@media (width <= to-rem(478px))`    |

> Desktop-first cascading ensures larger breakpoints apply first, with smaller breakpoints overriding as needed.

## 8. Logical Property Conversion

| Physical Property | Logical Property        |
|-------------------|-------------------------|
| `margin-left`     | `margin-inline-start`   |
| `margin-right`    | `margin-inline-end`     |
| `padding-left`    | `padding-inline-start`  |
| `padding-right`   | `padding-inline-end`    |
| `left`            | `inset-inline-start`    |
| `right`           | `inset-inline-end`      |
| `width`           | `inline-size`           |
| `height`          | `block-size`            |
| `min-width`       | `min-inline-size`       |
| `min-height`      | `min-block-size`        |
| `max-width`       | `max-inline-size`       |
| `max-height`      | `max-block-size`        |

## 9. Error Handling

- All logging routes through `EFS_Error_Handler::log_info()`, `log_warning()`, or `log_error()`.
- Info logs provide granular conversion diagnostics without violating PHPCS output rules.
- Syntax or migration failures produce structured error codes (e.g., `E002` for CSS validation).

## 10. Testing Strategy

### Unit & Automation

- Ensure existing unit/integration tests for CSS conversion continue to pass.
- Extend coverage where possible to include:
  - Custom CSS parsing edge cases.
  - Logical property conversions.
  - Breakpoint/media query merging.

### Manual Verification Checklist

- Convert representative Bricks projects featuring:
  - Custom CSS with nested selectors.
  - Breakpoint-specific adjustments.
  - Advanced layout features (flex, grid, positioning, effects).
- Validate Etch styles render correctly across desktop/tablet/mobile viewports.
- Confirm cache invalidation and rebuild hooks generate updated CSS assets.

---

**References:**

- Main implementation: `includes/css_converter.php`
- Error handling: `includes/error_handler.php`
- Style repository: `includes/repositories/class-wordpress-style-repository.php`
- PHPCS configuration: `phpcs.xml.dist`
- Security architecture: `docs/security-architecture.md`
