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
		add_action( 'wp_ajax_nopriv_efs_run_migration_background', array( $this, 'run_migration_background' ) );
		add_action( 'wp_ajax_efs_run_migration_background', array( $this, 'run_migration_background' ) );
		add_action( 'wp_ajax_efs_get_migration_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_efs_migrate_batch', array( $this, 'process_batch' ) );
		add_action( 'wp_ajax_efs_resume_migration', array( $this, 'resume_migration' ) );
		add_action( 'wp_ajax_efs_cancel_migration', array( $this, 'cancel_migration' ) );
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
		$payload    = array(
			'migration_key'       => $this->get_post( 'migration_key', '', 'raw' ),
			'target_url'          => $raw_target ? $this->convert_to_internal_url( $raw_target ) : '',
			'batch_size'          => $this->get_post( 'batch_size', 50, 'int' ),
			'selected_post_types' => $this->get_post( 'selected_post_types', array(), 'array' ),
			'post_type_mappings'  => $this->get_post( 'post_type_mappings', array(), 'array' ),
			'include_media'       => $this->get_post( 'include_media', '1', 'text' ),
			'nonce'               => $this->get_post( 'nonce', '', 'raw' ),
		);

		$result = $this->migration_controller->start_migration( $payload );

		$extra_data = is_wp_error( $result ) ? array() : array( 'migration_id' => $result['migrationId'] ?? null );
		$this->send_controller_response( $result, 'migration_start_failed', 'Migration started successfully.', $extra_data );
	}

	/**
	 * Run migration in background (invoked by spawn; no user context).
	 */
	public function run_migration_background() {
		$migration_id = isset( $_POST['migration_id'] ) ? sanitize_text_field( wp_unslash( $_POST['migration_id'] ) ) : '';
		$bg_token     = isset( $_POST['bg_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bg_token'] ) ) : '';

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			wp_die( '0', 503 );
		}

		$migration_repository = null;
		$error_handler        = null;
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'migration_repository' ) ) {
				$migration_repository = $container->get( 'migration_repository' );
			}
			if ( $container->has( 'error_handler' ) ) {
				$error_handler = $container->get( 'error_handler' );
			}
		}

		register_shutdown_function(
			function () use ( $migration_id, $migration_repository, $error_handler ) {
				$error = error_get_last();
				if ( ! is_array( $error ) || ! in_array( $error['type'] ?? null, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}

				if ( $migration_repository ) {
					$progress                   = $migration_repository->get_progress();
					$current_percentage         = isset( $progress['percentage'] ) ? (int) $progress['percentage'] : 0;
					$progress['migrationId']    = $migration_id;
					$progress['status']         = 'error';
					$progress['percentage']     = $current_percentage;
					$progress['message']        = sprintf( 'Fatal error during migration: %s', $error['message'] ?? '' );
					$progress['last_updated']   = current_time( 'mysql' );
					$progress['current_step']   = 'error';
					$progress['completed_at']   = current_time( 'mysql' );
					$progress['is_stale']       = false;
					$progress['current_phase_status'] = 'failed';
					$migration_repository->save_progress( $progress );
					$migration_repository->save_active_migration( array() );
				}

				if ( $error_handler ) {
					$error_handler->log_error(
						'E500',
						array(
							'message' => $error['message'] ?? '',
							'file'    => $error['file'] ?? '',
							'line'    => $error['line'] ?? 0,
							'action'  => 'Fatal error during background migration',
						)
					);
				}
			}
		);

		$result = $this->migration_controller->run_migration_execution( $migration_id, $bg_token );
		if ( is_wp_error( $result ) ) {
			wp_die( '0', 400 );
		}

		// Use result from run_migration_execution: when batching is pending, return completed false and progress/posts_ready.
		$completed = isset( $result['completed'] ) && $result['completed'];
		$payload   = array(
			'completed'   => $completed,
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : $migration_id,
		);
		if ( isset( $result['posts_ready'] ) ) {
			$payload['posts_ready'] = (bool) $result['posts_ready'];
			$payload['total']       = isset( $result['total'] ) ? $result['total'] : 0;
		}

		wp_send_json_success( $payload );
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
	public function process_batch() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_batch', 1200, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! $this->migration_controller instanceof EFS_Migration_Controller ) {
			$this->log_security_event( 'ajax_action', 'Migration batch aborted: controller unavailable.' );
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
		);

		$result = $this->migration_controller->cancel_migration( $payload );

		$this->send_controller_response( $result, 'migration_cancel_failed', 'Migration cancelled.', array(), array( 'cancelled' => true ) );
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
