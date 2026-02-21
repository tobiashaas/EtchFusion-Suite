<?php
/**
 * Migration Service (Facade)
 *
 * Thin facade that delegates to focused service classes:
 * - EFS_Migration_Orchestrator  — start / run / finalize the full migration flow
 * - EFS_Batch_Processor         — batched media + posts processing
 * - EFS_Legacy_Batch_Fallback   — inline batch loop for callers without phase handlers
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Service {

	/** @var EFS_Migration_Orchestrator */
	private $orchestrator;

	/** @var EFS_Batch_Processor */
	private $batch_processor;

	/** @var EFS_Legacy_Batch_Fallback */
	private $legacy_batch_fallback;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/**
	 * @param EFS_Migration_Orchestrator     $orchestrator
	 * @param EFS_Batch_Processor            $batch_processor
	 * @param EFS_Legacy_Batch_Fallback      $legacy_batch_fallback
	 * @param Migration_Repository_Interface $migration_repository
	 */
	public function __construct(
		EFS_Migration_Orchestrator $orchestrator,
		EFS_Batch_Processor $batch_processor,
		EFS_Legacy_Batch_Fallback $legacy_batch_fallback,
		Migration_Repository_Interface $migration_repository
	) {
		$this->orchestrator          = $orchestrator;
		$this->batch_processor       = $batch_processor;
		$this->legacy_batch_fallback = $legacy_batch_fallback;
		$this->migration_repository  = $migration_repository;
	}

	// -------------------------------------------------------------------------
	// Public API — thin delegations to focused classes
	// -------------------------------------------------------------------------

	/**
	 * Start migration workflow.
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options       Optional. selected_post_types, post_type_mappings.
	 * @return array|\WP_Error
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null, $options = array() ) {
		return $this->orchestrator->start_migration( $migration_key, $target_url, $batch_size, $options );
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
		return $this->orchestrator->start_migration_async( $migration_key, $target_url, $batch_size, $options, $nonce );
	}

	/**
	 * Run the long-running migration steps (called by background AJAX request).
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '' ) {
		return $this->orchestrator->run_migration_execution( $migration_id );
	}

	/**
	 * Retrieve current progress.
	 *
	 * @param string $migration_id
	 * @return array
	 */
	public function get_progress( $migration_id = '' ) {
		return $this->orchestrator->get_progress( $migration_id );
	}

	/**
	 * Detect resumable in-progress migration state.
	 *
	 * @return array
	 */
	public function detect_in_progress_migration() {
		return $this->orchestrator->detect_in_progress_migration();
	}

	/**
	 * Cancel migration and reset progress.
	 *
	 * @param string $migration_id
	 * @return array
	 */
	public function cancel_migration( $migration_id = '' ) {
		return $this->orchestrator->cancel_migration( $migration_id );
	}

	/**
	 * Generate migration report.
	 *
	 * @return array
	 */
	public function generate_report() {
		return $this->orchestrator->generate_report();
	}

	/**
	 * Validate target site requirements.
	 *
	 * @return array
	 */
	public function validate_target_site_requirements() {
		return $this->orchestrator->validate_target_site_requirements();
	}

	/**
	 * Migrate a single post.
	 *
	 * @param \WP_Post    $post
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @return array|\WP_Error
	 */
	public function migrate_single_post( $post, $migration_key, $target_url = null ) {
		return $this->orchestrator->migrate_single_post( $post, $migration_key, $target_url );
	}

	/**
	 * Resume a JS-driven batch loop after a timeout or error.
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function resume_migration_execution( $migration_id ) {
		return $this->batch_processor->resume_migration_execution( (string) $migration_id );
	}

	/**
	 * Process a single batch for the JS-driven batch loop.
	 *
	 * Delegates to EFS_Batch_Processor (DI path) or EFS_Legacy_Batch_Fallback.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $batch        Batch options. Accepts key 'batch_size' (int).
	 * @return array|\WP_Error
	 */
	public function process_batch( $migration_id, $batch ) {
		$active                   = $this->migration_repository->get_active_migration();
		$target_url               = is_array( $active ) && isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$migration_key            = is_array( $active ) && isset( $active['migration_key'] ) ? (string) $active['migration_key'] : '';
		$active_migration_options = is_array( $active ) && isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();

		if ( '' !== $target_url || '' !== $migration_key ) {
			return $this->batch_processor->process_batch(
				(string) $migration_id,
				$batch,
				$target_url,
				$migration_key,
				$active_migration_options
			);
		}

		// Legacy path: no active migration record (e.g. migration_manager.php direct path).
		return $this->legacy_batch_fallback->process_batch( (string) $migration_id, $batch );
	}
}
