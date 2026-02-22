<?php
/**
 * Headless Migration Job Service
 *
 * Runs the migration entirely server-side via Action Scheduler,
 * allowing the browser to be closed during execution.
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
 * Registers an Action Scheduler action and runs the complete migration
 * workflow inside that action, chunking across multiple AS jobs when the
 * time budget is approaching.
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
		add_action( 'efs_run_headless_migration', array( $this, 'run_headless_job' ) );
	}

	/**
	 * Action Scheduler callback: run migration setup and/or batch loop.
	 *
	 * @param string $migration_id Migration UUID.
	 */
	public function run_headless_job( string $migration_id ): void {
		// Allow long execution time and ignore client disconnects.
		set_time_limit( 0 );
		ignore_user_abort( true );

		// Capture dependencies by value for the shutdown handler.
		$progress_manager  = $this->progress_manager;
		$migration_logger  = $this->migration_logger;
		$captured_id       = $migration_id;

		register_shutdown_function(
			function () use ( $progress_manager, $migration_logger, $captured_id ) {
				$error = error_get_last();
				if ( null === $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					return;
				}
				$message = sprintf(
					'Fatal error in headless migration: %s in %s on line %d',
					$error['message'],
					$error['file'],
					$error['line']
				);
				$progress_data = $progress_manager->get_progress_data();
				$current_pct   = isset( $progress_data['percentage'] ) ? (int) $progress_data['percentage'] : 0;
				$progress_manager->update_progress( 'error', $current_pct, $message );
				$migration_logger->log( $captured_id, 'error', $message );
			}
		);

		// Idempotency guard: skip if already complete.
		if ( $this->progress_manager->is_migration_complete() ) {
			return;
		}

		// Setup phase: run if no checkpoint exists yet.
		$checkpoint = $this->migration_repository->get_checkpoint();
		if ( empty( $checkpoint ) ) {
			$setup_result = $this->runner->run_migration_execution( $migration_id );
			if ( is_wp_error( $setup_result ) ) {
				$this->migration_logger->log( $migration_id, 'error', 'Headless setup phase failed: ' . $setup_result->get_error_message() );
				return;
			}
		}

		// Compute time budget: 80 % of max_execution_time, defaulting to 300 s.
		$time_limit  = (int) ini_get( 'max_execution_time' );
		$start_time  = time();
		$time_budget = $time_limit > 0 ? (int) ( $time_limit * 0.8 ) : 300;

		// Batch loop.
		while ( true ) {
			$result = $this->batch_processor->process_batch( $migration_id );

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				$this->migration_logger->log( $migration_id, 'error', 'Headless batch error: ' . $error_message );
				$progress_data = $this->progress_manager->get_progress_data();
				$current_pct   = isset( $progress_data['percentage'] ) ? (int) $progress_data['percentage'] : 0;
				$this->progress_manager->update_progress( 'error', $current_pct, $error_message );
				return;
			}

			if ( ! empty( $result['completed'] ) ) {
				$this->migration_logger->log( $migration_id, 'info', 'Headless migration completed.' );
				return;
			}

			// Re-queue if time budget is approaching.
			if ( ( time() - $start_time ) >= $time_budget ) {
				$this->migration_logger->log( $migration_id, 'info', 'Time budget reached — re-queuing headless migration.' );
				$this->enqueue_job( $migration_id );
				return;
			}
		}
	}

	/**
	 * Enqueue a new Action Scheduler job for the given migration.
	 *
	 * @param string $migration_id Migration UUID.
	 * @return int Action ID.
	 */
	public function enqueue_job( string $migration_id ): int {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		return (int) as_enqueue_async_action(
			'efs_run_headless_migration',
			array( 'migration_id' => $migration_id ),
			'efs-migration'
		);
	}
}
