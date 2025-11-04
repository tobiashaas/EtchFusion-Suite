<?php
/**
 * Validation AJAX Handler
 *
 * Handles API key and token validation AJAX requests
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Api\EFS_API_Client;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Validation_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * API client instance
	 *
	 * @var EFS_API_Client
	 */
	private $api_client;

	/**
	 * Constructor
	 *
	 * @param EFS_API_Client $api_client
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( EFS_API_Client $api_client, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->api_client = $api_client;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_validate_migration_token', array( $this, 'validate_migration_key' ) );
	}

	/**
	 * AJAX handler for validating migration key
	 */
	public function validate_migration_key() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute for auth endpoints)
		if ( ! $this->check_rate_limit( 'validation_migration_key', 10, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
					'target_url'    => $this->get_post( 'target_url', '' ),
				),
				array(
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
					'target_url'    => array(
						'type'     => 'url',
						'required' => false,
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$migration_key = $validated['migration_key'];
		$target_url    = $validated['target_url'] ?? '';
		$internal_url  = $target_url ? $this->convert_to_internal_url( $target_url ) : '';

		$result = $this->api_client->validate_migration_key_on_target( $internal_url, $migration_key );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'migration_key_validation_failed';
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			// Log failed token validation
			$this->log_security_event(
				'auth_failure',
				' Migration key validation failed: ' . $result->get_error_message(),
				array(
					'target_url' => $target_url,
					'code'       => $error_code,
				)
			);
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $error_code,
				),
				$status
			);
		} else {
			// Log successful token validation
			$this->log_security_event(
				'auth_success',
				' Migration key validated successfully',
				array(
					'target_url' => ! empty( $target_url ) ? $target_url : ( $result['target_url'] ?? '' ),
				)
			);
			wp_send_json_success(
				array(
					'valid'      => true,
					'target_url' => $result['target_url'] ?? '',
					'expiration' => $result['expires'] ?? null,
					'issued_at'  => $result['payload']['iat'] ?? null,
					'payload'    => $this->mask_sensitive_values( $result['payload'] ?? array() ),
				)
			);
		}
	}
}
