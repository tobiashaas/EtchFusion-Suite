<?php
/**
 * Token Repository Interface
 *
 * Defines the contract for managing migration authentication tokens.
 *
 * @package Bricks2Etch\Repositories\Interfaces
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories\Interfaces;

/**
 * Interface Token_Repository_Interface
 *
 * Provides methods for accessing and managing migration token data.
 */
interface Token_Repository_Interface {

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
	 * Cleanup expired token transients.
	 *
	 * @return int Number of expired transients deleted.
	 */
	public function cleanup_expired_tokens(): int;
}
