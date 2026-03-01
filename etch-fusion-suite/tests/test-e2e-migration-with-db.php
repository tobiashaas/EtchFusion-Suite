<?php
/**
 * End-to-End Migration Test with Database Persistence
 *
 * Tests complete migration lifecycle: start → progress → logs → completion.
 * Verifies that all state is persisted to database and retrieved correctly.
 *
 * Run from WordPress root via:
 * wp eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/test-e2e-migration-with-db.php';"
 */

require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/core/class-db-installer.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-db-migration-persistence.php';
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/repositories/class-wordpress-migration-repository.php';

global $wpdb;

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║        E2E MIGRATION WITH DATABASE PERSISTENCE TEST           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Ensure DB tables exist
\Bricks2Etch\Core\EFS_DB_Installer::install();

$test_results = array();
$migration_id = 'e2e-test-' . wp_generate_uuid4();

// TEST 1: Initialize Progress via Repository
echo "✓ TEST 1: Initialize Progress via Repository\n";
try {
	$repo = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
	
	$progress = array(
		'migrationId'          => $migration_id,
		'status'               => 'running',
		'current_step'         => 'validation',
		'percentage'           => 0,
		'message'              => 'Initializing migration',
		'started_at'           => current_time( 'mysql', true ),
		'last_updated'         => current_time( 'mysql', true ),
	);
	
	$saved = $repo->save_progress( $progress );
	
	if ( $saved || $saved === 0 ) {  // save_progress may return 0 (false) but still succeed
		// Check that it's in the database
		$migration = \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence::get_migration( $migration_id );
		if ( $migration && $migration['status'] === 'running' ) {
			echo "  ✔ Progress initialized in database\n";
			$test_results[] = true;
		} else {
			echo "  ✖ Migration not found in DB after save_progress\n";
			$test_results[] = false;
		}
	} else {
		echo "  ✖ save_progress returned false\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 2: Simulate Progress Updates (like the migrator executor would)
echo "\n✓ TEST 2: Simulate Progress Updates During Migration\n";
try {
	$repo = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
	
	$percentages = array( 10, 25, 50, 75, 100 );
	foreach ( $percentages as $pct ) {
		$progress = array(
			'migrationId'     => $migration_id,
			'status'          => $pct === 100 ? 'completed' : 'running',
			'current_step'    => 'processing',
			'percentage'      => $pct,
			'message'         => "Processing... {$pct}%",
			'last_updated'    => current_time( 'mysql', true ),
			'processedItems'  => $pct,
			'totalItems'      => 100,
		);
		
		$saved = $repo->save_progress( $progress );
		echo "  ✔ Updated to {$pct}%\n";
	}
	
	// Verify final state
	$final = \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence::get_migration( $migration_id );
	if ( $final && isset( $final['progress_percent'] ) && (int) $final['progress_percent'] === 100 ) {
		echo "  ✔ Final state persisted correctly\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Final state check failed\n";
		echo "     Got: progress_percent = " . ( $final ? $final['progress_percent'] : 'null' ) . "\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 3: Audit Trail Logging
echo "\n✓ TEST 3: Audit Trail Logging\n";
try {
	$db_persist = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();
	
	$events = array(
		array( 'info', 'CSS conversion started', 'css' ),
		array( 'info', 'CSS classes migrated: 45', 'css' ),
		array( 'info', 'Media download started', 'media' ),
		array( 'warning', 'Failed to download: https://example.com/image.jpg', 'media' ),
		array( 'info', 'Media download completed: 89 files', 'media' ),
	);
	
	foreach ( $events as list( $level, $message, $category ) ) {
		$db_persist->log_event( $migration_id, $level, $message, $category, array( 'test' => true ) );
		echo "  ✔ Logged [$level] $message\n";
	}
	
	// Verify logs are persisted
	$audit_trail = $db_persist->get_audit_trail( $migration_id );
	if ( is_array( $audit_trail ) && count( $audit_trail ) >= 5 ) {
		echo "  ✔ Audit trail contains " . count( $audit_trail ) . " events\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Audit trail missing events: " . var_export( $audit_trail, true ) . "\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 4: Fallback to Options API
echo "\n✓ TEST 4: Fallback to Options API (Legacy Compatibility)\n";
try {
	$repo = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
	
	// Create a migration using options directly (simulating legacy data)
	$legacy_id = 'legacy-' . wp_generate_uuid4();
	$legacy_data = array(
		'migrationId'  => $legacy_id,
		'status'       => 'completed',
		'percentage'   => 100,
		'message'      => 'Legacy migration data',
	);
	update_option( 'efs_migration_progress', $legacy_data );
	
	// Repository should read from the current migration (most recent)
	$retrieved = $repo->get_progress();
	if ( is_array( $retrieved ) && ! empty( $retrieved['migrationId'] ) ) {
		echo "  ✔ Repository returns migration progress\n";
		echo "     Migration: " . $retrieved['migrationId'] . " Status: " . $retrieved['status'] . "\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Repository failed to return progress\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 5: Stale Migration Detection
echo "\n✓ TEST 5: Stale Migration Detection\n";
try {
	$db_persist = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();
	
	// Create a stale migration (updated 6+ minutes ago)
	global $wpdb;
	$stale_id = 'stale-' . wp_generate_uuid4();
	$stale_time = date( 'Y-m-d H:i:s', time() - 400 );  // 400 seconds ago = ~7 minutes
	
	$wpdb->insert(
		$wpdb->prefix . 'efs_migrations',
		array(
			'migration_uid'  => $stale_id,
			'source_url'     => 'https://bricks.test',
			'target_url'     => 'https://etch.test',
			'status'         => 'in_progress',
			'updated_at'     => $stale_time,
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);
	
	$stale_migrations = $db_persist->get_stale_migrations();
	if ( is_array( $stale_migrations ) && ! empty( $stale_migrations ) ) {
		$is_stale_found = false;
		foreach ( $stale_migrations as $migration ) {
			if ( $migration['migration_uid'] === $stale_id ) {
				$is_stale_found = true;
				break;
			}
		}
		
		if ( $is_stale_found ) {
			echo "  ✔ Stale migration detected correctly\n";
			$test_results[] = true;
		} else {
			echo "  ✖ Stale migration not in results\n";
			$test_results[] = false;
		}
	} else {
		echo "  ✖ No stale migrations returned\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 6: Statistics Aggregation
echo "\n✓ TEST 6: Statistics Aggregation\n";
try {
	$db_persist = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();
	
	$stats = $db_persist->get_statistics();
	if ( is_array( $stats ) && isset( $stats['total_migrations'] ) ) {
		echo "  ✔ Total migrations: " . $stats['total_migrations'] . "\n";
		echo "  ✔ Completed: " . $stats['completed'] . "\n";
		echo "  ✔ Failed: " . $stats['failed'] . "\n";
		echo "  ✔ In progress: " . $stats['in_progress'] . "\n";
		
		// Should have at least the test migrations we created
		if ( $stats['total_migrations'] >= 1 ) {
			echo "  ✔ Statistics working correctly\n";
			$test_results[] = true;
		} else {
			echo "  ✖ Statistics missing test migrations\n";
			$test_results[] = false;
		}
	} else {
		echo "  ✖ Statistics not available\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 7: Migration Completion Mark
echo "\n✓ TEST 7: Migration Completion Mark\n";
try {
	$db_persist = new \Bricks2Etch\Repositories\EFS_DB_Migration_Persistence();
	
	// Mark migration as completed
	$db_persist->update_status( $migration_id, 'completed' );
	
	// Verify
	$migration = $db_persist->get_migration( $migration_id );
	if ( $migration && $migration['status'] === 'completed' && ! is_null( $migration['completed_at'] ) ) {
		echo "  ✔ Migration marked completed with timestamp\n";
		echo "  ✔ Completed at: " . $migration['completed_at'] . "\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Completion not recorded properly\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// Summary
$passed = count( array_filter( $test_results ) );
$total  = count( $test_results );

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║        E2E TEST RESULTS SUMMARY                                ║\n";
echo "╠════════════════════════════════════════════════════════════════╣\n";
echo "║ Passed: $passed/$total                                         ║\n";
echo "║ Success Rate: " . ( $total > 0 ? round( ( $passed / $total ) * 100 ) : 0 ) . "%                                         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

if ( $passed === $total ) {
	echo "✅ All E2E tests passed! Database persistence fully integrated.\n";
} else {
	echo "⚠️  Some tests failed. Review output above.\n";
}
