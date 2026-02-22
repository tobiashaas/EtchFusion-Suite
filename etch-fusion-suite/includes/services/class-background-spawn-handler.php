<?php
/**
 * Background Spawn Handler Service
 *
 * Handles generating migration IDs and spawning the non-blocking background
 * request that runs the long-running migration steps.
 * Extracted from EFS_Migration_Orchestrator.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Background_Spawn_Handler
 *
 * Generates migration IDs and fires the non-blocking loopback request.
 */
class EFS_Background_Spawn_Handler {

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Async_Migration_Runner */
	private $async_runner;

	/**
	 * @param EFS_Error_Handler          $error_handler
	 * @param EFS_Async_Migration_Runner $async_runner
	 */
	public function __construct(
		EFS_Error_Handler $error_handler,
		EFS_Async_Migration_Runner $async_runner
	) {
		$this->error_handler = $error_handler;
		$this->async_runner  = $async_runner;
	}

	/**
	 * Generate a migration identifier.
	 *
	 * @return string
	 */
	public function generate_migration_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'efs_migration_', true );
	}

	/**
	 * Build URL for the background self-request (spawn).
	 *
	 * @return string URL to admin-ajax.php for efs_run_migration_background.
	 */
	public function get_spawn_url(): string {
		$url = admin_url( 'admin-ajax.php' );
		if ( function_exists( 'etch_fusion_suite_resolve_bricks_internal_host' ) ) {
			$internal = etch_fusion_suite_resolve_bricks_internal_host();
			if ( ! empty( $internal ) && is_string( $internal ) ) {
				$url = untrailingslashit( $internal ) . '/wp-admin/admin-ajax.php';
			}
		}
		return (string) apply_filters( 'etch_fusion_suite_spawn_url', $url );
	}

	/**
	 * Trigger a non-blocking request to run the migration in the background.
	 *
	 * Falls back to synchronous execution via EFS_Async_Migration_Runner when
	 * the loopback probe fails.
	 *
	 * @param string      $migration_id
	 * @param string|null $nonce Unused; kept for API. Background auth is via short-lived token.
	 */
	public function spawn_migration_background_request( string $migration_id, ?string $nonce ): void {
		// #region agent log
		$efs_log_spawn = defined( 'ETCH_FUSION_SUITE_DIR' ) ? ETCH_FUSION_SUITE_DIR . 'debug-916622.log' : null;
		// #endregion
		// Use hex token only: wp_generate_password with special chars can produce %XX sequences
		// that sanitize_text_field strips on the receiving end, causing token mismatch → 400.
		$bg_token = bin2hex( random_bytes( 16 ) );
		set_transient( 'efs_bg_' . $migration_id, $bg_token, 120 );

		$url  = $this->get_spawn_url();
		// #region agent log
		if ( $efs_log_spawn ) {
			file_put_contents( $efs_log_spawn, json_encode( array( 'sessionId' => '916622', 'timestamp' => (int) ( microtime( true ) * 1000 ), 'location' => __FILE__ . ':' . __LINE__, 'message' => 'spawn url and token set', 'data' => array( 'url' => $url, 'migration_id' => $migration_id ), 'hypothesisId' => 'B' ) ) . "\n", FILE_APPEND | LOCK_EX );
		}
		// #endregion
		$body = array(
			'action'       => 'efs_run_migration_background',
			'migration_id' => $migration_id,
			'bg_token'     => $bg_token,
		);

		$skip_ssl_verify = in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			|| (bool) apply_filters( 'etch_fusion_suite_loopback_skip_ssl_verify', false );

		// Fire non-blocking POST directly. Skip loopback probe: in single-worker setups the probe
		// would block (same process cannot serve itself) and always timeout, forcing sync fallback.
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 5,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => ! $skip_ssl_verify,
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			// #region agent log
			if ( isset( $efs_log_spawn ) && $efs_log_spawn ) {
				file_put_contents( $efs_log_spawn, json_encode( array( 'sessionId' => '916622', 'timestamp' => (int) ( microtime( true ) * 1000 ), 'location' => __FILE__ . ':' . __LINE__, 'message' => 'wp_remote_post failed', 'data' => array( 'post_error' => $response->get_error_message() ), 'hypothesisId' => 'B', 'runId' => 'post-fix' ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			// #endregion
			$this->error_handler->log_warning(
				'W013',
				array(
					'message' => 'Background spawn request failed (' . $response->get_error_message() . '). Falling back to synchronous execution.',
					'action'  => 'spawn_migration_background_request',
				)
			);
			$this->async_runner->run_migration_execution( $migration_id );
		} else {
			// #region agent log
			if ( isset( $efs_log_spawn ) && $efs_log_spawn ) {
				file_put_contents( $efs_log_spawn, json_encode( array( 'sessionId' => '916622', 'timestamp' => (int) ( microtime( true ) * 1000 ), 'location' => __FILE__ . ':' . __LINE__, 'message' => 'background POST fired (no probe)', 'data' => array( 'url' => $url ), 'hypothesisId' => 'B', 'runId' => 'post-fix' ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			// #endregion
		}
	}
}
