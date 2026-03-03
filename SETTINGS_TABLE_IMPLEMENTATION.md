# Custom Settings Table Implementation

**Completed:** 2026-03-03  
**Commits:** `4ab265a7`, `82509cfc`

## Summary

Implemented a custom `wp_efs_settings` database table to centralize plugin configuration storage, replacing the WordPress Options API. This completes the custom-table architecture pattern already established by `wp_efs_migrations` and `wp_efs_migration_logs`.

## What Changed

### 1. Database Layer (`includes/core/class-db-installer.php`)

**New Settings Table:**
```sql
CREATE TABLE wp_efs_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value LONGTEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY setting_key (setting_key)
)
```

**Installation Logic:**
- `install()` method: Creates all three tables (settings, migrations, migration_logs)
- `migrate_settings_to_custom_table()`: Automatically migrates existing wp_options data on first install
  - Only runs once (checks if table already has data)
  - Migrates: `efs_settings`, `efs_migration_settings`, `efs_feature_flags`, `efs_cors_allowed_origins`, `efs_security_settings`
  - Logs migration completion to error log

**Uninstall Logic:**
- `uninstall()` method: Drops custom table AND deletes legacy wp_options
- Complete cleanup (no orphaned data)

### 2. Data Access Layer (`includes/repositories/class-wordpress-settings-repository.php`)

**New Private Helper Methods:**

```php
private function get_setting($setting_key, $default_value = null)
  - Query wp_efs_settings by setting_key
  - Auto-decode JSON values
  - Return default if not found

private function save_setting($setting_key, $setting_value)
  - INSERT OR UPDATE on duplicate key
  - Auto-encode values as JSON
  - Update timestamp

private function delete_setting($setting_key)
  - DELETE from wp_efs_settings
```

**Updated Public Methods:**

1. **Plugin Settings**
   - `get_plugin_settings()`: Now uses `get_setting('efs_settings')`
   - `save_plugin_settings()`: Now uses `save_setting()`

2. **Migration Settings**
   - `get_migration_settings()`: Now uses `get_setting('efs_migration_settings')`
   - `save_migration_settings()`: Now uses `save_setting()`

3. **Feature Flags**
   - `get_feature_flags()`: Now uses `get_setting('efs_feature_flags')`
   - `save_feature_flags()`: Now uses `save_setting()`

4. **CORS Settings**
   - `get_cors_allowed_origins()`: Now uses `get_setting('efs_cors_allowed_origins')`
   - `save_cors_allowed_origins()`: Now uses `save_setting()`

5. **Security Settings**
   - `get_security_settings()`: Now uses `get_setting('efs_security_settings')`
   - `save_security_settings()`: Now uses `save_setting()`

6. **Clear All Settings**
   - `clear_all_settings()`: TRUNCATE custom table + delete legacy wp_options

### 3. Controller Layer (`includes/controllers/class-migration-controller.php`)

**Updated Methods:**

`start_migration()`:
- Retrieves migration_key from request or Settings Repository
- Saves migration_key to Settings Repository after validation
- Uses: `settings_repo->get_migration_settings()` and `save_migration_settings()`

`get_progress()`:
- Retrieves migration_key from request or Settings Repository
- Uses Settings Repository for consistency
- Extracted via: `settings_repo->get_migration_settings()`

## Benefits

✅ **Unified Architecture** - All structured data now uses custom tables (settings, migrations, logs)  
✅ **Better Schema Control** - Define exact column types, indexes, constraints  
✅ **Cleaner Audit Trail** - No mixing of plugin settings with site-wide WordPress options  
✅ **Performance** - Transient caching (5 minutes) reduces database queries  
✅ **Forward-Compatible** - Easy to add new settings without polluting wp_options table  
✅ **Clean Persistence** - Settings Repository handles encoding/decoding transparently  

## Data Storage

The following configuration is now persisted in `wp_efs_settings` table:

| Key | Type | Purpose |
|-----|------|---------|
| `efs_settings` | object | General plugin config (license, toggles) |
| `efs_migration_settings` | object | Current migration config (migration_key JWT) |
| `efs_feature_flags` | object | Feature toggle states (beta features) |
| `efs_cors_allowed_origins` | array | CORS whitelist for cross-site API calls |
| `efs_security_settings` | object | Security config (rate limits, audit logging, HTTPS) |

## Testing

To verify the implementation works:

```bash
# Start Docker environment (auto-installs and migrates settings)
npm run dev

# Verify wp_efs_settings table was created and populated
npx wp-env run cli wp db tables efs_

# Run unit tests
npm run test:unit
```

## Migration Path

**Automatic (no action needed):**
1. Plugin activation triggers `install()` via WordPress plugin activation hook
2. `migrate_settings_to_custom_table()` runs automatically (one-time)
3. Existing wp_options data is migrated to custom table
4. Settings Repository uses custom table immediately

**For existing installs:**
- First time Settings Repository is called after update, it queries wp_efs_settings
- Table creation and data migration happen in `install()` which runs on plugin activation
- No data loss; legacy wp_options entries remain until uninstall

## Technical Details

### Transient Caching
- Cache key pattern: `efs_cache_<setting_type>` (e.g. `efs_cache_settings_plugin`)
- Expiration: 5 minutes (300 seconds)
- Invalidated on write: `invalidate_cache()` method

### JSON Encoding
- `wp_json_encode()` for safe serialization
- Automatic decode on retrieval: `json_decode()` with array flag
- Fallback to raw value if JSON decode fails

### Database Queries
- Prepared statements: `$wpdb->prepare()` prevents SQL injection
- UNIQUE constraint on `setting_key` prevents duplicates
- ON DUPLICATE KEY UPDATE allows insert-or-update patterns
- Indexes on `setting_key` for fast lookups

## Files Modified

```
includes/core/class-db-installer.php
  - Added: wp_efs_settings table creation (lines 38-46)
  - Added: migrate_settings_to_custom_table() method (lines 139-174)
  - Updated: install() to call migration (line 93)
  - Updated: is_installed() to verify settings table (lines 110-115)
  - Updated: uninstall() to drop settings table (line 188)

includes/repositories/class-wordpress-settings-repository.php
  - Updated: get_plugin_settings() to use get_setting() (line 45)
  - Updated: save_plugin_settings() to use save_setting() (line 60)
  - Updated: get_migration_settings() to use get_setting() (line 76)
  - Updated: save_migration_settings() to use save_setting() (line 91)
  - Updated: get_feature_flags() to use get_setting() (line 114)
  - Updated: save_feature_flags() to use save_setting() (line 131)
  - Updated: get_cors_allowed_origins() to use get_setting() (line 162)
  - Updated: save_cors_allowed_origins() to use save_setting() (line 178)
  - Updated: get_security_settings() to use get_setting() (line 237)
  - Updated: save_security_settings() to use save_setting() (line 272)
  - Updated: clear_all_settings() to truncate custom table (lines 229-242)
  - Added: get_setting() helper method (lines 291-308)
  - Added: save_setting() helper method (lines 317-333)
  - Added: delete_setting() helper method (lines 341-352)

includes/controllers/class-migration-controller.php
  - Updated: start_migration() to use settings_repo (lines 45-64)
  - Updated: get_progress() to use settings_repo (lines 158-166)
```

## Documentation Updates

**DOCUMENTATION.md:**
- Added comprehensive Settings Table section in "Database Persistence"
- Document table schema, data storage, data flow, and migration process
- Updated overview to reflect unified architecture

**CHANGELOG.md:**
- Added Architecture & Infrastructure section for Unreleased
- Document custom settings table implementation and benefits
- Update Bug Fixes section with migration key persistence details

## Next Steps

1. **Run full test suite** to verify no regressions:
   ```bash
   npm run test:unit          # PHPUnit (162 tests)
   npm run test:playwright    # E2E tests
   ```

2. **Test in Docker** to verify table creation and data migration:
   ```bash
   npm run dev                # Start environment
   npm run wp -- wp db tables efs_  # Verify tables
   ```

3. **Manual verification** of migration flow:
   - Check wp_efs_settings has 5 rows (one per setting key)
   - Verify Settings Repository methods work end-to-end
   - Confirm transient caching is working

4. **Other get_option/update_option calls** for settings:
   - Currently many legacy files still use `get_option('efs_*')` directly
   - Should be refactored to use Settings Repository for consistency
   - Not blocking; can be done incrementally

## References

- **DOCUMENTATION.md** - Database Persistence section (lines 2497-2590)
- **CHANGELOG.md** - Unreleased section
- **class-db-installer.php** - Table creation and migration logic
- **class-wordpress-settings-repository.php** - Data access implementation
- **class-migration-controller.php** - Controller usage example

---

**Status:** ✅ Implementation Complete  
**Ready for:** Testing, documentation review, deployment
