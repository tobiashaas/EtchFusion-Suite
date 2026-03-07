# CSS Converter Architecture

**Updated:** 2026-03-08

## 1. Overview

- **Purpose:** Convert Bricks global classes, raw custom CSS, and element-scoped CSS into Etch-compatible style data.
- **Primary orchestrator:** `includes/css_converter.php`
- **Primary importer:** `includes/css/class-style-importer.php`
- **Operational guides:** `docs/css-migration-bricks-to-etch-de.md`, `docs/css-migration-bricks-to-etch-en.md`

The current architecture is modular. `EFS_CSS_Converter` is no longer a monolithic converter. It is an orchestrator over focused submodules plus a small number of top-level helpers that still belong at orchestration level.

## 2. Main Components

### 2.1 Orchestrator

`EFS_CSS_Converter` in `includes/css_converter.php` coordinates the end-to-end conversion pipeline.

It keeps:

- the public API surface
- the top-level conversion loop
- built-in Etch framework styles
- final block-display CSS injection

It delegates most concrete work to submodules.

### 2.2 Submodules

The orchestrator currently wires these submodules:

| Component | Responsibility |
| --- | --- |
| `EFS_CSS_Normalizer` | CSS string normalization, logical property conversion, final cleanup |
| `EFS_Breakpoint_Resolver` | Bricks breakpoint keys -> Etch media query strings |
| `EFS_ACSS_Handler` | ACSS utility handling and inline style mapping |
| `EFS_Settings_CSS_Converter` | structured Bricks settings -> CSS declarations |
| `EFS_CSS_Stylesheet_Parser` | raw stylesheet parsing for class and ID selectors |
| `EFS_Class_Reference_Scanner` | detection of actually referenced global classes |
| `EFS_Element_ID_Style_Collector` | element-scoped CSS from Bricks content |
| `EFS_Style_Importer` | persistence into target-side style options plus rebuild triggers |

### 2.3 Service Layer

`EFS_CSS_Service` in `includes/services/class-css-service.php` sits above the converter.

It is responsible for:

- building the migration CSS payload once per run
- sending class CSS to the target
- sending Bricks global CSS to the target
- returning progress counts that match the real migration payload

### 2.4 Transport Layer

Relevant REST endpoints:

- `/wp-json/efs/v1/import/css-classes`
- `/wp-json/efs/v1/import/global-css`

Relevant transport client:

- `includes/api_client.php`

### 2.5 Content Side Consumers

The CSS pipeline is consumed later by content migration via:

- `includes/gutenberg_generator.php`
- converters built through `EFS_Element_Factory`
- other content parsers that read `efs_style_map`

That dependency is architectural, not optional. CSS migration is only correct if content migration resolves the same Etch style IDs later.

## 3. Current Architecture Model

The modern flow is best understood as two linked systems:

1. **Style conversion and persistence**
2. **Content-to-style resolution**

The CSS pipeline does not end when `etch_styles` is saved. The second half is the later lookup path from Bricks class IDs inside content to Etch style IDs inside migrated blocks.

That is why `efs_style_map` is central.

## 4. Core Data Model

### 4.1 `styles`

`styles` is the Etch style registry payload. It is eventually stored in `etch_styles`.

Each migrated class style is keyed by an Etch style ID, for example:

```json
{
  "2af6e4f": {
    "type": "class",
    "selector": ".card",
    "css": "..."
  }
}
```

### 4.2 `style_map`

`style_map` is the translation table from Bricks class ID to Etch style identity:

```json
{
  "bricks-class-id": {
    "id": "2af6e4f",
    "selector": ".card"
  }
}
```

This is persisted as `efs_style_map`.

### 4.3 `etch_styles`

`etch_styles` is the target-side persistent Etch registry. The keys in `etch_styles` must be the same IDs referenced from `style_map`.

### 4.4 `etch_global_stylesheets`

Global Bricks CSS is not part of `style_map`. It is stored separately in `etch_global_stylesheets`, currently under the reserved ID `bricks-global`.

## 5. Architectural Invariants

These invariants define whether the system is healthy:

1. `efs_style_map[bricks_id].id` must reference a real key in `etch_styles`.
2. The selector in `efs_style_map[bricks_id].selector` must match the selector stored in that `etch_styles` entry.
3. Content migration must write that same Etch style ID into block `styles[]`.
4. Visible frontend class names are derived from selectors, but Builder assignment depends on style IDs.

If these invariants break, a class can still appear in the frontend DOM while the Etch Builder cannot resolve it correctly.

## 6. Conversion Pipeline

The current conversion pipeline in `convert_bricks_classes_to_etch()` is effectively a seven-stage process.

### Stage 1: Load and scope classes

The orchestrator:

- loads `bricks_global_classes`
- reads active migration options from `efs_active_migration`
- computes selected post types
- optionally restricts the migration to referenced classes only

This stage is driven by `EFS_Class_Reference_Scanner`.

### Stage 2: Seed framework styles

Built-in Etch framework styles such as:

- `etch-section-style`
- `etch-container-style`
- `etch-iframe-style`
- `etch-block-style`

are seeded into `styles`.

These are internal Etch styles and never appear in `style_map`.

### Stage 3: Pre-collect raw CSS

Before structured class conversion, the converter aggregates:

- `_cssCustom`
- `_cssCustom:<breakpoint>`
- temporary inline CSS in `efs_inline_css_*`

Breakpoint snippets are stored separately in `breakpoint_css_map` so they can be merged back into the correct style later.

### Stage 4: Convert structured Bricks classes

For each eligible non-ACSS class:

- structured settings are converted to CSS
- the CSS is normalized
- a stable Etch style ID is resolved
- a `styles[style_id]` entry is created
- a matching `style_map[bricks_id]` entry is written

Stable ID reuse is critical here. Re-running the conversion must not drift to a different Etch style ID for the same Bricks class when an existing mapping is already known.

### Stage 5: Collect element-scoped styles

`EFS_Element_ID_Style_Collector` contributes:

- element ID based CSS
- additional raw custom CSS extracted from content

These rules are merged into the same top-level payload.

### Stage 6: Parse and merge custom stylesheet CSS

`EFS_CSS_Stylesheet_Parser` parses the aggregated raw stylesheet and produces:

- class-scoped custom styles
- ID-scoped custom styles

The converter then merges those results back into `styles`.

### Stage 7: Final normalization and persistence

The final phase:

- merges breakpoint CSS back into the correct style entries
- runs final CSS normalization
- injects block display CSS where needed
- persists `efs_style_map`
- persists `efs_acss_inline_style_map`
- returns the payload `{ styles, style_map }`

## 7. ACSS Architecture

ACSS is architecturally distinct from normal Bricks classes.

For ACSS classes:

- the converter registers inline declarations through `EFS_ACSS_Handler`
- creates a stub Etch style entry so the class still appears in the Etch Builder
- still creates a `style_map` entry for the Bricks class ID

This means some valid ACSS entries intentionally have empty CSS in `etch_styles`.

That is not a persistence bug. It is part of the model.

## 8. Target Import Architecture

`EFS_Style_Importer::import_etch_styles()` is the canonical target-side persistence path.

It performs four important operations:

### 8.1 Accept both current and legacy payload shapes

It supports:

- current payload: `{ styles, style_map }`
- legacy payload: flat style array

### 8.2 Deduplicate by selector

If multiple incoming entries share one selector, their CSS is merged.

This is especially important because generated CSS and parsed `_cssCustom` CSS may arrive as separate entries for the same logical class.

### 8.3 Merge with existing `etch_styles`

Existing styles remain the base.
Incoming styles overwrite older styles by selector.

This makes re-migration idempotent at selector level while preserving unrelated existing target styles.

### 8.4 Preserve storage keys

This is the key architectural rule of the importer:

- `etch_styles` must preserve style IDs as array keys
- those keys must remain aligned with `style_map`

The importer explicitly rebuilds the merged payload using preserved storage keys instead of allowing numeric reindexing.

That behavior is not an optimization. It is required for Builder correctness.

## 9. CSS Rebuild Architecture

After persistence, the importer triggers an Etch rebuild through three mechanisms:

1. increment `etch_svg_version`
2. invalidate repository caches
3. fire:
   - `etch_fusion_suite_styles_updated`
   - `etch_fusion_suite_rebuild_css`

The design goal is to avoid a hard dependency on Etch internal services while still forcing Etch to rebuild reliably.

## 10. Global CSS Architecture

Global Bricks CSS follows a separate architectural path:

- source reads `bricks_global_settings['customCss']`
- source sends it to `/import/global-css`
- target stores it in `etch_global_stylesheets`

This is intentionally separate from class migration because:

- global CSS is not keyed by Bricks class ID
- it does not participate in `style_map`
- it should not interfere with Builder style ID assignment

## 11. Progress and Counting Architecture

The CSS phase uses class-unit counts, not request counts.

The authoritative preferred count is:

```text
count(style_map)
```

Why:

- `style_map` mirrors the actual Bricks class -> Etch style relationship
- Builder correctness depends on those mappings
- request count is not meaningful when a single payload contains many classes

This same counting rule must be shared by:

- migration preview
- init totals
- target receiving counters
- CSS service migration counts

## 12. Content Integration Architecture

The CSS architecture is coupled to content migration through `efs_style_map`.

Downstream generators resolve:

- Bricks `_cssGlobalClasses`
- Bricks `_cssClasses`

into:

- Etch style IDs for block `styles[]`
- visible CSS class names for block attributes

`gutenberg_generator.php` uses `efs_style_map` both:

- forward: Bricks class ID -> Etch style ID
- reverse: Etch style ID -> selector/class name

This is why a valid frontend class name alone is insufficient. The block must also carry the correct Etch style ID.

## 13. Failure Modes the Architecture Must Prevent

The current design is explicitly shaped around preventing these failures:

### 13.1 Style ID drift

Same Bricks class gets a different Etch style ID on re-run.

Prevention:

- stable style ID resolution in the converter

### 13.2 Key loss during import

The importer stores styles under numeric indexes or selector keys instead of the incoming style IDs.

Prevention:

- explicit storage-key preservation in `EFS_Style_Importer`

### 13.3 Payload/progress mismatch

Progress counts all Bricks classes while the real migration only sends referenced classes.

Prevention:

- CSS service counts based on the real payload

### 13.4 Conflating global CSS with class CSS

Global Bricks CSS is mixed into `etch_styles` or expected to appear in `style_map`.

Prevention:

- dedicated `/import/global-css` route and `etch_global_stylesheets` storage

## 14. Current Design Decisions

The architecture currently makes these deliberate design choices:

1. class CSS and global CSS are separate flows
2. `style_map` is first-class state, not an incidental helper
3. the importer is direct option persistence plus rebuild trigger, not an intermediate Etch API abstraction
4. re-migration correctness is more important than naive append-only behavior
5. ACSS is modeled as a special path with stubs plus inline mapping

## 15. Files to Read Together

For architecture:

- `includes/css_converter.php`
- `includes/css/class-style-importer.php`
- `includes/services/class-css-service.php`
- `includes/css/class-class-reference-scanner.php`
- `includes/gutenberg_generator.php`
- `includes/repositories/class-wordpress-style-repository.php`

For operations and troubleshooting:

- `docs/css-migration-bricks-to-etch-de.md`
- `docs/css-migration-bricks-to-etch-en.md`
