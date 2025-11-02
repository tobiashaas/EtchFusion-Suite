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
		add_action( 'wp_ajax_efs_validate_api_key', array( $this, 'validate_api_key' ) );
		add_action( 'wp_ajax_efs_validate_migration_token', array( $this, 'validate_migration_token' ) );
	}

	/**
	 * AJAX handler for validating API key
	 */
	public function validate_api_key() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute for auth endpoints)
		if ( ! $this->check_rate_limit( 'validation_api_key', 10, 60 ) ) {
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
			return; // Error already sent by validate_input
		}

		$target_url = $validated['target_url'];
		$api_key    = $validated['api_key'];

		// Convert to internal URL
		$internal_url = $this->convert_to_internal_url( $target_url );

		// Validate API key via API client
		$result = $this->api_client->validate_api_key( $internal_url, $api_key );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'api_key_validation_failed';
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			// Log failed authentication
			$this->log_security_event(
				'auth_failure',
				'API key validation failed: ' . $result->get_error_message(),
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
			// Log successful authentication
			$this->log_security_event(
				'auth_success',
				'API key validated successfully',
				array(
					'target_url' => $target_url,
				)
			);
			wp_send_json_success( $result );
		}
	}

	/**
	 * AJAX handler for validating migration token
	 */
	public function validate_migration_token() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute for auth endpoints)
		if ( ! $this->check_rate_limit( 'validation_migration_token', 10, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'target_url' => $this->get_post( 'target_url', '' ),
					'token'      => $this->get_post( 'token', '' ),
					'expires'    => $this->get_post( 'expires', 0 ),
				),
				array(
					'target_url' => array(
						'type'     => 'url',
						'required' => true,
					),
					'token'      => array(
						'type'     => 'token',
						'required' => true,
					),
					'expires'    => array(
						'type'     => 'integer',
						'required' => true,
						'min'      => time(),
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$target_url = $validated['target_url'];
		$token      = $validated['token'];
		$expires    = $validated['expires'];

		// Convert to internal URL
		$internal_url = $this->convert_to_internal_url( $target_url );

		// Validate migration token on target site
		$result = $this->api_client->validate_migration_token( $internal_url, $token, $expires );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'token_validation_failed';
			$status     = 401;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			// Log failed token validation
			$this->log_security_event(
				'auth_failure',
				' Migration token validation failed: ' . $result->get_error_message(),
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
				'Migration token validated successfully',
				array(
					'target_url' => $target_url,
				)
			);
			// Token is valid, return success with API key
			wp_send_json_success( $result );
		}
	}
}
