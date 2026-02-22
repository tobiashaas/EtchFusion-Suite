<?php
/**
 * Batch Processor Service
 *
 * Owns the delegating phase-dispatch path for the JS-driven batch loop.
 * Each phase is handled by a registered Phase_Handler_Interface implementation.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\EFS_Migration_Runs_Repository;
use Bricks2Etch\Repositories\Interfaces\Checkpoint_Repository_Interface;
use Bricks2Etch\Services\Interfaces\Phase_Handler_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Batch_Processor
 *
 * Processes batches of posts/media via registered phase handlers.
 */
class EFS_Batch_Processor {

	const LOCK_EXPIRY_TTL = 300;

	/** @var string|null */
	private static $shutdown_lock_key = null;

	/** @var Phase_Handler_Interface[] */
	private $phase_handlers;

	/** @var Checkpoint_Repository_Interface */
	private $checkpoint_repository;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Migration_Runs_Repository|null */
	private $migration_runs_repository;

	/** @var EFS_Migration_Run_Finalizer|null */
	private $run_finalizer;

	/** @var EFS_Batch_Phase_Runner|null */
	private $batch_phase_runner;

	/**
	 * @param Phase_Handler_Interface[]          $phase_handlers            Registered phase handlers.
	 * @param Checkpoint_Repository_Interface    $checkpoint_repository
	 * @param EFS_Progress_Manager               $progress_manager
	 * @param EFS_API_Client                     $api_client
	 * @param EFS_Error_Handler                  $error_handler
	 * @param EFS_Migration_Runs_Repository|null $migration_runs_repository
	 * @param EFS_Migration_Run_Finalizer|null   $run_finalizer             Finalizer for run records.
	 * @param EFS_Batch_Phase_Runner|null         $batch_phase_runner        Phase runner for batch iteration.
	 */
	public function __construct(
		array $phase_handlers,
		Checkpoint_Repository_Interface $checkpoint_repository,
		EFS_Progress_Manager $progress_manager,
		EFS_API_Client $api_client,
		EFS_Error_Handler $error_handler,
		?EFS_Migration_Runs_Repository $migration_runs_repository = null,
		?EFS_Migration_Run_Finalizer $run_finalizer = null,
		?EFS_Batch_Phase_Runner $batch_phase_runner = null
	) {
		$this->phase_handlers            = $phase_handlers;
		$this->checkpoint_repository     = $checkpoint_repository;
		$this->progress_manager          = $progress_manager;
		$this->api_client                = $api_client;
		$this->error_handler             = $error_handler;
		$this->migration_runs_repository = $migration_runs_repository;
		$this->run_finalizer             = $run_finalizer;
		$this->batch_phase_runner        = $batch_phase_runner;
	}

	/**
	 * Process a single batch of items for JS-driven batch loop.
	 *
	 * @param string $migration_id            Migration ID.
	 * @param array  $batch                   Batch options. Accepts key 'batch_size' (int).
	 * @param string $target_url              Target site URL.
	 * @param string $migration_key           Migration key / JWT token.
	 * @param array  $active_migration_options Options from the active migration record.
	 *
	 * @return array|\WP_Error
	 */
	public function process_batch( string $migration_id, array $batch, string $target_url, string $migration_key, array $active_migration_options ) {
		$lock_key   = 'efs_batch_lock_' . $migration_id;
		$expiry_key = 'efs_batch_lock_expiry_' . $migration_id;

		$got_lock = add_option( $lock_key, '1', '', 'no' );
		if ( ! $got_lock ) {
			$expiry_alive = get_transient( $expiry_key );
			if ( false === $expiry_alive ) {
				delete_option( $lock_key );
				$got_lock = add_option( $lock_key, '1', '', 'no' );
			}
			if ( ! $got_lock ) {
				return new \WP_Error( 'batch_locked', __( 'Another batch process is running for this migration.', 'etch-fusion-suite' ) );
			}
		}

		set_transient( $expiry_key, '1', self::LOCK_EXPIRY_TTL );
		self::$shutdown_lock_key = $lock_key;
		register_shutdown_function( array( self::class, 'release_lock_on_shutdown' ) );

		try {
			$checkpoint = $this->checkpoint_repository->get_checkpoint();

			if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
				return new \WP_Error( 'no_checkpoint', __( 'No checkpoint found for this migration.', 'etch-fusion-suite' ) );
			}

			$phase = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';

			// Find the registered phase handler for the current phase.
			$active_handler = null;
			foreach ( $this->phase_handlers as $handler ) {
				if ( $handler->get_phase_key() === $phase ) {
					$active_handler = $handler;
					break;
				}
			}

			if ( null === $active_handler ) {
				return new \WP_Error(
					'no_phase_handler',
					/* translators: %s: phase key */
					sprintf( __( 'No handler registered for phase: %s', 'etch-fusion-suite' ), $phase )
				);
			}

			return $this->batch_phase_runner->run_phase(
				$active_handler,
				$phase,
				$checkpoint,
				$batch,
				$migration_id,
				$target_url,
				$migration_key,
				$active_migration_options
			);
		} finally {
			delete_option( $lock_key );
			delete_transient( $expiry_key );
			self::$shutdown_lock_key = null;
		}
	}

	/**
	 * Release batch lock on shutdown (e.g. fatal error).
	 */
	public static function release_lock_on_shutdown(): void {
		if ( null !== self::$shutdown_lock_key ) {
			delete_option( self::$shutdown_lock_key );
			self::$shutdown_lock_key = null;
		}
	}

	/**
	 * Resume a JS-driven batch loop after a timeout or error.
	 *
	 * @param string $migration_id Migration ID to resume.
	 *
	 * @return array|\WP_Error
	 */
	public function resume_migration_execution( string $migration_id ) {
		$checkpoint = $this->checkpoint_repository->get_checkpoint();

		if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
			return new \WP_Error( 'no_checkpoint', __( 'No checkpoint found for this migration ID.', 'etch-fusion-suite' ) );
		}

		$phase     = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';
		$remaining = 'media' === $phase
			? count( isset( $checkpoint['remaining_media_ids'] ) ? (array) $checkpoint['remaining_media_ids'] : array() )
			: count( isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array() );

		return array(
			'resumed'     => true,
			'remaining'   => $remaining,
			'migrationId' => $migration_id,
			'progress'    => $this->progress_manager->get_progress_data(),
		);
	}
}
