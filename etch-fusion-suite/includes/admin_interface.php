<?php
/**
 * Admin Interface for Etch Fusion Suite
 *
 * Handles the WordPress admin menu and dashboard
 */

namespace Bricks2Etch\Admin;

use Bricks2Etch\Controllers\EFS_Dashboard_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;
use Bricks2Etch\Controllers\EFS_Migration_Controller;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Admin_Interface {
	private $dashboard_controller;
	private $settings_controller;
	private $migration_controller;

	public function __construct(
		EFS_Dashboard_Controller $dashboard_controller,
		EFS_Settings_Controller $settings_controller,
		EFS_Migration_Controller $migration_controller,
		$register_menu = true
	) {
		$this->dashboard_controller = $dashboard_controller;
		$this->settings_controller  = $settings_controller;
		$this->migration_controller = $migration_controller;

		if ( $register_menu ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_ajax_efs_save_settings', array( $this, 'delegate_settings_request' ) );
		add_action( 'wp_ajax_efs_test_connection', array( $this, 'delegate_validation_request' ) );
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'Etch Fusion', 'etch-fusion-suite' ),
			__( 'Etch Fusion', 'etch-fusion-suite' ),
			'manage_options',
			'etch-fusion-suite',
			array( $this, 'render_dashboard' ),
			'dashicons-migrate',
			30
		);
	}

	public function render_dashboard() {
		$this->dashboard_controller->render();
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, 'etch-fusion-suite' ) === false ) {
			return;
		}

		// Enqueue admin CSS
		$css_path = ETCH_FUSION_SUITE_DIR . 'assets/css/admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'efs-admin-css',
				ETCH_FUSION_SUITE_URL . 'assets/css/admin.css',
				array(),
				ETCH_FUSION_SUITE_VERSION
			);
		}

		// Enqueue admin JavaScript (ES6 module)
		$js_path = ETCH_FUSION_SUITE_DIR . 'assets/js/admin/main.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'efs-admin-main',
				ETCH_FUSION_SUITE_URL . 'assets/js/admin/main.js',
				array(),
				ETCH_FUSION_SUITE_VERSION,
				true
			);

			wp_script_add_data( 'efs-admin-main', 'type', 'module' );

			// Localize script data
			$context            = $this->dashboard_controller->get_dashboard_context();
			$context['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			$context['nonce']   = wp_create_nonce( 'efs_nonce' );

			wp_localize_script( 'efs-admin-main', 'efsData', $context );
		} else {
			// Log missing asset for debugging
			error_log( sprintf( '[EFS] Admin script not found: %s', $js_path ) );
		}
	}

	public function delegate_settings_request() {
		$this->get_request_payload();
		$handler = $this->resolve_ajax_handler( 'validation_ajax' );
		if ( $handler ) {
			$handler->validate_api_key();
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Settings handler unavailable.', 'etch-fusion-suite' ),
				'code'    => 'handler_unavailable',
			),
			503
		);
	}

	public function delegate_validation_request() {
		$this->get_request_payload();
		$handler = $this->resolve_ajax_handler( 'validation_ajax' );
		if ( $handler ) {
			$handler->validate_api_key();
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Validation handler unavailable.', 'etch-fusion-suite' ),
				'code'    => 'handler_unavailable',
			),
			503
		);
	}

	/**
	 * Retrieve sanitized POST payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_request_payload() {
		$nonce_valid = check_ajax_referer( 'efs_nonce', 'nonce', false );

		if ( false === $nonce_valid ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed. Please refresh and try again.', 'etch-fusion-suite' ),
					'code'    => 'invalid_nonce',
				),
				403
			);
		}
		if ( empty( $_POST ) || ! is_array( $_POST ) ) {
			return array();
		}

		$payload = $this->sanitize_payload_recursively( wp_unslash( $_POST ) );

		if ( isset( $payload['nonce'] ) ) {
			unset( $payload['nonce'] );
		}

		return $payload;
	}

	/**
	 * Recursively sanitize payload values.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private function sanitize_payload_recursively( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->sanitize_payload_recursively( $item );
			}

			return $value;
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return $value;
	}

	private function resolve_ajax_handler( $service_id ) {
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$container = etch_fusion_suite_container();
				if ( $container->has( $service_id ) ) {
					return $container->get( $service_id );
				}
			} catch ( \Exception $exception ) {
				error_log( sprintf( '[EFS] Failed to resolve AJAX handler "%s": %s', $service_id, $exception->getMessage() ) );
			}
		}

		return null;
	}
}
