<?php
/**
 * Background Spawn Handler Service
 *
 * Handles generating migration IDs and spawning the non-blocking background
 * request that runs the long-running migration steps (validation, CSS, etc.)
 * for the browser-mode migration path.
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
 * Fires a non-blocking loopback POST to admin-ajax.php so that the
 * long-running migration phases (validation, CSS, collection) execute
 * in a separate PHP process while the initial AJAX response returns
 * immediately to the browser.
 */
class EFS_Background_Spawn_Handler {

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Async_Migration_Runner */
	private $async_runner;

	/**
	 * @param EFS_Error_Handler          $error_handler
	 * @param EFS_Async_Migration_Runner $async_runner  Fallback for environments where loopback fails.
	 */
	public function __construct(
		EFS_Error_Handler $error_handler,
		EFS_Async_Migration_Runner $async_runner
	) {
		$this->error_handler = $error_handler;
		$this->async_runner  = $async_runner;
	}

	/**
	 * Build the URL for the background self-request.
	 *
	 * Resolves the internal Docker host when running inside wp-env so the
	 * loopback POST reaches the correct container instead of the public URL.
	 *
	 * @return string
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
	 * Trigger a non-blocking background request to run the migration.
	 *
	 * A short-lived hex token stored in a transient authenticates the request
	 * on the receiving end, avoiding the need to pass a nonce across processes.
	 * If the POST fails (e.g. loopback blocked in single-worker environments),
	 * execution falls back to synchronous inline processing.
	 *
	 * @param string      $migration_id
	 * @param string|null $nonce Kept for API compatibility; background auth uses the bg_token transient.
	 */
	public function spawn_migration_background_request( string $migration_id, ?string $nonce ): void {
		// hex-only token: wp_generate_password with special chars can produce
		// %XX sequences that sanitize_text_field strips on the receiver side,
		// causing a token mismatch and a 400 response.
		$bg_token = bin2hex( random_bytes( 16 ) );
		set_transient( 'efs_bg_' . $migration_id, $bg_token, 120 );

		$url = $this->get_spawn_url();

		$skip_ssl_verify = in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			|| (bool) apply_filters( 'etch_fusion_suite_loopback_skip_ssl_verify', false );

		// Fire non-blocking POST. Skip loopback probe: in single-worker setups
		// the probe would block (same process cannot serve itself) and always
		// timeout, forcing a sync fallback even when loopback works.
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 5,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => ! $skip_ssl_verify,
				'body'        => array(
					'action'       => 'efs_run_migration_background',
					'migration_id' => $migration_id,
					'bg_token'     => $bg_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_warning(
				'W013',
				array(
					'message' => 'Background spawn request failed (' . $response->get_error_message() . '). Falling back to synchronous execution.',
					'action'  => 'spawn_migration_background_request',
				)
			);
			// Synchronous fallback: run the migration inline.
			$this->async_runner->run_migration_execution( $migration_id );
		} else {
			// Non-blocking POST accepted — the background process will handle the migration.
			$this->error_handler->debug_log(
				'Background spawn accepted',
				array(
					'migration_id' => $migration_id,
					'spawn_url'    => $url,
				),
				'EFS_SPAWN'
			);
		}
	}
}
