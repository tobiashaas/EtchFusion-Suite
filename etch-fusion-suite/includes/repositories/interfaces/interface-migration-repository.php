<?php
/**
 * Migration Repository Interface
 *
 * Defines the contract for managing migration progress, steps, stats, and tokens.
 *
 * @package Bricks2Etch\Repositories\Interfaces
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories\Interfaces;

/**
 * Interface Migration_Repository_Interface
 *
 * Provides methods for accessing and managing migration-related data.
 */
interface Migration_Repository_Interface {

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
	 * Get token data.
	 *
	 * @return array Token data array.
	 */
	public function get_token_data(): array;

	/**
	 * Save token data.
	 *
	 * @param array $token_data Token data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_token_data( array $token_data ): bool;

	/**
	 * Store active migration metadata.
	 *
	 * @param array $data Migration metadata to persist.
	 * @return bool
	 */
	public function save_active_migration( array $data ): bool;

	/**
	 * Retrieve active migration metadata.
	 *
	 * @return array
	 */
	public function get_active_migration(): array;

	/**
	 * Get token value.
	 *
	 * @return string Token value or empty string if not set.
	 */
	public function get_token_value(): string;

	/**
	 * Save token value.
	 *
	 * @param string $token Token value to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_token_value( string $token ): bool;

	/**
	 * Delete token data and value.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_token_data(): bool;

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

	/**
	 * Cleanup expired token transients.
	 *
	 * @return int Number of expired transients deleted.
	 */
	public function cleanup_expired_tokens(): int;
}
