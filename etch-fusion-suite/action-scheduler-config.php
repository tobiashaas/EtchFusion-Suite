<?php
/**
 * Action Scheduler Headless Configuration
 *
 * Configures Action Scheduler to run in headless mode without WP-Cron.
 * Uses loopback HTTP requests instead for better hosting compatibility.
 *
 * Note: DISABLE_WP_CRON is defined in main plugin file before AS is loaded.
 *
 * @package Bricks2Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter: Disable Action Scheduler's WP-Cron initialization
 * This filter forces Action Scheduler to only run via our loopback runner
 */
add_filter( 'action_scheduler_disable_wpcron', '__return_true' );

/**
 * Handle loopback queue trigger requests
 */
add_action( 'init', function() {
	// Only on loopback requests
	if ( ! isset( $_GET['efs_run_queue'] ) ) {
		return;
	}

	require_once dirname( __FILE__ ) . '/includes/services/class-action-scheduler-loopback-runner.php';
	\Bricks2Etch\Services\EFS_Action_Scheduler_Loopback_Runner::handle_queue_trigger();
}, 1 );

