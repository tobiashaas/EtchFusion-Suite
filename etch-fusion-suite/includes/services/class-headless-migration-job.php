<?php
/**
 * Headless Migration Job Service
 *
 * Registers and runs headless migration via Action Scheduler; handles log cleanup.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Headless_Migration_Job
 *
 * Registers efs_run_headless_migration and efs_cleanup_migration_log; runs batch loop
 * with time budget and re-enqueue; provides enqueue_job and handle_cleanup_log.
 */
class EFS_Headless_Migration_Job {

	/** @var EFS_Async_Migration_Runner */
	private $runner;

	/** @var EFS_Batch_Processor */
	private $batch_processor;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var EFS_Migration_Logger */
	private $migration_logger;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/**
	 * @param EFS_Async_Migration_Runner     $runner
	 * @param EFS_Batch_Processor            $batch_processor
	 * @param EFS_Progress_Manager           $progress_manager
	 * @param EFS_Migration_Logger           $migration_logger
	 * @param Migration_Repository_Interface $migration_repository
	 */
	public function __construct(
		EFS_Async_Migration_Runner $runner,
		EFS_Batch_Processor $batch_processor,
		EFS_Progress_Manager $progress_manager,
		EFS_Migration_Logger $migration_logger,
		Migration_Repository_Interface $migration_repository
	) {
		$this->runner               = $runner;
		$this->batch_processor      = $batch_processor;
		$this->progress_manager     = $progress_manager;
		$this->migration_logger     = $migration_logger;
		$this->migration_repository = $migration_repository;

		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks(): void {
		add_action( 'efs_run_headless_migration', array( $this, 'run_headless_job' ), 10, 1 );
		add_action( 'efs_cleanup_migration_log', array( $this, 'handle_cleanup_log' ), 10, 1 );
	}

	/**
	 * Run headless migration job (Action Scheduler callback).
	 *
	 * @param string $migration_id Migration ID.
	 */
	public function run_headless_job( string $migration_id ): void {
		set_time_limit( 0 );
		ignore_user_abort( true );

		$migration_logger_shutdown = $this->migration_logger;
		register_shutdown_function(
			function () use ( $migration_id, $migration_logger_shutdown ) {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					$migration_logger_shutdown->log( $migration_id, 'error', 'Fatal shutdown: ' . $error['message'] );
				}
			}
		);

		$progress = $this->progress_manager->get_progress_data();
		$status   = isset( $progress['status'] ) ? (string) $progress['status'] : '';
		if ( in_array( $status, array( 'completed', 'error' ), true ) ) {
			return;
		}

		$checkpoint = $this->migration_repository->get_checkpoint();
		if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
			$runner_result = $this->runner->run_migration_execution( $migration_id );
			if ( is_wp_error( $runner_result ) ) {
				return;
			}
		}

		$active         = $this->progress_manager->get_active_migration();
		$target_url     = isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$migration_key  = isset( $active['migration_key'] ) ? (string) $active['migration_key'] : '';
		$active_options = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();
		$batch_size     = isset( $active['batch_size'] ) ? max( 1, (int) $active['batch_size'] ) : 50;

		if ( '' === $target_url || '' === $migration_key ) {
			$this->migration_logger->log( $migration_id, 'error', 'Headless job: missing target_url or migration_key in active migration.' );
			return;
		}

		$max_time   = (int) ini_get( 'max_execution_time' );
		$budget     = $max_time > 0 ? (int) floor( 0.8 * $max_time ) : 0;
		$start_time = time();

		for ( ; ; ) {
			$result = $this->batch_processor->process_batch( $migration_id, array(), $target_url, $migration_key, $active_options );
			if ( is_wp_error( $result ) ) {
				if ( 'no_checkpoint' === $result->get_error_code() ) {
					$runner_result = $this->runner->run_migration_execution( $migration_id );
					if ( is_wp_error( $runner_result ) ) {
						return;
					}
					continue;
				}
				return;
			}

			$progress = $this->progress_manager->get_progress_data();
			$status   = isset( $progress['status'] ) ? (string) $progress['status'] : '';
			if ( in_array( $status, array( 'completed', 'error' ), true ) ) {
				// Unschedule any pending headless job actions for this migration
				if ( function_exists( 'as_unschedule_action' ) ) {
					as_unschedule_action( 'efs_run_headless_migration', array( 'migration_id' => $migration_id ), 'efs-migration' );
				}
				// Schedule log cleanup for 7 days later
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + 7 * DAY_IN_SECONDS, 'efs_cleanup_migration_log', array( 'migration_id' => $migration_id ), 'efs-migration' );
				}
				return;
			}

			if ( $budget > 0 && ( time() - $start_time ) >= $budget ) {
				$this->enqueue_job( $migration_id );
				return;
			}
		}
	}

	/**
	 * Enqueue headless migration job (Action Scheduler).
	 *
	 * @param string $migration_id Migration ID.
	 * @return int Action ID or 0.
	 */
	public function enqueue_job( string $migration_id ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->migration_logger->log( $migration_id, 'warning', 'Headless: as_enqueue_async_action not available.' );
			return 0;
		}
		$count = $this->progress_manager->increment_headless_job_count();
		if ( $count > 50 ) {
			$this->migration_logger->log( $migration_id, 'warning', 'Headless job count exceeds 50; consider checking for stuck migrations.' );
		}
		return (int) as_enqueue_async_action( 'efs_run_headless_migration', array( 'migration_id' => $migration_id ), 'efs-migration' );
	}

	/**
	 * Delete migration log (scheduled cleanup callback).
	 *
	 * @param string $migration_id Migration ID.
	 */
	public function handle_cleanup_log( string $migration_id ): void {
		if ( '' === $migration_id ) {
			return;
		}
		$this->migration_logger->delete_log( $migration_id );
		if ( defined( 'WP_DEBUG_LOG' ) && true === WP_DEBUG_LOG ) {
			$this->migration_logger->log( $migration_id, 'info', 'Migration log deleted.' );
		}
	}
}
