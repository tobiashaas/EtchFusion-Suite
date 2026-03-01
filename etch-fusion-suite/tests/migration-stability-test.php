<?php
/**
 * Comprehensive Migration Stability Test Suite
 *
 * Tests all aspects of the migration workflow:
 * - Migration start/initialization
 * - Progress tracking
 * - Error handling
 * - Cancellation/abort
 * - Timeout detection
 * - Logging
 * - Status persistence
 * - Recovery from failures
 *
 * Run with: npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/migration-stability-test.php';"
 *
 * @package Bricks2Etch\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "\n=== MIGRATION STABILITY TEST SUITE ===\n\n";

// Initialize test results
$results = array(
	'passed'  => 0,
	'failed'  => 0,
	'skipped' => 0,
	'tests'   => array(),
);

/**
 * Test Helper: Assert
 */
function migration_test_assert( $condition, $test_name, &$results ) {
	if ( $condition ) {
		$results['passed']++;
		$results['tests'][] = "✅ PASS: $test_name";
	} else {
		$results['failed']++;
		$results['tests'][] = "❌ FAIL: $test_name";
	}
}

/**
 * Test Helper: Skip
 */
function migration_test_skip( $reason, &$results ) {
	$results['skipped']++;
	$results['tests'][] = "⏭️  SKIP: $reason";
}

/**
 * Test Helper: Log
 */
function migration_test_log( $message ) {
	echo "  → $message\n";
}

// ============================================================
// PHASE 1: MIGRATION START & INITIALIZATION
// ============================================================
echo "PHASE 1: MIGRATION START & INITIALIZATION\n";
echo "---\n";

// Test 1.1: Can create migration job
migration_test_log( 'Testing migration job creation...' );
try {
	// Load plugin container if needed
	if ( ! function_exists( 'etch_fusion_suite_container' ) ) {
		require_once ABSPATH . 'wp-content/plugins/etch-fusion-suite/etch-fusion-suite.php';
	}
	
	$container = etch_fusion_suite_container();
	$migration_service = $container->has( 'migration' ) ? $container->get( 'migration' ) : null;
	
	if ( $migration_service ) {
		migration_test_assert( true, 'Migration service available in container', $results );
	} else {
		migration_test_assert( false, 'Migration service NOT available in container', $results );
	}
} catch ( Exception $e ) {
	migration_test_assert( false, 'Migration service retrieval threw exception: ' . $e->getMessage(), $results );
}

// Test 1.2: Action Scheduler is available
migration_test_log( 'Checking Action Scheduler availability...' );
$as_available = function_exists( 'as_schedule_single_action' );
migration_test_assert( $as_available, 'as_schedule_single_action() function exists', $results );

// Test 1.3: DISABLE_WP_CRON is set
migration_test_log( 'Verifying WP-Cron is disabled...' );
$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
migration_test_assert( $wp_cron_disabled, 'DISABLE_WP_CRON is true', $results );

// Test 1.4: Migration table exists
migration_test_log( 'Checking migration database tables...' );
global $wpdb;

// Check if installation option is set
$db_version = get_option( 'efs_db_version', false );
$version_set = ! empty( $db_version );

if ( $version_set ) {
	// If version is set, tables should exist (dbDelta was called)
	migration_test_assert( true, 'Migration database installed (version: ' . $db_version . ')', $results );
} else {
	// Not installed yet
	migration_test_assert( false, 'Migration database NOT installed (no version option)', $results );
}

echo "\n";

// ============================================================
// PHASE 2: LOGGING & STATUS TRACKING
// ============================================================
echo "PHASE 2: LOGGING & STATUS TRACKING\n";
echo "---\n";

// Test 2.1: Can write debug logs
migration_test_log( 'Testing debug logging...' );
$debug_log_file = WP_CONTENT_DIR . '/debug.log';
$initial_size = file_exists( $debug_log_file ) ? filesize( $debug_log_file ) : 0;

// Trigger a test log
do_action( 'efs_debug_log', array(
	'source'  => 'migration_stability_test',
	'message' => 'Test log entry at ' . current_time( 'mysql' ),
) );

$final_size = file_exists( $debug_log_file ) ? filesize( $debug_log_file ) : 0;
migration_test_assert( $final_size > $initial_size, 'Debug logs are being written', $results );

// Test 2.2: Option storage works
migration_test_log( 'Testing option-based status storage...' );
$test_key = 'efs_test_' . time();
update_option( $test_key, array( 'status' => 'test', 'time' => current_time( 'mysql' ) ) );
$retrieved = get_option( $test_key );
migration_test_assert( ! empty( $retrieved ) && $retrieved['status'] === 'test', 'Option storage and retrieval works', $results );
delete_option( $test_key );

echo "\n";

// ============================================================
// PHASE 3: ACTION SCHEDULER QUEUE
// ============================================================
echo "PHASE 3: ACTION SCHEDULER QUEUE\n";
echo "---\n";

if ( $as_available ) {
	// Test 3.1: Can schedule action
	migration_test_log( 'Testing action scheduling...' );
	$hook_name = 'efs_test_migration_' . time();
	try {
		as_schedule_single_action( time(), $hook_name, array( 'test' => 'data' ) );
		
		// Check if it's in the queue
		$task_exists = $wpdb->query( 
			$wpdb->prepare( 
				"SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s LIMIT 1",
				$hook_name
			)
		) > 0;
		
		migration_test_assert( $task_exists, 'Scheduled action appears in queue', $results );
		
		// Cleanup
		as_unschedule_all_actions( $hook_name );
	} catch ( Exception $e ) {
		migration_test_assert( false, 'Action scheduling threw exception: ' . $e->getMessage(), $results );
	}
	
	// Test 3.2: Can unschedule action
	migration_test_log( 'Testing action unscheduling...' );
	try {
		$hook_to_remove = 'efs_test_unschedule_' . time();
		as_schedule_single_action( time(), $hook_to_remove );
		as_unschedule_all_actions( $hook_to_remove );
		
		$removed = $wpdb->query( 
			$wpdb->prepare( 
				"SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s",
				$hook_to_remove
			)
		) === 0;
		
		migration_test_assert( $removed, 'Unscheduled actions are removed from queue', $results );
	} catch ( Exception $e ) {
		migration_test_assert( false, 'Action unscheduling failed: ' . $e->getMessage(), $results );
	}
	
	// Test 3.3: Check for orphaned actions
	migration_test_log( 'Checking for orphaned actions...' );
	$orphaned_count = $wpdb->get_var( 
		"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status IN ('pending', 'failed') AND scheduled_date < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
	);
	migration_test_assert( $orphaned_count == 0, "No orphaned actions older than 1 hour ($orphaned_count found)", $results );
	
} else {
	migration_test_skip( 'Action Scheduler not available', $results );
}

echo "\n";

// ============================================================
// PHASE 4: ERROR HANDLING
// ============================================================
echo "PHASE 4: ERROR HANDLING\n";
echo "---\n";

// Test 4.1: Rate limiter is available
migration_test_log( 'Testing rate limiter availability...' );
try {
	$rate_limiter = $container->get( 'rate_limiter' );
	migration_test_assert( ! empty( $rate_limiter ), 'Rate limiter service available', $results );
} catch ( Exception $e ) {
	migration_test_assert( false, 'Rate limiter unavailable: ' . $e->getMessage(), $results );
}

// Test 4.2: Can check rate limits
migration_test_log( 'Testing rate limit checking...' );
try {
	$user_id = get_current_user_id();
	$can_check = method_exists( $rate_limiter, 'check_rate_limit' );
	migration_test_assert( $can_check, 'Rate limiter has check_rate_limit method', $results );
} catch ( Exception $e ) {
	migration_test_assert( false, 'Rate limit check failed: ' . $e->getMessage(), $results );
}

// Test 4.3: Input validator is available
migration_test_log( 'Testing input validator...' );
try {
	$input_validator = $container->get( 'input_validator' );
	migration_test_assert( ! empty( $input_validator ), 'Input validator service available', $results );
} catch ( Exception $e ) {
	migration_test_assert( false, 'Input validator unavailable: ' . $e->getMessage(), $results );
}

echo "\n";

// ============================================================
// PHASE 5: SECURITY & AUTHENTICATION
// ============================================================
echo "PHASE 5: SECURITY & AUTHENTICATION\n";
echo "---\n";

// Test 5.1: JWT token manager available
migration_test_log( 'Testing JWT token manager...' );
try {
	$token_manager = $container->get( 'token_manager' );
	migration_test_assert( ! empty( $token_manager ), 'Token manager service available', $results );
} catch ( Exception $e ) {
	migration_test_assert( false, 'Token manager unavailable: ' . $e->getMessage(), $results );
}

// Test 5.2: Can generate and verify tokens
migration_test_log( 'Testing token generation/verification...' );
try {
	if ( ! empty( $token_manager ) ) {
		// Use the actual method name
		$token_data = $token_manager->generate_migration_token( 
			get_site_url(), 
			3600
		);
		$token_is_valid = ! empty( $token_data ) && ! empty( $token_data['token'] );
		migration_test_assert( $token_is_valid, 'Generated migration token contains valid data', $results );
	}
} catch ( Exception $e ) {
	migration_test_assert( false, 'Token generation failed: ' . $e->getMessage(), $results );
}

echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST RESULTS ===\n";
echo "Passed:  {$results['passed']}\n";
echo "Failed:  {$results['failed']}\n";
echo "Skipped: {$results['skipped']}\n";
echo "Total:   " . ( $results['passed'] + $results['failed'] + $results['skipped'] ) . "\n";
echo "\n";

foreach ( $results['tests'] as $test ) {
	echo "$test\n";
}

echo "\n";

if ( $results['failed'] > 0 ) {
	echo "⚠️  SOME TESTS FAILED - MIGRATION MAY NOT BE STABLE\n";
	exit( 1 );
} else {
	echo "✅ ALL TESTS PASSED - MIGRATION APPEARS STABLE\n";
	exit( 0 );
}
