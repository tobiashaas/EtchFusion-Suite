<?php
/**
 * Content AJAX Handler
 *
 * Handles content migration AJAX requests
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Services\EFS_Migration_Service;
use Bricks2Etch\Services\EFS_Content_Service;
use Bricks2Etch\Api\EFS_API_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Content_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * Migration service instance
	 *
	 * @var mixed
	 */
	private $migration_service;

	/**
	 * Content service instance
	 *
	 * @var EFS_Content_Service|null
	 */
	private $content_service;

	/**
	 * API client instance
	 *
	 * @var EFS_API_Client|null
	 */
	private $api_client;

	/**
	 * Constructor
	 *
	 * @param mixed $migration_service Migration service instance.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $migration_service = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		if ( $migration_service ) {
			$this->migration_service = $migration_service;
		} elseif ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$this->migration_service = etch_fusion_suite_container()->get( 'migration_service' );
			} catch ( \Exception $exception ) {
				$this->migration_service = null;
			}
		}

		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$container = etch_fusion_suite_container();
				if ( ! $this->content_service && $container->has( 'content_service' ) ) {
					$this->content_service = $container->get( 'content_service' );
				}
				if ( $container->has( 'api_client' ) ) {
					$this->api_client = $container->get( 'api_client' );
				}
			} catch ( \Exception $exception ) {
				$this->content_service = $this->content_service ?? null;
				$this->api_client      = $this->api_client ?? null;
			}
		}

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register WordPress hooks
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_migrate_batch', array( $this, 'migrate_batch' ) );
		add_action( 'wp_ajax_efs_get_bricks_posts', array( $this, 'get_bricks_posts' ) );
	}

	/**
	 * AJAX handler for batch migration (one post at a time)
	 */
	public function migrate_batch() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (30 requests per minute)
		if ( ! $this->check_rate_limit( 'migrate_batch', 30, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'post_id'    => $this->get_post( 'post_id', 0 ),
					'target_url' => $this->get_post( 'target_url', '' ),
					'api_key'    => $this->get_post( 'api_key', '' ),
				),
				array(
					'post_id'    => array(
						'type'     => 'integer',
						'required' => true,
						'min'      => 1,
					),
					'target_url' => array(
						'type'     => 'url',
						'required' => true,
					),
					'api_key'    => array(
						'type'     => 'api_key',
						'required' => true,
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$post_id    = $validated['post_id'];
		$target_url = $validated['target_url'];
		$api_key    = $validated['api_key'];

		// Get the post
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error(
				array(
					'message' => __( 'Requested post could not be found.', 'etch-fusion-suite' ),
					'code'    => 'post_not_found',
				),
				404
			);
			return;
		}

		// Check if it's media/attachment
		if ( 'attachment' === $post->post_type ) {
			// Media migration handled separately
			wp_send_json_success(
				array(
					'message' => 'Media migration handled separately',
					'skipped' => true,
				)
			);
			return;
		}

		// Migrate this single post
		try {
			// Convert to internal URL
			$internal_url = $this->convert_to_internal_url( $target_url );

			// Save settings temporarily
			update_option(
				'efs_settings',
				array(
					'target_url' => $internal_url,
					'api_key'    => $api_key,
				),
				false
			);

			if ( ! $this->content_service || ! $this->migration_service instanceof EFS_Migration_Service ) {
				$this->log_security_event(
					'ajax_action',
					'Batch migration aborted: migration services unavailable.',
					array( 'post_id' => $post_id )
				);
				wp_send_json_error(
					array(
						'message' => __( 'Migration services unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
						'code'    => 'service_unavailable',
					),
					503
				);
				return;
			}

			$api_client = $this->api_client;
			if ( ! $api_client || ! $api_client instanceof EFS_API_Client ) {
				$this->log_security_event(
					'ajax_action',
					'Batch migration aborted: API client unavailable.',
					array( 'post_id' => $post_id )
				);
				wp_send_json_error(
					array(
						'message' => __( 'API client unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
						'code'    => 'api_client_unavailable',
					),
					503
				);
				return;
			}

			$result = $this->content_service->convert_bricks_to_gutenberg( $post_id, $api_client, $internal_url, $api_key );

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'migration_failed';
				$status     = 400;
				$error_data = $result->get_error_data();

				if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
					$status = (int) $error_data['status'];
				}

				$this->log_security_event(
					'ajax_action',
					'Batch migration failed: ' . $result->get_error_message(),
					array(
						'post_id' => $post_id,
						'code'    => $error_code,
					)
				);
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'code'    => $error_code,
					),
					$status
				);
				return;
			}

			$this->log_security_event(
				'ajax_action',
				'Post migrated successfully',
				array(
					'post_id'    => $post_id,
					'post_title' => $post->post_title,
				)
			);

			wp_send_json_success(
				array(
					'message'    => __( 'Post migrated successfully.', 'etch-fusion-suite' ),
					'post_title' => $post->post_title,
					'target_id'  => $result['target_id'] ?? null,
				)
			);
		} catch ( \Exception $e ) {
			$this->log_security_event(
				'ajax_action',
				'Batch migration exception: ' . $e->getMessage(),
				array(
					'post_id' => $post_id,
				),
				'high'
			);
			wp_send_json_error(
				array(
					'message' => sprintf( 'Exception: %s', $e->getMessage() ),
					'code'    => 'migration_exception',
				),
				500
			);
		}
	}

	/**
	 * AJAX handler to get list of ALL content (Bricks, Gutenberg, Media)
	 */
	public function get_bricks_posts() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (60 requests per minute)
		if ( ! $this->check_rate_limit( 'get_bricks_posts', 60, 60 ) ) {
			return;
		}

		$content_service = $this->content_service;
		if ( ! $content_service || ! $content_service instanceof EFS_Content_Service ) {
			$this->log_security_event( 'ajax_action', 'Content listing failed: content service unavailable.' );
			wp_send_json_error(
				array(
					'message' => __( 'Content service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$all_content     = $content_service->get_all_content();
		$bricks_posts    = $all_content['bricks_posts'] ?? array();
		$gutenberg_posts = $all_content['gutenberg_posts'] ?? array();
		$media           = $all_content['media'] ?? array();

		$posts_data = array();

		// Add Bricks posts
		foreach ( $bricks_posts as $post ) {
			$posts_data[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'type'       => $post->post_type,
				'has_bricks' => true,
			);
		}

		// Add Gutenberg posts
		foreach ( $gutenberg_posts as $post ) {
			$posts_data[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'type'       => $post->post_type,
				'has_bricks' => false,
			);
		}

		// Add Media
		foreach ( $media as $attachment ) {
			$posts_data[] = array(
				'id'         => $attachment->ID,
				'title'      => ! empty( $attachment->post_title ) ? $attachment->post_title : basename( $attachment->guid ),
				'type'       => 'attachment',
				'has_bricks' => false,
			);
		}

		wp_send_json_success(
			array(
				'posts'           => $posts_data,
				'count'           => count( $posts_data ),
				'bricks_count'    => count( $bricks_posts ),
				'gutenberg_count' => count( $gutenberg_posts ),
				'media_count'     => count( $media ),
			)
		);
	}
}
