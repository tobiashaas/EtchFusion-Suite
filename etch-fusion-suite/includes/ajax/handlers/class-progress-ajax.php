<?php
/**
 * Progress AJAX Handler
 *
 * Handles polling-friendly migration progress and receiving status endpoints.
 *
 * @package Etch_Fusion_Suite
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Controllers\EFS_Migration_Controller;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Progress_Ajax_Handler
 */
class EFS_Progress_Ajax_Handler extends EFS_Base_Ajax_Handler {
	/**
	 * Migration controller.
	 *
	 * @var EFS_Migration_Controller|null
	 */
	private $migration_controller;

	/**
	 * Migration repository.
	 *
	 * @var Migration_Repository_Interface|null
	 */
	private $migration_repository;

	/**
	 * Constructor.
	 *
	 * @param EFS_Migration_Controller|null        $migration_controller Migration controller.
	 * @param Migration_Repository_Interface|null  $migration_repository Migration repository.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance.
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance.
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance.
	 */
	public function __construct( $migration_controller = null, $migration_repository = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->migration_controller = $migration_controller;
		$this->migration_repository = $migration_repository;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register hooks.
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_get_progress', array( $this, 'get_progress' ) );
		add_action( 'wp_ajax_efs_get_receiving_status', array( $this, 'get_receiving_status' ) );
	}

	/**
	 * Get migration progress.
	 */
	public function get_progress() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'progress_polling', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$controller = $this->resolve_migration_controller();
		if ( ! $controller ) {
			wp_send_json_error(
				array(
					'message' => __( 'Migration service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$migration_id = $this->get_post( 'migration_id', '', 'text' );
		if ( '' === $migration_id ) {
			$migration_id = $this->get_post( 'migrationId', '', 'text' );
		}

		$result = $controller->get_progress(
			array(
				'migrationId' => $migration_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $error_code ? $error_code : 'progress_failed',
				),
				400
			);
			return;
		}

		$payload = is_array( $result ) ? $result : array();
		if ( empty( $payload['last_updated'] ) && isset( $payload['progress']['last_updated'] ) ) {
			$payload['last_updated'] = $payload['progress']['last_updated'];
		}
		if ( ! isset( $payload['is_stale'] ) ) {
			$payload['is_stale'] = ! empty( $payload['progress']['is_stale'] );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Get receiving status (Etch-side).
	 */
	public function get_receiving_status() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'receiving_status_polling', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$repository = $this->resolve_migration_repository();
		if ( ! $repository ) {
			wp_send_json_error(
				array(
					'message' => __( 'Migration repository unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$state = $repository->get_receiving_state();
		wp_send_json_success(
			array(
				'status'                  => isset( $state['status'] ) ? $state['status'] : 'idle',
				'source_site'             => isset( $state['source_site'] ) ? $state['source_site'] : '',
				'migration_id'            => isset( $state['migration_id'] ) ? $state['migration_id'] : '',
				'current_phase'           => isset( $state['current_phase'] ) ? $state['current_phase'] : '',
				'items_received'          => isset( $state['items_received'] ) ? (int) $state['items_received'] : 0,
				'started_at'              => isset( $state['started_at'] ) ? $state['started_at'] : '',
				'last_activity'           => isset( $state['last_activity'] ) ? $state['last_activity'] : '',
				'last_updated'            => isset( $state['last_updated'] ) ? $state['last_updated'] : '',
				'is_stale'                => ! empty( $state['is_stale'] ),
				'estimated_time_remaining' => null,
			)
		);
	}

	/**
	 * Resolve migration controller.
	 *
	 * @return EFS_Migration_Controller|null
	 */
	private function resolve_migration_controller() {
		if ( $this->migration_controller instanceof EFS_Migration_Controller ) {
			return $this->migration_controller;
		}

		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'migration_controller' ) ) {
				$this->migration_controller = $container->get( 'migration_controller' );
				if ( $this->migration_controller instanceof EFS_Migration_Controller ) {
					return $this->migration_controller;
				}
			}
		}

		return null;
	}

	/**
	 * Resolve migration repository.
	 *
	 * @return Migration_Repository_Interface|null
	 */
	private function resolve_migration_repository() {
		if ( $this->migration_repository instanceof Migration_Repository_Interface ) {
			return $this->migration_repository;
		}

		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'migration_repository' ) ) {
				$this->migration_repository = $container->get( 'migration_repository' );
				if ( $this->migration_repository instanceof Migration_Repository_Interface ) {
					return $this->migration_repository;
				}
			}
		}

		return null;
	}
}
