# etchData Structure Validation Report

**Date:** 2026-02-08  
**Migration Report:** Run `npm run test:migration` for full migration report (see workflow below).  
**Exported JSONs:** `validation-results/post-<id>.json`, `validation-results/page-<slug>.json` (after export step)

## Prerequisites

- **Docker** must be running.
- **Environment:** `cd etch-fusion-suite && npx wp-env start --update && npm run health` (all checks incl. Bricks/Etch REST `/wp-json/wp/v2/posts`).
- If REST fails: `npm run e2e:wait` or manual delay, then re-run `npm run health`.

## How to Produce Validation Artifacts (execute sequentially)

1. **Environment:** `cd etch-fusion-suite && npx wp-env start --update && npm run health` (all checks incl. Bricks/Etch REST). If REST fails: `npm run e2e:wait` or manual delay.
2. **Test content:** `npm run create-test-content` (10+ Bricks posts/pages: headings, paragraphs, containers, sections, images). Verify: `npm run wp:bricks post list --format=count` > 4.
3. **Migrate:** `npm run test:migration` (Bricks→Etch via REST; polls to 'completed'; saves `migration-report-*.json`). If baseline exists: `SKIP_BASELINE=1 npm run test:migration`.
4. **Verify:** Post counts match (`npm run wp:bricks post list --format=count` == `npm run wp:etch post list --format=count`); check report JSON.
5. **Export:** `npm run export:etch` (exports all posts/pages to `validation-results/post-*.json`, `page-*.json` with etchData).
6. **Validate:** `npm run validate:etchdata validation-results/*.json` (target: Total Blocks 50+, Valid 100%, 0 invalid).
7. **Document:** Update this file with real totals, element breakdown table, sample `metadata.etchData` from exports, then commit: `git add validation-results/ ETCHDATA-VALIDATION.md && git commit -m "Complete etchData validation post-migration"`.

## Validator Fix (block attribute slicing)

Block attribute slicing in `scripts/validate-etchdata.js` was adjusted so the **leading `{` is included** when extracting JSON attributes. Previously, `rest` started after the `{`, so `extractBlockAttributes(rest, 0)` saw a string starting with `"` and failed, leaving `attrs` as `{}`. With the fix, `rest` is sliced from `afterName - 1`, so the JSON string starts with `{` and blocks with JSON attributes parse correctly and `attrs` is populated.

## Validation Summary

*(After running the full workflow: `npm run health` → `create-test-content` → `test:migration` → `export:etch` → `validate:etchdata`; update this section with real totals.)*

- **Total Posts/Pages Exported:** *(e.g. 12)*
- **Total Blocks Analyzed:** *(e.g. 52)*
- **Blocks with Valid etchData:** *(e.g. 52 — target 100%)*
- **Blocks with Invalid etchData:** *(e.g. 0)*

**Success metrics:** Validator exit 0, Total Blocks 50+, Valid 100%, Invalid 0. If migration did not complete (REST API not ready or timeout), run `npm run e2e:wait` then retry `npm run test:migration` until "Migration completed", then `export:etch` and `validate:etchdata`. *(Last run: 4 files exported, 30 blocks, 0 valid / 30 invalid — default WP content; migration was in progress but did not complete before timeout.)*

## Element Type Breakdown

*(From `npm run validate:etchdata -- validation-results/*.json`. Fill with real counts after successful migration.)*

| Element Type | Count | Valid | Invalid | Notes |
|-------------|-------|-------|---------|-------|
| Heading     | *(e.g. 12)* | *(e.g. 12)* | 0 | h2 tags (and h1–h6) |
| Paragraph   | *(e.g. 10)* | *(e.g. 10)* | 0 | p |
| Container   | *(e.g. 8)* | *(e.g. 8)* | 0 | div with children |
| Section     | *(e.g. 6)* | *(e.g. 6)* | 0 | section |
| Image       | *(e.g. 4)* | *(e.g. 4)* | 0 | figure |

## etchData Structure Validation

### Expected Structure

```json
{
  "metadata": {
    "etchData": {
      "origin": "etch",
      "name": "Element Label",
      "styles": ["style-id-1", "style-id-2"],
      "attributes": {
        "class": "class-1 class-2"
      },
      "block": {
        "type": "html",
        "tag": "div"
      }
    }
  }
}
```

### Validation Criteria

- ✅ `origin` is string "etch"
- ✅ `name` is non-empty string
- ✅ `styles` is array
- ✅ `attributes` is object
- ✅ `attributes.class` is string (not array)
- ✅ `block.type` is string "html"
- ✅ `block.tag` is valid HTML tag string

## Sample etchData by Element Type

After running the full workflow, paste actual `metadata.etchData` JSON from exports (e.g. from `validation-results/post-*.json`, `validation-results/page-*.json`). Example extraction: grep or JSON extract for blocks with `origin: "etch"`, `block: { type: "html", tag: "h2" }`, `attributes.class` as string, `styles: []`. Confirm: `class` is always a string, not an array; edge cases: empty class (omit or `attributes: {}` OK), nested containers OK.

The following are reference samples produced by the Bricks→Etch converters (`build_attributes()` and `class-image.php`).

### Heading (h2 / h1)

```json
{
  "origin": "etch",
  "name": "Heading 1",
  "styles": ["heading-style-id"],
  "attributes": { "class": "heading-style-id" },
  "block": { "type": "html", "tag": "h2" }
}
```

### Paragraph (p)

```json
{
  "origin": "etch",
  "name": "Body Copy",
  "styles": [],
  "attributes": {},
  "block": { "type": "html", "tag": "p" }
}
```

### Container (div)

```json
{
  "origin": "etch",
  "name": "Test Container 1",
  "styles": ["etch-container-style", "container-style-id"],
  "attributes": { "data-etch-element": "container", "class": "etch-container-style container-style-id" },
  "block": { "type": "html", "tag": "div" }
}
```

### Section (section)

```json
{
  "origin": "etch",
  "name": "Hero Section",
  "styles": ["etch-section-style", "section-style-id"],
  "attributes": { "data-etch-element": "section", "class": "etch-section-style section-style-id" },
  "block": { "type": "html", "tag": "section" }
}
```

### Image (figure)

```json
{
  "origin": "etch",
  "name": "Image",
  "styles": [],
  "attributes": {},
  "block": { "type": "html", "tag": "figure" },
  "nestedData": {
    "img": {
      "origin": "etch",
      "attributes": { "src": "https://example.com/image.jpg", "alt": "" },
      "block": { "type": "html", "tag": "img" }
    }
  }
}
```

With CSS classes (validator requires `attributes.class` to be a string):

```json
{
  "origin": "etch",
  "name": "Featured Image",
  "styles": ["image-style-id"],
  "attributes": { "class": "image-style-id" },
  "block": { "type": "html", "tag": "figure" },
  "nestedData": { "img": { "origin": "etch", "attributes": { "src": "...", "alt": "" }, "block": { "type": "html", "tag": "img" } } }
}
```

## Discrepancies Observed

- **Current exports (2026-02-08):** Exported 3 items from Etch (`post-1`, `page-sample-page`, `page-privacy-policy`). All 30 blocks have no `metadata.etchData` (default WP content). Migration was triggered but did not complete (REST API not available in this run), so Etch does not yet have migrated Bricks→Etch posts.
- **When migration completes:** Re-run `export:etch` and `validate:etchdata`. Expect **0 invalid** for blocks that contain etchData from the Bricks converters. If any invalid etchData remains, document here (e.g. image attributes, nested containers, empty `attributes.class`).

## Consistency and Edge Cases

- **Confirm:** `class` is always a string, not an array; edge cases: empty class (omit or `attributes: {}` OK), nested containers OK.
- **Verify consistency:** After export of migrated content, inspect JSON files in `validation-results/` and confirm `metadata.etchData` matches the Etch schema: `origin: "etch"`, `block.type: "html"`, `block.tag` correct for element type (h2/div/section/figure/p), `attributes` object, `attributes.class` string when present (not array), `styles` array.
- **Edge cases:** Nested containers (inner divs with etchData), blocks with no CSS classes (`attributes: {}` is OK), custom tags (e.g. Container with `tag: "ul"`) if used in test content.

## Converter Verification (Step 11)

Verified converter implementations:

| File | Uses build_attributes() | attributes.class | block.tag |
|------|--------------------------|------------------|-----------|
| class-heading.php (line 54) | Yes | String when set | get_tag(), default h2 |
| class-paragraph.php (line 55) | Yes | String when set | get_tag(), default p |
| class-container.php (line 57) | Yes | String when set (line 51–52) | get_tag(), default div |
| class-section.php (line 56) | Yes | String when set (line 50–51) | get_tag(), default section |
| class-image.php | No (builds etchData manually) | Always set; class when !empty($css_classes) | figure (hardcoded) |

- **Heading, Paragraph, Container, Section:** All call `build_attributes($label, $style_ids, $etch_attributes, $tag)`. The `etch_attributes` array sets `class` only when not empty, and always as a string. The `tag` is passed correctly.
- **Image:** Builds `etchData` manually. **Updated:** `includes/converters/elements/class-image.php` now always sets `$etch_data['attributes'] = array();` before the `if ( ! empty( $css_classes ) )` block so `attributes` is always an object; when classes exist, `attributes.class` is a string.

## Issues Found

1. **Image converter (resolved):** `class-image.php` now always sets `$etch_data['attributes'] = array();` before the if-classes block so `attributes` is always an object.
2. **Default content:** If exports were default WordPress content only, run the full workflow (create-test-content → test:migration → export:etch → validate:etchdata) with REST API ready; use `npm run e2e:wait` if health fails.

## Recommendations

1. **Full validation run:** Run `npm run health`, then `create-test-content`, `test:migration`, `export:etch`, `validate:etchdata -- validation-results/*.json`, and update this document with real totals, element breakdown, and sample `metadata.etchData` from exports.
