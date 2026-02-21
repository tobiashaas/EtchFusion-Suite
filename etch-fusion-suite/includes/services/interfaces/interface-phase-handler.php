<?php
/**
 * Phase Handler Interface for Bricks to Etch Migration plugin
 *
 * Defines the contract for batch phase handler implementations, allowing
 * process_batch() to delegate phase-specific logic to dedicated handlers
 * while keeping orchestration (attempts, retries, progress) in the service.
 *
 * @package Bricks2Etch\Services\Interfaces
 */

namespace Bricks2Etch\Services\Interfaces;

use WP_Error;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface that all batch phase handler implementations must follow.
 */
interface Phase_Handler_Interface {
	/**
	 * Returns the phase key that matches $checkpoint['phase'].
	 *
	 * Example: "media", "posts".
	 *
	 * @return string
	 */
	public function get_phase_key();

	/**
	 * Returns the percentage range [start, end] for this phase.
	 *
	 * Used by the service to calculate progress percentage within the phase.
	 * Both values are integers (0–100).
	 *
	 * Example: [60, 79] for media, [80, 95] for posts.
	 *
	 * @return array
	 */
	public function get_percentage_range();

	/**
	 * Returns the default number of items to process per batch call.
	 *
	 * The service may override this for posts via $batch['batch_size'].
	 *
	 * @return int
	 */
	public function get_batch_size();

	/**
	 * Returns the maximum number of processing attempts per item.
	 *
	 * When an item's attempt count reaches this value the item is moved
	 * to the failed IDs list rather than being retried.
	 *
	 * @return int
	 */
	public function get_max_retries();

	/**
	 * Processes a single item for this phase.
	 *
	 * Implementations should perform the actual migration work (e.g. transfer
	 * a media file or convert a post) and return true on success or a WP_Error
	 * describing the failure.
	 *
	 * @param int   $id      The item ID (attachment ID or post ID).
	 * @param array $context Runtime context including target_url, migration_key,
	 *                       api_client, and post_type_mappings.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function process_item( $id, array $context );

	/**
	 * Returns the phase-specific remaining item IDs from the checkpoint.
	 *
	 * Reads the phase-specific checkpoint key (e.g. remaining_media_ids or
	 * remaining_post_ids) and returns the current list of IDs still to process.
	 *
	 * @param array $checkpoint Current checkpoint data.
	 *
	 * @return array
	 */
	public function get_remaining( array $checkpoint );

	/**
	 * Writes phase-specific progress keys back into the checkpoint.
	 *
	 * Updates the remaining IDs list and the processed count key for this
	 * phase. The checkpoint is passed by reference so changes take effect
	 * immediately on the caller's copy.
	 *
	 * Note: attempts and failed IDs keys are mutated by the service before
	 * this call; this method only writes remaining and processed count.
	 *
	 * @param array $checkpoint The current checkpoint array (passed by reference).
	 * @param array $remaining  Updated list of remaining item IDs after this batch.
	 * @param int   $processed  Cumulative count of successfully processed items.
	 *
	 * @return void
	 */
	public function save_progress( array &$checkpoint, array $remaining, $processed );
}
