<?php
/**
 * Checkpoint Repository Interface
 *
 * Defines the contract for managing migration checkpoints used by the
 * JS-driven batch loop.
 *
 * @package Bricks2Etch\Repositories\Interfaces
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories\Interfaces;

/**
 * Interface Checkpoint_Repository_Interface
 *
 * Provides methods for accessing and managing migration checkpoint data.
 */
interface Checkpoint_Repository_Interface {

	/**
	 * Get migration checkpoint.
	 *
	 * @return array Checkpoint data, or empty array if none exists.
	 */
	public function get_checkpoint(): array;

	/**
	 * Save migration checkpoint for JS-driven batch loop.
	 *
	 * @param array $checkpoint Checkpoint data.
	 * @return bool True on success, false on failure.
	 */
	public function save_checkpoint( array $checkpoint ): bool;

	/**
	 * Delete migration checkpoint.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_checkpoint(): bool;
}
