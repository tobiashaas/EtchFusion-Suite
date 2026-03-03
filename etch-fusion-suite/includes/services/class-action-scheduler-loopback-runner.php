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
		// Use 'init' instead of 'admin_init' because loopback requests go to admin-ajax.php,
		// and admin_init does NOT fire for AJAX requests. The 'init' hook fires for all request types.
		add_action( 'init', array( self::class, 'handle_queue_trigger' ), 1 );
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

		// If an EFS migration is active, always process the queue (aggressive mode).
		// This ensures headless migrations don't stall waiting for the random 1-in-100 trigger.
		$progress = get_option( 'efs_migration_progress', array() );
		if ( ! empty( $progress['migrationId'] ) && ! empty( $progress['status'] ) ) {
			if ( 'completed' !== $progress['status'] && 'cancelled' !== $progress['status'] ) {
				return true; // Always trigger during active migrations.
			}
		}

		// Otherwise, only on 1 in 100 requests to avoid overhead (for non-migration requests).
		// This is fine for most WordPress sites that don't use Action Scheduler heavily.
		if ( wp_rand( 1, 100 ) > 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * Trigger queue processing via loopback HTTP request.
	 *
	 * Sends a non-blocking request to a custom endpoint that processes the queue.
	 * Uses Docker-aware URL conversion for container-to-container networking.
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

		// Convert localhost URL to internal container hostname for Docker compatibility.
		// In Docker, localhost:8888 from inside a container doesn't resolve to the host.
		// This uses the docker-url-helper to translate to the correct container-internal address.
		if ( function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {
			$url = etch_fusion_suite_convert_to_internal_url( $url );
		}

		// Use wp_remote_post with short timeout for non-blocking behavior.
		// Note: timeout < 0.1 may be too aggressive; WordPress needs time to establish connection.
		// Using 0.5 seconds allows async processing without blocking the calling request.
		$response = wp_remote_post(
			$url,
			array(
				'blocking'  => false,
				'timeout'   => 0.5,
				'sslverify' => apply_filters( 'https_local_over_ssl', false ),
			)
		);

		// Log loopback request status for debugging.
		if ( is_wp_error( $response ) ) {
			error_log( '[EFS] Loopback request failed: ' . $response->get_error_message() . ' (URL: ' . $url . ')' );
		} else {
			error_log( '[EFS] Loopback request initiated successfully (URL: ' . $url . ')' );
		}
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

		error_log( '[EFS] Loopback handler triggered: efs_run_queue param found' );

		// Verify token to ensure this is a legitimate loopback request from our own system.
		if ( ! isset( $_GET['efs_queue_token'] ) ) {
			error_log( '[EFS] Loopback handler: No queue token found' );
			return;
		}

		$token_from_request = isset( $_GET['efs_queue_token'] ) ? sanitize_text_field( wp_unslash( $_GET['efs_queue_token'] ) ) : '';
		$expected_token     = wp_hash( 'efs_queue_runner_' . wp_date( 'Y-m-d H:0' ) );
		if ( ! hash_equals( (string) $expected_token, (string) $token_from_request ) ) {
			error_log( '[EFS] Loopback handler: Token mismatch. Expected: ' . $expected_token . ', Got: ' . $token_from_request );
			return;
		}

		// Verify request comes from localhost or private network (for Docker containers).
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		error_log( '[EFS] Loopback handler: REMOTE_ADDR = ' . $remote_addr );

		// Allow localhost (127.0.0.1, ::1, localhost) and Docker private networks (10.x.x.x, 172.16.x.x-172.31.x.x, 192.168.x.x)
		$is_localhost  = in_array( $remote_addr, array( '127.0.0.1', '::1', 'localhost' ), true );
		$is_private_ip = (bool) preg_match( '/^(10\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|192\.168\.)/', $remote_addr );

		if ( ! ( $is_localhost || $is_private_ip ) ) {
			error_log( '[EFS] Loopback handler: REMOTE_ADDR not in whitelist (not localhost or private IP)' );
			return;
		}

		// Don't output anything.
		if ( function_exists( 'wp_doing_ajax' ) ) {
			define( 'DOING_AJAX', true );
		}

		// Process the Action Scheduler queue.
		if ( class_exists( 'ActionScheduler' ) ) {
			error_log( '[EFS] Loopback handler: Triggering action_scheduler_run_queue' );
			do_action( 'action_scheduler_run_queue' );
		}

		// Exit early without output.
		exit;
	}
}
