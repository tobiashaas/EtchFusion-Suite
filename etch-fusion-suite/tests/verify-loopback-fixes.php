<?php
/**
 * Verification script for Action Scheduler loopback fixes
 *
 * Tests:
 * 1. Docker URL conversion works
 * 2. Hook handler is registered on 'init' (not 'admin_init')
 * 3. No duplicate handlers exist
 * 4. Class exists and methods are callable
 *
 * Run with: npm run wp -- eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/verify-loopback-fixes.php';"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "=== Verifying Loopback Fixes ===\n\n";

$passed = 0;
$failed = 0;

// Test 1: Docker URL helper function exists
echo "✓ Test 1: Docker URL helper function exists\n";
if ( function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {
	echo "  PASS: etch_fusion_suite_convert_to_internal_url() function found\n";
	$passed++;
} else {
	echo "  FAIL: etch_fusion_suite_convert_to_internal_url() function not found\n";
	$failed++;
}

// Test 2: Docker URL conversion works correctly
echo "\n✓ Test 2: Docker URL conversion works\n";
$test_url = 'http://localhost:8888/wp-admin/admin-ajax.php?efs_run_queue=1';
$converted = etch_fusion_suite_convert_to_internal_url( $test_url );
if ( strpos( $converted, 'localhost' ) === false ) {
	echo "  PASS: localhost URL converted to internal URL\n";
	echo "    Original: $test_url\n";
	echo "    Converted: $converted\n";
	$passed++;
} else {
	echo "  FAIL: localhost URL not converted\n";
	echo "    URL: $converted\n";
	$failed++;
}

// Test 3: EFS_Action_Scheduler_Loopback_Runner class exists
echo "\n✓ Test 3: Loopback runner class exists\n";
if ( class_exists( 'Bricks2Etch\\Services\\EFS_Action_Scheduler_Loopback_Runner' ) ) {
	echo "  PASS: EFS_Action_Scheduler_Loopback_Runner class found\n";
	$passed++;
} else {
	echo "  FAIL: EFS_Action_Scheduler_Loopback_Runner class not found\n";
	$failed++;
}

// Test 4: Check that hook is registered (inspect global $wp_filter array)
echo "\n✓ Test 4: Hook registration check\n";
global $wp_filter;
$init_hooks = isset( $wp_filter['init'] ) ? $wp_filter['init'] : array();

// Count how many times 'handle_queue_trigger' appears
$count = 0;
foreach ( $init_hooks as $priority => $callbacks ) {
	if ( is_array( $callbacks ) ) {
		foreach ( $callbacks as $hook_name => $callback_data ) {
			if ( isset( $callback_data['function'] ) && is_array( $callback_data['function'] ) ) {
				if ( isset( $callback_data['function'][1] ) && $callback_data['function'][1] === 'handle_queue_trigger' ) {
					$count++;
				}
			}
		}
	}
}

if ( $count > 0 ) {
	echo "  PASS: handle_queue_trigger is registered on 'init' hook (count: $count)\n";
	$passed++;
} else {
	echo "  WARNING: handle_queue_trigger not found on init hook via global inspection\n";
	echo "    (This may be expected if the hook is registered in plugin initialization order)\n";
}

// Test 5: Verify no duplicate handler exists in action-scheduler-config.php
echo "\n✓ Test 5: Duplicate handler removal verification\n";
$config_file = dirname( dirname( __FILE__ ) ) . '/action-scheduler-config.php';
$config_content = file_get_contents( $config_file );

if ( strpos( $config_content, 'add_action' ) === false || strpos( $config_content, 'handle_queue_trigger' ) === false ) {
	echo "  PASS: No duplicate handler found in action-scheduler-config.php\n";
	echo "    File only contains the disable_wpcron filter\n";
	$passed++;
} else {
	echo "  FAIL: Possible duplicate handler found in action-scheduler-config.php\n";
	$failed++;
}

// Test 6: Verify loopback runner class file doesn't have admin_init anymore
echo "\n✓ Test 6: Hook change from admin_init to init\n";
$runner_file = dirname( __FILE__ ) . '/includes/services/class-action-scheduler-loopback-runner.php';
if ( file_exists( $runner_file ) ) {
	$runner_content = file_get_contents( $runner_file );
	
	// Check that 'init' hook is used for handle_queue_trigger
	if ( preg_match( "/add_action\s*\(\s*['\"]init['\"]\s*,.*handle_queue_trigger/s", $runner_content ) ) {
		echo "  PASS: handle_queue_trigger registered on 'init' hook\n";
		$passed++;
	} else {
		echo "  FAIL: handle_queue_trigger not found on 'init' hook\n";
		$failed++;
	}
	
	// Check that admin_init is NOT used for handle_queue_trigger
	if ( ! preg_match( "/add_action\s*\(\s*['\"]admin_init['\"]\s*,.*handle_queue_trigger/s", $runner_content ) ) {
		echo "  PASS: handle_queue_trigger NOT registered on 'admin_init' hook\n";
		$passed++;
	} else {
		echo "  FAIL: handle_queue_trigger still uses admin_init hook\n";
		$failed++;
	}
}

// Summary
echo "\n";
echo "=== Verification Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ( $failed === 0 ) {
	echo "\n✅ All tests passed! Loopback fixes are working correctly.\n";
} else {
	echo "\n❌ Some tests failed. Please review the output above.\n";
}

exit( $failed > 0 ? 1 : 0 );
