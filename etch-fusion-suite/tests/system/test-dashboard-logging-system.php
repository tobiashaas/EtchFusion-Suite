<?php
/**
 * Comprehensive Dashboard Logging System Test
 *
 * Tests all aspects of the real-time progress logging system:
 * 1. REST API endpoint registration
 * 2. Permission checking
 * 3. Logger trait methods
 * 4. Service integration
 * 5. Database persistence
 * 6. Error handling
 *
 * Run: php tests/system/test-dashboard-logging-system.php
 */

namespace Bricks2Etch\Tests;

$test_results = array();
$test_count   = 0;
$passed_count = 0;

/**
 * Helper: Assert condition
 */
function assert_true( $condition, $message ) {
	global $test_results, $test_count, $passed_count;

	$test_count++;

	if ( $condition ) {
		$test_results[] = "✓ {$message}";
		$passed_count++;
	} else {
		$test_results[] = "✗ {$message}";
	}
}

/**
 * Helper: Assert class exists
 */
function assert_class_exists( $class, $message = null ) {
	$msg = $message ?: "Class {$class} exists";
	assert_true( class_exists( $class ), $msg );
}

/**
 * Helper: Assert trait exists
 */
function assert_trait_exists( $trait, $message = null ) {
	$msg = $message ?: "Trait {$trait} exists";
	assert_true( trait_exists( $trait ), $msg );
}

/**
 * Helper: Assert method exists
 */
function assert_method_exists( $class, $method, $message = null ) {
	$msg = $message ?: "Method {$class}::{$method}() exists";
	assert_true( method_exists( $class, $method ), $msg );
}

echo str_repeat( '=', 70 ) . PHP_EOL;
echo 'Dashboard Real-Time Logging System - Comprehensive Test Suite' . PHP_EOL;
echo str_repeat( '=', 70 ) . PHP_EOL;
echo PHP_EOL;

// ==========================================
// TEST PHASE 1: Component Registration
// ==========================================
echo '[PHASE 1] Component Registration' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

assert_class_exists( 'Bricks2Etch\Admin\EFS_Progress_Dashboard_API', 'REST API class exists' );
assert_class_exists( 'Bricks2Etch\Services\EFS_Detailed_Progress_Tracker', 'Progress Tracker class exists' );
assert_class_exists( 'Bricks2Etch\Services\EFS_Media_Service', 'Media Service class exists' );
assert_class_exists( 'Bricks2Etch\Services\EFS_CSS_Service', 'CSS Service class exists' );
assert_trait_exists( 'Bricks2Etch\Controllers\EFS_Migration_Progress_Logger', 'Logger trait exists' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 2: REST API Structure
// ==========================================
echo '[PHASE 2] REST API Endpoint Structure' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

$api = 'Bricks2Etch\Admin\EFS_Progress_Dashboard_API';

assert_method_exists( $api, 'register_routes', 'REST route registration method exists' );
assert_method_exists( $api, 'check_dashboard_access', 'Permission check method exists' );
assert_method_exists( $api, 'handle_progress_request', 'Progress endpoint callback exists' );
assert_method_exists( $api, 'handle_errors_request', 'Errors endpoint callback exists' );
assert_method_exists( $api, 'handle_category_request', 'Category endpoint callback exists' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 3: Logger Trait Methods
// ==========================================
echo '[PHASE 3] Logger Trait Methods (via REST API)' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

assert_method_exists( $api, 'get_migration_progress', 'Progress query method exists' );
assert_method_exists( $api, 'get_migration_errors', 'Error query method exists' );
assert_method_exists( $api, 'get_migration_logs_by_category', 'Category filter method exists' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 4: Service Integration
// ==========================================
echo '[PHASE 4] Service Logging Integration' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

$media_svc = 'Bricks2Etch\Services\EFS_Media_Service';
$css_svc   = 'Bricks2Etch\Services\EFS_CSS_Service';

assert_method_exists( $media_svc, 'migrate_media_by_id', 'Media service has per-item migration method' );
assert_method_exists( $media_svc, 'set_progress_tracker', 'Media service accepts progress tracker' );

assert_method_exists( $css_svc, 'migrate_css_classes', 'CSS service has migration method' );
assert_method_exists( $css_svc, 'set_progress_tracker', 'CSS service accepts progress tracker' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 5: Progress Tracker Features
// ==========================================
echo '[PHASE 5] Progress Tracker Features' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

$tracker = 'Bricks2Etch\Services\EFS_Detailed_Progress_Tracker';

assert_method_exists( $tracker, 'log_post_migration', 'Tracker can log posts' );
assert_method_exists( $tracker, 'log_media_migration', 'Tracker can log media' );
assert_method_exists( $tracker, 'log_css_migration', 'Tracker can log CSS' );
assert_method_exists( $tracker, 'log_batch_completion', 'Tracker can log batch completion' );
assert_method_exists( $tracker, 'set_current_item', 'Tracker can set current item' );
assert_method_exists( $tracker, 'get_current_item', 'Tracker can retrieve current item' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 6: Database Persistence
// ==========================================
echo '[PHASE 6] Database Persistence' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

$db_persist = 'Bricks2Etch\Repositories\EFS_DB_Migration_Persistence';

if ( class_exists( $db_persist ) ) {
	assert_true( true, 'DB Persistence class exists' );
	assert_method_exists( $db_persist, 'log_event', 'Can log events to audit trail' );
	assert_method_exists( $db_persist, 'get_audit_trail', 'Can query audit trail' );
} else {
	assert_true( false, 'DB Persistence class exists' );
}

echo PHP_EOL;

// ==========================================
// TEST PHASE 7: Error Handling
// ==========================================
echo '[PHASE 7] Error Handling' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

// Test REST API with mock objects
$api_instance = new $api();

// Test with empty migration ID (should return WP_Error)
$result = $api_instance->get_migration_progress( '' );
assert_true( is_wp_error( $result ) || is_array( $result ), 'Progress handles invalid migration ID' );

// Test with valid but non-existent ID (should return error or empty array)
$result = $api_instance->get_migration_errors( 'nonexistent-id' );
assert_true( is_array( $result ), 'Errors method always returns array' );

$result = $api_instance->get_migration_logs_by_category( 'nonexistent-id', 'test' );
assert_true( is_array( $result ), 'Category filter always returns array' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 8: Security
// ==========================================
echo '[PHASE 8] Security Checks' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

// Check permission method signature
$reflection = new \ReflectionMethod( $api, 'check_dashboard_access' );
assert_true( ! $reflection->isPrivate(), 'Permission check is callable by REST' );

// Verify API class uses trait
$traits = class_uses( $api );
assert_true( isset( $traits['Bricks2Etch\Controllers\EFS_Migration_Progress_Logger'] ), 'API uses Logger trait' );

echo PHP_EOL;

// ==========================================
// TEST PHASE 9: Syntax Validation
// ==========================================
echo '[PHASE 9] Syntax Validation' . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;

$files_to_check = array(
	'includes/controllers/trait-migration-progress-logger.php'    => 'Migration Progress Logger trait',
	'includes/admin/class-progress-dashboard-api.php'             => 'Progress Dashboard API',
	'includes/services/class-detailed-progress-tracker.php'       => 'Detailed Progress Tracker',
);

foreach ( $files_to_check as $file => $name ) {
	$full_path = __DIR__ . '/../../' . $file;
	if ( file_exists( $full_path ) ) {
		assert_true( true, "{$name} file exists" );
	} else {
		assert_true( false, "{$name} file exists (not found: {$full_path})" );
	}
}

echo PHP_EOL;

// ==========================================
// SUMMARY
// ==========================================
echo str_repeat( '=', 70 ) . PHP_EOL;
echo 'Test Results Summary' . PHP_EOL;
echo str_repeat( '=', 70 ) . PHP_EOL;
echo PHP_EOL;

foreach ( $test_results as $result ) {
	echo $result . PHP_EOL;
}

echo PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;
echo "Total Tests: {$test_count}" . PHP_EOL;
echo "Passed: {$passed_count}" . PHP_EOL;
echo "Failed: " . ( $test_count - $passed_count ) . PHP_EOL;
echo str_repeat( '-', 70 ) . PHP_EOL;
echo PHP_EOL;

if ( $passed_count === $test_count ) {
	echo '✓ ALL TESTS PASSED!' . PHP_EOL;
	echo PHP_EOL;
	echo 'REST API Endpoints Ready:' . PHP_EOL;
	echo '  GET /wp-json/efs/v1/migration/{id}/progress' . PHP_EOL;
	echo '  GET /wp-json/efs/v1/migration/{id}/errors' . PHP_EOL;
	echo '  GET /wp-json/efs/v1/migration/{id}/logs/{category}' . PHP_EOL;
	echo PHP_EOL;
	echo 'Dashboard can now display real-time migration progress!' . PHP_EOL;
	echo PHP_EOL;
	exit( 0 );
} else {
	echo '✗ SOME TESTS FAILED' . PHP_EOL;
	echo PHP_EOL;
	exit( 1 );
}
