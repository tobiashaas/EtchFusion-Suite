<?php
/**
 * Action Scheduler Headless Configuration
 *
 * Configures Action Scheduler to run in headless mode without WP-Cron.
 * Uses loopback HTTP requests instead for better hosting compatibility.
 *
 * @package Bricks2Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Disable WP-Cron for Action Scheduler
 * Action Scheduler will not use wp_schedule_event() for queue processing
 */
define( 'DISABLE_WP_CRON', true );

/**
 * Filter: Disable Action Scheduler's WP-Cron initialization
 * This forces Action Scheduler to only run via our loopback runner
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

	require_once dirname( __FILE__ ) . '/services/class-action-scheduler-loopback-runner.php';
	\Bricks2Etch\Services\EFS_Action_Scheduler_Loopback_Runner::handle_queue_trigger();
}, 1 );
