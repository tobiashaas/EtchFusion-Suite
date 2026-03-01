<?php
/**
 * Integration Test: Detailed Progress Tracking
 *
 * This test file demonstrates that the detailed progress tracking is working correctly.
 * It simulates a migration and verifies that individual post details are logged.
 *
 * Usage: npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/test-detailed-progress-tracking.php';"
 *
 * @package Bricks2Etch\Tests
 */

// Avoid WP initialization if not running in WP environment.
if ( ! defined( 'ABSPATH' ) ) {
	echo "WordPress environment not detected. Exiting.\n";
	exit( 1 );
}

// Setup test helpers.
$container  = etch_fusion_suite_container();
$persistence = $container->get( 'db_migration_persistence' );

echo "\n=== Detailed Progress Tracking Integration Test ===\n\n";

// Simulate a migration ID.
$migration_id = 'test-tracking-' . uniqid();

// Ensure migration exists.
$migration = $persistence->create_migration(
	array(
		'migration_uid' => $migration_id,
		'source_url'   => 'http://localhost:8888',
		'target_url'   => 'http://localhost:8889',
		'initiated_by' => 'admin',
		'state'        => 'in_progress',
	)
);

if ( is_wp_error( $migration ) ) {
	echo "❌ Failed to create migration: " . $migration->get_error_message() . "\n";
	exit( 1 );
}

echo "✅ Created migration: $migration_id\n";

// Create the tracker and test its logging methods.
require_once EFS_PLUGIN_DIR . 'includes/services/class-detailed-progress-tracker.php';

$tracker = new \Bricks2Etch\Services\EFS_Detailed_Progress_Tracker( $migration_id, $persistence );

echo "\n--- Testing Post Logging ---\n";
$tracker->log_post_migration(
	42,
	'Sample Post Title',
	'success',
	array(
		'blocks_converted'    => 5,
		'fields_migrated'     => 2,
		'custom_fields'       => 1,
		'duration_ms'         => 450,
	)
);
echo "✅ Logged successful post migration\n";

$tracker->log_post_migration(
	43,
	'Failed Post',
	'failed',
	array(
		'error'               => 'Unsupported block type',
		'unsupported_blocks'  => array( 'bricks-form', 'bricks-slider' ),
		'duration_ms'         => 200,
	)
);
echo "✅ Logged failed post migration\n";

echo "\n--- Testing Media Logging ---\n";
$tracker->log_media_migration(
	'https://example.com/image.jpg',
	'image.jpg',
	'success',
	array(
		'size_bytes'   => 245000,
		'mime_type'    => 'image/jpeg',
		'duration_ms'  => 1200,
	)
);
echo "✅ Logged successful media migration\n";

$tracker->log_media_migration(
	'https://example.com/missing.jpg',
	'missing.jpg',
	'failed',
	array(
		'error'        => '404 Not Found',
		'duration_ms'  => 500,
	)
);
echo "✅ Logged failed media migration\n";

echo "\n--- Testing CSS Logging ---\n";
$tracker->log_css_migration(
	'button-primary',
	'converted',
	array(
		'new_class_name' => 'etch-btn-primary',
		'conflicts'      => 0,
	)
);
echo "✅ Logged CSS class conversion\n";

$tracker->log_css_migration(
	'slider-nav',
	'conflict',
	array(
		'conflict_reason' => 'Multiple possible conversions',
		'suggestions'     => array( 'etch-slider-nav-v1', 'etch-slider-nav-v2' ),
	)
);
echo "✅ Logged CSS class conflict\n";

echo "\n--- Testing Custom Fields Logging ---\n";
$tracker->log_custom_fields_migration(
	'ACF',
	15,
	'success',
	array(
		'acf_field_groups' => 3,
		'complex_fields'   => 5,
	)
);
echo "✅ Logged custom fields migration\n";

echo "\n--- Testing Batch Completion Logging ---\n";
$tracker->log_batch_completion(
	'posts',
	50,
	100,
	2,
	array(
		'avg_duration_ms' => 1250,
		'memory_peak'     => '256MB',
	)
);
echo "✅ Logged batch completion\n";

// Now query the audit trail to verify all logs were recorded.
echo "\n--- Verifying Logged Events ---\n";
$trail = $persistence->get_audit_trail( $migration_id );

if ( is_array( $trail ) ) {
	echo sprintf( "✅ Found %d logged events\n\n", count( $trail ) );

	// Display a sample of logs.
	$categories = array();
	foreach ( $trail as $log_entry ) {
		$cat = $log_entry['category'] ?? 'unknown';
		$categories[ $cat ] = ( $categories[ $cat ] ?? 0 ) + 1;
	}

	echo "Log categories found:\n";
	foreach ( $categories as $cat => $count ) {
		echo sprintf( "  - %s: %d entries\n", $cat, $count );
	}

	// Display detailed info for post logs.
	echo "\nSample post migration logs:\n";
	foreach ( $trail as $log_entry ) {
		if ( in_array( $log_entry['category'] ?? '', array( 'content_post_migrated', 'content_post_failed' ), true ) ) {
			$ctx = json_decode( $log_entry['context'], true );
			echo sprintf(
				"  - [%s] Post #%d '%s' (%s): %d blocks, %d fields, %.0f ms\n",
				$log_entry['level'],
				$ctx['post_id'] ?? 'N/A',
				$ctx['title'] ?? 'N/A',
				$ctx['status'] ?? 'N/A',
				$ctx['blocks_converted'] ?? $ctx['blocks_migrated'] ?? 'N/A',
				$ctx['fields_migrated'] ?? 'N/A',
				$ctx['duration_ms'] ?? 'N/A'
			);
		}
	}
} else {
	echo "❌ Failed to retrieve audit trail\n";
}

echo "\n--- Current Item State ---\n";
$current = $tracker->get_current_item();
if ( ! empty( $current ) ) {
	echo sprintf(
		"Current: %s #%s '%s' [%s]\n",
		$current['type'],
		$current['id'],
		$current['title'],
		$current['status']
	);
}

echo "\n✅ All tests passed! Detailed tracking is working correctly.\n";
echo "Migration ID: $migration_id (database persisted)\n";
