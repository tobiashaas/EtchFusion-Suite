<?php
/**
 * Query Paginator Utility
 *
 * Provides a reusable pagination wrapper for large WordPress queries.
 * Handles posts_per_page batching and automatic offset calculation.
 *
 * @package Bricks2Etch\Utils
 */

namespace Bricks2Etch\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Query_Paginator
 *
 * Pagination helper that batches large queries into manageable chunks.
 */
class Query_Paginator {

	const DEFAULT_PER_PAGE = 100;

	/**
	 * Get posts in batches using WP_Query.
	 *
	 * Yields each page of results. Returns early if no results found on first page.
	 *
	 * @param array $args Query arguments (get_posts format). Will override posts_per_page and paged.
	 * @param int   $per_page Items per batch (default: 100).
	 * @return \Generator Yields array of post IDs per batch.
	 */
	public static function get_posts_paginated( array $args, int $per_page = self::DEFAULT_PER_PAGE ) {
		// Ensure we use get_posts compatible args
		$args['posts_per_page'] = $per_page;
		$args['paged']          = 1;

		do {
			$posts = get_posts( $args );

			if ( empty( $posts ) ) {
				break;
			}

			// Convert to IDs if posts are returned as objects
			$post_ids = is_numeric( reset( $posts ) )
				? array_map( 'intval', $posts )
				: wp_list_pluck( $posts, 'ID' );

			yield $post_ids;

			// Stop if we got fewer items than per_page (we've reached the end)
			if ( count( $posts ) < $per_page ) {
				break;
			}

			++$args['paged'];
		} while ( true );

		wp_reset_postdata();
	}

	/**
	 * Get attachments in batches using WP_Query.
	 *
	 * Specialized variant for attachments with built-in status/type filtering.
	 *
	 * @param array $meta_query Optional meta_query array for filtering.
	 * @param int   $per_page Items per batch (default: 100).
	 * @return \Generator Yields array of attachment IDs per batch.
	 */
	public static function get_attachments_paginated( array $meta_query = array(), int $per_page = self::DEFAULT_PER_PAGE ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'posts_per_page' => $per_page,
			'paged'          => 1,
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		do {
			$posts = get_posts( $args );

			if ( empty( $posts ) ) {
				break;
			}

			$post_ids = array_map( 'intval', $posts );
			yield $post_ids;

			if ( count( $posts ) < $per_page ) {
				break;
			}

			++$args['paged'];
		} while ( true );

		wp_reset_postdata();
	}

	/**
	 * Get posts by query generator for child posts (e.g., attachments under a post).
	 *
	 * @param int   $post_parent Parent post ID.
	 * @param array $args Additional query args (e.g., post_type).
	 * @param int   $per_page Items per batch (default: 100).
	 * @return \Generator Yields array of post IDs per batch.
	 */
	public static function get_children_paginated( int $post_parent, array $args = array(), int $per_page = self::DEFAULT_PER_PAGE ) {
		$args['post_parent']    = $post_parent;
		$args['posts_per_page'] = $per_page;
		$args['paged']          = 1;

		do {
			$posts = get_children( $args, ARRAY_A );

			if ( empty( $posts ) ) {
				break;
			}

			// get_children returns assoc array keyed by ID
			$post_ids = array_map( 'intval', array_keys( $posts ) );
			yield $post_ids;

			if ( count( $posts ) < $per_page ) {
				break;
			}

			++$args['paged'];
		} while ( true );
	}

	/**
	 * Helper: safely process all results from a paginated query.
	 *
	 * Useful when you want to process results synchronously instead of using a generator.
	 *
	 * @param array    $args Query arguments.
	 * @param callable $callback Function called with each batch of post IDs. Should return true to continue.
	 * @param int      $per_page Items per batch (default: 100).
	 * @return int Total items processed.
	 */
	public static function process_paginated( array $args, callable $callback, int $per_page = self::DEFAULT_PER_PAGE ): int {
		$total = 0;

		foreach ( self::get_posts_paginated( $args, $per_page ) as $batch ) {
			$total += count( $batch );

			// Call the callback with the batch
			$continue = call_user_func( $callback, $batch );

			if ( false === $continue ) {
				break;
			}
		}

		return $total;
	}
}
