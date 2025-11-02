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
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		$this->error_handler = $error_handler;
		$this->api_client    = $api_client;
	}

	/**
	 * Migrate all media files from source to target site
	 */
	public function migrate_media( $target_url, $api_key ) {
		$this->error_handler->log_info( 'Media Migration: Starting' );
		$media_files = $this->get_media_files();
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
		$batches    = array_chunk( $media_files, $batch_size );

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}

		foreach ( $batches as $batch_index => $batch ) {
			foreach ( $batch as $media_id => $media_data ) {
				// Check if media was already migrated (deduplication)
				$existing_mapping = $this->get_media_mapping( $media_id );
				if ( null !== $existing_mapping ) {
					$this->error_handler->log_info( 'Media Migration: Skipped media ID ' . $media_id . ' (already migrated as ID: ' . $existing_mapping . ')' );
					++$skipped_media;
					continue;
				}

				$this->error_handler->log_info( 'Media Migration: Migrating media ID ' . $media_id . ' (' . $media_data['title'] . ')' );
				$result = $this->migrate_single_media( $media_id, $media_data, $target_url, $api_key );

				if ( is_wp_error( $result ) ) {
					$this->error_handler->log_error(
						'E401',
						array(
							'media_id'    => $media_id,
							'media_title' => $media_data['title'],
							'error'       => $result->get_error_message(),
							'action'      => 'Failed to migrate media file',
						)
					);
					continue;
				}

				$this->error_handler->log_info( 'Media Migration: Success for media ID ' . $media_id );

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
	 * Get all media files from the source site
	 */
	private function get_media_files() {
		$media_query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_wp_attached_file',
						'compare' => 'EXISTS',
					),
				),
			)
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
	 * Migrate a single media file
	 */
	private function migrate_single_media( $media_id, $media_data, $target_url, $api_key ) {
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
		$result = $api_client->send_media_data( $target_url, $api_key, $media_data );

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
		$media_query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
			)
		);

		$total_media   = $media_query->found_posts;
		$total_size    = 0;
		$media_by_type = array();

		if ( $media_query->have_posts() ) {
			while ( $media_query->have_posts() ) {
				$media_query->the_post();
				$attachment_id = get_the_ID();
				$mime_type     = get_post_mime_type( $attachment_id );
				$file_size     = filesize( get_attached_file( $attachment_id ) );

				$total_size += $file_size;

				// Group by type
				$type = explode( '/', $mime_type )[0];
				if ( ! isset( $media_by_type[ $type ] ) ) {
					$media_by_type[ $type ] = 0;
				}
				++$media_by_type[ $type ];
			}
		}

		wp_reset_postdata();

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
