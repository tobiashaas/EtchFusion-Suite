<?php
/**
 * Action Scheduler Loopback Queue Runner
 *
 * Triggers the Action Scheduler queue via loopback HTTP requests instead of WP-Cron.
 * This approach is more reliable and works on shared hosting without wp-cron.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Action_Scheduler_Loopback_Runner
 *
 * Implements loopback HTTP-based queue runner for Action Scheduler.
 * Sends a non-blocking HTTP request to process the queue.
 */
class EFS_Action_Scheduler_Loopback_Runner {

	/**
	 * Initialize loopback queue runner.
	 * Hooks into WordPress to trigger queue processing via loopback requests.
	 */
	public static function init(): void {
		// Trigger queue on frontend and admin requests (excluding AJAX).
		if ( ! wp_doing_ajax() ) {
			add_action( 'init', array( self::class, 'maybe_trigger_queue' ), 999 );
		}

		// Also check on shutdown to catch any remaining actions.
		add_action( 'shutdown', array( self::class, 'maybe_trigger_queue' ), 999 );

		// Hook the queue handler to process legitimate loopback requests.
		add_action( 'admin_init', array( self::class, 'handle_queue_trigger' ), 1 );
	}

	/**
	 * Maybe trigger queue processing via loopback request.
	 * Only triggers if queue has pending actions and respects rate limiting.
	 */
	public static function maybe_trigger_queue(): void {
		// Check if we should run the queue now.
		if ( ! self::should_run_queue() ) {
			return;
		}

		// Store that we're triggering a queue run to prevent duplicate requests.
		set_transient( 'efs_action_scheduler_running', 1, 5 );

		// Trigger loopback request to process queue.
		self::trigger_loopback_request();
	}

	/**
	 * Check if queue should be triggered.
	 *
	 * @return bool True if queue should run now, false otherwise.
	 */
	private static function should_run_queue(): bool {
		// Check transient to prevent too frequent runs (every 5 seconds minimum).
		if ( get_transient( 'efs_action_scheduler_running' ) ) {
			return false;
		}

		// Only on 1 in 100 requests to avoid overhead.
		// (most sites won't have frequent requests; adjust as needed).
		if ( wp_rand( 1, 100 ) > 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * Trigger queue processing via loopback HTTP request.
	 *
	 * Sends a non-blocking request to a custom endpoint that processes the queue.
	 */
	private static function trigger_loopback_request(): void {
		// Generate secure token to verify this is our loopback request.
		$token = wp_hash( 'efs_queue_runner_' . wp_date( 'Y-m-d H:0' ) );
		$url   = add_query_arg(
			array(
				'efs_run_queue'   => '1',
				'efs_queue_token' => $token,
			),
			admin_url( 'admin-ajax.php' )
		);

		// Use wp_remote_post with timeout=0.001 to make it non-blocking.
		wp_remote_post(
			$url,
			array(
				'blocking'  => false,
				'timeout'   => 0.001,
				'sslverify' => apply_filters( 'https_local_over_ssl', false ),
			)
		);
	}

	/**
	 * Handle loopback queue processing request.
	 * Verifies request authenticity before processing the queue.
	 */
	public static function handle_queue_trigger(): void {
		// Verify this is our loopback request (both parameter and token required).
		if ( ! isset( $_GET['efs_run_queue'] ) || '1' !== $_GET['efs_run_queue'] ) {
			return;
		}

		// Verify token to ensure this is a legitimate loopback request from our own system.
		if ( ! isset( $_GET['efs_queue_token'] ) ) {
			return;
		}

		$token_from_request = isset( $_GET['efs_queue_token'] ) ? sanitize_text_field( wp_unslash( $_GET['efs_queue_token'] ) ) : '';
		$expected_token     = wp_hash( 'efs_queue_runner_' . wp_date( 'Y-m-d H:0' ) );
		if ( ! hash_equals( (string) $expected_token, (string) $token_from_request ) ) {
			return;
		}

		// Verify request comes from localhost to prevent external triggering.
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! in_array( $remote_addr, array( '127.0.0.1', '::1', 'localhost' ), true ) ) {
			return;
		}

		// Don't output anything.
		if ( function_exists( 'wp_doing_ajax' ) ) {
			define( 'DOING_AJAX', true );
		}

		// Process the Action Scheduler queue.
		if ( class_exists( 'ActionScheduler' ) ) {
			do_action( 'action_scheduler_run_queue' );
		}

		// Exit early without output.
		exit;
	}
}
