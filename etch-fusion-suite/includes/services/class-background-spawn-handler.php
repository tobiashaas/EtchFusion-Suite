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
		// Use hex token only: wp_generate_password with special chars can produce %XX sequences
		// that sanitize_text_field strips on the receiving end, causing token mismatch → 400.
		$bg_token = bin2hex( random_bytes( 16 ) );
		set_transient( 'efs_bg_' . $migration_id, $bg_token, 120 );

		$url  = $this->get_spawn_url();
		$body = array(
			'action'       => 'efs_run_migration_background',
			'migration_id' => $migration_id,
			'bg_token'     => $bg_token,
		);

		$skip_ssl_verify = in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
			|| (bool) apply_filters( 'etch_fusion_suite_loopback_skip_ssl_verify', false );

		$probe = wp_remote_head(
			$url,
			array(
				'timeout'     => 1,
				'blocking'    => true,
				'redirection' => 0,
				'sslverify'   => ! $skip_ssl_verify,
			)
		);

		if ( is_wp_error( $probe ) ) {
			$this->error_handler->log_warning(
				'W013',
				array(
					'message' => 'Loopback probe failed (' . $probe->get_error_message() . '). Falling back to synchronous execution.',
					'url'     => $url,
					'action'  => 'spawn_migration_background_request',
				)
			);
			$this->async_runner->run_migration_execution( $migration_id );
			return;
		}

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
			$this->error_handler->log_warning(
				'W013',
				array(
					'message' => 'Background spawn request failed (' . $response->get_error_message() . '). Falling back to synchronous execution.',
					'action'  => 'spawn_migration_background_request',
				)
			);
			$this->async_runner->run_migration_execution( $migration_id );
		}
	}
}
