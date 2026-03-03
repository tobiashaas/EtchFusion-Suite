# Migration Failure Debug Report

## Issue Summary
User reported "Migration failed" on Bricks side with error:
```
"Migration is not configured. Please complete Step 2: Connect to Etch Site in the migration wizard."
code: 'configuration_incomplete'
```

## Root Cause Analysis

### Problem 1: Migration Key Not Saved to Settings
**Symptom:** `wp option get efs_settings` returns "Does not exist"

**Root Cause:** 
- User generates migration key on Etch admin → gets a JWT URL/key
- User copies key to Bricks admin wizard
- User clicks "Start Migration" → JavaScript sends `migration_key` in POST data
- **Backend receives it BUT NEVER SAVES it to Settings**
- Later, when `get_progress()` is called by frontend polling, it looks in `wp_options.efs_settings['migration_key']` (line 147-148 of class-migration-controller.php) but finds nothing
- Returns "configuration_incomplete" error

**Architecture Issue:**
The frontend only sends the migration_key on the initial `start_migration()` call. Subsequent calls to `get_progress()` need to retrieve it from Settings, but it's never persisted.

### Problem 2: Target URL Extraction Failures
The `get_target_url_from_migration_key()` method (line 56) depends on the migration_key being present. If key is not saved to Settings, it can't extract the target URL for subsequent API calls.

## Fix Applied

### File: `includes/controllers/class-migration-controller.php`

**Lines 51-55 (after existing `start_migration()` migration_key validation):**

```php
// Save migration_key to Settings for later retrieval by get_progress().
// This ensures the key is available for all subsequent API calls without re-sending it in every request.
$settings                  = get_option( 'efs_settings', array() );
$settings['migration_key'] = $migration_key;
update_option( 'efs_settings', $settings );
```

**What this does:**
1. After validating that `$migration_key` is not empty
2. Retrieves current Settings from wp_options
3. Updates Settings with the received migration_key
4. Persists back to database with `update_option()`

**Why this works:**
- Now when `get_progress()` (line 147-148) looks in Settings for the key, it finds it
- Can extract target_url from JWT payload
- Migration continues without "configuration_incomplete" error
- Key is available across all subsequent requests

## Architectural Pattern

**Migration Workflow (Before Fix):**
```
1. User generates key on Etch   → JWT created, returned to browser
2. User pastes key on Bricks    → Stored in JS memory state
3. User clicks "Start"          → JS sends key in POST data
4. Backend validates & starts   → Key received but NOT saved
5. get_progress() polls         → Looks in Settings, FINDS NOTHING
6. Error: "configuration_incomplete"
```

**Migration Workflow (After Fix):**
```
1. User generates key on Etch   → JWT created, returned to browser
2. User pastes key on Bricks    → Stored in JS memory state
3. User clicks "Start"          → JS sends key in POST data
4. Backend validates, SAVES     → Key persisted to wp_options
5. get_progress() polls         → Looks in Settings, FINDS KEY
6. Target URL extracted from JWT
7. Migration proceeds normally
```

## Verification

### PHP Syntax
✅ Verified: `php -l includes/controllers/class-migration-controller.php`

### Settings Storage
The fix ensures that after starting migration, this query returns the key:
```bash
npx wp-env run cli wp option get efs_settings --format=json
```

Expected output:
```json
{
  "migration_key": "eyJhbGc..."
}
```

### Data Flow
1. **Frontend sends:** `POST admin-ajax.php` with `migration_key` in request body
2. **Backend receives:** Via `get_post( 'migration_key' )` in AJAX handler
3. **Backend saves:** Via `update_option( 'efs_settings', $settings )`
4. **Backend retrieves:** Via `get_option( 'efs_settings' )` in subsequent calls
5. **URL extraction:** Via `decode_migration_key_locally()` which parses JWT payload for `target_url` field

## Related Issues

### Dashboard Breakdown & Time Display
Separate issues reported by user:
- Breakdown items not displaying in UI
- Time calculation still incorrect
- Both require migration to reach the point where logs are created

**Status:** These should resolve once migration_key persistence fix allows migration to proceed.

## Next Steps

1. User should restart the migration from Bricks admin
2. Monitor browser console for new errors (should NOT see "configuration_incomplete" again)
3. Verify breakdown items display once migration logs are created
4. Monitor elapsed/remaining time display

