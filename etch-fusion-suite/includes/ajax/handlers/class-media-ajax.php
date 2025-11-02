<?php
/**
 * Media AJAX Handler
 *
 * Handles media migration AJAX requests
 *
 * @package Etch_Fusion_Suite
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Services\EFS_Media_Service;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Media_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * Media service instance
	 *
	 * @var mixed
	 */
	private $media_service;

	/**
	 * Constructor
	 *
	 * @param mixed $media_service Media service instance.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $media_service = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		if ( $media_service ) {
			$this->media_service = $media_service;
		} elseif ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$this->media_service = etch_fusion_suite_container()->get( 'media_service' );
			} catch ( \Exception $exception ) {
				$this->media_service = null;
			}
		}

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_migrate_media', array( $this, 'migrate_media' ) );
	}

	/**
	 * AJAX handler to migrate media files
	 */
	public function migrate_media() {
		// Verify request (nonce + capability)
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (30 requests per minute)
		if ( ! $this->check_rate_limit( 'media_migrate', 30, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'target_url' => $this->get_post( 'target_url', '' ),
					'api_key'    => $this->get_post( 'api_key', '' ),
				),
				array(
					'target_url' => array(
						'type'     => 'url',
						'required' => true,
					),
					'api_key'    => array(
						'type'     => 'api_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			$this->log( '❌ Media Migration: Validation failed: ' . $e->getMessage() );
			return; // Error already sent by validate_input
		}

		$target_url = $validated['target_url'];
		$api_key    = $validated['api_key'];

		// Convert to internal URL
		$internal_url = $this->convert_to_internal_url( $target_url );

		// Save settings temporarily
		update_option(
			'efs_settings',
			array(
				'target_url' => $internal_url,
				'api_key'    => $api_key,
			),
			false
		);

		// Migrate media
		try {
			if ( ! $this->media_service || ! $this->media_service instanceof EFS_Media_Service ) {
				$this->log_security_event( 'ajax_action', 'Media migration aborted: media service unavailable.' );
				wp_send_json_error(
					array(
						'message' => __( 'Media service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
						'code'    => 'service_unavailable',
					),
					503
				);
				return;
			}

			$result = $this->media_service->migrate_media( $internal_url, $api_key );

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'media_migration_failed';
				$status     = 400;
				$error_data = $result->get_error_data();
				if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
					$status = (int) $error_data['status'];
				}

				$this->log_security_event(
					'ajax_action',
					'Media migration failed: ' . $result->get_error_message(),
					array(
						'code'    => $error_code,
						'post_id' => $result->get_error_data()['post_id'] ?? null,
					)
				);

				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'code'    => $error_code,
					),
					$status
				);
				return;
			}

			$summary = $result['summary'] ?? array();

			$this->log_security_event(
				'ajax_action',
				'ਕਾਰ Media migrated successfully',
				array(
					'migrated' => $summary['migrated_media'] ?? 0,
					'failed'   => $summary['failed_media'] ?? 0,
				)
			);

			wp_send_json_success(
				array(
					'message'   => $result['message'] ?? __( 'Media migrated successfully.', 'etch-fusion-suite' ),
					'summary'   => $summary,
					'timestamp' => current_time( 'mysql' ),
				)
			);
		} catch ( \Exception $e ) {
			$this->log_security_event( 'ajax_action', 'Media migration exception: ' . $e->getMessage(), array(), 'high' );
			wp_send_json_error(
				array(
					'message' => sprintf( 'Exception: %s', $e->getMessage() ),
					'code'    => 'media_migration_exception',
				),
				500
			);
		}
	}

}
