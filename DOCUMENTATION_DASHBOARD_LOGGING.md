# Dashboard Real-Time Progress Logging

## Overview

The Etch Fusion Suite provides real-time progress logging through a REST API that feeds migration status to the dashboard. This enables users to see live updates during migrations, including per-item details (posts, media, CSS classes), error summaries, and duration metrics.

## Architecture

### Components

1. **EFS_Detailed_Progress_Tracker** (`services/class-detailed-progress-tracker.php`)
   - Core service for tracking item-level migration progress
   - Logs posts, media files, CSS classes to database
   - Provides current item state for dashboard display
   - Integrates with `EFS_DB_Migration_Persistence` for audit trail storage

2. **EFS_Migration_Progress_Logger Trait** (`controllers/trait-migration-progress-logger.php`)
   - Provides three query methods for REST API
   - `get_migration_progress()` - Real-time progress with stats
   - `get_migration_errors()` - Failure logs only
   - `get_migration_logs_by_category()` - Filtered logs by category

3. **EFS_Progress_Dashboard_API** (`admin/class-progress-dashboard-api.php`)
   - REST API endpoints for dashboard consumption
   - Handles permission checks (admin only)
   - Routes requests to logger trait methods
   - Returns structured JSON responses

4. **Admin Interface** (`admin/class-admin-interface.php`)
   - Registers REST routes on `rest_api_init` hook
   - Calls `EFS_Progress_Dashboard_API::register_routes()` during init

## REST API Endpoints

### Get Migration Progress

```
GET /wp-json/efs/v1/migration/{migration_id}/progress
```

**Parameters:**
- `migration_id` (string, required) - UUID of the migration

**Response (200 OK):**
```json
{
  "migration_id": "12345678-abcd-efgh-ijkl",
  "current_item": {
    "timestamp": "2024-12-20T14:23:45Z",
    "category": "content_post_migrated",
    "message": "Post migrated: \"About Us\"",
    "context": {
      "post_id": 42,
      "title": "About Us",
      "status": "success",
      "blocks_converted": 5,
      "fields_migrated": 3,
      "duration_ms": 1250
    }
  },
  "recent_logs": [
    {
      "timestamp": "2024-12-20T14:23:45Z",
      "level": "info",
      "category": "content_post_migrated",
      "message": "Post migrated: \"About Us\"",
      "context": {
        "post_id": 42,
        "title": "About Us",
        "status": "success",
        "blocks_converted": 5,
        "fields_migrated": 3,
        "duration_ms": 1250
      }
    }
  ],
  "statistics": {
    "total_events": 47,
    "posts_migrated": 12,
    "posts_failed": 0,
    "media_processed": 23,
    "css_classes": 12,
    "total_duration_ms": 45320
  }
}
```

**Error Response (404 Not Found):**
```json
{
  "success": false,
  "error": "No logs found for this migration."
}
```

### Get Migration Errors

```
GET /wp-json/efs/v1/migration/{migration_id}/errors
```

**Parameters:**
- `migration_id` (string, required) - UUID of the migration

**Response (200 OK):**
```json
{
  "success": true,
  "errors": [
    {
      "timestamp": "2024-12-20T14:22:30Z",
      "message": "Post failed: \"Problematic Page\"",
      "category": "content_post_failed",
      "context": {
        "post_id": 99,
        "title": "Problematic Page",
        "status": "failed",
        "error": "Unsupported element type: custom_widget",
        "duration_ms": 890
      }
    }
  ],
  "count": 1
}
```

### Get Logs by Category

```
GET /wp-json/efs/v1/migration/{migration_id}/logs/{category}
```

**Parameters:**
- `migration_id` (string, required) - UUID of the migration
- `category` (string, required) - Log category to filter
  - `content_post_migrated` - Successfully migrated posts
  - `content_post_failed` - Failed post migrations
  - `media_success` - Successfully migrated media
  - `media_failed` - Failed media migrations
  - `css_class_converted` - CSS class conversions

**Response (200 OK):**
```json
{
  "success": true,
  "category": "content_post_migrated",
  "logs": [
    {
      "timestamp": "2024-12-20T14:23:45Z",
      "level": "info",
      "message": "Post migrated: \"About Us\"",
      "context": {
        "post_id": 42,
        "title": "About Us",
        "status": "success",
        "blocks_converted": 5,
        "fields_migrated": 3,
        "duration_ms": 1250
      }
    }
  ],
  "count": 1
}
```

## Security

### Authentication

All endpoints require:
- User to be logged in (`is_user_logged_in()`)
- User to have admin capability (`manage_options`)

Requests without proper permissions return **403 Forbidden**.

### Nonce

REST API uses WordPress nonce verification automatically through the REST API framework. AJAX calls still use the dedicated `efs_nonce` from `admin_interface.php`.

## Service Integration

### Content Service

Posts are logged during conversion:

```php
// In Content_Service::convert_bricks_to_gutenberg()
$this->progress_tracker->log_post_migration(
    $post_id,
    $post->post_title,
    $status,
    array(
        'blocks_converted'    => count($converted_blocks),
        'fields_migrated'     => count($migrated_fields),
        'duration_ms'         => $duration_ms,
    )
);
```

### Media Service

Media files are logged during migration:

```php
// In Media_Service::migrate_media_by_id()
$this->progress_tracker->log_media_migration(
    get_the_guid($media_id),
    $filename,
    'success',
    array(
        'media_id'      => $media_id,
        'size_bytes'    => $file_size,
        'mime_type'     => $mime_type,
        'duration_ms'   => $duration_ms,
    )
);
```

### CSS Service

CSS classes are logged during conversion:

```php
// In CSS_Service::migrate_css_classes()
foreach ($etch_styles as $bricks_class => $etch_class) {
    $this->progress_tracker->log_css_migration(
        $bricks_class,
        'converted',
        array(
            'etch_class_name' => $etch_class,
            'conflicts'       => 0,
        )
    );
}
```

## Dashboard Integration

The dashboard at `/wp-admin/admin.php?page=etch-fusion-suite` displays logs using the existing `logs.js` module:

### Initial Page Load

```javascript
// includes/views/logs.php provides initial logs from AJAX
allSecurityLogs = getInitialData('logs', []);
allMigrationRuns = getInitialData('migration_runs', []);
renderAll();
```

### Real-Time Updates During Migration

When a migration is active, the dashboard auto-polls for progress:

```javascript
// Fetch logs every 5 seconds during migration
startAutoRefreshLogs(5000);

// Behind the scenes: calls efs_get_logs AJAX action
// Should be updated to call REST API: GET /wp-json/efs/v1/migration/{id}/progress
```

### Log Categories

Logs are filtered by type in the dashboard UI:
- **All** - Show all logs and migration runs
- **Migration** - Show only migration runs with per-post details
- **Security** - Show only security audit logs

## Database Schema

Progress logs are stored in the `{prefix}_efs_audit_trail` table created by `EFS_DB_Migration_Persistence`:

```sql
CREATE TABLE wp_efs_audit_trail (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  migration_id    VARCHAR(255) NOT NULL,
  timestamp       DATETIME DEFAULT CURRENT_TIMESTAMP,
  level           ENUM('info', 'warning', 'error') DEFAULT 'info',
  category        VARCHAR(100),
  message         TEXT,
  context         JSON,
  KEY (migration_id),
  KEY (timestamp)
);
```

### Log Context Structure

Each log entry stores structured metadata in the `context` JSON field:

```json
{
  "post_id": 42,
  "title": "About Us",
  "status": "success",
  "blocks_converted": 5,
  "fields_migrated": 3,
  "duration_ms": 1250
}
```

## Error Handling

### Invalid Migration ID

```
GET /wp-json/efs/v1/migration//progress
```

**Response (404 Not Found):**
```json
{
  "success": false,
  "error": "Migration ID is required."
}
```

### Missing Persistence Service

If the database persistence service is not available:

```json
{
  "success": false,
  "error": "Database persistence not available."
}
```

### Non-Admin User

```
(Without manage_options capability)
```

**Response (403 Forbidden):**
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 403 }
}
```

## Performance Considerations

### Pagination

The progress endpoint returns:
- Last 10 logs in `recent_logs`
- Real-time statistics (totals only, not per-item list)

This limits response payload to ~10-20KB even for large migrations.

### Polling Interval

Recommended dashboard polling interval during migration:
- **Active migration**: 2-5 seconds (balances UI responsiveness vs server load)
- **Idle**: No polling (fetch on demand only)

### Log Retention

Logs are persisted indefinitely in the database but can be cleared:
1. Via `Clear Logs` button in dashboard
2. Via AJAX action `efs_clear_logs`
3. Via `EFS_DB_Migration_Persistence::clear_migration_logs($migration_id)`

## Testing

Run the integration test to verify all components:

```bash
cd etch-fusion-suite
bash tests/integration/test-dashboard-logging.sh
```

Output should show:
```
✓ Migration Progress Logger trait exists
✓ Progress Dashboard API class exists
✓ Detailed Progress Tracker exists
✓ Media Service exists
✓ CSS Service exists
✓ Method handle_progress_request exists
✓ Method handle_errors_request exists
✓ Method handle_category_request exists
```

## Example: Manual API Call

Using curl to fetch migration progress:

```bash
curl -X GET \
  'http://localhost:8889/wp-json/efs/v1/migration/abc-123/progress' \
  -H 'Authorization: Bearer <JWT_TOKEN>' \
  -u 'admin:password'
```

Or from the browser console:

```javascript
fetch('/wp-json/efs/v1/migration/abc-123/progress')
  .then(r => r.json())
  .then(data => console.log(data))
  .catch(err => console.error(err));
```

## Troubleshooting

### Endpoints Returning 404

**Problem:** REST routes not registered.

**Solution:**
1. Verify `rest_api_init` hook fires (check `admin_interface.php` constructor)
2. Verify REST API is enabled in WordPress
3. Clear WordPress REST API cache: `delete_transient('rest_endpoints')`

### Logs Not Persisting

**Problem:** Logs are fetched but empty.

**Solution:**
1. Verify `EFS_DB_Migration_Persistence` is in container: `etch_fusion_suite_container()->has('db_migration_persistence')`
2. Check database table exists: `wp_efs_audit_trail`
3. Verify migration ID is passed to tracker during migration

### 403 Forbidden

**Problem:** Non-admin users cannot access endpoints.

**Solution:**
- This is intentional for security
- Only users with `manage_options` capability can view logs
- Dashboard loads with admin-only page

## Related Files

- **REST API**: `includes/admin/class-progress-dashboard-api.php`
- **Trait**: `includes/controllers/trait-migration-progress-logger.php`
- **Tracker**: `includes/services/class-detailed-progress-tracker.php`
- **Dashboard**: `includes/views/logs.php`, `assets/js/admin/logs.js`
- **Admin Init**: `includes/admin/class-admin-interface.php`
- **Database**: `repositories/class-db-migration-persistence.php`
