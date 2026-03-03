# Code Review & Testing Process - Etch Fusion Suite

**Purpose:** Standardized process to prevent bugs and ensure quality

**Version:** 1.0  
**Effective Date:** 2026-03-03  
**Based On:** Dashboard Elapsed/Time Fix Verification (Commit 85ddaa9c)

---

## Overview

This document defines the standard process for reviewing and testing code changes in Etch Fusion Suite. It ensures:

1. ✅ Code quality (syntax, standards)
2. ✅ Logical correctness (design, edge cases)
3. ✅ Integration completeness (all layers touched)
4. ✅ Testing coverage (manual + automated)
5. ✅ Documentation (for future devs)

---

## Phase 1: Pre-Implementation Review

**Goal:** Ensure architecture is sound before coding

### 1.1 Requirements Analysis
- [ ] Understand what change is needed
- [ ] Identify all affected systems (backend, frontend, data layer)
- [ ] Document scope boundaries
- [ ] Identify edge cases early

### 1.2 Design Review
- [ ] Sketch data flow: backend → API → frontend
- [ ] List all files to be modified
- [ ] Plan for backward compatibility
- [ ] Consider performance impact
- [ ] Plan error handling strategy

### 1.3 Impact Analysis
- [ ] Will existing functionality break?
- [ ] Are there dependent features?
- [ ] Performance implications?
- [ ] Database changes needed?

---

## Phase 2: Implementation

**Goal:** Write clean, standard-compliant code

### 2.1 Code Writing Standards

**PHP Files:**
```
- Use PSR-4 namespace: Bricks2Etch\...
- Follow WordPress Coding Standards
- Add docblocks to all functions
- Use type hints: function foo(array $data): array
- Use strict comparisons: === not ==
```

**JavaScript Files:**
```
- Use ES6 modules: import/export
- Add JSDoc comments
- Use const/let, not var
- Use arrow functions where appropriate
```

**CSS Files:**
```
- Use CSS custom properties (--efs-*)
- Follow BEM naming: .efs-component__element
- Comment complex selectors
```

### 2.2 New Methods/Functions
- [ ] Add proper docblock with parameters and return type
- [ ] Handle null/empty inputs gracefully
- [ ] Test with edge cases in mind
- [ ] Add inline comments for complex logic

---

## Phase 3: Automated Quality Checks

**Goal:** Catch syntax errors and standard violations

### 3.1 PHP Syntax Check (REQUIRED)

```bash
php -l includes/services/class-progress-manager.php
```

**Exit code must be 0 (success)**

Every modified PHP file must pass.

### 3.2 JavaScript Syntax Check (REQUIRED)

```bash
node --check assets/js/admin/migration.js
```

**Exit code must be 0 (success)**

Every modified JavaScript file must pass.

### 3.3 PHPCS WordPress Standards (REQUIRED)

```bash
cd etch-fusion-suite
composer exec phpcs -- includes/services/class-progress-manager.php
```

**Expected output:** "0 sniffs to check, X minutes to run"

Fix any violations:
```bash
composer exec phpcbf -- includes/services/class-progress-manager.php
```

All modified PHP files must pass.

### 3.4 Linting Summary Checklist

- [ ] All PHP files: `php -l` ✅ PASS
- [ ] All JS files: `node --check` ✅ PASS
- [ ] All PHP files: `composer exec phpcs` ✅ PASS (0 violations)

---

## Phase 4: Code Review

**Goal:** Verify logic, integration, and correctness

### 4.1 Self-Review Checklist

**For each modified file:**

1. **Does it compile/run?**
   - [ ] Syntax is valid
   - [ ] All required dependencies imported
   - [ ] No undefined variables

2. **Is logic correct?**
   - [ ] Algorithm matches requirements
   - [ ] Edge cases handled (null, empty, zero)
   - [ ] No off-by-one errors
   - [ ] Loops terminate correctly

3. **Is it safe?**
   - [ ] Input validation present
   - [ ] SQL injection protected (use `$wpdb->prepare`)
   - [ ] XSS protection (use `esc_*` functions)
   - [ ] CSRF protection (use nonces)

4. **Is it performant?**
   - [ ] No N+1 query problems
   - [ ] No unnecessary loops
   - [ ] Caching used where appropriate
   - [ ] No blocking operations on frontend

5. **Is it documented?**
   - [ ] Docblocks present
   - [ ] Complex logic has inline comments
   - [ ] Parameters documented
   - [ ] Return types documented

### 4.2 Integration Review

**Verify data flows through all layers:**

```
1. Is backend calculation correct?
   - [ ] Values computed correctly
   - [ ] Edge cases handled
   - [ ] Return type matches expectation

2. Does it flow through API?
   - [ ] New fields included in response
   - [ ] Backwards compatible (old fields still there)
   - [ ] Proper array keys

3. Does JavaScript receive it?
   - [ ] API response parsed correctly
   - [ ] Data passed to next layer (UI)
   - [ ] Defaults provided if missing

4. Does frontend display it?
   - [ ] DOM element exists
   - [ ] Data bound to element
   - [ ] User can see result
```

### 4.3 Checklist Template

Create a file: `REVIEW_<FEATURE>.md`

```markdown
# Code Review: <Feature Name>

## Files Modified
- [ ] <file1> - reviewed by [name]
- [ ] <file2> - reviewed by [name]

## Syntax Validation
- [ ] PHP files pass: php -l
- [ ] JS files pass: node --check
- [ ] PHPCS: composer exec phpcs

## Logic Review
- [ ] Core algorithm correct
- [ ] Edge cases handled
- [ ] No security issues
- [ ] Performance acceptable

## Integration
- [ ] Backend → API complete
- [ ] API → Frontend complete
- [ ] All data flows working

## Backward Compatibility
- [ ] Old code paths still work
- [ ] No breaking changes
- [ ] Graceful fallback for missing data

## Sign-Off
- [x] Code review complete
- [ ] Testing complete
- [ ] Documentation complete
```

---

## Phase 5: Data Flow Verification

**Goal:** Ensure data reaches user correctly

### 5.1 Trace Data Path

For **Backend → API → Frontend** changes:

1. **Backend**: Create/modify data
   - Where is data calculated?
   - What is the data type?
   - What are valid ranges?

2. **API**: Return data
   - Is new field in response array?
   - What array key is used?
   - Is it always present (or graceful default)?

3. **Frontend**: Receive data
   - Is response parsed correctly?
   - Is data passed to next handler?
   - Are defaults provided?

4. **Display**: Show to user
   - Is DOM element present?
   - Is data bound to element?
   - Does user see the result?

### 5.2 Data Flow Diagram

Example for time display feature:

```
BACKEND CALCULATION
    ↓
    time() - strtotime($started_at) = elapsed_seconds
    (items_total - items_processed) / rate = eta
    ↓
API RESPONSE
    ↓
    {
      "elapsed_seconds": 1234,
      "estimated_time_remaining": 2467
    }
    ↓
JAVASCRIPT HANDLER
    ↓
    const elapsed = data?.elapsed_seconds || 0;
    const eta = data?.estimated_time_remaining || null;
    updateProgress({elapsed_seconds: elapsed, ...});
    ↓
DISPLAY FUNCTION
    ↓
    formatElapsed(1234) → "20:34"
    formatEta(2467) → "~41m 7s remaining"
    timeDisplay.textContent = "Elapsed: 20:34 • ~41m 7s remaining"
    ↓
DOM UPDATE
    ↓
    <p data-efs-progress-time>Elapsed: 20:34 • ~41m 7s remaining</p>
```

### 5.3 Verification Questions

- [ ] Is data created/calculated in right place?
- [ ] Is data included in API response?
- [ ] Is JavaScript receiving the data?
- [ ] Is data transformed correctly (formatting)?
- [ ] Is result displayed in DOM?
- [ ] Does user see expected output?

---

## Phase 6: Manual Testing

**Goal:** Verify feature works in real environment

### 6.1 Setup Test Environment

```bash
# Start Docker environment
npm run dev

# Wait for both instances to be ready
npm run health

# Log in to both sites
# Bricks: http://localhost:8888 (admin/password)
# Etch: http://localhost:8889 (admin/password)
```

### 6.2 Manual Test Plan

Create test document: `TEST_<FEATURE>.md`

**Minimum test cases:**

1. **Happy path**: Normal operation
   - Feature works as designed
   - Output is correct
   - No errors

2. **Edge cases**:
   - Empty/null inputs
   - Zero values
   - Boundary conditions
   - Maximum sizes

3. **Error handling**:
   - Invalid input
   - Missing data
   - API errors
   - Graceful degradation

4. **Integration**:
   - Doesn't break existing features
   - Works with other features
   - No side effects

5. **Performance**:
   - No slowdown
   - No memory leaks
   - No excessive network requests

### 6.3 Browser DevTools Checks

```javascript
// Console
- No red error messages
- No "undefined" errors
- All functions available

// Network
- API requests complete successfully
- Response includes new fields
- No 404s or 500s
- Response time acceptable

// Elements
- DOM elements exist
- Data bound correctly
- CSS styles applied
- No layout shifts
```

### 6.4 Regression Testing

Verify existing features still work:

- [ ] Feature A: ______ (works / broken)
- [ ] Feature B: ______ (works / broken)
- [ ] Feature C: ______ (works / broken)
- [ ] Performance: ______ (same / worse / better)

---

## Phase 7: Documentation

**Goal:** Help future developers understand changes

### 7.1 Code Comments

**Every new function/method needs docblock:**

```php
/**
 * Calculate elapsed time and ETA from progress data.
 *
 * Computes elapsed seconds since migration start and estimates
 * remaining time based on current processing rate.
 *
 * @param array $progress Progress data with 'started_at', 'items_processed', 'items_total'.
 * @return array Progress data enriched with 'elapsed_seconds' and 'estimated_time_remaining'.
 *
 * @throws \Exception If timestamp parsing fails.
 */
public function enrich_progress_with_times( array $progress ): array {
```

### 7.2 Complex Logic Comments

```php
// Prevent division by zero: only calculate ETA if we have
// progress data and time elapsed.
if ( $items_total > 0 && $items_processed > 0 && $elapsed > 0 ) {
    // Calculate items per second rate
    $rate = $items_processed / $elapsed;
    
    // Project remaining time: (remaining items) / (rate)
    $eta = (int) round( ( $items_total - $items_processed ) / $rate );
}
```

### 7.3 Update DOCUMENTATION.md

Add section explaining new feature:

```markdown
### Progress Time Display

Elapsed and estimated remaining time are calculated and displayed
on the migration dashboard.

**Backend:**
- Progress Manager: `enrich_progress_with_times()` method
- Calculates elapsed from `started_at` timestamp
- Estimates remaining based on items/rate

**Frontend:**
- JavaScript receives `elapsed_seconds` and `estimated_time_remaining`
- Formats using `formatElapsed()` and `formatEta()` utilities
- Displays in `[data-efs-progress-time]` element

**Example:**
Display: "Elapsed: 20:34 • ~41m 7s remaining"
```

### 7.4 Update TODOS.md

Mark task as complete with details:

```markdown
- [✅] Dashboard: Fix elapsed/remaining time calculation - COMPLETED 2026-03-03
  - Added enrich_progress_with_times() to Progress Manager
  - Integrated in Migration Orchestrator
  - Updated JavaScript to display times
  - All files: syntax ✅, PHPCS ✅, logic ✅
  - Manual testing: pending (see TEST_DASHBOARD_TIME_DISPLAY.md)
```

---

## Phase 8: Commit & Sign-Off

**Goal:** Create clean, documented commit

### 8.1 Commit Message Format

```
<type>: <subject>

<body>

<footer>
```

**Example:**

```
feat: dashboard elapsed/remaining time display

- Add elapsed_seconds and estimated_time_remaining calculation
- Update Progress Manager with enrich_progress_with_times()
- Integrate time enrichment in Migration Orchestrator
- Update JavaScript to forward and display time data
- Add time display element to migration progress view

Files modified: 7
Files deleted: 25
Tests: See TEST_DASHBOARD_TIME_DISPLAY.md

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>
```

### 8.2 Pre-Commit Checklist

- [ ] Syntax validation: All pass
- [ ] Code review: Complete
- [ ] Data flow: Verified
- [ ] Manual testing: Complete (or documented as pending)
- [ ] Documentation: Updated
- [ ] Commit message: Clear and descriptive
- [ ] No debug code: Removed
- [ ] No commented code: Removed

### 8.3 Commit

```bash
git add -A
git commit -m "feat: <description>"
git push origin main
```

---

## Phase 9: Monitoring & Rollback

**Goal:** Catch issues after deployment

### 9.1 Post-Deployment Monitoring

- [ ] Check error logs: No new errors
- [ ] Check user reports: No complaints
- [ ] Monitor performance: No degradation
- [ ] Verify feature: Works as expected

### 9.2 Rollback Plan

If critical issues found:

```bash
git revert <commit_sha>
git push origin main
```

---

## Quick Reference Checklist

### Before Coding
- [ ] Requirements clear
- [ ] Scope defined
- [ ] Design reviewed
- [ ] Files to modify listed

### During Coding
- [ ] Docblocks added
- [ ] Edge cases handled
- [ ] Standards followed
- [ ] Comments for complex logic

### After Coding - QA
- [ ] PHP syntax: `php -l <file>`
- [ ] JS syntax: `node --check <file>`
- [ ] PHPCS: `composer exec phpcs`
- [ ] Code review: Checklist complete
- [ ] Data flow: Traced and verified
- [ ] Manual testing: Complete

### Before Commit
- [ ] Syntax: All pass
- [ ] Review: Complete
- [ ] Tests: Complete
- [ ] Docs: Updated
- [ ] Commit message: Clear

### After Commit
- [ ] Push to main
- [ ] Monitor logs
- [ ] Verify feature works
- [ ] Monitor performance

---

## Tools & Commands Reference

### PHP
```bash
# Syntax check
php -l <file>

# WordPress standards
cd etch-fusion-suite
composer exec phpcs -- <file>
composer exec phpcbf -- <file>  # Auto-fix
```

### JavaScript
```bash
# Syntax check
node --check <file>

# ESLint (if available)
npm run lint <file>
```

### Environment
```bash
# Start Docker
npm run dev

# Check status
npm run health

# View logs
npm run logs
npm run logs:etch
npm run logs:bricks
```

### Git
```bash
# Show changes
git status
git diff <file>

# Commit
git add -A
git commit -m "message"
git push origin main

# Revert if needed
git revert <commit_sha>
```

---

## Prevention Tips

**Based on common mistakes from this fix:**

1. **Always verify data flows end-to-end**
   - Backend calculates ✅
   - API returns it ✅
   - JavaScript receives it ✅
   - Frontend displays it ✅

2. **Handle edge cases early**
   - What if data is null?
   - What if value is 0?
   - What if calculation fails?

3. **Test with real data**
   - Manual Docker testing required
   - Not just unit tests
   - Real user scenarios

4. **Document as you go**
   - Comments in code
   - DOCUMENTATION.md updates
   - TODOS.md status

5. **Follow standards automatically**
   - PHPCS catches 90% of issues
   - Run linters before committing
   - Fix violations, don't ignore

6. **Create reusable test documents**
   - VERIFICATION_<FEATURE>.md
   - TEST_<FEATURE>.md
   - For future developers

---

## Approval Process

For changes affecting:
- **Core business logic**: Requires code review ✅
- **User-facing UI**: Requires manual testing ✅
- **API endpoints**: Requires API testing ✅
- **Database**: Requires migration review ✅

---

## Document Control

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-03-03 | Initial version based on Dashboard Time Fix |

---

**Status:** Active  
**Next Review:** After 3 more features completed  
**Owner:** Development Team
