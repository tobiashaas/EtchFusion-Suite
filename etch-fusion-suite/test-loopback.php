<?php
/**
 * Test loopback request trigger
 */

echo "=== Testing Loopback Request ===\n\n";

if ( ! class_exists( 'Bricks2Etch\Services\EFS_Action_Scheduler_Loopback_Runner' ) ) {
	echo "ERROR: Class not found\n";
	exit;
}

$runner = new Bricks2Etch\Services\EFS_Action_Scheduler_Loopback_Runner();
echo "1. Runner instantiated ✓\n";

echo "2. Triggering loopback request...\n";
$result = $runner->trigger_loopback_request();

echo "3. Result:\n";
if ( is_wp_error( $result ) ) {
	echo "   ERROR: " . $result->get_error_message() . "\n";
	echo "   Code: " . $result->get_error_code() . "\n";
} else {
	echo "   Success: " . print_r( $result, true ) . "\n";
}

echo "\n4. Checking Action Scheduler status:\n";
$status = do_action( 'action_scheduler_run_queue' );
echo "   Queue processing triggered\n";
