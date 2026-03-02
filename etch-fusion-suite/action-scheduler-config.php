<?php
/**
 * Action Scheduler Headless Configuration
 *
 * Configures Action Scheduler to run without WP-Cron by using our own
 * loopback HTTP runner instead. This does NOT disable WP-Cron globally —
 * other plugins' scheduled events continue to work normally.
 *
 * The `action_scheduler_disable_wpcron` filter tells Action Scheduler not
 * to register itself with WP-Cron. Our loopback runner
 * (EFS_Action_Scheduler_Loopback_Runner) triggers the AS queue directly.
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
