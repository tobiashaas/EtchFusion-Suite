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

	/** @var array|null Lock metadata (migration_id, lock_uuid) */
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
		global $wpdb;

		// Try to acquire database-based lock (atomic UPDATE with lock_uuid)
		$lock_uuid = wp_generate_uuid4();
		$locked    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$lock_uuid,
				$migration_id
			)
		);

		if ( ! $locked ) {
			return new \WP_Error( 'batch_locked', __( 'Another batch process is running for this migration.', 'etch-fusion-suite' ) );
		}

		// Store lock UUID so we can release it on shutdown.
		self::$shutdown_lock_key = array( 'migration_id' => $migration_id, 'lock_uuid' => $lock_uuid );
		register_shutdown_function( array( self::class, 'release_lock_on_shutdown' ) );

		try {
			$checkpoint = $this->checkpoint_repository->get_checkpoint();

			// Validate checkpoint integrity before processing.
			$validation = self::validate_checkpoint( $checkpoint, $migration_id );
			if ( is_wp_error( $validation ) ) {
				return $validation;
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

			// Guard: batch_phase_runner is optional in the constructor (legacy/test context).
			if ( null === $this->batch_phase_runner ) {
				return new \WP_Error( 'no_batch_phase_runner', __( 'Batch phase runner not configured.', 'etch-fusion-suite' ) );
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
			// Release the database lock by clearing the lock_uuid.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}efs_migrations
					SET lock_uuid = NULL, locked_at = NULL
					WHERE migration_uid = %s",
					$migration_id
				)
			);
			self::$shutdown_lock_key = null;
		}
	}

	/**
	 * Release batch lock on shutdown (e.g. fatal error).
	 */
	public static function release_lock_on_shutdown(): void {
		if ( null !== self::$shutdown_lock_key && is_array( self::$shutdown_lock_key ) ) {
			global $wpdb;
			$migration_id = self::$shutdown_lock_key['migration_id'] ?? null;
			$lock_uuid    = self::$shutdown_lock_key['lock_uuid'] ?? null;

			if ( $migration_id && $lock_uuid ) {
				// Only clear lock if it still matches our UUID (prevents clearing locks from other processes).
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}efs_migrations
						SET lock_uuid = NULL, locked_at = NULL
						WHERE migration_uid = %s
						AND lock_uuid = %s",
						$migration_id,
						$lock_uuid
					)
				);
			}
			self::$shutdown_lock_key = null;
		}
	}

	/**
	 * Resume a JS-driven batch loop after a timeout or error.
	 *
	 * Refreshes the heartbeat before returning so that the computed status in
	 * get_progress_data() is 'running' rather than 'stale' for the resumed session.
	 * Without this, auto-resume on page reload would still see a stale status on the
	 * very next progress poll, potentially triggering another (redundant) resume cycle.
	 *
	 * @param string $migration_id Migration ID to resume.
	 *
	 * @return array|\WP_Error
	 */
	public function resume_migration_execution( string $migration_id ) {
		$checkpoint = $this->checkpoint_repository->get_checkpoint();

		// Validate checkpoint integrity before resuming.
		$validation = self::validate_checkpoint( $checkpoint, $migration_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$phase     = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';
		$remaining = 'media' === $phase
			? count( isset( $checkpoint['remaining_media_ids'] ) ? (array) $checkpoint['remaining_media_ids'] : array() )
			: count( isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array() );

		// Reset the stale flag immediately so the next get_progress_data() call
		// returns status='running' instead of 'stale'.
		$this->progress_manager->touch_progress_heartbeat();

		return array(
			'resumed'     => true,
			'remaining'   => $remaining,
			'migrationId' => $migration_id,
			'progress'    => $this->progress_manager->get_progress_data(),
		);
	}

	/**
	 * Validate checkpoint structure and required fields.
	 *
	 * Ensures checkpoint contains all required keys and valid phase values.
	 * Returns WP_Error if validation fails, null if valid.
	 *
	 * @param mixed  $checkpoint   Checkpoint data to validate.
	 * @param string $migration_id Expected migration ID (for cross-check).
	 *
	 * @return \WP_Error|null WP_Error if invalid, null if valid.
	 */
	public static function validate_checkpoint( $checkpoint, string $migration_id = '' ) {
		// Check if checkpoint exists.
		if ( empty( $checkpoint ) || ! is_array( $checkpoint ) ) {
			return new \WP_Error( 'invalid_checkpoint', __( 'Checkpoint is empty or not an array.', 'etch-fusion-suite' ) );
		}

		// Validate required fields.
		$required_fields = array( 'migrationId', 'phase', 'total_count' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $checkpoint[ $field ] ) || '' === $checkpoint[ $field ] ) {
				return new \WP_Error(
					'missing_checkpoint_field',
					/* translators: %s: field name */
					sprintf( __( 'Checkpoint missing required field: %s', 'etch-fusion-suite' ), $field )
				);
			}
		}

		// Validate migration ID if provided.
		if ( ! empty( $migration_id ) && (string) $migration_id !== (string) ( $checkpoint['migrationId'] ?? '' ) ) {
			return new \WP_Error(
				'checkpoint_migration_mismatch',
				__( 'Checkpoint migration ID does not match expected migration ID.', 'etch-fusion-suite' )
			);
		}

		// Validate phase is one of the allowed phases.
		$allowed_phases = array( 'posts', 'media', 'css' );
		if ( ! in_array( $checkpoint['phase'], $allowed_phases, true ) ) {
			return new \WP_Error(
				'invalid_checkpoint_phase',
				/* translators: %s: phase name */
				sprintf( __( 'Checkpoint phase "%s" is not valid. Allowed: %s', 'etch-fusion-suite' ), $checkpoint['phase'], implode( ', ', $allowed_phases ) )
			);
		}

		// Validate total_count is a positive integer.
		$total_count = (int) ( $checkpoint['total_count'] ?? 0 );
		if ( $total_count <= 0 ) {
			return new \WP_Error( 'invalid_checkpoint_count', __( 'Checkpoint total_count must be a positive integer.', 'etch-fusion-suite' ) );
		}

		// All validations passed.
		return null;
	}
}
