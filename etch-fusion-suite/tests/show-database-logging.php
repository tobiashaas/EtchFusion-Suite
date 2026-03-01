<?php
/**
 * Show All Database Logging Data
 *
 * Simulates a complete migration and displays exactly what gets logged
 * to the database tables.
 *
 * Run: wp eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/show-database-logging.php';"
 */

require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-db-migration-persistence.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-wordpress-migration-repository.php';

global $wpdb;

// Ensure tables exist
\Bricks2Etch\Core\EFS_DB_Installer::install();

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║             DATABASE LOGGING - COMPLETE MIGRATION              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Simulate a complete migration
$migration_id = 'demo-' . wp_generate_uuid4();
$repo         = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
$db_persist   = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();

echo "📝 SIMULATING MIGRATION: $migration_id\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Initialize migration
echo "STEP 1: Initialize Migration\n";
$progress = array(
	'migrationId'  => $migration_id,
	'status'       => 'running',
	'current_step' => 'validation',
	'percentage'   => 0,
	'message'      => 'Initializing migration...',
	'source_url'   => 'https://bricks.example.com',
	'target_url'   => 'https://etch.example.com',
	'started_at'   => current_time( 'mysql', true ),
);
$repo->save_progress( $progress );
echo "✓ Migration initialized\n\n";

// 2. Validation phase
echo "STEP 2: Validation Phase\n";
$progress['percentage']     = 10;
$progress['current_step']   = 'validation';
$progress['message']        = 'Validating Bricks Builder installation...';
$progress['processedItems'] = 1;
$progress['totalItems']     = 1;
$repo->save_progress( $progress );
echo "✓ Validation 10%\n";

$db_persist->log_event(
	$migration_id,
	'info',
	'Bricks Builder detected: v1.5.1',
	'validation',
	array( 'plugin' => 'Bricks', 'version' => '1.5.1', 'status' => 'compatible' )
);
echo "✓ Logged: Bricks detected\n\n";

// 3. Analysis phase
echo "STEP 3: Content Analysis Phase\n";
$progress['percentage']   = 20;
$progress['current_step'] = 'analyzing';
$progress['message']      = 'Analyzing content...';
$repo->save_progress( $progress );
echo "✓ Analysis 20%\n";

$db_persist->log_event(
	$migration_id,
	'info',
	'Found 45 Bricks posts, 12 Gutenberg posts, 89 media files (146 total items)',
	'analysis',
	array(
		'bricks_posts'    => 45,
		'gutenberg_posts' => 12,
		'media_files'     => 89,
		'total_items'     => 146,
	)
);
echo "✓ Logged: Content analysis results\n\n";

// 4. CSS Conversion
echo "STEP 4: CSS Conversion Phase\n";
for ( $pct = 25; $pct <= 50; $pct += 25 ) {
	$progress['percentage']   = $pct;
	$progress['current_step'] = 'css';
	$progress['message']      = "Converting CSS classes... {$pct}%";
	$repo->save_progress( $progress );
	echo "✓ CSS conversion {$pct}%\n";
}

$db_persist->log_event(
	$migration_id,
	'info',
	'CSS classes migrated: 234 classes → 234 mapped, 12 custom rules converted',
	'css',
	array(
		'migrated'        => 234,
		'mapped'          => 234,
		'custom_rules'    => 12,
		'conflicts'       => 0,
		'success_rate'    => 100,
		'duration_ms'     => 2340,
	)
);
echo "✓ Logged: CSS conversion complete (234 classes)\n";

$db_persist->log_event(
	$migration_id,
	'warning',
	'CSS: 3 custom media queries require manual review',
	'css',
	array(
		'count'     => 3,
		'selectors' => array(
			'@media (max-width: 768px)',
			'@media (min-width: 1024px)',
			'@media (orientation: landscape)',
		),
		'action'    => 'manual_review',
	)
);
echo "✓ Logged: CSS warnings (3 media queries need review)\n\n";

// 5. Media Migration
echo "STEP 5: Media Migration Phase\n";
for ( $pct = 55; $pct <= 75; $pct += 20 ) {
	$progress['percentage']      = $pct;
	$progress['current_step']    = 'media';
	$progress['message']         = "Downloading media... {$pct}%";
	$progress['processedItems']  = intval( ( $pct - 25 ) / 50 * 89 );
	$progress['totalItems']      = 89;
	$repo->save_progress( $progress );
	echo "✓ Media migration {$pct}% ({$progress['processedItems']}/89)\n";
}

$db_persist->log_event(
	$migration_id,
	'info',
	'Media download started',
	'media',
	array(
		'total_files' => 89,
		'formats'     => array( 'jpg' => 45, 'png' => 32, 'gif' => 12 ),
	)
);

$db_persist->log_event(
	$migration_id,
	'warning',
	'Failed to download 2 media files (network timeout)',
	'media',
	array(
		'failed_count'  => 2,
		'failed_urls'   => array(
			'https://old-cdn.example.com/image-001.jpg',
			'https://old-cdn.example.com/video-001.webm',
		),
		'retry_enabled' => true,
	)
);
echo "✓ Logged: Media migration (87/89 successful, 2 failed)\n\n";

// 6. Content Migration
echo "STEP 6: Content Migration Phase\n";
for ( $pct = 80; $pct <= 100; $pct += 20 ) {
	$progress['percentage']      = $pct;
	$progress['current_step']    = 'content';
	$progress['message']         = "Migrating posts and pages... {$pct}%";
	$progress['processedItems']  = intval( ( $pct - 50 ) / 50 * 57 );
	$progress['totalItems']      = 57;
	$repo->save_progress( $progress );
	echo "✓ Content migration {$pct}% ({$progress['processedItems']}/57)\n";
}

$db_persist->log_event(
	$migration_id,
	'info',
	'Bricks posts converted to Gutenberg: 45 posts migrated',
	'content',
	array(
		'migrated_posts'  => 45,
		'post_types'      => array( 'post' => 30, 'page' => 15 ),
		'gutenberg_blocks' => 234,
		'duration_ms'     => 4567,
	)
);

$db_persist->log_event(
	$migration_id,
	'info',
	'Custom fields (ACF) migrated: 78 field groups, 456 field values',
	'content',
	array(
		'field_groups'     => 78,
		'field_values'     => 456,
		'migrated_success' => 456,
		'skipped'          => 0,
	)
);

$db_persist->log_event(
	$migration_id,
	'error',
	'ACF: 2 field groups have unsupported field types',
	'content',
	array(
		'unsupported_count' => 2,
		'field_types'       => array( 'repeater', 'flexible_content' ),
		'action'            => 'manual_migration_required',
	)
);
echo "✓ Logged: Content migration (45 posts, 78 ACF groups, 2 errors)\n\n";

// 7. Final event - Completion
echo "STEP 7: Migration Complete\n";
$progress['percentage']   = 100;
$progress['current_step'] = 'complete';
$progress['status']       = 'completed';
$progress['message']      = 'Migration completed successfully!';
$repo->save_progress( $progress );
echo "✓ Migration marked as completed (100%)\n";

$db_persist->log_event(
	$migration_id,
	'info',
	'Migration completed successfully',
	'migration_complete',
	array(
		'total_items'     => 146,
		'processed_items' => 144,
		'skipped_items'   => 2,
		'success_rate'    => 98.6,
		'total_errors'    => 3,
		'total_warnings'  => 2,
		'duration_seconds' => 185,
		'start_time'      => '2026-03-01 17:30:00',
		'end_time'        => '2026-03-01 17:33:05',
	)
);
echo "✓ Logged: Final migration statistics\n\n";

// Now show everything that was logged
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║                  DATABASE CONTENTS                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Show migration record
echo "📊 MIGRATION RECORD (wp_efs_migrations)\n";
echo "═══════════════════════════════════════════════════════════════\n";
$migration = $db_persist->get_migration( $migration_id );
if ( $migration ) {
	echo "Migration UID:     " . $migration['migration_uid'] . "\n";
	echo "Status:            " . $migration['status'] . "\n";
	echo "Source URL:        " . $migration['source_url'] . "\n";
	echo "Target URL:        " . $migration['target_url'] . "\n";
	echo "Progress:          " . $migration['progress_percent'] . "%\n";
	echo "Processed Items:   " . $migration['processed_items'] . "/" . $migration['total_items'] . "\n";
	echo "Error Count:       " . $migration['error_count'] . "\n";
	echo "Created At:        " . $migration['created_at'] . "\n";
	echo "Started At:        " . $migration['started_at'] . "\n";
	echo "Completed At:      " . $migration['completed_at'] . "\n";
	echo "Updated At:        " . $migration['updated_at'] . "\n";
	if ( ! empty( $migration['error_message'] ) ) {
		echo "Error Message:     " . $migration['error_message'] . "\n";
	}
} else {
	echo "ERROR: Migration not found!\n";
}

// Show all logged events
echo "\n\n📋 AUDIT TRAIL (wp_efs_migration_logs)\n";
echo "═══════════════════════════════════════════════════════════════\n";
$audit_trail = $db_persist->get_audit_trail( $migration_id );

if ( is_array( $audit_trail ) && ! empty( $audit_trail ) ) {
	echo "Total Events: " . count( $audit_trail ) . "\n\n";

	foreach ( $audit_trail as $i => $event ) {
		$num = count( $audit_trail ) - $i;  // Reverse numbering (newest first)
		echo "EVENT #$num\n";
		echo "─────────────────────────────────────────────────────────────\n";
		echo "  Level:       " . strtoupper( $event['log_level'] ) . "\n";
		echo "  Category:    " . $event['category'] . "\n";
		echo "  Message:     " . $event['message'] . "\n";
		echo "  Timestamp:   " . $event['created_at'] . "\n";

		if ( ! empty( $event['context'] ) ) {
			$context = json_decode( $event['context'], true );
			if ( is_array( $context ) && ! empty( $context ) ) {
				echo "  Context Data:\n";
				foreach ( $context as $key => $value ) {
					if ( is_array( $value ) ) {
						echo "    • $key: [array with " . count( $value ) . " items]\n";
					} elseif ( is_string( $value ) ) {
						echo "    • $key: $value\n";
					} else {
						echo "    • $key: " . var_export( $value, true ) . "\n";
					}
				}
			}
		}
		echo "\n";
	}
} else {
	echo "No events logged!\n";
}

// Show statistics
echo "\n📈 MIGRATION STATISTICS\n";
echo "═══════════════════════════════════════════════════════════════\n";
$stats = $db_persist->get_statistics();
echo "Total Migrations:     " . $stats['total_migrations'] . "\n";
echo "Completed:            " . $stats['completed'] . "\n";
echo "Failed:               " . $stats['failed'] . "\n";
echo "In Progress:          " . $stats['in_progress'] . "\n";
echo "Pending:              " . $stats['pending'] . "\n";
if ( isset( $stats['success_rate'] ) ) {
	echo "Success Rate:         " . round( $stats['success_rate'], 2 ) . "%\n";
}

// Show raw database queries
echo "\n\n🗄️  RAW DATABASE QUERIES\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "MIGRATIONS TABLE COUNT:\n";
$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations" );
echo "  Total rows: " . $count . "\n\n";

echo "LOGS TABLE COUNT:\n";
$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}efs_migration_logs" );
echo "  Total rows: " . $count . "\n\n";

echo "EVENTS BY CATEGORY:\n";
$categories = $wpdb->get_results(
	"SELECT category, COUNT(*) as count FROM {$wpdb->prefix}efs_migration_logs 
	 WHERE migration_uid = %s
	 GROUP BY category
	 ORDER BY count DESC",
	array( $migration_id )
);
foreach ( $categories as $cat ) {
	echo "  • " . ( $cat->category ?: '(null)' ) . ": " . $cat->count . " events\n";
}

echo "\n\nEVENTS BY LEVEL:\n";
$levels = $wpdb->get_results(
	"SELECT log_level, COUNT(*) as count FROM {$wpdb->prefix}efs_migration_logs 
	 WHERE migration_uid = %s
	 GROUP BY log_level
	 ORDER BY FIELD(log_level, 'error', 'warning', 'info')",
	array( $migration_id )
);
foreach ( $levels as $level ) {
	echo "  • " . strtoupper( $level->log_level ) . ": " . $level->count . " events\n";
}

echo "\n\n✅ LOGGING DEMONSTRATION COMPLETE\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "The migration lifecycle has been logged completely to the database.\n";
echo "All state, events, errors, and metrics are now queryable and persistent.\n\n";
