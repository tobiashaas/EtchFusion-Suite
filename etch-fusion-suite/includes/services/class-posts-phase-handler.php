<?php
/**
 * Posts Phase Handler for Bricks to Etch Migration plugin
 *
 * Handles the posts batch phase: processes one batch of Bricks/Gutenberg posts
 * per request by delegating each item to EFS_Content_Service.
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
 * Phase handler for the posts migration batch phase.
 */
class EFS_Posts_Phase_Handler implements Phase_Handler_Interface {

	/**
	 * Checkpoint phase key for the posts phase.
	 */
	private const PHASE_KEY = 'posts';

	/**
	 * Default number of posts to process per batch call.
	 * The caller may override this via $batch['batch_size'].
	 */
	private const DEFAULT_BATCH_SIZE = 10;

	/**
	 * Maximum number of attempts before marking a post as permanently failed.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Start of the posts phase percentage range.
	 */
	private const PERCENTAGE_START = 80;

	/**
	 * End of the posts phase percentage range.
	 */
	private const PERCENTAGE_END = 95;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/**
	 * @param EFS_Content_Service $content_service
	 * @param EFS_Error_Handler   $error_handler
	 */
	public function __construct( EFS_Content_Service $content_service, EFS_Error_Handler $error_handler ) {
		$this->content_service = $content_service;
		$this->error_handler   = $error_handler;
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
		return self::DEFAULT_BATCH_SIZE;
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
	 * Converts a single Bricks or Gutenberg post and transfers it to the target site.
	 */
	public function process_item( $id, array $context ) {
		$result = $this->content_service->convert_bricks_to_gutenberg(
			$id,
			$context['api_client'],
			$context['target_url'],
			$context['migration_key'],
			$context['post_type_mappings']
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_remaining( array $checkpoint ) {
		return (array) ( $checkpoint['remaining_post_ids'] ?? array() );
	}

	/**
	 * {@inheritdoc}
	 */
	public function save_progress( array &$checkpoint, array $remaining, $processed ) {
		$checkpoint['remaining_post_ids'] = $remaining;
		$checkpoint['processed_count']    = $processed;
	}
}
