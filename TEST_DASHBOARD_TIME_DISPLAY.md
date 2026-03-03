# Dashboard Time Display - Testing Checklist

**Purpose:** Verify the elapsed/remaining time display feature works correctly

**Date:** 2026-03-03  
**Feature:** Dashboard Elapsed/Remaining Time Calculation  
**Commit:** 85ddaa9c

---

## Pre-Test Setup

### Environment Check
- [ ] Docker running: `docker ps` shows containers
- [ ] WordPress started: `npm run dev` completed
- [ ] Bricks site accessible: http://localhost:8888
- [ ] Etch site accessible: http://localhost:8889
- [ ] Both sites login: admin / password
- [ ] Browser DevTools ready: F12

### Plugin Status
- [ ] Etch Fusion Suite activated on Bricks site
- [ ] No plugin errors in WordPress debug log
- [ ] No JavaScript errors in browser console

---

## Test 1: API Response Includes Time Fields ✓

**Objective:** Verify backend returns elapsed/ETA in progress API

**Steps:**
1. Start a migration (note migration ID from console or UI)
2. Wait 5-10 seconds for migration to process
3. In browser console, make API request:
   ```javascript
   fetch('/wp-admin/admin-ajax.php', {
     method: 'POST',
     headers: {'Content-Type': 'application/x-www-form-urlencoded'},
     body: 'action=efs_get_progress&migrationId=<MIGRATION_ID>'
   }).then(r => r.json()).then(d => console.log(d.data))
   ```
4. Check response contains:
   - `elapsed_seconds` (number > 0)
   - `estimated_time_remaining` (number or null)

**Expected Results:**
- [ ] API response includes `elapsed_seconds`
- [ ] API response includes `estimated_time_remaining`
- [ ] Values are numbers (not strings)
- [ ] No JavaScript errors

**Pass / Fail:** ______

---

## Test 2: Time Display Element Exists ✓

**Objective:** Verify DOM element for time display is present

**Steps:**
1. Open browser DevTools (F12)
2. Go to Elements tab
3. Find the migration progress section
4. Look for element: `<p data-efs-progress-time ...>`

**Expected Results:**
- [ ] Element exists in DOM
- [ ] Has class: `efs-progress-time`
- [ ] Has attribute: `data-efs-progress-time`
- [ ] Has `hidden` attribute initially
- [ ] Located in `.efs-card__header`

**Pass / Fail:** ______

---

## Test 3: Time Formatting Functions Available ✓

**Objective:** Verify time utility functions are loaded

**Steps:**
1. Open browser console (F12 → Console)
2. Type: `formatElapsed(1234)` and press Enter
3. Type: `formatEta(2467)` and press Enter

**Expected Results:**
- [ ] `formatElapsed(1234)` returns `'20:34'`
- [ ] `formatEta(2467)` returns `'~41m 7s remaining'`
- [ ] No "function not defined" errors
- [ ] Functions return strings

**Pass / Fail:** ______

---

## Test 4: Live Time Display During Migration ✓

**Objective:** Verify time display updates during migration

**Steps:**
1. Start a migration from dashboard
2. Watch progress display in real-time
3. Check that time display updates every 3 seconds
4. Format should be: "Elapsed: MM:SS • ~Xm Ys remaining"

**Expected Results:**
- [ ] Time display appears in dashboard header
- [ ] Shows "Elapsed: " prefix
- [ ] Shows formatted elapsed time (MM:SS)
- [ ] Shows ETA with "• " separator
- [ ] Updates every ~3 seconds
- [ ] Elapsed time increases
- [ ] ETA decreases
- [ ] No console errors
- [ ] No layout shift when appears

**Pass / Fail:** ______

---

## Test 5: ETA Calculation Accuracy ✓

**Objective:** Verify ETA estimates are reasonable

**Setup:**
- Migration with ~1000 items
- Monitoring for 30+ seconds

**Steps:**
1. Note elapsed time and items processed at T=0
2. Calculate rate: items_processed / elapsed_seconds
3. Calculate ETA: (items_total - items_processed) / rate
4. Compare with displayed ETA

**Expected Results:**
- [ ] Displayed ETA ≈ calculated ETA (within ±10%)
- [ ] ETA becomes more accurate over time
- [ ] ETA updates smoothly
- [ ] Large jumps in ETA only when processing speeds up/down

**Example:**
- Elapsed: 60 seconds
- Items processed: 120
- Items total: 1000
- Rate: 120/60 = 2 items/sec
- Remaining: 1000-120 = 880 items
- ETA: 880/2 = 440 seconds ≈ 7m 20s
- Displayed ETA should be close to this

**Pass / Fail:** ______

---

## Test 6: Time Display Disappears on Completion ✓

**Objective:** Verify display behavior when migration completes

**Steps:**
1. Let migration run until completion
2. Watch dashboard header as migration finishes
3. Check time display behavior

**Expected Results:**
- [ ] Time display remains visible during completion
- [ ] Time display shows final elapsed time
- [ ] ETA disappears (returns null)
- [ ] "Elapsed: MM:SS" still shows (no ETA part)
- [ ] Dashboard shows "Migration completed" message

**Pass / Fail:** ______

---

## Test 7: Time Display in Error State ✓

**Objective:** Verify display persists when migration errors

**Setup:** Force an error (e.g., bad target URL)

**Steps:**
1. Start migration with invalid settings
2. Let it fail
3. Check time display in error state

**Expected Results:**
- [ ] Time display remains visible
- [ ] Shows elapsed time at failure
- [ ] No ETA displayed (or null)
- [ ] Error message visible
- [ ] Time frozen at error point

**Pass / Fail:** ______

---

## Test 8: Responsive Design Check ✓

**Objective:** Verify display works on mobile/tablet

**Steps:**
1. Open DevTools (F12)
2. Toggle device toolbar (mobile view)
3. Test at different breakpoints: 320px, 768px, 1024px
4. Watch time display as migration runs

**Expected Results:**
- [ ] Time display visible on all screen sizes
- [ ] Text doesn't overflow or wrap awkwardly
- [ ] Alignment remains right-aligned
- [ ] Font size readable on mobile
- [ ] No horizontal scroll
- [ ] Progress bar still visible
- [ ] Items count visible

**Breakpoints Tested:**
- [ ] Mobile (320px): Pass / Fail
- [ ] Tablet (768px): Pass / Fail
- [ ] Desktop (1024px+): Pass / Fail

**Pass / Fail:** ______

---

## Test 9: Stale Migration Detection ✓

**Objective:** Verify time display during stale migration

**Setup:** Simulate no progress for > 5 minutes (browser mode TTL)

**Steps:**
1. Start migration
2. Let it timeout without progress updates
3. Check dashboard response

**Expected Results:**
- [ ] Dashboard shows "stale" status
- [ ] Time display remains visible
- [ ] Elapsed time still updates (if polling continues)
- [ ] User can resume or cancel
- [ ] No console errors

**Pass / Fail:** ______

---

## Test 10: Backward Compatibility ✓

**Objective:** Verify feature doesn't break existing functionality

**Steps:** Verify each feature still works:

1. Progress bar updates
   - [ ] Percentage increases over time
   - [ ] Bar fill width changes smoothly

2. Items count displays
   - [ ] "X / Y" format shows
   - [ ] Updates every poll
   - [ ] Shows skipped items when relevant

3. Migration steps
   - [ ] Steps list shows
   - [ ] Current step highlighted
   - [ ] Completed steps marked

4. Cancel button
   - [ ] Click "Cancel Migration"
   - [ ] Migration stops
   - [ ] Progress resets
   - [ ] Confirmation works

5. Dashboard state persistence
   - [ ] Refresh page mid-migration
   - [ ] Migration continues
   - [ ] Progress displays correctly
   - [ ] Time display updates

**Pass / Fail:** ______

---

## Test 11: Network Performance ✓

**Objective:** Verify no degradation in network requests

**Steps:**
1. Open DevTools → Network tab
2. Filter to: `admin-ajax.php`
3. Start migration
4. Monitor for 30 seconds

**Expected Results:**
- [ ] `efs_get_progress` requests at ~3 second intervals
- [ ] Response size unchanged (< 2KB)
- [ ] Response time < 500ms
- [ ] No additional network requests
- [ ] No failed requests

**Metrics:**
- Polling interval: ______ seconds (should be ~3)
- Response size: ______ bytes
- Response time: ______ ms
- Number of requests in 30s: ______ (should be ~10)

**Pass / Fail:** ______

---

## Test 12: Console Error Check ✓

**Objective:** Verify no JavaScript errors

**Steps:**
1. Open browser console (F12 → Console)
2. Set filter to: "Errors"
3. Run a complete migration
4. Monitor console throughout

**Expected Results:**
- [ ] No red error messages
- [ ] No "undefined function" errors
- [ ] No "Cannot read property" errors
- [ ] No CORS errors
- [ ] No 404s for JS files
- [ ] Warnings OK (yellow messages)

**Errors Found:** ______________________

**Pass / Fail:** ______

---

## Test 13: CSS Styling Verification ✓

**Objective:** Verify time display styling is correct

**Steps:**
1. Open DevTools → Elements tab
2. Find time display element: `[data-efs-progress-time]`
3. Check computed styles

**Expected Styling:**
- [ ] Font size: 12px
- [ ] Color: Muted gray (matches items count)
- [ ] Margin top: 4px
- [ ] Text align: right
- [ ] No visible borders
- [ ] No background color
- [ ] Display visible when not hidden

**Pass / Fail:** ______

---

## Test 14: Edge Case: Zero Elapsed Time ✓

**Objective:** Verify display when elapsed < 1 second

**Steps:**
1. Just after starting migration
2. Check time display immediately

**Expected Results:**
- [ ] Element hidden (no display)
- [ ] OR shows "Elapsed: 00:00"
- [ ] No errors
- [ ] Appears once elapsed > 0

**Pass / Fail:** ______

---

## Test 15: Edge Case: No Items Processed ✓

**Objective:** Verify display when items_processed = 0

**Steps:**
1. Start migration
2. Check API response when no items yet processed
3. Check dashboard display

**Expected Results:**
- [ ] Elapsed time shows (if elapsed > 0)
- [ ] ETA is null or not displayed
- [ ] Format: "Elapsed: MM:SS" (no ETA part)
- [ ] No calculation errors
- [ ] No console errors

**Pass / Fail:** ______

---

## Summary

### Tests Passed: _____ / 15
### Tests Failed: _____ / 15
### Tests Blocked: _____ / 15

### Critical Issues Found:
1. ______________________
2. ______________________
3. ______________________

### Recommendations:
- ______________________
- ______________________

### Tester Signature: ____________________  
### Date: ____________________

---

## Post-Test Actions

- [ ] Document any failures in TODOS.md
- [ ] Log issues in GitHub
- [ ] Update VERIFICATION_DASHBOARD_TIME_FIX.md with results
- [ ] If all pass: Proceed to production
- [ ] If failures: Create bug fix branch

---

## Regression Check (Run Once Per Release)

Before each release, re-run tests:
- Test 1: API Response ✓
- Test 10: Backward Compatibility ✓
- Test 12: Console Errors ✓

---

**Document Status:** Ready for Testing  
**Last Updated:** 2026-03-03 17:00
