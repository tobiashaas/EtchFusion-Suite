<?php
/**
 * CSS AJAX Handler
 *
 * Handles CSS migration AJAX requests
 *
 * @package Etch_Fusion_Suite
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Services\EFS_CSS_Service;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_CSS_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * CSS service instance
	 *
	 * @var mixed
	 */
	private $css_service;

	/**
	 * Constructor
	 *
	 * @param mixed $css_service CSS service instance.
	 * @param \Bricks2Etch\Security\B2E_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\B2E_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\B2E_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $css_service = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		if ( $css_service ) {
			$this->css_service = $css_service;
		} elseif ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$this->css_service = etch_fusion_suite_container()->get( 'css_service' );
			} catch ( \Exception $exception ) {
				$this->css_service = null;
			}
		}

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_migrate_css', array( $this, 'convert_css' ) );
		add_action( 'wp_ajax_efs_convert_css', array( $this, 'convert_css' ) );
		add_action( 'wp_ajax_efs_get_global_styles', array( $this, 'get_global_styles' ) );
	}

	/**
	 * AJAX handler to migrate CSS
	 */
	public function convert_css() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (30 requests per minute)
		if ( ! $this->check_rate_limit( 'css_convert', 30, 60 ) ) {
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
			$this->log( '❌ CSS Migration: Validation failed: ' . $e->getMessage() );
			return; // Error already sent by validate_input
		}

		$target_url = $validated['target_url'];
		$api_key    = $validated['api_key'];

		// Convert localhost:8081 to efs-etch for Docker internal communication
		$internal_url = $this->convert_to_internal_url( $target_url );

		// Save settings temporarily with internal URL
		update_option(
			'efs_settings',
			array(
				'target_url' => $internal_url,
				'api_key'    => $api_key,
			),
			false
		);

		// Migrate CSS
		try {
			if ( ! $this->css_service || ! $this->css_service instanceof EFS_CSS_Service ) {
				$this->log_security_event( 'ajax_action', 'CSS migration aborted: CSS service unavailable.' );
				wp_send_json_error(
					array(
						'message' => __( 'CSS service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
						'code'    => 'service_unavailable',
					),
					503
				);
				return;
			}

			$result = $this->css_service->migrate_css_classes( $internal_url, $api_key );

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'css_migration_failed';
				$status     = 400;
				$error_data = $result->get_error_data();
				if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
					$status = (int) $error_data['status'];
				}

				$this->log_security_event(
					'ajax_action',
					'CSS migration failed: ' . $result->get_error_message(),
					array( 'code' => $error_code )
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

			$api_response = isset( $result['response'] ) && is_array( $result['response'] ) ? $result['response'] : array();
			$styles_count = $result['migrated'] ?? ( isset( $api_response['style_map'] ) ? count( (array) $api_response['style_map'] ) : 0 );

			if ( isset( $api_response['style_map'] ) && is_array( $api_response['style_map'] ) ) {
				update_option( 'efs_style_map', $api_response['style_map'] );
			}

			$this->log_security_event(
				'ajax_action',
				'CSS migrated successfully',
				array(
					'styles_count' => $styles_count,
				)
			);

			$message = $result['message'] ?? __( 'CSS migrated successfully.', 'etch-fusion-suite' );
			wp_send_json_success(
				array(
					'message'      => $message,
					'styles_count' => $styles_count,
					'api_response' => $api_response,
				)
			);
		} catch ( \Exception $e ) {
			// Log CSS migration failure
			$this->log_security_event( 'ajax_action', 'CSS migration exception: ' . $e->getMessage(), array(), 'high' );

			wp_send_json_error(
				array(
					'message' => sprintf( 'Exception: %s', $e->getMessage() ),
					'code'    => 'css_migration_exception',
				),
				500
			);
		}
	}

	public function get_global_styles() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'css_global_styles', 60, 60 ) ) {
			return;
		}

		$styles = $this->css_service->get_global_styles();

		if ( is_wp_error( $styles ) ) {
			$error_code = $styles->get_error_code() ? sanitize_key( $styles->get_error_code() ) : 'css_styles_unavailable';
			$status     = 400;
			$error_data = $styles->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			$this->log_security_event( 'ajax_action', 'Failed to retrieve global styles: ' . $styles->get_error_message(), array( 'code' => $error_code ) );

			wp_send_json_error(
				array(
					'message' => $styles->get_error_message(),
					'code'    => $error_code,
				),
				$status
			);
		} else {
			wp_send_json_success( $styles );
		}
	}

	/**
	 * Convert localhost URL to internal Docker URL
	 *
	 * @param string $url
	 * @return string
	 */
	private function convert_to_internal_url( $url ) {
		if ( strpos( $url, 'localhost:8081' ) !== false ) {
			return str_replace( 'localhost:8081', 'efs-etch', $url );
		}
		return $url;
	}
}
