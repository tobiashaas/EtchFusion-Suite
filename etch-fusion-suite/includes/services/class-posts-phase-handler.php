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
	 * Convert and transfer a batch of posts in a single HTTP request.
	 *
	 * Converts all posts locally first (no HTTP), then sends the entire batch to
	 * the target site via EFS_API_Client::send_posts_batch(). This reduces N HTTP
	 * round-trips to 1, cutting per-batch overhead by ~90%.
	 *
	 * @param int[]  $ids     Post IDs to process in this batch.
	 * @param array  $context Same context array used by process_item().
	 * @return array<int, true|\WP_Error>  Map of post ID => true on success, WP_Error on failure.
	 */
	public function process_items_batch( array $ids, array $context ): array {
		$prepared = array();
		$results  = array();

		// Prime WordPress post and meta caches for all IDs at once.
		// Converts N individual get_post / get_post_meta calls into 2 bulk queries,
		// eliminating the N+1 pattern that dominates batch processing time.
		_prime_post_caches( array_map( 'intval', $ids ) );

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$item = $this->content_service->prepare_post_for_batch( $id, $context['post_type_mappings'] );
			if ( is_wp_error( $item ) ) {
				$results[ $id ] = $item;
			} else {
				$prepared[ $id ] = $item;
			}
		}

		if ( empty( $prepared ) ) {
			return $results;
		}

		$batch_response = $context['api_client']->send_posts_batch(
			$context['target_url'],
			$context['migration_key'],
			array_values( $prepared )
		);

		if ( is_wp_error( $batch_response ) ) {
			foreach ( array_keys( $prepared ) as $id ) {
				$results[ (int) $id ] = $batch_response;
			}
			return $results;
		}

		foreach ( $batch_response as $item_result ) {
			$source_id = isset( $item_result['source_id'] ) ? (int) $item_result['source_id'] : 0;
			if ( $source_id <= 0 ) {
				continue;
			}
			if ( isset( $item_result['error'] ) ) {
				$results[ $source_id ] = new \WP_Error( 'batch_item_failed', (string) $item_result['error'] );
			} else {
				$results[ $source_id ] = true;
			}
		}

		// Posts that were prepared but received no result in the response.
		foreach ( array_keys( $prepared ) as $id ) {
			if ( ! isset( $results[ (int) $id ] ) ) {
				$results[ (int) $id ] = new \WP_Error(
					'batch_item_missing',
					/* translators: %d is the post ID. */
					sprintf( __( 'No result received for post %d in batch response.', 'etch-fusion-suite' ), $id )
				);
			}
		}

		return $results;
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
