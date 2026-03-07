# Etch Fusion Suite - TODO List

**Updated:** 2026-03-07 (Post-Migration Bug Fixes + UI Improvements)

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

#### 🟡 In Progress

- [ ] **CSS Not Applied to Elements** - CSS exists in DB but not applied to HTML elements
  - Root cause: Unknown (possibly style repository caching or merge conflict)
  - Next: Debug with fresh migration

#### 🔴 Known Issues from Last Migration

| Issue | Status | Notes |
|-------|--------|-------|
| Media: 457 received, 124 in DB | ⚠️ | Counter incremented before success (FIXED) |
| Pages: 480 migrated | ✅ | All arrived |
| CSS: In DB but not applied | 🔴 | Needs investigation |
| Templates: 0/480 | ❌ | Never sent or failed silently |

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

1. [ ] **Debug CSS Not Applied** - CSS saved to etch_global_stylesheets but not appearing on frontend
2. [ ] **Verify Media Counter Fix** - Fresh migration to confirm counter only increments on success
3. [ ] **Verify Complete Validation** - Fresh migration to confirm incomplete phases are rejected

### Medium Priority

4. [ ] **wp-env Persistence** - Configure volumes to prevent data loss on restart
5. [ ] **Rate Limit Tuning** - Adjust limits if migrations are still timing out

### Completed This Session

- [✅] API Refactoring (Tickets 1-6)
- [✅] wp_tempnam require_once order
- [✅] Budget timeout fallback
- [✅] Counter-after-import fix
- [✅] complete_migration validation
- [✅] 120+ backslash fixes
- [✅] CSS error handling
- [✅] UI breakdown list

---

## ✅ Previously Completed

See sections below for historical completion status.

