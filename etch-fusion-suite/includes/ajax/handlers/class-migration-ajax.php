<?php
/**
 * Migration AJAX Handler
 *
 * Handles migration workflow AJAX requests.
 *
 * @package Etch_Fusion_Suite
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Controllers\EFS_Migration_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Ajax_Handler extends EFS_Base_Ajax_Handler {
	/**
	 * Migration controller instance.
	 *
	 * @var EFS_Migration_Controller|null
	 */
	private $migration_controller;

	/**
	 * Settings controller instance.
	 *
	 * @var EFS_Settings_Controller|null
	 */
	private $settings_controller;

	/**
	 * Constructor.
	 *
	 * @param EFS_Migration_Controller|null $migration_controller Migration controller.
	 * @param EFS_Settings_Controller|null  $settings_controller  Settings controller.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance.
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance.
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance.
	 */
	public function __construct( $migration_controller = null, $settings_controller = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->migration_controller = $migration_controller;
		$this->settings_controller  = $settings_controller;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks.
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_start_migration', array( $this, 'start_migration' ) );
		add_action( 'wp_ajax_efs_get_migration_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_efs_migrate_batch', array( $this, 'process_batch' ) );
		add_action( 'wp_ajax_efs_resume_migration', array( $this, 'resume_migration' ) );
		add_action( 'wp_ajax_efs_cancel_migration', array( $this, 'cancel_migration' ) );
		add_action( 'wp_ajax_efs_cancel_headless_job', array( $this, 'cancel_headless_job' ) );
		add_action( 'wp_ajax_efs_generate_report', array( $this, 'generate_report' ) );
		add_action( 'wp_ajax_efs_generate_migration_key', array( $this, 'generate_migration_key' ) );
	}

	/**
	 * Start migration.
	 */
	public function start_migration() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_start', 15, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Start migration aborted: migration controller unavailable.' ) ) {
			return;
		}

		$raw_target = $this->get_post( 'target_url', '', 'url' );
		$payload    = array(
			'migration_key'        => $this->get_post( 'migration_key', '', 'raw' ),
			'target_url'           => $raw_target ? $this->convert_to_internal_url( $raw_target ) : '',
			'batch_size'           => $this->get_post( 'batch_size', 50, 'int' ),
			'selected_post_types'  => $this->get_post( 'selected_post_types', array(), 'array' ),
			'post_type_mappings'   => $this->get_post( 'post_type_mappings', array(), 'array' ),
			'include_media'        => $this->get_post( 'include_media', '1', 'text' ),
			'restrict_css_to_used' => $this->get_post( 'restrict_css_to_used', '1', 'text' ),
			'nonce'                => $this->get_post( 'nonce', '', 'raw' ),
			'mode'                 => $this->get_post( 'mode', 'browser', 'text' ),
		);

		$result = $this->migration_controller->start_migration( $payload );
		if ( is_wp_error( $result ) && 'migration_in_progress' === $result->get_error_code() ) {
			$error_data  = $result->get_error_data();
			$error_extra = array(
				'message' => $result->get_error_message(),
				'code'    => 'migration_in_progress',
			);
			if ( is_array( $error_data ) && isset( $error_data['existing_migration_id'] ) ) {
				$error_extra['existing_migration_id'] = sanitize_text_field( $error_data['existing_migration_id'] );
				$error_extra['existing_status']       = sanitize_key( $error_data['status'] ?? '' );
			}
			wp_send_json_error( $error_extra, 409 );
			return;
		}
		$extra_data = is_wp_error( $result ) ? array() : array( 'migration_id' => $result['migrationId'] ?? null );
		$this->send_controller_response( $result, 'migration_start_failed', 'Migration started successfully.', $extra_data );
	}

	/**
	 * Get migration progress.
	 */
	public function get_progress() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_progress', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Get migration progress aborted: migration controller unavailable.' ) ) {
			return;
		}

		$migration_id = $this->get_post( 'migrationId', '', 'text' );
		if ( '' === $migration_id ) {
			$migration_id = $this->get_post( 'migration_id', '', 'text' );
		}

		$payload = array(
			'migrationId' => $migration_id,
		);

		$result = $this->migration_controller->get_progress( $payload );

		$this->send_controller_response( $result, 'migration_progress_failed' );
	}

	/**
	 * Process migration batch.
	 */
	public function process_batch() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_batch', 1200, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Process migration batch aborted: migration controller unavailable.' ) ) {
			return;
		}

		$batch_migration_id = $this->get_post( 'migrationId', '', 'text' );
		if ( '' === $batch_migration_id ) {
			$batch_migration_id = $this->get_post( 'migration_id', '', 'text' );
		}

		$payload = array(
			'migrationId' => $batch_migration_id,
			'batch'       => $this->get_post( 'batch', array(), 'array' ),
			'batch_size'  => $this->get_post( 'batch_size', 10, 'int' ),
		);

		$result = $this->migration_controller->process_batch( $payload );

		$this->send_controller_response( $result, 'migration_batch_failed' );
	}

	/**
	 * Resume JS-driven batch loop after timeout or error.
	 */
	public function resume_migration() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_resume', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Resume migration aborted: migration controller unavailable.' ) ) {
			return;
		}

		$resume_migration_id = $this->get_post( 'migrationId', '', 'text' );
		if ( '' === $resume_migration_id ) {
			$resume_migration_id = $this->get_post( 'migration_id', '', 'text' );
		}

		$payload = array(
			'migrationId' => $resume_migration_id,
		);

		$result = $this->migration_controller->resume_migration( $payload );

		$this->send_controller_response( $result, 'migration_resume_failed' );
	}

	/**
	 * Cancel migration.
	 */
	public function cancel_migration() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_cancel', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Cancel migration aborted: migration controller unavailable.' ) ) {
			return;
		}

		$cancel_migration_id = $this->get_post( 'migrationId', '', 'text' );
		if ( '' === $cancel_migration_id ) {
			$cancel_migration_id = $this->get_post( 'migration_id', '', 'text' );
		}

		$payload = array(
			'migrationId' => $cancel_migration_id,
		);

		$result = $this->migration_controller->cancel_migration( $payload );

		$this->send_controller_response( $result, 'migration_cancel_failed', 'Migration cancelled.', array(), array( 'cancelled' => true ) );
	}

	/**
	 * Cancel headless migration job (Action Scheduler).
	 */
	public function cancel_headless_job(): void {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->check_rate_limit( 'migration_cancel_headless', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}
		$action_scheduler_id = (int) $this->get_post( 'action_scheduler_id', 0, 'int' );
		$migration_id        = $this->get_post( 'migration_id', '', 'text' );
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( 'efs_run_headless_migration', array( 'migration_id' => $migration_id ), 'efs-migration' );
		} elseif ( class_exists( 'EFS_Vendor_ActionScheduler' ) ) {
			EFS_Vendor_ActionScheduler::store()->cancel_action( $action_scheduler_id );
		}
		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * Generate migration report.
	 */
	public function generate_report() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_report', 5, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_migration_controller( 'Generate migration report aborted: migration controller unavailable.' ) ) {
			return;
		}

		$result = $this->migration_controller->generate_report();

		$this->send_controller_response( $result, 'migration_report_failed' );
	}

	/**
	 * Generate migration key.
	 */
	public function generate_migration_key() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_key', 5, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->require_settings_controller() ) {
			return;
		}

		$payload = array(
			'target_url' => $this->get_post( 'target_url', '', 'text' ),
		);

		$result = $this->settings_controller->generate_migration_key( $payload );

		$this->send_controller_response( $result, 'migration_key_failed', 'Migration key generated.' );
	}

	/**
	 * Guard: ensure migration controller is available before proceeding.
	 *
	 * @param string $log_message Optional audit log message override.
	 * @return bool True when controller is available, false after sending a 503 JSON error.
	 */
	protected function require_migration_controller( $log_message = 'Migration controller unavailable.' ) {
		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', $log_message );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return false;
		}
		return true;
	}

	/**
	 * Guard: ensure settings controller is available before proceeding.
	 *
	 * @param string $log_message Optional audit log message override.
	 * @return bool True when controller is available, false after sending a 503 JSON error.
	 */
	protected function require_settings_controller( $log_message = 'Migration key generation aborted: settings controller unavailable.' ) {
		if ( ! $this->settings_controller instanceof EFS_Settings_Controller ) {
			$this->log_security_event( 'ajax_action', $log_message );
			wp_send_json_error(
				array(
					'message' => __( 'Settings service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return false;
		}
		return true;
	}

	/**
	 * Send WP-style JSON response based on controller result.
	 *
	 * @param mixed       $result           Controller result.
	 * @param string      $default_code     Default error code.
	 * @param string|null $success_message  Success fallback message.
	 * @param array       $log_details      Additional details for success logging.
	 * @param array       $success_payload  Extra payload to merge into success response.
	 */
	private function send_controller_response( $result, $default_code, $success_message = null, array $log_details = array(), array $success_payload = array() ) {
		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : $default_code;
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			$this->log_security_event(
				'ajax_action',
				sprintf( 'Migration handler failure [%s]: %s', $error_code, $result->get_error_message() ),
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

		$payload = is_array( $result ) ? $result : array();
		if ( $success_message && ! isset( $payload['message'] ) ) {
			$payload['message'] = $success_message;
		}

		if ( ! empty( $success_payload ) ) {
			$payload = array_merge( $payload, $success_payload );
		}

		if ( ! empty( $log_details ) ) {
			$this->log_security_event( 'ajax_action', 'Migration handler succeeded.', $log_details );
		}

		wp_send_json_success( $payload );
	}
}
