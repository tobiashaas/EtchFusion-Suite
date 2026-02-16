# Validation Checklist: Testing & Validation mit echten Bricks-Daten

Abschluss-Checkliste für das Ticket „Testing & Validation mit echten Bricks-Daten“. Alle Punkte müssen erfüllt sein, damit das Ticket als abgeschlossen gilt.

---

## Unit Tests

- [ ] All converter tests pass
- [ ] Expected block types updated (`wp:etch/element`)
- [ ] Expected attribute structure updated (flat)
- [ ] Assertions for `data-etch-element` correct
- [ ] 100% test coverage for new schema

---

## Integration Tests

- [ ] Full migration successful
- [ ] CSS migration: All classes → Etch styles
- [ ] Component migration: All components → wp_block
- [ ] Content migration: All posts/pages → Etch
- [ ] Media migration: All images → Etch

---

## Real Data Validation

- [ ] bricks.getframes.io backup imported
- [ ] Migration without errors
- [ ] Post count: Bricks = Etch
- [ ] Media count: Bricks = Etch
- [ ] All element types converted

---

## Etch Validation (CRITICAL)

- [ ] **NO "Mandatory Migration" warning**
- [ ] Blocks render correctly in editor
- [ ] No console errors
- [ ] Frontend rendering correct
- [ ] CSS styles applied
- [ ] Layout matches Bricks original

---

## Quality Assurance

- [ ] PHPCS: 0 errors
- [ ] PHPUnit: All tests pass
- [ ] Performance: No regression
- [ ] Memory: < 512M

---

## Mixed Content Validation

- [ ] Pages with Etch + Gutenberg core blocks migrate correctly
- [ ] No errors when components contain core blocks
- [ ] Mixed content renders correctly in editor and frontend

---

## Referenz

- **Key Test Files:** `tests/unit/Converters/ElementConvertersSchemaMigrationTest.php`, `StructuralConvertersSchemaTest.php`, `GutenbergGeneratorSchemaTest.php`, sowie Video-, TextLink-, Html-, Shortcode-, Code-, Text-, Svg-, NotesConverterTest
- **Integration:** `tests/integration/MigrationIntegrationTest.php`
- **Performance:** `tests/performance/MigrationPerformanceTest.php`
- **Mixed Content Reference:** `page-5.json`
