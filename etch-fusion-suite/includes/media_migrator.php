<?php
/**
 * Media Migrator for Bricks to Etch Migration Plugin
 *
 * Handles migration of media files and attachments
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Api\EFS_API_Client;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Media_Migrator {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * API client instance
	 */
	private $api_client;

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, ?EFS_API_Client $api_client = null ) {
		$this->error_handler = $error_handler;
		$this->api_client    = $api_client;
	}

	/**
	 * Migrate all media files from source to target site
	 */
	public function migrate_media( $target_url, $jwt_token, $selected_post_types = array() ) {
		$this->error_handler->log_info( 'Media Migration: Starting' );
		$media_files = $this->get_media_files( $selected_post_types );
		$this->error_handler->log_info( 'Media Migration: Found ' . count( $media_files ) . ' media files' );

		if ( empty( $media_files ) ) {
			$this->error_handler->log_info( 'Media Migration: No media files found' );
			return array(
				'total'    => 0,
				'migrated' => 0,
				'failed'   => 0,
				'skipped'  => 0,
			);
		}

		$total_media    = count( $media_files );
		$migrated_media = 0;
		$skipped_media  = 0;
		$this->error_handler->log_info( 'Media Migration: Processing ' . $total_media . ' media files' );

		// Process in batches to avoid memory issues
		$batch_size = 5; // Process 5 media files at a time
		$batches    = array_chunk( $media_files, $batch_size, true );

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}

		foreach ( $batches as $batch_index => $batch ) {
			foreach ( $batch as $media_id => $media_data ) {
				$source_media_id = isset( $media_data['id'] ) ? (int) $media_data['id'] : (int) $media_id;

				// Check if media was already migrated (deduplication)
				$existing_mapping = $this->get_media_mapping( $source_media_id );
				if ( null !== $existing_mapping ) {
					$this->error_handler->log_info( 'Media Migration: Skipped media ID ' . $source_media_id . ' (already migrated as ID: ' . $existing_mapping . ')' );
					++$skipped_media;
					continue;
				}

				$this->error_handler->log_info( 'Media Migration: Migrating media ID ' . $source_media_id . ' (' . $media_data['title'] . ')' );
				$result = $this->migrate_single_media( $source_media_id, $media_data, $target_url, $jwt_token );

				if ( is_wp_error( $result ) ) {
					$this->error_handler->log_warning(
						'W012',
						array(
							'media_id'  => $media_id,
							'source_id' => $source_media_id,
							'title'     => $media_data['title'],
							'error'     => $result->get_error_message(),
							'action'    => 'Failed to migrate media file',
						)
					);
					continue;
				}

				$this->error_handler->log_info( 'Media Migration: Success for media ID ' . $source_media_id );

				++$migrated_media;

				// Memory cleanup
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}

			// Small delay between batches
			usleep( 200000 ); // 0.2 seconds
		}

		$this->error_handler->log_info( 'Media Migration: Complete - Total: ' . $total_media . ', Migrated: ' . $migrated_media . ', Skipped: ' . $skipped_media . ', Failed: ' . ( $total_media - $migrated_media - $skipped_media ) );

		return array(
			'total_media'    => $total_media,
			'migrated_media' => $migrated_media,
			'skipped_media'  => $skipped_media,
			'failed_media'   => $total_media - $migrated_media - $skipped_media,
		);
	}

	/**
	 * Get attachment IDs only (lightweight, no full post data).
	 *
	 * @param array $selected_post_types Optional. Selected post types; empty = all attachments.
	 * @return array<int> Attachment IDs.
	 */
	public function get_media_ids( array $selected_post_types = array() ) {
		$selected_post_types = is_array( $selected_post_types ) ? array_values( array_filter( array_map( 'sanitize_key', $selected_post_types ) ) ) : array();
		if ( ! empty( $selected_post_types ) ) {
			return $this->get_media_ids_for_selected_post_types( $selected_post_types );
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'compare' => 'EXISTS',
				),
			),
		);

		$media_query = new \WP_Query( $query_args );
		$ids         = is_array( $media_query->posts ) ? array_map( 'intval', $media_query->posts ) : array();
		wp_reset_postdata();

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return $id > 0 && 'attachment' === get_post_type( $id );
				}
			)
		);
	}

	/**
	 * Migrate a single media item by ID. Skips if already mapped.
	 *
	 * @param int    $media_id    Attachment ID.
	 * @param string $target_url  Target site URL.
	 * @param string $jwt_token   Migration JWT.
	 * @return array|\WP_Error Result with keys migrated, skipped, failed, title; or WP_Error.
	 */
	public function migrate_media_by_id( $media_id, $target_url, $jwt_token ) {
		$media_id = (int) $media_id;
		if ( $media_id <= 0 ) {
			return array(
				'migrated' => 0,
				'skipped'  => 0,
				'failed'   => 1,
				'title'    => '',
			);
		}

		$existing_mapping = $this->get_media_mapping( $media_id );
		if ( null !== $existing_mapping ) {
			$this->error_handler->log_info( 'Media Migration: Skipped media ID ' . $media_id . ' (already migrated as ID: ' . $existing_mapping . ')' );
			$title = get_the_title( $media_id );
			return array(
				'migrated' => 0,
				'skipped'  => 1,
				'failed'   => 0,
				'title'    => is_string( $title ) ? $title : '',
			);
		}

		$post = get_post( $media_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array(
				'migrated' => 0,
				'skipped'  => 0,
				'failed'   => 1,
				'title'    => '',
			);
		}

		$file_path  = get_attached_file( $media_id );
		$media_data = array(
			'id'          => $media_id,
			'title'       => get_the_title( $media_id ),
			'alt_text'    => get_post_meta( $media_id, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'file_path'   => get_post_meta( $media_id, '_wp_attached_file', true ),
			'file_url'    => wp_get_attachment_url( $media_id ),
			'mime_type'   => get_post_mime_type( $media_id ),
			'file_size'   => is_string( $file_path ) && file_exists( $file_path ) ? filesize( $file_path ) : 0,
			'upload_date' => get_the_date( 'Y-m-d H:i:s', $media_id ),
			'post_parent' => wp_get_post_parent_id( $media_id ),
			'metadata'    => wp_get_attachment_metadata( $media_id ),
		);

		$result = $this->migrate_single_media( $media_id, $media_data, $target_url, $jwt_token );

		if ( is_wp_error( $result ) ) {
			$this->error_handler->log_warning(
				'W012',
				array(
					'media_id'  => $media_id,
					'source_id' => $media_id,
					'title'     => isset( $media_data['title'] ) ? (string) $media_data['title'] : '',
					'error'     => $result->get_error_message(),
					'action'    => 'Failed to migrate media file',
				)
			);
			return array(
				'migrated' => 0,
				'skipped'  => 0,
				'failed'   => 1,
				'title'    => isset( $media_data['title'] ) ? (string) $media_data['title'] : '',
			);
		}

		$this->error_handler->log_info( 'Media Migration: Success for media ID ' . $media_id );
		return array(
			'migrated' => 1,
			'skipped'  => 0,
			'failed'   => 0,
			'title'    => isset( $media_data['title'] ) ? (string) $media_data['title'] : '',
		);
	}

	/**
	 * Get all media files from the source site
	 */
	private function get_media_files( $selected_post_types = array() ) {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_wp_attached_file',
					'compare' => 'EXISTS',
				),
			),
		);

		$selected_post_types = is_array( $selected_post_types ) ? array_values( array_filter( array_map( 'sanitize_key', $selected_post_types ) ) ) : array();
		if ( ! empty( $selected_post_types ) ) {
			$scoped_ids = $this->get_media_ids_for_selected_post_types( $selected_post_types );
			if ( empty( $scoped_ids ) ) {
				return array();
			}
			$query_args['post__in'] = $scoped_ids;
		}

		$media_query = new \WP_Query(
			$query_args
		);

		$media_files = array();

		if ( $media_query->have_posts() ) {
			while ( $media_query->have_posts() ) {
				$media_query->the_post();
				$attachment_id = get_the_ID();

				$media_files[ $attachment_id ] = array(
					'id'          => $attachment_id,
					'title'       => get_the_title(),
					'alt_text'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
					'caption'     => get_the_excerpt(),
					'description' => get_the_content(),
					'file_path'   => get_post_meta( $attachment_id, '_wp_attached_file', true ),
					'file_url'    => wp_get_attachment_url( $attachment_id ),
					'mime_type'   => get_post_mime_type( $attachment_id ),
					'file_size'   => filesize( get_attached_file( $attachment_id ) ),
					'upload_date' => get_the_date( 'Y-m-d H:i:s' ),
					'post_parent' => wp_get_post_parent_id( $attachment_id ),
					'metadata'    => wp_get_attachment_metadata( $attachment_id ),
				);
			}
		}

		wp_reset_postdata();

		return $media_files;
	}

	/**
	 * Resolve attachment IDs that are relevant for selected source post types.
	 *
	 * @param array $selected_post_types Selected post types from wizard.
	 * @return array<int>
	 */
	private function get_media_ids_for_selected_post_types( array $selected_post_types ) {
		$posts = get_posts(
			array(
				'post_type'      => $selected_post_types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$media_ids = array();
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}

			$thumbnail_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumbnail_id > 0 ) {
				$media_ids[] = $thumbnail_id;
			}

			$attachments = get_children(
				array(
					'post_parent'    => $post_id,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			if ( is_array( $attachments ) ) {
				$media_ids = array_merge( $media_ids, array_map( 'intval', $attachments ) );
			}

			$post_content = (string) get_post_field( 'post_content', $post_id );
			if ( '' !== $post_content ) {
				$from_content = $this->find_media_in_content( $post_content );
				if ( is_array( $from_content ) ) {
					$media_ids = array_merge( $media_ids, array_map( 'intval', $from_content ) );
				}
			}

			$bricks_content = get_post_meta( $post_id, '_bricks_page_content_2', true );
			if ( empty( $bricks_content ) ) {
				$bricks_content = get_post_meta( $post_id, '_bricks_page_content', true );
			}
			if ( is_string( $bricks_content ) ) {
				$decoded = json_decode( $bricks_content, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$bricks_content = $decoded;
				} else {
					$bricks_content = maybe_unserialize( $bricks_content );
				}
			}
			$this->collect_attachment_ids_from_value( $bricks_content, $media_ids );
		}

		$media_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $media_ids ),
					static function ( $value ) {
						return $value > 0 && 'attachment' === get_post_type( $value );
					}
				)
			)
		);

		return $media_ids;
	}

	/**
	 * Recursively collect attachment IDs from arbitrary Bricks data payloads.
	 *
	 * @param mixed $value Input payload.
	 * @param array $media_ids Destination ID list (by reference).
	 * @return void
	 */
	private function collect_attachment_ids_from_value( $value, array &$media_ids ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->collect_attachment_ids_from_value( $item, $media_ids );
			}
			return;
		}

		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $item ) {
				$this->collect_attachment_ids_from_value( $item, $media_ids );
			}
			return;
		}

		if ( is_numeric( $value ) ) {
			$attachment_id = (int) $value;
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$media_ids[] = $attachment_id;
			}
			return;
		}

		if ( is_string( $value ) && strpos( $value, '/wp-content/uploads/' ) !== false ) {
			if ( preg_match_all( '/wp-content\/uploads\/[^"\s)]+/', $value, $matches ) ) {
				foreach ( $matches[0] as $url_path ) {
					$attachment_id = attachment_url_to_postid( home_url( '/' . ltrim( $url_path, '/' ) ) );
					if ( $attachment_id ) {
						$media_ids[] = (int) $attachment_id;
					}
				}
			}
		}
	}

	/**
	 * Migrate a single media file
	 */
	private function migrate_single_media( $media_id, $media_data, $target_url, $jwt_token ) {
		// Read file directly from filesystem instead of downloading via URL
		// Use the actual attachment ID from media_data, not the array key
		$attachment_id = $media_data['id'];
		$file_path     = get_attached_file( $attachment_id );

		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'file_not_found', 'Media file not found: ' . $file_path . ' (ID: ' . $attachment_id . ')' );
		}

		$file_content = file_get_contents( $file_path );

		if ( false === $file_content ) {
			return new \WP_Error( 'read_failed', 'Failed to read media file: ' . $file_path );
		}

		// Prepare media data for API
		$media_payload = array(
			'title'        => $media_data['title'],
			'alt_text'     => $media_data['alt_text'],
			'caption'      => $media_data['caption'],
			'description'  => $media_data['description'],
			'filename'     => basename( $media_data['file_path'] ),
			'mime_type'    => $media_data['mime_type'],
			'file_content' => base64_encode( $file_content ),
			'upload_date'  => $media_data['upload_date'],
			'post_parent'  => $media_data['post_parent'],
			'metadata'     => $media_data['metadata'],
		);

		// Send to target site via API
		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}

		$result = $api_client->send_media_data( $target_url, $jwt_token, $media_payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Store mapping for later reference
		$this->store_media_mapping( $media_id, $result['media_id'] );

		return $result;
	}

	/**
	 * Download file from source URL
	 */
	private function download_file( $file_url ) {
		$response = wp_remote_get(
			$file_url,
			array(
				'timeout'   => 30,
				'sslverify' => false, // In case of self-signed certificates
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new \WP_Error( 'download_failed', 'Failed to download file: HTTP ' . $response_code );
		}

		$file_content = wp_remote_retrieve_body( $response );

		if ( empty( $file_content ) ) {
			return new \WP_Error( 'empty_file', 'Downloaded file is empty' );
		}

		return $file_content;
	}

	/**
	 * Store media ID mapping for later reference
	 */
	private function store_media_mapping( $source_media_id, $target_media_id ) {
		$mappings                     = get_option( 'b2e_media_mappings', array() );
		$mappings[ $source_media_id ] = $target_media_id;
		update_option( 'b2e_media_mappings', $mappings );
	}

	/**
	 * Get media ID mapping
	 */
	public function get_media_mapping( $source_media_id ) {
		$mappings = get_option( 'b2e_media_mappings', array() );
		return isset( $mappings[ $source_media_id ] ) ? $mappings[ $source_media_id ] : null;
	}

	/**
	 * Get media statistics
	 */
	public function get_media_stats() {
		global $wpdb;

		$media_by_type = array();
		$total_media   = 0;
		$total_size    = 0;

		$mime_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_mime_type, COUNT(*) AS total
				FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status = %s
				GROUP BY post_mime_type",
				'attachment',
				'inherit'
			),
			ARRAY_A
		);

		if ( is_array( $mime_rows ) ) {
			foreach ( $mime_rows as $row ) {
				$count = isset( $row['total'] ) ? (int) $row['total'] : 0;
				if ( $count <= 0 ) {
					continue;
				}

				$mime_type = isset( $row['post_mime_type'] ) ? (string) $row['post_mime_type'] : '';
				$type_bits = explode( '/', $mime_type, 2 );
				$type      = ! empty( $type_bits[0] ) ? $type_bits[0] : 'other';

				if ( ! isset( $media_by_type[ $type ] ) ) {
					$media_by_type[ $type ] = 0;
				}

				$media_by_type[ $type ] += $count;
				$total_media            += $count;
			}
		}

		if ( $total_media > 0 ) {
			$sample_limit = (int) apply_filters( 'etch_fusion_suite_media_stats_sample_limit', 200 );
			$sample_limit = max( 1, min( 500, $sample_limit ) );

			$sample_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID
					FROM {$wpdb->posts}
					WHERE post_type = %s AND post_status = %s
					ORDER BY ID DESC
					LIMIT %d",
					'attachment',
					'inherit',
					$sample_limit
				)
			);

			$sample_total_size = 0;
			$sample_count      = 0;

			if ( is_array( $sample_ids ) ) {
				foreach ( $sample_ids as $attachment_id ) {
					$file_path = get_attached_file( (int) $attachment_id );
					if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
						continue;
					}

					$file_size = filesize( $file_path );
					if ( false === $file_size ) {
						continue;
					}

					$sample_total_size += (int) $file_size;
					++$sample_count;
				}
			}

			if ( $sample_count > 0 ) {
				$average_size = $sample_total_size / $sample_count;
				$total_size   = (int) round( $average_size * $total_media );
			}
		}

		return array(
			'total_media'   => $total_media,
			'total_size'    => $total_size,
			'total_size_mb' => round( $total_size / 1024 / 1024, 2 ),
			'media_by_type' => $media_by_type,
		);
	}

	/**
	 * Find media files referenced in Bricks content
	 */
	public function find_media_in_content( $content ) {
		$media_ids = array();

		// Find image IDs in content
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$media_ids = array_merge( $media_ids, $matches[1] );
		}

		// Find attachment IDs in shortcodes
		if ( preg_match_all( '/\[gallery ids="([^"]+)"]/', $content, $matches ) ) {
			$ids       = explode( ',', $matches[1][0] );
			$media_ids = array_merge( $media_ids, array_map( 'trim', $ids ) );
		}

		// Find attachment URLs
		if ( preg_match_all( '/wp-content\/uploads\/[^"\s]+/', $content, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id ) {
					$media_ids[] = $attachment_id;
				}
			}
		}

		return array_unique( $media_ids );
	}
}
