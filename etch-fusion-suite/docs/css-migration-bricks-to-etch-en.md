# CSS Migration Bricks -> Etch

Status: 2026-03-08

This guide documents the full CSS migration path in Etch Fusion Suite:

- where Bricks stores CSS-related data
- how that data is converted into Etch-compatible styles
- which data is stored on source and target
- how Builder class assignment works
- how to recognize failures
- how to debug them systematically

This file is intentionally technical. The goal is not a product overview, but a durable operational guide for debugging, maintenance, and regression checks.

## 1. Short version

CSS migration is not just "send CSS to the target".

In practice, three things must always stay consistent:

1. The Bricks class identifier must exist in `efs_style_map`.
2. The Etch style ID referenced there must be a real key in `etch_styles`.
3. That same Etch style ID must later appear in the migrated Etch/Gutenberg block under `styles[]`.

If only the visible CSS class is present in the frontend DOM, but point 2 or 3 is missing or drifted, the frontend may still look correct while the Etch Builder cannot resolve the class correctly.

That is the most important invariant in the whole CSS migration flow.

## 2. Terms

There are three different ID/styling systems. They must not be mixed.

### 2.1 Element IDs

Example:

- Bricks: `brxe-abc1234`
- Etch: `etch-abc1234`

These are HTML IDs for individual elements. They are used for element-scoped selectors such as `#brxe-...` or `#etch-...`.

### 2.2 Bricks Global Class IDs

Bricks does not store global classes only by name. It also stores an internal ID.

Example:

- Bricks class name: `card`
- Bricks class ID: `efs-probe-card-id`

That ID is the primary link between Bricks content and `efs_style_map`.

### 2.3 Etch Style Manager IDs

Etch stores reusable styles under internal keys such as:

- `2af6e4f`
- `etch-section-style`

Normal migrated Bricks classes receive an Etch style ID like `2af6e4f`.
Etch framework styles like `etch-section-style` are internal read-only styles without a Bricks counterpart.

## 3. End-to-end flow

### 3.1 Source side

1. Bricks classes are loaded from WordPress data.
2. Referenced classes are optionally filtered by the selected post types.
3. Structured Bricks settings are converted into CSS.
4. Raw custom CSS and breakpoint CSS are parsed and merged.
5. This produces a payload:
   - `styles`
   - `style_map`
6. That payload is sent to the target endpoint `/wp-json/efs/v1/import/css-classes`.
7. Global Bricks CSS from `bricks_global_settings['customCss']` is sent separately to `/wp-json/efs/v1/import/global-css`.

### 3.2 Target side

1. The CSS payload is imported.
2. Styles are deduplicated by selector.
3. Existing `etch_styles` are merged with the new styles.
4. `efs_style_map` is saved.
5. Etch caches are invalidated and a CSS rebuild is triggered.
6. During later content migration, style IDs from `efs_style_map` are written into block `styles[]`.

## 4. Where the data lives

## 4.1 Source: Bricks data

The central CSS-related data sources on the Bricks side are:

| Location | Purpose |
| --- | --- |
| `bricks_global_classes` | global Bricks classes including internal ID, name, and settings |
| `bricks_global_settings['customCss']` | site-wide global Bricks CSS |
| `efs_active_migration['options']` | active migration options such as `selected_post_types` and `restrict_css_to_used` |
| Post meta `_bricks_page_content_2` / `_bricks_page_content` | Bricks element tree with class references |
| Post meta `_bricks_settings` / `_bricks_page_settings` | wrapper/page settings with additional class references |
| `efs_inline_css_*` | temporary inline CSS options produced during code/element CSS parsing |
| `efs_acss_inline_style_map` | helper map for ACSS utilities and inline styles |

## 4.2 Target: Etch data

The relevant target-side stores are:

| Location | Purpose |
| --- | --- |
| `etch_styles` | main registry for Etch styles |
| `efs_style_map` | mapping Bricks class ID -> `{ id, selector }` |
| `etch_global_stylesheets` | global Etch CSS, where Bricks `customCss` is stored |
| `etch_svg_version` | cache-buster for Etch rebuilds |
| `efs_receiving_migration` | target-side progress state during import |

## 4.3 Repositories and caches

Style access runs through `includes/repositories/class-wordpress-style-repository.php`.

Important caches/transients:

- `efs_cache_etch_styles`
- `efs_cache_style_map`
- `efs_cache_global_stylesheets`
- `efs_cache_svg_version`
- `efs_cache_bricks_global_classes`

## 5. Which files matter

| File | Responsibility |
| --- | --- |
| `includes/services/class-css-service.php` | orchestrates CSS migration and global CSS transport |
| `includes/css_converter.php` | actual Bricks -> Etch conversion |
| `includes/css/class-class-reference-scanner.php` | scans referenced global classes from Bricks content |
| `includes/css/class-style-importer.php` | imports `styles` + `style_map` on the target |
| `includes/class-migration-endpoints.php` | REST endpoints for CSS import and global CSS import |
| `includes/api_client.php` | sends CSS payloads to the target |
| `includes/gutenberg_generator.php` | writes style IDs into migrated blocks |
| `includes/repositories/class-wordpress-style-repository.php` | stores `etch_styles`, `efs_style_map`, `etch_global_stylesheets` |

## 6. How the source finds Bricks classes

Discovery is not blind. It uses selection and reference logic.

### 6.1 Starting point

The global class list comes from:

```php
get_option( 'bricks_global_classes', array() )
```

Each entry typically contains:

- `id`
- `name`
- `settings`

### 6.2 Restrict-to-used

By default, CSS migration is restricted to classes that are actually referenced.

Relevant options:

- `efs_active_migration['options']['selected_post_types']`
- `efs_active_migration['options']['restrict_css_to_used']`

If `restrict_css_to_used` is enabled, the `Class Reference Scanner` scans all relevant Bricks posts and collects references from:

- `_cssGlobalClasses`
- `_cssClasses`
- `_bricks_settings`
- `_bricks_page_settings`

That means only classes actually used in the selected post types are migrated.

### 6.3 Why this matters

If preview/init totals count all Bricks classes but the real migration only sends used classes, progress and receiving status will drift. That was already a real bug.

Because of that:

- wizard preview
- init totals
- conversion payload
- receiving counters

must all use the same counting rule.

## 7. How conversion works

The actual conversion runs in `convert_bricks_classes_to_etch()`.

## 7.1 Etch built-in styles first

At the beginning, Etch internal framework styles are pre-seeded into `etch_styles`.

Typical keys:

- `etch-section-style`
- `etch-container-style`
- `etch-iframe-style`
- `etch-block-style`

Important:

- these styles are read-only
- they never belong in `efs_style_map`
- they do not have a Bricks counterpart

## 7.2 ACSS special case

ACSS utilities are handled differently from normal Bricks classes:

- an inline style map is built in `efs_acss_inline_style_map`
- an empty stub is also created in `etch_styles` so the class still appears in the Builder
- for every Bricks class ID, an `efs_style_map` entry is still created

That means:

- some ACSS classes intentionally have empty `css` in `etch_styles`
- that is not an error
- the class is still registered for Builder and content mapping

## 7.3 Structured settings -> CSS

For normal Bricks classes, structured `settings` are converted into CSS:

- spacing
- typography
- background
- border
- flex/grid
- positioning
- effects
- responsive variants

After that, CSS is normalized, for example:

- variables
- logical properties instead of physical properties
- final CSS cleanup

## 7.4 Stable style IDs

A critical part is how the Etch style ID is chosen.

The ID must not randomly change on every run when the same Bricks class already has a known mapping. Otherwise these pieces drift apart later:

- `efs_style_map`
- `etch_styles`
- block `styles[]`

Because of that, conversion tries to reuse existing IDs from a previous `style_map`.

## 7.5 Raw custom CSS

In addition to structured settings CSS, conversion also collects raw Bricks CSS from:

- `_cssCustom`
- `_cssCustom:<breakpoint>`
- element-scoped inline CSS

That CSS is assembled into a temporary stylesheet string and parsed again.

During that phase:

- class rules are mapped to style IDs
- ID rules for element-specific styles are extracted
- nested selectors are normalized
- logical properties are applied

## 7.6 Breakpoint CSS

Breakpoint CSS is not immediately merged into the base rule. It is collected separately first and appended to the correct style later.

That keeps:

- responsive rules intact
- media queries attached to the right migrated classes

## 7.7 Final pass

Before returning the payload, conversion also performs:

- final CSS normalization
- injection of `display:flex` for specific block cases
- saving `efs_style_map`
- saving `efs_acss_inline_style_map`

## 8. What the payload looks like

CSS migration works with a payload shaped like this:

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

Important:

- `styles` is the Etch registry
- `style_map` is the Bricks -> Etch translation layer
- `style_map[*].id` must point to a real key in `styles`

## 9. API transfer

### 9.1 Class CSS

The source sends class CSS to:

```text
/wp-json/efs/v1/import/css-classes
```

This happens through `EFS_API_Client::send_css_styles()`.

For progress, CSS quantity is not derived from HTTP requests. It is derived from the payload:

- preferred: `count(style_map)`
- fallback: `count(styles)`

That is intentional, because Builder assignment depends on the number of class mappings, not the number of requests.

### 9.2 Global CSS

Bricks `customCss` is sent separately to:

```text
/wp-json/efs/v1/import/global-css
```

Payload:

```json
{
  "id": "bricks-global",
  "name": "Migrated from Bricks",
  "css": "....",
  "type": "custom"
}
```

That CSS does not go into `etch_styles`. It goes into `etch_global_stylesheets`.

## 10. How the target imports it

The target endpoint `import_css_classes()` calls `import_etch_styles()` internally.

The import logic has four core steps.

### 10.1 Deduplication by selector

If two incoming style entries share the same selector, their CSS is merged.

Example:

- settings-based style for `.card`
- `_cssCustom` follow-up for `.card`

Both are consolidated into one merged entry.

### 10.2 Merge with existing `etch_styles`

Existing `etch_styles` remain the base.
Incoming styles overwrite older entries by selector.

That matters for re-migrations:

- old target styles are updated
- unaffected manual Etch styles remain intact

### 10.3 Preserve storage keys

This is the most critical import behavior:

`etch_styles` must not be stored as a numerically reindexed array.

Instead, style keys must be preserved, because:

- `efs_style_map[*].id` points at those keys
- block `styles[]` uses the same IDs

If imported styles lose their keys and are stored only by selector or numeric index, you get the exact failure mode:

- class visible in the frontend DOM
- Builder cannot assign it correctly

### 10.4 Cache rebuild

After saving, the importer triggers:

- invalidation of style caches
- increment of `etch_svg_version`
- hooks:
  - `etch_fusion_suite_styles_updated`
  - `etch_fusion_suite_rebuild_css`

That makes Etch rebuild and re-render the updated CSS.

## 11. How content later references the same styles

CSS migration is only half the job. The other half is content migration.

When Bricks elements are later converted into Etch/Gutenberg blocks, style IDs are resolved through `efs_style_map`.

### 11.1 From Bricks ID to Etch style ID

Bricks elements often carry:

- `_cssGlobalClasses`
- or class names in `_cssClasses`

The content generators resolve those through:

- Bricks class ID
- then `efs_style_map[bricks_id].id`
- then that Etch style ID is written into `styles[]`

### 11.2 From style ID to visible class name

For `attributes.class` and similar visible class attributes, the pipeline later resolves back through `efs_style_map[*].selector`.

That means:

- `styles[]` is Builder-/Etch-internal
- `attributes.class` is the visible CSS class in the DOM

They must match logically, but they are not the same field.

## 12. Builder invariant: what must always match

For every migrated Bricks class, these three locations must represent the same logical style:

```text
efs_style_map[bricks_class_id].id
==
key in etch_styles
==
entry in block.styles[]
```

Example:

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

If only `attributes.class = "efs-probe-card"` is correct, but `styles[]` or `etch_styles` do not point to the same ID, Builder assignment is broken.

## 13. Global CSS: special case

Global Bricks CSS is not part of `style_map`.

It lives separately:

- Source: `bricks_global_settings['customCss']`
- Target: `etch_global_stylesheets['bricks-global']`

Because of that:

- missing global CSS is a different failure from broken class assignment
- `customCss` does not directly control Builder resolution of individual class IDs

## 14. What must happen on re-migrations

A correct re-run must not create new drifting IDs.

Correct behavior:

- reuse the existing style ID for a known Bricks class
- update existing `etch_styles` entries by selector
- rebuild `efs_style_map` consistently

Incorrect behavior:

- generate a new random style ID despite an existing confirmed mapping
- store `etch_styles` under a different key than the one referenced by `style_map`
- numerically reindex styles during import

## 15. Typical failure patterns

### 15.1 Class visible on the frontend, but not in the Etch Builder

Almost always one of these:

- `efs_style_map[*].id` points to no real key in `etch_styles`
- block `styles[]` contains a different ID than `efs_style_map`
- `style_map` was saved, but `etch_styles` was imported with wrong keys

### 15.2 CSS exists in the Builder, but is not active on the frontend

Possible causes:

- Etch caches were not invalidated
- CSS rebuild was not triggered
- the style exists, but the block does not reference it in `styles[]`
- global CSS was written to `etch_global_stylesheets`, but the concrete class actually belongs in `etch_styles`

### 15.3 CSS progress is wrong

Typical causes:

- init totals count "all Bricks classes"
- the real payload only sends "used classes"
- receiving state counts requests instead of style mappings

### 15.4 ACSS class is visible, but `css` in `etch_styles` is empty

That can be correct.

For ACSS, the system often uses:

- Builder stubs in `etch_styles`
- actual declarations in `efs_acss_inline_style_map`

## 16. Systematic debug checklist

If CSS looks wrong or Builder assignment is missing, check in this order.

### 16.1 Source: do the classes exist at all?

```powershell
docker exec bricks-cli wp option get bricks_global_classes --path=/var/www/html --format=json
```

Check:

- does the expected Bricks class exist?
- does it have `id`, `name`, `settings`?

### 16.2 Source: does global Bricks CSS exist?

```powershell
docker exec bricks-cli wp option get bricks_global_settings --path=/var/www/html --format=json
```

Check:

- does `customCss` exist?
- is it empty?

### 16.3 Source: is CSS restricted to used classes?

```powershell
docker exec bricks-cli wp option get efs_active_migration --path=/var/www/html --format=json
```

Check:

- `selected_post_types`
- `restrict_css_to_used`

### 16.4 Source: was a `style_map` generated?

```powershell
docker exec bricks-cli wp option get efs_style_map --path=/var/www/html --format=json
```

Check:

- does the Bricks key exist?
- does it contain `id` + `selector`?

### 16.5 Target: was the same ID stored in `etch_styles`?

```powershell
docker exec etch-cli wp option get etch_styles --path=/var/www/html --format=json
```

Check:

- does the key from `efs_style_map[*].id` actually exist?
- does the selector match?

### 16.6 Target: was global CSS stored?

```powershell
docker exec etch-cli wp option get etch_global_stylesheets --path=/var/www/html --format=json
```

Check:

- does `bricks-global` exist?
- does it contain the expected CSS?

### 16.7 Was the CSS endpoint hit at all?

```powershell
docker logs etch --since 10m 2>&1 | Select-String -Pattern '/wp-json/efs/v1/import/css-classes|/wp-json/efs/v1/import/global-css'
```

Check:

- did `import/css-classes` return `200`?
- did `import/global-css` return `200`?

### 16.8 Do block data and style IDs match?

Inspect the migrated post/block on the target and make sure:

- block `styles[]` contains the Etch style ID
- `attributes.class` contains the visible CSS class
- the same ID exists in `etch_styles`

## 17. Safe reset and recovery steps

If you want to rebuild CSS migration from scratch:

1. Back up the current options first:
   - `etch_styles`
   - `efs_style_map`
   - `etch_global_stylesheets`
2. Reset only the relevant data. Do not blindly destroy the whole environment.
3. Re-run the migration after the reset.
4. Immediately verify the invariant:
   - `efs_style_map`
   - `etch_styles`
   - one real migrated block

Dangerous patterns:

- deleting `etch_styles` while leaving `efs_style_map`
- deleting `efs_style_map` while keeping old migrated block data
- resetting target CSS without re-migrating content

## 18. What must never break again

These rules should hold for every fix and every refactor:

1. `style_map` is not optional. It is the central join between Bricks and Etch.
2. Import must never lose style keys or numerically reindex them.
3. Content generators and CSS importer must see the same Etch style ID.
4. Progress counting must use the same CSS unit as the real migration.
5. Global CSS and class CSS are two separate data streams.
6. ACSS must not be treated like a normal Bricks class.
7. Re-migrations must keep IDs stable.

## 19. Recommended regression checks after changes

After every change in the CSS path, at minimum verify this:

1. A normal Bricks global class is imported.
2. `efs_style_map[bricks_id].id` exists in `etch_styles`.
3. A migrated block references that same ID in `styles[]`.
4. The visible class appears in the DOM.
5. The class is correctly resolvable in the Etch Builder.
6. Bricks `customCss` appears in `etch_global_stylesheets['bricks-global']`.
7. Re-migrating the same class does not create a drifting new ID.
8. ACSS classes still behave correctly.

## 20. Related files

For deeper architecture detail:

- `docs/css-converter-architecture.md`
- `docs/etch-data-schema-reference.md`
- `docs/wo-liegen-die-daten.md`

For the production CSS migration path in code:

- `includes/services/class-css-service.php`
- `includes/css_converter.php`
- `includes/css/class-style-importer.php`
- `includes/class-migration-endpoints.php`
- `includes/api_client.php`
- `includes/gutenberg_generator.php`
- `includes/repositories/class-wordpress-style-repository.php`
