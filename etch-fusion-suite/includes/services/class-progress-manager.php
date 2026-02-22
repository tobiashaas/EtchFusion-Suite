<?php
/**
 * Progress Manager Service
 *
 * Manages migration progress state, step tracking, heartbeat, and stats.
 * Extracted from EFS_Migration_Service via the Strangler Fig pattern.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Repositories\Interfaces\Progress_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Progress_Manager
 *
 * Handles all progress/steps I/O for the migration workflow.
 */
class EFS_Progress_Manager {

	/**
	 * Running progress with no updates for this period is stale.
	 * Allows a new migration to start after an abandoned/hung run.
	 */
	private const PROGRESS_STALE_TTL = 300;

	/** @var Progress_Repository_Interface */
	private $progress_repository;

	/** @var EFS_Steps_Manager */
	private $steps_manager;

	/**
	 * @param Progress_Repository_Interface $progress_repository
	 * @param EFS_Steps_Manager             $steps_manager
	 */
	public function __construct(
		Progress_Repository_Interface $progress_repository,
		EFS_Steps_Manager $steps_manager
	) {
		$this->progress_repository = $progress_repository;
		$this->steps_manager       = $steps_manager;
	}

	/**
	 * Initialize progress state.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $options      Optional. selected_post_types, post_type_mappings, include_media.
	 */
	public function init_progress( string $migration_id, array $options = array() ): void {
		$progress = array(
			'migrationId'              => sanitize_text_field( $migration_id ),
			'status'                   => 'running',
			'current_step'             => 'validation',
			'current_phase_name'       => 'Validation',
			'current_phase_status'     => 'active',
			'percentage'               => 0,
			'started_at'       => current_time( 'mysql' ),
			'last_updated'     => current_time( 'mysql' ),
			'completed_at'     => null,
			'items_processed'  => 0,
			'items_total'      => 0,
		);

		$this->progress_repository->save_progress( $progress );
		$this->set_steps_state( $this->steps_manager->initialize_steps( $options ) );
	}

	/**
	 * Update progress data.
	 *
	 * @param string   $step
	 * @param int      $percentage
	 * @param string   $message
	 * @param int|null $items_processed
	 * @param int|null $items_total
	 * @param bool     $mark_step_completed If false, set step as active only; do not mark it completed. Default true.
	 */
	public function update_progress( string $step, int $percentage, string $message, ?int $items_processed = null, ?int $items_total = null, bool $mark_step_completed = true ): void {
		$progress                 = $this->progress_repository->get_progress();
		$previous_percentage      = isset( $progress['percentage'] ) ? (float) $progress['percentage'] : 0.0;
		$normalized_percentage    = max( $previous_percentage, min( 100, (float) $percentage ) );
		$progress['current_step'] = sanitize_key( (string) $step );
		$progress['percentage']   = $normalized_percentage;
		$progress['message']      = $message;
		$progress['last_updated'] = current_time( 'mysql' );
		$progress['is_stale']     = false;

		if ( $this->steps_manager->is_known_phase( $step ) ) {
			$progress['current_phase_name'] = $this->steps_manager->get_phase_name( $step );
		}

		if ( null !== $items_processed ) {
			$progress['items_processed'] = max( 0, (int) $items_processed );
		}
		if ( null !== $items_total ) {
			$progress['items_total'] = max( 0, (int) $items_total );
		}

		if ( 'completed' === $step ) {
			$progress['status']               = 'completed';
			$progress['current_phase_status'] = 'completed';
			$progress['completed_at']         = current_time( 'mysql' );
			$this->store_active_migration( array() );
		} elseif ( 'error' === $step ) {
			$progress['status']               = 'error';
			$progress['current_phase_status'] = 'failed';
			$progress['completed_at']         = current_time( 'mysql' );
			$this->store_active_migration( array() );
		} else {
			$progress['status']               = 'running';
			$progress['current_phase_status'] = 'active';
		}

		$this->progress_repository->save_progress( $progress );

		// Do not mark batched steps (posts, media) as completed until truly finished.
		$step_truly_finished = $mark_step_completed;
		if ( in_array( $step, array( 'posts', 'media' ), true ) && $normalized_percentage < 100 && 'completed' !== $step ) {
			$step_truly_finished = false;
		}

		$steps = $this->get_steps_state();
		if ( ! empty( $steps ) ) {
			$steps = $this->steps_manager->update_steps_for_progress( $step, $step_truly_finished, $steps );
			$this->set_steps_state( $steps );
		}
	}

	/**
	 * Get raw progress data.
	 *
	 * @return array
	 */
	public function get_progress_data(): array {
		$progress = $this->progress_repository->get_progress();

		if ( empty( $progress ) ) {
			return array(
				'status'       => 'idle',
				'current_step' => '',
				'percentage'   => 0,
				'last_updated' => '',
				'is_stale'     => false,
			);
		}

		if ( empty( $progress['last_updated'] ) ) {
			$progress['last_updated'] = isset( $progress['started_at'] ) ? $progress['started_at'] : current_time( 'mysql' );
		}

		$status = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		if ( in_array( $status, array( 'running', 'receiving' ), true ) ) {
			$last_updated_ts      = strtotime( (string) $progress['last_updated'] );
			$is_stale             = false !== $last_updated_ts && ( time() - $last_updated_ts ) >= self::PROGRESS_STALE_TTL;
			$progress['is_stale'] = $is_stale;
			if ( $is_stale ) {
				$progress['status'] = 'stale';
			}
		} else {
			$progress['is_stale'] = false;
		}

		return $progress;
	}

	/**
	 * Retrieve step state map.
	 *
	 * @return array
	 */
	public function get_steps_state(): array {
		$steps = $this->progress_repository->get_steps();

		return is_array( $steps ) ? $steps : array();
	}

	/**
	 * Persist step state map.
	 *
	 * @param array $steps
	 */
	public function set_steps_state( array $steps ): void {
		$this->progress_repository->save_steps( $steps );
	}

	/**
	 * Check if migration is complete.
	 *
	 * @return bool
	 */
	public function is_migration_complete(): bool {
		$progress = $this->get_progress_data();

		return isset( $progress['status'] ) && 'completed' === $progress['status'];
	}

	/**
	 * Refresh `last_updated` in stored progress to prevent stale detection.
	 * Only updates if migration is actively running.
	 */
	public function touch_progress_heartbeat(): void {
		$progress = $this->progress_repository->get_progress();
		if ( is_array( $progress ) && isset( $progress['status'] ) && in_array( $progress['status'], array( 'running', 'receiving' ), true ) ) {
			$progress['last_updated'] = current_time( 'mysql' );
			$progress['is_stale']     = false;
			$this->progress_repository->save_progress( $progress );
		}
	}

	/**
	 * Persist metadata about the currently running migration.
	 *
	 * @param array $data
	 */
	public function store_active_migration( array $data ): void {
		$this->progress_repository->save_active_migration( $data );
	}

	/**
	 * Retrieve active migration metadata.
	 *
	 * @return array
	 */
	public function get_active_migration(): array {
		return $this->progress_repository->get_active_migration();
	}

	/**
	 * Mark migration stats as completed (sets last_migration and status = 'completed').
	 */
	public function mark_stats_completed(): void {
		$stats                   = $this->progress_repository->get_stats();
		$stats['last_migration'] = current_time( 'mysql' );
		$stats['status']         = 'completed';
		$this->progress_repository->save_stats( $stats );
	}

	/**
	 * Reset the stale flag so the batch loop can restart.
	 */
	public function reset_stale_flag(): void {
		$progress = $this->progress_repository->get_progress();
		if ( is_array( $progress ) && isset( $progress['is_stale'] ) ) {
			$progress['is_stale']     = false;
			$progress['status']       = 'running';
			$progress['last_updated'] = current_time( 'mysql' );
			$this->progress_repository->save_progress( $progress );
		}
	}
}
