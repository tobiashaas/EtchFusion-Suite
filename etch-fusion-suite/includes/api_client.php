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
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;
	}

	/**
	 * Public wrapper for sending authorized requests to the Etch REST API.
	 *
	 * @param string      $url       Target site base URL.
	 * @param string|null $jwt_token Migration token for Authorization header.
	 * @param string      $endpoint  REST endpoint path (without prefix).
	 * @param string      $method    HTTP verb.
	 * @param array|null  $data      Optional payload.
	 *
	 * @return array|\WP_Error
	 */
	public function send_authorized_request( $url, $jwt_token, $endpoint, $method = 'GET', $data = null ) {
		return $this->send_request( $url, $jwt_token, $endpoint, $method, $data );
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
	 * Send request to target site
	 */
	private function send_request( $url, $jwt_token, $endpoint, $method = 'GET', $data = null ) {
		$full_url = rtrim( $url, '/' ) . '/wp-json/efs/v1' . $endpoint;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
		);

		if ( ! empty( $jwt_token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $jwt_token;
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

		if ( $response_code >= 400 ) {
			$decoded_error = json_decode( $response_body, true );
			$error_message = 'API request failed with code: ' . $response_code;

			if ( isset( $decoded_error['message'] ) ) {
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
	 * Send post
	 */
	public function send_post( $url, $jwt_token, $post, $etch_content = null ) {
		$post_data = array(
			'post'         => array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post->post_name, // Add slug for duplicate checking
				'post_type'   => $post->post_type,
				'post_date'   => $post->post_date,
				'post_status' => $post->post_status,
			),
			'etch_content' => $etch_content,
		);

		return $this->send_request( $url, $jwt_token, '/import/post', 'POST', $post_data );
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
		$styles_count = is_array( $etch_styles ) ? count( $etch_styles ) : 0;

		$result = $this->send_request( $url, $jwt_token, '/import/css-classes', 'POST', $etch_styles );

		if ( is_wp_error( $result ) ) {
		} else {
		}

		return $result;
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
	 * Get custom post types from target site
	 */
	public function get_custom_post_types( $url, $jwt_token ) {
		return $this->send_request( $url, $jwt_token, '/export/cpts' );
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
