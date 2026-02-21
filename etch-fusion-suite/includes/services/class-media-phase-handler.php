<?php
/**
 * Media Phase Handler for Bricks to Etch Migration plugin
 *
 * Handles the media batch phase: processes one small batch of media
 * attachments per request by delegating each item to EFS_Media_Service.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Services\Interfaces\Phase_Handler_Interface;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase handler for the media migration batch phase.
 */
class EFS_Media_Phase_Handler implements Phase_Handler_Interface {

	/**
	 * Checkpoint phase key for the media phase.
	 */
	private const PHASE_KEY = 'media';

	/**
	 * Number of media items to process per batch call.
	 * Kept small to limit base64 memory usage per request.
	 */
	private const BATCH_SIZE = 3;

	/**
	 * Maximum number of attempts before marking a media item as permanently failed.
	 */
	private const MAX_RETRIES = 2;

	/**
	 * Start of the media phase percentage range.
	 */
	private const PERCENTAGE_START = 60;

	/**
	 * End of the media phase percentage range.
	 */
	private const PERCENTAGE_END = 79;

	/** @var EFS_Media_Service */
	private $media_service;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/**
	 * @param EFS_Media_Service $media_service
	 * @param EFS_Error_Handler $error_handler
	 */
	public function __construct( EFS_Media_Service $media_service, EFS_Error_Handler $error_handler ) {
		$this->media_service = $media_service;
		$this->error_handler = $error_handler;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_phase_key() {
		return self::PHASE_KEY;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_percentage_range() {
		return array( self::PERCENTAGE_START, self::PERCENTAGE_END );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_batch_size() {
		return self::BATCH_SIZE;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_max_retries() {
		return self::MAX_RETRIES;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Migrates a single media attachment to the target site.
	 */
	public function process_item( $id, array $context ) {
		$result = $this->media_service->migrate_media_by_id(
			$id,
			$context['target_url'],
			$context['migration_key']
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_remaining( array $checkpoint ) {
		return (array) ( $checkpoint['remaining_media_ids'] ?? array() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_progress( array &$checkpoint, array $remaining, $processed ) {
		$checkpoint['remaining_media_ids']   = $remaining;
		$checkpoint['processed_media_count'] = $processed;
	}
}
