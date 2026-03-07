# Etch Fusion Suite - TODO List

**Updated:** 2026-03-07 (Style-ID Bug + Complete Validation Fix)

## 🚀 Current Development

### 🔨 Phase 1️⃣1️⃣: Migration Stability Fixes (Active)

**Context:** Fix critical bugs found during headless migration testing

#### 🔴 Completed (2026-03-07)

- [✅] **wp_tempnam Bug Fix** - Added require_once before wp_tempnam() call in class-svg.php (was calling function before loading WordPress file)
- [✅] **Budget Timeout Fix** - Changed fallback from 0 to 240 seconds when max_execution_time = 0 (prevents infinite loop in headless jobs)
- [✅] **Counter Timing Fix** - All touch_receiving_state() calls moved AFTER successful import (prevents false "completed" status)
- [✅] **complete_migration Validation** - Added check to prevent marking as complete when items_by_type shows incomplete phases
- [✅] **Backslash Fixes** - Added 120+ missing backslashes to WordPress function calls in class-migration-endpoints.php
- [✅] **CSS Error Handling** - Added proper error handling and return value checking for save_global_stylesheets()
- [✅] **UI Breakdown List** - Changed receiving status display from inline to `<ul>` list with percentages
- [✅] **Style-ID Mismatch Bug Fix** - Fixed class-style-importer.php to preserve style IDs instead of converting to numeric indices

#### 🟡 In Progress

- [ ] **Migration Hangs on "Complete"** - Bricks shows all phases complete but Etch receives nothing
  - Root cause: Phases marked complete without sending data
  - Investigation needed

#### 🔴 Known Issues from Last Migration

| Issue | Status | Notes |
|-------|--------|-------|
| Media: 457 received, 124 in DB | ⚠️ | Counter incremented before success (FIXED) |
| Pages: 480 migrated | ✅ | All arrived |
| CSS: Style-ID mismatch | 🔴 | FIXED - IDs lost during array_values() |
| Templates: 0/480 | ❌ | Never sent or failed silently |

---

## 🐛 CSS Style-ID Bug - Technical Details

### Problem Description
CSS classes exist in `etch_styles` and `efs_style_map` but are NOT applied to HTML elements in the frontend.

### Root Cause Analysis
1. **Bricks side**: Generates style ID like `3861282` for `.hero-juliet`
2. **style_map**: Stores mapping `bricks_id → {id: 3861282, selector: .hero-juliet}`
3. **CSS sent to Etch**: With key `3861282`
4. **BUG in Etch**: `class-style-importer.php` used `array_values($index)` which converts:
   - Key `3861282` → `12` (numeric index)
5. **Frontend**: Element references `3861282` → **NOT FOUND!**

### The Fix (Commit: 5557a72b)
**File:** `includes/css/class-style-importer.php`

| Before | After |
|--------|-------|
| `$merged_styles = array_values( $index )` | `$merged_styles[$style_id] = $style` |
| All keys → `0,1,2,...` | Style IDs like `3861282` preserved |

### Verification Steps
1. Check `etch_styles` - entries should have string keys (not numeric)
2. Check `efs_style_map` - should reference style IDs that exist in `etch_styles`
3. Check frontend - elements should have `data-etch-style-id` attributes matching IDs in `etch_styles`

### Example Debug Commands
```bash
# Check if style IDs are preserved
docker exec etch-cli wp --allow-root eval '
$styles = get_option("etch_styles", []);
foreach ($styles as $k => $s) {
    if (isset($s["selector"]) && $s["selector"] === ".hero-juliet") {
        echo "Key: $k, Selector: " . $s["selector"];
    }
}
'

# Compare with efs_style_map
docker exec etch-cli wp --allow-root eval '
$map = get_option("efs_style_map", []);
echo $map["edsgbt"]["id"];  # Should match key in etch_styles
'
```

---

## 📋 API Refactoring Summary (Completed ✅)

### Final File Sizes

| File | Before | After | Change |
|------|--------|-------|--------|
| api_endpoints.php | ~2700 | 1490 | -1210 lines |
| class-permissions.php | - | 354 | NEW |
| class-rate-limiting.php | - | 102 | NEW |
| class-cors.php | - | 215 | NEW |
| class-migration-endpoints.php | - | 1001 | NEW |

**Total:** 3162 lines (modular, maintainable)

---

## 🧪 Test Results

```
✅ 259 tests, 805 assertions - all passing
✅ PHPCS: 0 errors, 1 unrelated warning
```

---

## 📝 Todo Summary for Next Session

### High Priority

1. [ ] **Debug CSS Not Applied** - FIXED: Style-ID mismatch in class-style-importer.php
2. [ ] **Verify Media Counter Fix** - Fresh migration to confirm counter only increments on success
3. [ ] **Verify Complete Validation** - Fresh migration to confirm incomplete phases are rejected
4. [ ] **Investigate Migration Hang** - Bricks marks phases complete without sending data

### Medium Priority

5. [ ] **wp-env Persistence** - Configure volumes to prevent data loss on restart
6. [ ] **Rate Limit Tuning** - Adjust limits if migrations are still timing out

### Completed This Session

- [✅] API Refactoring (Tickets 1-6)
- [✅] wp_tempnam require_once order
- [✅] Budget timeout fallback
- [✅] Counter-after-import fix
- [✅] complete_migration validation
- [✅] 120+ backslash fixes
- [✅] CSS error handling
- [✅] UI breakdown list
- [✅] Style-ID mismatch fix (class-style-importer.php)

---

## ✅ Previously Completed

See sections below for historical completion status.

