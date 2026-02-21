<?php
/**
 * Progress Repository Interface
 *
 * Defines the contract for managing migration progress, steps, stats,
 * active migration state, receiving state, and imported data.
 *
 * @package Bricks2Etch\Repositories\Interfaces
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories\Interfaces;

/**
 * Interface Progress_Repository_Interface
 *
 * Provides methods for accessing and managing migration progress data.
 */
interface Progress_Repository_Interface {

	/**
	 * Get migration progress.
	 *
	 * @return array Progress data array.
	 */
	public function get_progress(): array;

	/**
	 * Save migration progress.
	 *
	 * @param array $progress Progress data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_progress( array $progress ): bool;

	/**
	 * Delete migration progress.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_progress(): bool;

	/**
	 * Get migration steps.
	 *
	 * @return array Steps data array.
	 */
	public function get_steps(): array;

	/**
	 * Save migration steps.
	 *
	 * @param array $steps Steps data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_steps( array $steps ): bool;

	/**
	 * Delete migration steps.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_steps(): bool;

	/**
	 * Get migration statistics.
	 *
	 * @return array Stats data array.
	 */
	public function get_stats(): array;

	/**
	 * Save migration statistics.
	 *
	 * @param array $stats Stats data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_stats( array $stats ): bool;

	/**
	 * Retrieve active migration metadata.
	 *
	 * @return array
	 */
	public function get_active_migration(): array;

	/**
	 * Store active migration metadata.
	 *
	 * @param array $data Migration metadata to persist.
	 * @return bool
	 */
	public function save_active_migration( array $data ): bool;

	/**
	 * Get receiving state for target-site imports.
	 *
	 * @return array
	 */
	public function get_receiving_state(): array;

	/**
	 * Save receiving state for target-site imports.
	 *
	 * @param array $state Receiving state payload.
	 * @return bool
	 */
	public function save_receiving_state( array $state ): bool;

	/**
	 * Clear receiving state.
	 *
	 * @return bool
	 */
	public function clear_receiving_state(): bool;

	/**
	 * Get imported data by type.
	 *
	 * @param string $type Data type: 'cpts', 'acf_field_groups', or 'metabox_configs'.
	 * @return array Imported data array.
	 */
	public function get_imported_data( string $type ): array;

	/**
	 * Save imported data by type.
	 *
	 * @param string $type Data type: 'cpts', 'acf_field_groups', or 'metabox_configs'.
	 * @param array  $data Data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_imported_data( string $type, array $data ): bool;
}
