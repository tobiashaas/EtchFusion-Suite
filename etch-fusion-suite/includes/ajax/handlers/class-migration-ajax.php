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
use Bricks2Etch\Services\EFS_Migration_Job_Runner;

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
	 * Job runner instance.
	 *
	 * @var EFS_Migration_Job_Runner|null
	 */
	private $migration_job_runner;

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
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$container = etch_fusion_suite_container();
				if ( $container->has( 'migration_job_runner' ) ) {
					$this->migration_job_runner = $container->get( 'migration_job_runner' );
				}
			} catch ( \Exception $exception ) {
				$this->migration_job_runner = null;
			}
		}
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks.
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_start_migration', array( $this, 'start_migration' ) );
		add_action( 'wp_ajax_efs_get_migration_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_efs_process_job_batch', array( $this, 'process_migration_job_batch' ) );
		add_action( 'wp_ajax_efs_cancel_migration', array( $this, 'cancel_migration' ) );
		add_action( 'wp_ajax_efs_pause_migration', array( $this, 'pause_migration' ) );
		add_action( 'wp_ajax_efs_resume_migration', array( $this, 'resume_migration' ) );
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

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration start aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$raw_target = $this->get_post( 'target_url', '', 'url' );
		$include_media = $this->get_post( 'include_media', null, 'bool' );
		if ( null === $include_media ) {
			$include_media = true;
		}

		$payload    = array(
			'migration_key'       => $this->get_post( 'migration_key', '', 'raw' ),
			'target_url'          => $raw_target ? $this->convert_to_internal_url( $raw_target ) : '',
			'batch_size'          => $this->get_post( 'batch_size', 50, 'int' ),
			'selected_post_types' => $this->get_post( 'selected_post_types', array(), 'array' ),
			'post_type_mappings'  => $this->get_post( 'post_type_mappings', array(), 'array' ),
			'include_media'       => $include_media,
			'config'              => $this->get_post( 'config', array(), 'array' ),
		);

		$result = $this->migration_controller->start_migration( $payload );

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

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration progress aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$payload = array(
			'migrationId' => $this->get_post( 'migration_id', '', 'text' ),
		);

		$result = $this->migration_controller->get_progress( $payload );

		$this->send_controller_response( $result, 'migration_progress_failed' );
	}

	/**
	 * Process migration batch.
	 */
	public function process_migration_job_batch() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_batch', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->migration_job_runner instanceof EFS_Migration_Job_Runner ) {
			$this->log_security_event( 'ajax_action', 'Migration job batch aborted: job runner unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration job runner unavailable.', 'etch-fusion-suite' ),
					'code'    => 'job_runner_unavailable',
				),
				503
			);
			return;
		}

		$job_id = $this->get_post( 'job_id', '', 'text' );
		if ( empty( $job_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Job ID is required.', 'etch-fusion-suite' ),
					'code'    => 'missing_job_id',
				),
				400
			);
			return;
		}

		$result = $this->migration_job_runner->execute_next_batch( $job_id );

		$this->send_controller_response( $result, 'migration_job_batch_failed' );
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

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration cancel aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$payload = array(
			'migrationId' => $this->get_post( 'migration_id', '', 'text' ),
			'job_id'      => $this->get_post( 'job_id', '', 'text' ),
		);

		$result = $this->migration_controller->cancel_migration( $payload );

		$this->send_controller_response( $result, 'migration_cancel_failed', 'Migration cancelled.', array(), array( 'cancelled' => true ) );
	}

	/**
	 * Pause migration.
	 */
	public function pause_migration() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_pause', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration pause aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$payload = array(
			'migrationId' => $this->get_post( 'migration_id', '', 'text' ),
			'job_id'      => $this->get_post( 'job_id', '', 'text' ),
		);

		$result = $this->migration_controller->pause_migration( $payload );

		$this->send_controller_response( $result, 'migration_pause_failed', 'Migration pause requested.', array(), array( 'paused' => true ) );
	}

	/**
	 * Resume migration.
	 */
	public function resume_migration() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_resume', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration resume aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$payload = array(
			'migrationId' => $this->get_post( 'migration_id', '', 'text' ),
			'job_id'      => $this->get_post( 'job_id', '', 'text' ),
		);

		$result = $this->migration_controller->resume_migration( $payload );

		$this->send_controller_response( $result, 'migration_resume_failed', 'Migration resumed.', array(), array( 'resumed' => true ) );
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

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration report aborted: controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
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

		if ( ! $this->settings_controller instanceof EFS_Settings_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration key generation aborted: settings controller unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Settings service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$payload = array(
			'target_url' => $this->get_post( 'target_url', '', 'text' ),
		);

		$result = $this->settings_controller->generate_migration_key( $payload );

		$this->send_controller_response( $result, 'migration_key_failed', 'Migration key generated.' );
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
