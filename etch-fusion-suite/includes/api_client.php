<?php
/**
 * API Client for Etch Fusion Suite
 *
 * Handles communication with target site API
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Core\EFS_Error_Handler;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_API_Client {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * Total items count to include in outgoing requests as X-EFS-Items-Total header.
	 *
	 * @var int
	 */
	private $items_total = 0;

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;
	}

	/**
	 * Set the total items count for the current migration phase.
	 * The value is injected as X-EFS-Items-Total on subsequent requests.
	 *
	 * @param int $total Total number of items in the current phase.
	 * @return void
	 */
	public function set_items_total( int $total ): void {
		$this->items_total = max( 0, $total );
	}

	/**
	 * Public wrapper for sending authorized requests to the Etch REST API.
	 *
	 * @param string      $url       Target site base URL.
	 * @param string|null $jwt_token Migration token for Authorization header.
	 * @param string      $endpoint  REST endpoint path (without prefix).
	 * @param string      $method    HTTP verb.
	 * @param array|null  $data      Optional payload.
	 * @param string      $api_base  Optional. API namespace (default 'efs/v1'). Use 'etch/v1' for Etch component API.
	 *
	 * @return array|\WP_Error
	 */
	public function send_authorized_request( $url, $jwt_token, $endpoint, $method = 'GET', $data = null, $api_base = 'efs/v1' ) {
		return $this->send_request( $url, $jwt_token, $endpoint, $method, $data, $api_base );
	}

	/**
	 * Validate migration token on the target site and return token data.
	 *
	 * @param string $target_url    Target site URL.
	 * @param string $migration_key Migration key JWT.
	 * @return array|\WP_Error
	 */
	public function validate_migration_key_on_target( $target_url, $migration_key ) {
		if ( empty( $migration_key ) ) {
			return new \WP_Error( 'missing_migration_key', __( 'Migration key is required.', 'etch-fusion-suite' ) );
		}

		if ( empty( $target_url ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Target URL is required to validate migration key.', 'etch-fusion-suite' ) );
		}

		$payload = array(
			'migration_key' => trim( $migration_key ),
		);

		$validation_response = $this->send_request( $target_url, null, '/validate', 'POST', $payload );

		if ( is_wp_error( $validation_response ) ) {
			return $validation_response;
		}

		if ( ! isset( $validation_response['success'] ) || true !== $validation_response['success'] ) {
			$message = isset( $validation_response['message'] ) ? (string) $validation_response['message'] : __( 'Migration key validation failed on target site.', 'etch-fusion-suite' );
			return new \WP_Error( 'migration_key_validation_failed', $message );
		}

		return $validation_response;
	}

	/**
	 * Send request to target site.
	 *
	 * @param string      $url       Target site base URL.
	 * @param string|null $jwt_token Migration token.
	 * @param string      $endpoint  Endpoint path (e.g. '/components').
	 * @param string      $method    HTTP method.
	 * @param array|null  $data      Optional payload.
	 * @param string      $api_base  API namespace (default 'efs/v1'). Use 'etch/v1' for Etch component API.
	 *
	 * @return array|\WP_Error
	 */
	private function send_request( $url, $jwt_token, $endpoint, $method = 'GET', $data = null, $api_base = 'efs/v1' ) {
		if ( function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {
			$url = etch_fusion_suite_convert_to_internal_url( $url );
		}
		$base     = rtrim( $url, '/' );
		$full_url = $base . '/wp-json/' . $api_base . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'efs_api_request_timeout', 60 ),
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		if ( ! empty( $jwt_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $jwt_token;
			// Authorization can be dropped in some server-to-server WordPress setups.
			// Mirror the token in a custom header as a fallback transport.
			$args['headers']['X-EFS-Migration-Key'] = $jwt_token;
		}
		// So the target (Etch) can show the real source site in "Receiving Migration" UI.
		$source_origin = is_string( home_url() ) && '' !== home_url() ? esc_url_raw( home_url() ) : '';
		if ( '' !== $source_origin ) {
			$args['headers']['X-EFS-Source-Origin'] = $source_origin;
		}
		if ( $this->items_total > 0 ) {
			$args['headers']['X-EFS-Items-Total'] = (string) $this->items_total;
		}

		if ( $data && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $full_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'url'    => $full_url,
					'error'  => $response->get_error_message(),
					'action' => 'API request failed',
				)
			);
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		// On 404, retry with ?rest_route= for targets with plain permalinks.
		if ( 404 === (int) $response_code && false !== strpos( $full_url, '/wp-json/' ) ) {
			$rest_route_url = $base . '/?rest_route=/' . $api_base . $endpoint;
			$retry_response = wp_remote_request( $rest_route_url, $args );
			if ( ! is_wp_error( $retry_response ) ) {
				$retry_code = wp_remote_retrieve_response_code( $retry_response );
				if ( $retry_code < 400 ) {
					$response      = $retry_response;
					$response_code = $retry_code;
					$response_body = wp_remote_retrieve_body( $retry_response );
				}
			}
		}

		if ( $response_code >= 400 ) {
			$decoded_error = json_decode( $response_body, true );
			$error_message = 'API request failed with code: ' . $response_code;

			if ( 404 === (int) $response_code ) {
				$error_message .= ' - ' . __( 'Target site returned "Not Found". On the target (Bricks) site: ensure Etch Fusion Suite is active and Permalinks are not set to "Plain" (e.g. use "Post name").', 'etch-fusion-suite' );
			} elseif ( isset( $decoded_error['message'] ) ) {
				$error_message .= ' - ' . $decoded_error['message'];
			} elseif ( isset( $decoded_error['data'] ) && is_string( $decoded_error['data'] ) ) {
				$error_message .= ' - ' . $decoded_error['data'];
			} elseif ( ! empty( $response_body ) && strlen( $response_body ) < 500 ) {
				$error_message .= ' - Response: ' . substr( $response_body, 0, 200 );
			}

			$this->error_handler->log_error(
				'E103',
				array(
					'url'           => $full_url,
					'response_code' => $response_code,
					'response_body' => $response_body,
					'action'        => 'API request returned error',
				)
			);

			return new \WP_Error( 'api_error', $error_message );
		}

		$decoded_response = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'url'           => $full_url,
					'json_error'    => json_last_error_msg(),
					'response_body' => $response_body,
					'action'        => 'Failed to decode API response',
				)
			);
			return new \WP_Error( 'json_decode_error', 'Failed to decode API response' );
		}

		return $decoded_response;
	}

	/**
	 * Get target site plugins
	 */
	public function get_target_plugins( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/validate/plugins' );
	}

	/**
	 * Send custom post types
	 */
	public function send_custom_post_types( $url, $jwt_token, $cpts ) {
		return $this->send_request( $url, $jwt_token, '/import/cpts', 'POST', $cpts );
	}

	/**
	 * Send ACF field groups
	 */
	public function send_acf_field_groups( $url, $jwt_token, $field_groups ) {
		return $this->send_request( $url, $jwt_token, '/import/acf-field-groups', 'POST', $field_groups );
	}

	/**
	 * Send MetaBox configurations
	 */
	public function send_metabox_configs( $url, $jwt_token, $configs ) {
		return $this->send_request( $url, $jwt_token, '/import/metabox-configs', 'POST', $configs );
	}

	/**
	 * Send CSS classes
	 */
	public function send_css_classes( $url, $jwt_token, $css_classes ) {
		return $this->send_request( $url, $jwt_token, '/import/css-classes', 'POST', $css_classes );
	}

	/**
	 * Send post to target site.
	 *
	 * @param string       $url             Target base URL.
	 * @param string       $jwt_token       Migration token.
	 * @param \WP_Post     $post             Source post.
	 * @param string|null  $etch_content    Gutenberg/Etch content.
	 * @param string|null  $target_post_type Optional. Target post type (from wizard mapping). If not set, source post_type is used.
	 * @param array<string, array<string, mixed>> $loop_presets Optional. Referenced Etch loop presets.
	 * @return array|\WP_Error
	 */
	public function send_post( $url, $jwt_token, $post, $etch_content = null, $target_post_type = null, $loop_presets = array() ) {
		$post_type = ( null !== $target_post_type && '' !== $target_post_type )
			? $target_post_type
			: $post->post_type;

		$post_data = array(
			'post'         => array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name,
				'post_type'   => $post_type,
				'post_date'   => $post->post_date,
				'post_status' => $post->post_status,
			),
			'etch_content' => $etch_content,
		);
		if ( is_array( $loop_presets ) && ! empty( $loop_presets ) ) {
			$post_data['etch_loops'] = $loop_presets;
		}

		return $this->send_request( $url, $jwt_token, '/import/post', 'POST', $post_data );
	}

	/**
	 * Send a batch of posts to the target site in a single HTTP request.
	 *
	 * @param string $url       Target site URL.
	 * @param string $jwt_token Migration token.
	 * @param array  $posts     Prepared post payloads, each with 'post', 'etch_content', 'etch_loops'.
	 * @return array|\WP_Error  Array of per-post result objects on success, or WP_Error on transport failure.
	 */
	public function send_posts_batch( string $url, string $jwt_token, array $posts ) {
		$response = $this->send_request( $url, $jwt_token, '/import/posts', 'POST', array( 'posts' => $posts ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
	}

	/**
	 * Send post meta
	 */
	public function send_post_meta( $url, $jwt_token, $post_id, $meta_data ) {
		$data = array(
			'post_id' => $post_id,
			'meta'    => $meta_data,
		);

		return $this->send_request( $url, $jwt_token, '/import/post-meta', 'POST', $data );
	}

	/**
	 * Send media data to target site.
	 */
	public function send_media_data( $url, $jwt_token, $media_payload ) {
		return $this->send_request( $url, $jwt_token, '/receive-media', 'POST', $media_payload );
	}

	/**
	 * Send CSS styles to target site
	 */
	public function send_css_styles( $url, $jwt_token, $etch_styles ) {
		return $this->send_request( $url, $jwt_token, '/import/css-classes', 'POST', $etch_styles );
	}



	/**
	 * Get migrated content count from target site
	 */
	public function get_migrated_content_count( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/migrated-count' );
	}

	/**
	 * Get posts list from target site
	 */
	public function get_posts_list( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/posts' );
	}

	/**
	 * Get post content from target site
	 */
	public function get_post_content( $url, $jwt_token, $post_id ) {
		return $this->send_request( $url, $jwt_token, '/export/post/' . $post_id );
	}

	/**
	 * Get CSS classes from target site
	 */
	public function get_css_classes( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/css-classes' );
	}

	/**
	 * Send media file to target site
	 */
	public function send_media_file( $url, $jwt_token, $media_data ) {
		return $this->send_request( $url, $jwt_token, '/import/media', 'POST', $media_data );
	}

	/**
	 * Get custom post types from target site (full CPT definitions for import).
	 */
	public function get_custom_post_types( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/cpts' );
	}

	/**
	 * Get list of post types available on target site (for mapping dropdown).
	 * Returns array with key 'post_types' => [ ['slug' => ..., 'label' => ...], ... ].
	 *
	 * @param string $url       Target site base URL.
	 * @param string $jwt_token Migration token.
	 * @return array|\WP_Error Decoded response or error.
	 */
	public function get_target_post_types( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/post-types' );
	}

	/**
	 * Get ACF field groups from target site
	 */
	public function get_acf_field_groups( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/acf-field-groups' );
	}

	/**
	 * Get MetaBox configurations from target site
	 */
	public function get_metabox_configs( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/metabox-configs' );
	}
}
