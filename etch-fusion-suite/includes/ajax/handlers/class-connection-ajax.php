<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Controllers\EFS_Settings_Controller;
use Bricks2Etch\Repositories\Interfaces\Settings_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Connection_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * API client instance
	 *
	 * @var mixed
	 */
	private $api_client;

	/**
	 * Constructor
	 *
	 * @param mixed $api_client API client instance.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $api_client = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->api_client = $api_client;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	protected function register_hooks() {
		add_action( 'wp_ajax_efs_test_export_connection', array( $this, 'test_export_connection' ) );
		add_action( 'wp_ajax_efs_test_import_connection', array( $this, 'test_import_connection' ) );
		add_action( 'wp_ajax_efs_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_efs_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_efs_save_feature_flags', array( $this, 'save_feature_flags' ) );
	}

	/**
	 * Save connection settings via AJAX.
	 */
	public function save_settings() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'connection_save_settings', 5, 60 ) ) {
			return;
		}

		$raw_target_url      = $this->get_post( 'target_url', '', 'url' );
		$internal_target_url = $this->convert_to_internal_url( $raw_target_url );

		try {
			$validated = $this->validate_input(
				array(
					'target_url'    => $internal_target_url,
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
				),
				array(
					'target_url'    => array(
						'type'     => 'url',
						'required' => true,
					),
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			$this->log_security_event(
				'ajax_action',
				'Connection settings validation failed.',
				array( 'error' => $e->getMessage() ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'invalid_settings_payload',
					'message' => __( 'Invalid settings data provided. Please check the form and try again.', 'etch-fusion-suite' ),
				),
				400
			);
			return;
		}

		$settings_controller = $this->resolve_settings_controller();
		if ( ! $settings_controller ) {
			$this->log_security_event(
				'ajax_action',
				'Connection settings save failed: settings controller unavailable.',
				array( 'action' => 'efs_save_settings' ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'service_unavailable',
					'message' => __( 'Settings service unavailable. Please ensure the plugin is fully initialised.', 'etch-fusion-suite' ),
				),
				503
			);
			return;
		}

		/** @var array|\WP_Error $result */
		$result = $settings_controller->save_settings( $validated );

		if ( is_wp_error( $result ) ) {
			$this->log_security_event(
				'ajax_action',
				'Connection settings save failed.',
				array(
					'code'    => $result->get_error_code(),
					'context' => $this->mask_sensitive_values( $validated ),
				)
			);
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'settings_save_failed',
					'message' => $result->get_error_message(),
				),
				$this->determine_error_status( $result )
			);
			return;
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : __( 'Settings saved successfully.', 'etch-fusion-suite' );
		$data    = wp_parse_args(
			$result,
			array(
				'message'  => $message,
				'settings' => array(),
			)
		);

		$this->log_security_event(
			'ajax_action',
			'Connection settings saved successfully.',
			array( 'action' => 'efs_save_settings' )
		);

		wp_send_json_success( $data );
	}

	/**
	 * Persist feature flag settings via AJAX.
	 */
	public function save_feature_flags() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'feature_flags_save', 5, 60 ) ) {
			return;
		}

		$raw_flags = $this->get_post( 'feature_flags', array(), 'array' );
		$raw_flags = is_array( $raw_flags ) ? $raw_flags : array();

		$allowed_features = $this->get_allowed_feature_flags();

		if ( empty( $allowed_features ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'No feature flags are currently configurable.', 'etch-fusion-suite' ),
				)
			);
			return;
		}

		$invalid_flags   = array();
		$validated_flags = array();

		foreach ( $raw_flags as $flag_name => $value ) {
			$sanitized_name = sanitize_key( (string) $flag_name );

			if ( ! $this->validate_feature_flag_name( $sanitized_name ) ) {
				$invalid_flags[] = $flag_name;
				continue;
			}

			$sanitized_value                    = \rest_sanitize_boolean( $value );
			$validated_flags[ $sanitized_name ] = (bool) $sanitized_value;
		}

		if ( ! empty( $invalid_flags ) ) {
			$this->log_security_event(
				'ajax_action',
				'Feature flag save rejected due to invalid flag names.',
				array( 'invalid_flags' => $this->mask_sensitive_values( $invalid_flags ) ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'invalid_feature_flags',
					'message' => __( 'One or more feature flags are not recognized.', 'etch-fusion-suite' ),
					'details' => array( 'invalid_flags' => array_map( 'sanitize_text_field', $invalid_flags ) ),
				),
				400
			);
			return;
		}

		foreach ( $allowed_features as $flag_name ) {
			if ( ! isset( $validated_flags[ $flag_name ] ) ) {
				$validated_flags[ $flag_name ] = false;
			}
		}

		$repository = $this->resolve_settings_repository();

		if ( ! $repository ) {
			$this->log_security_event(
				'ajax_action',
				'Feature flag save failed: settings repository unavailable.',
				array( 'action' => 'efs_save_feature_flags' ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'service_unavailable',
					'message' => __( 'Feature settings service unavailable. Please reload the page and try again.', 'etch-fusion-suite' ),
				),
				503
			);
			return;
		}

		$save_result = $repository->save_feature_flags( $validated_flags );

		if ( ! $save_result ) {
			$this->log_security_event(
				'ajax_action',
				'Feature flag save failed.',
				array(
					'action' => 'efs_save_feature_flags',
					'flags'  => $this->mask_sensitive_values( $validated_flags ),
				),
				'medium'
			);
			wp_send_json_error(
				array(
					'code'    => 'feature_flags_save_failed',
					'message' => __( 'Failed to save feature flags. Please try again.', 'etch-fusion-suite' ),
				),
				500
			);
			return;
		}

		$this->log_security_event(
			'ajax_action',
			'Feature flags updated successfully.',
			array(
				'action' => 'efs_save_feature_flags',
				'flags'  => $this->mask_sensitive_values( $validated_flags ),
			),
			'low'
		);

		wp_send_json_success(
			array(
				'message' => __( 'Feature flags saved successfully.', 'etch-fusion-suite' ),
			)
		);
	}

	/**
	 * Get the list of allowed feature flags.
	 *
	 * @return array<string>
	 */
	private function get_allowed_feature_flags(): array {
		$flags = apply_filters( 'etch_fusion_suite_allowed_feature_flags', array() );
		$flags = apply_filters_deprecated(
			'efs_allowed_feature_flags',
			array( $flags ),
			'0.11.27',
			'etch_fusion_suite_allowed_feature_flags'
		);

		return is_array( $flags ) ? array_values( array_unique( $flags ) ) : array();
	}

	/**
	 * Validate a feature flag identifier against the whitelist.
	 *
	 * @param string $flag_name Feature flag identifier.
	 * @return bool
	 */
	private function validate_feature_flag_name( string $flag_name ): bool {
		return in_array( $flag_name, $this->get_allowed_feature_flags(), true );
	}

	/**
	 * Resolve the settings repository instance.
	 *
	 * @return Settings_Repository_Interface|null
	 */
	private function resolve_settings_repository() {
		if ( ! function_exists( 'etch_fusion_suite_container' ) ) {
			return null;
		}

		try {
			$container = etch_fusion_suite_container();

			if ( $container && method_exists( $container, 'has' ) && $container->has( 'settings_repository' ) ) {
				$repository = $container->get( 'settings_repository' );

				if ( $repository instanceof Settings_Repository_Interface ) {
					return $repository;
				}
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}


	/**
	 * Test connection via AJAX using stored settings payload.
	 */
	public function test_connection() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'connection_test_settings', 10, 60 ) ) {
			return;
		}

		$raw_target_url      = $this->get_post( 'target_url', '', 'url' );
		$internal_target_url = $this->convert_to_internal_url( $raw_target_url );

		try {
			$validated = $this->validate_input(
				array(
					'target_url'    => $internal_target_url,
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
				),
				array(
					'target_url'    => array(
						'type'     => 'url',
						'required' => true,
					),
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			$this->log_security_event(
				'ajax_action',
				'Connection test validation failed.',
				array( 'error' => $e->getMessage() ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'invalid_connection_payload',
					'message' => __( 'Invalid connection details provided. Please verify the fields and try again.', 'etch-fusion-suite' ),
				),
				400
			);
			return;
		}

		$client = $this->api_client;
		if ( ! $client || ! $client instanceof EFS_API_Client ) {
			$this->log_security_event(
				'ajax_action',
				'Connection test failed: API client unavailable.',
				array( 'action' => 'efs_test_connection' ),
				'high'
			);
			wp_send_json_error(
				array(
					'code'    => 'service_unavailable',
					'message' => __( 'Connection service unavailable. Please ensure the plugin is fully initialised.', 'etch-fusion-suite' ),
				),
				503
			);
			return;
		}

		$migration_key = $validated['migration_key'];
		$result        = $client->validate_migration_key_on_target( $internal_target_url, $migration_key );

		if ( is_wp_error( $result ) ) {
			$this->log_security_event(
				'ajax_action',
				'Connection test failed: target validation rejected migration key.',
				array(
					'code'    => $result->get_error_code(),
					'context' => $this->mask_sensitive_values( $validated ),
				)
			);
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'connection_failed',
					'message' => $result->get_error_message(),
				),
				$this->determine_error_status( $result )
			);
			return;
		}

		$this->log_security_event(
			'ajax_action',
			'Connection test succeeded: target validated migration key.',
			array( 'action' => 'efs_test_connection' )
		);

		wp_send_json_success(
			array(
				'valid'      => true,
				'payload'    => $this->mask_sensitive_values( $result['payload'] ?? array() ),
				'target_url' => $result['target_domain'] ?? $result['target_url'] ?? $internal_target_url,
				'expiration' => $result['expires'] ?? null,
				'issued_at'  => $result['payload']['iat'] ?? null,
				'jwt_target' => $result['payload']['target_url'] ?? null,
			)
		);
	}

	/**
	 * Resolve settings controller from container.
	 *
	 * @return EFS_Settings_Controller|null
	 */
	private function resolve_settings_controller() {
		if ( ! function_exists( 'etch_fusion_suite_container' ) ) {
			return null;
		}

		try {
			$container = etch_fusion_suite_container();
			if ( $container && $container->has( 'settings_controller' ) ) {
				$controller = $container->get( 'settings_controller' );
				if ( $controller instanceof EFS_Settings_Controller ) {
					return $controller;
				}
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Determine HTTP status code from WP_Error.
	 *
	 * @param \WP_Error $error Error instance.
	 * @return int
	 */
	private function determine_error_status( $error ) {
		$status = 400;
		if ( $error instanceof \WP_Error ) {
			$additional_data = $error->get_error_data();
			if ( is_array( $additional_data ) && isset( $additional_data['status'] ) ) {
				$status = (int) $additional_data['status'];
			}
		}
		return $status > 0 ? $status : 400;
	}

	/**
	 * Normalize API key by removing whitespace characters.
	 *
	 * @param string $api_key Raw API key input.
	 * @return string
	 */
	public function test_export_connection() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute)
		if ( ! $this->check_rate_limit( 'connection_test_export', 10, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'target_url'    => $this->get_post( 'target_url', '' ),
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
				),
				array(
					'target_url'    => array(
						'type'     => 'url',
						'required' => true,
					),
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$target_url    = $validated['target_url'];
		$migration_key = $validated['migration_key'];

		$client = $this->api_client;
		if ( ! $client || ! $client instanceof EFS_API_Client ) {
			$this->log_security_event( 'connection_unavailable', 'Export connection test aborted: API client unavailable.', array( 'target_url' => $target_url ), 'high' );
			wp_send_json_error(
				array(
					'message' => __( 'Connection service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$result = $client->validate_migration_key_on_target( $this->convert_to_internal_url( $target_url ), $migration_key );

		if ( isset( $result['valid'] ) && $result['valid'] ) {
			// Log successful connection test
			$this->log_security_event(
				'ajax_action',
				'Export connection test successful',
				array(
					'target_url' => $target_url,
				)
			);
			wp_send_json_success(
				array(
					'message' => __( 'Connection successful.', 'etch-fusion-suite' ),
					'plugins' => $result['plugins'] ?? array(),
				)
			);
		}

		$error_messages = array();
		if ( isset( $result['errors'] ) && is_array( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				$error_messages[] = is_scalar( $error ) ? (string) $error : wp_json_encode( $this->mask_sensitive_values( $error ) );
			}
		}
		$errors     = $error_messages ? implode( ', ', $error_messages ) : __( 'Connection failed.', 'etch-fusion-suite' );
		$error_code = $result['code'] ?? 'connection_failed';
		$status     = isset( $result['status'] ) ? (int) $result['status'] : 400;
		$this->log_security_event(
			'ajax_action',
			'Export connection test failed: ' . $errors,
			array(
				'target_url' => $target_url,
				'code'       => $error_code,
			)
		);
		wp_send_json_error(
			array(
				'message' => $errors,
				'code'    => sanitize_key( (string) $error_code ),
			),
			$status
		);
	}

	public function test_import_connection() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute)
		if ( ! $this->check_rate_limit( 'connection_test_import', 10, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'source_url'    => $this->get_post( 'source_url', '' ),
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
				),
				array(
					'source_url'    => array(
						'type'     => 'url',
						'required' => true,
					),
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$source_url    = $validated['source_url'];
		$migration_key = $validated['migration_key'];

		$client = $this->api_client;
		if ( ! $client || ! $client instanceof EFS_API_Client ) {
			$this->log_security_event( 'connection_unavailable', 'Import connection test aborted: API client unavailable.', array( 'source_url' => $source_url ), 'high' );
			wp_send_json_error(
				array(
					'message' => __( 'Connection service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$result = $client->validate_migration_key_on_target( $this->convert_to_internal_url( $source_url ), $migration_key );

		if ( isset( $result['valid'] ) && $result['valid'] ) {
			// Log successful connection test
			$this->log_security_event(
				'ajax_action',
				'Import connection test successful',
				array(
					'source_url' => $source_url,
				)
			);
			wp_send_json_success(
				array(
					'message' => __( 'Connection successful.', 'etch-fusion-suite' ),
					'plugins' => $result['plugins'] ?? array(),
				)
			);
		}

		$error_messages = array();
		if ( isset( $result['errors'] ) && is_array( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				$error_messages[] = is_scalar( $error ) ? (string) $error : wp_json_encode( $this->mask_sensitive_values( $error ) );
			}
		}
		$errors     = $error_messages ? implode( ', ', $error_messages ) : __( 'Connection failed.', 'etch-fusion-suite' );
		$error_code = $result['code'] ?? 'connection_failed';
		$status     = isset( $result['status'] ) ? (int) $result['status'] : 400;
		$this->log_security_event(
			'ajax_action',
			'Import connection test failed: ' . $errors,
			array(
				'source_url' => $source_url,
				'code'       => $error_code,
			)
		);
		wp_send_json_error(
			array(
				'message' => $errors,
				'code'    => sanitize_key( (string) $error_code ),
			),
			$status
		);
	}
}
