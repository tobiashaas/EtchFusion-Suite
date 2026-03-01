<?php
/**
 * Migration Orchestrator Service
 *
 * Thin coordinator: delegates start/async-start/execution to focused helpers
 * while retaining the read-only operations (progress, cancel, report) and the
 * single-post migration path that other callers depend on.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_Orchestrator
 *
 * Drives the end-to-end migration workflow.
 */
class EFS_Migration_Orchestrator {

	/** @var EFS_Migration_Starter */
	private $starter;

	/** @var EFS_Async_Migration_Runner */
	private $async_runner;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/** @var EFS_Plugin_Detector */
	private $plugin_detector;

	/** @var EFS_Migration_Token_Manager */
	private $token_manager;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/**
	 * @param EFS_Migration_Starter          $starter
	 * @param EFS_Async_Migration_Runner     $async_runner
	 * @param EFS_Progress_Manager           $progress_manager
	 * @param Migration_Repository_Interface $migration_repository
	 * @param EFS_Plugin_Detector            $plugin_detector
	 * @param EFS_Migration_Token_Manager    $token_manager
	 * @param EFS_Content_Service            $content_service
	 * @param EFS_API_Client                 $api_client
	 * @param EFS_Error_Handler              $error_handler
	 */
	public function __construct(
		EFS_Migration_Starter $starter,
		EFS_Async_Migration_Runner $async_runner,
		EFS_Progress_Manager $progress_manager,
		Migration_Repository_Interface $migration_repository,
		EFS_Plugin_Detector $plugin_detector,
		EFS_Migration_Token_Manager $token_manager,
		EFS_Content_Service $content_service,
		EFS_API_Client $api_client,
		EFS_Error_Handler $error_handler
	) {
		$this->starter              = $starter;
		$this->async_runner         = $async_runner;
		$this->progress_manager     = $progress_manager;
		$this->migration_repository = $migration_repository;
		$this->plugin_detector      = $plugin_detector;
		$this->token_manager        = $token_manager;
		$this->content_service      = $content_service;
		$this->api_client           = $api_client;
		$this->error_handler        = $error_handler;
	}

	/**
	 * Start migration workflow (synchronous path).
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options
	 * @return array|\WP_Error
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null, $options = array() ) {
		return $this->starter->start_migration( $migration_key, $target_url, $batch_size, $options );
	}

	/**
	 * Start migration asynchronously.
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options
	 * @param string      $nonce
	 * @return array|\WP_Error
	 */
	public function start_migration_async( $migration_key, $target_url = null, $batch_size = null, $options = array(), $nonce = '' ) {
		return $this->starter->start_migration_async( $migration_key, $target_url, $batch_size, $options, $nonce );
	}

	/**
	 * Run the long-running migration steps (called by background AJAX request).
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '' ) {
		return $this->async_runner->run_migration_execution( $migration_id );
	}

	/**
	 * Detect resumable in-progress migration state.
	 *
	 * @return array
	 */
	public function detect_in_progress_migration(): array {
		$state        = $this->get_progress();
		$progress     = isset( $state['progress'] ) && is_array( $state['progress'] ) ? $state['progress'] : array();
		$migration_id = isset( $state['migrationId'] ) ? (string) $state['migrationId'] : '';
		$status       = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		$is_stale     = ! empty( $progress['is_stale'] ) || 'stale' === $status;
		$is_running   = in_array( $status, array( 'running', 'receiving' ), true );

		if ( '' !== $migration_id && $is_running ) {
			return array(
				'migrationId' => $migration_id,
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => $is_stale,
				'resumable'   => ! $is_stale,
			);
		}

		if ( in_array( $status, array( 'completed', 'error' ), true ) ) {
			return array(
				'migrationId' => '',
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => false,
				'resumable'   => false,
			);
		}

		$active = $this->migration_repository->get_active_migration();
		if ( is_array( $active ) && ! empty( $active['migration_id'] ) ) {
			$expires_at              = isset( $active['expires_at'] ) ? (int) $active['expires_at'] : 0;
			$active_is_stale         = $is_stale || ( $expires_at > 0 && time() > $expires_at );
			$progress['migrationId'] = (string) $active['migration_id'];
			return array(
				'migrationId' => (string) $active['migration_id'],
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => $active_is_stale,
				'resumable'   => ! $active_is_stale,
			);
		}

		return array(
			'migrationId' => '',
			'progress'    => $progress,
			'steps'       => $state['steps'],
			'is_stale'    => false,
			'resumable'   => false,
		);
	}

	/**
	 * Retrieve current progress.
	 *
	 * @param string $migration_id
	 *
	 * @return array
	 */
	public function get_progress( $migration_id = '' ): array {
		$progress_data             = $this->progress_manager->get_progress_data();
		$steps                     = $this->progress_manager->get_steps_state();
		$migration_id              = isset( $progress_data['migrationId'] ) ? $progress_data['migrationId'] : '';
		$progress_data['steps']    = $steps;
		$progress_data['is_stale'] = ! empty( $progress_data['is_stale'] );

		return array(
			'progress'                 => $progress_data,
			'steps'                    => $steps,
			'migrationId'              => $migration_id,
			'last_updated'             => isset( $progress_data['last_updated'] ) ? $progress_data['last_updated'] : '',
			'is_stale'                 => ! empty( $progress_data['is_stale'] ),
			'estimated_time_remaining' => isset( $progress_data['estimated_time_remaining'] ) ? $progress_data['estimated_time_remaining'] : null,
			'completed'                => $this->progress_manager->is_migration_complete(),
		);
	}

	/**
	 * Cancel migration and reset progress.
	 *
	 * @param string $migration_id
	 *
	 * @return array
	 */
	public function cancel_migration( $migration_id = '' ): array {
		// Get migration_id from active migration if not provided
		if ( empty( $migration_id ) ) {
			$active       = $this->progress_manager->get_active_migration();
			$migration_id = isset( $active['migration_id'] ) ? (string) $active['migration_id'] : '';
		}

		// If headless mode, unschedule any pending actions before clearing state
		if ( ! empty( $migration_id ) ) {
			$active = $this->progress_manager->get_active_migration();
			$mode   = isset( $active['mode'] ) ? (string) $active['mode'] : '';
			if ( 'headless' === $mode && function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( 'efs_run_headless_migration', array( 'migration_id' => $migration_id ), 'efs-migration' );
				as_unschedule_action( 'efs_cleanup_migration_log', array( 'migration_id' => $migration_id ), 'efs-migration' );
			}
		}

		$this->migration_repository->delete_progress();
		$this->migration_repository->delete_steps();
		$this->migration_repository->delete_token_data();
		// Delete the batch checkpoint so a fresh migration can start from scratch.
		// Without this, a subsequent start_migration() could find an orphaned checkpoint
		// from the cancelled run and behave unexpectedly (e.g. treat 0 remaining items
		// as "already done" and complete immediately).
		$this->migration_repository->delete_checkpoint();
		$this->progress_manager->store_active_migration( array() );

		$this->error_handler->log_warning(
			'W900',
			array(
				'action' => 'Migration cancelled by user',
			)
		);

		return array(
			'message'     => __( 'Migration cancelled.', 'etch-fusion-suite' ),
			'progress'    => $this->progress_manager->get_progress_data(),
			'steps'       => $this->progress_manager->get_steps_state(),
			'migrationId' => '',
			'completed'   => false,
		);
	}

	/**
	 * Generate migration report.
	 *
	 * @return array
	 */
	public function generate_report(): array {
		$progress = $this->progress_manager->get_progress_data();
		$stats    = $this->migration_repository->get_stats();
		$steps    = $this->progress_manager->get_steps_state();

		return array(
			'progress' => $progress,
			'stats'    => $stats,
			'steps'    => $steps,
		);
	}

	/**
	 * Validate target site requirements.
	 *
	 * @return array
	 */
	public function validate_target_site_requirements(): array {
		$result = $this->plugin_detector->validate_migration_requirements();

		if ( ! is_array( $result ) ) {
			return array(
				'valid'  => false,
				'errors' => array( __( 'Unknown validation response.', 'etch-fusion-suite' ) ),
			);
		}

		return $result;
	}

	/**
	 * Migrate a single post (used for batch processing).
	 *
	 * @param \WP_Post    $post
	 * @param string      $migration_key
	 * @param string|null $target_url
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_single_post( $post, $migration_key, $target_url = null ) {
		$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$target = ! empty( $target_url ) ? $target_url : ( $decoded['payload']['target_url'] ?? '' );

		if ( empty( $target ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Target URL could not be determined from migration key.', 'etch-fusion-suite' ) );
		}

		return $this->content_service->convert_bricks_to_gutenberg( $post->ID, $this->api_client, $target, $migration_key );
	}
}
