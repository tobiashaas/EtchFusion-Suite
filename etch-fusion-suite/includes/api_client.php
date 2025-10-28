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
	 * Save API credentials to WordPress options
	 * Single source of truth for credential storage
	 *
	 * @param string $target_url Target site URL
	 * @param string $api_username Username for authentication
	 * @param string $api_key API key/Application Password
	 */
	public static function save_api_credentials( $target_url, $api_username, $api_key ) {
		$settings                 = get_option( 'efs_settings', array() );
		$settings['target_url']   = $target_url;
		$settings['api_username'] = $api_username;
		$settings['api_key']      = $api_key;
		update_option( 'efs_settings', $settings );
	}

	/**
	 * Send request to target site
	 */
	private function send_request( $url, $api_key, $endpoint, $method = 'GET', $data = null ) {
		$full_url = rtrim( $url, '/' ) . '/wp-json/efs/v1' . $endpoint;

		// Remove spaces from API key (Application Passwords have spaces for readability)
		$clean_api_key = str_replace( ' ', '', $api_key );

		// Get username from settings or default to 'admin'
		$settings      = get_option( 'efs_settings', array() );
		$auth_username = ! empty( $settings['api_username'] ) ? $settings['api_username'] : 'admin';

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'X-API-Key'     => $clean_api_key,
				'Authorization' => 'Basic ' . base64_encode( $auth_username . ':' . $clean_api_key ),
				'Content-Type'  => 'application/json',
			),
		);

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
			// Try to decode error message from response
			$decoded_error = json_decode( $response_body, true );
			$error_message = 'API request failed with code: ' . $response_code;

			if ( isset( $decoded_error['message'] ) ) {
				$error_message .= ' - ' . $decoded_error['message'];
			} elseif ( isset( $decoded_error['data'] ) && is_string( $decoded_error['data'] ) ) {
				$error_message .= ' - ' . $decoded_error['data'];
			} elseif ( ! empty( $response_body ) && strlen( $response_body ) < 500 ) {
				// Include short response body if available
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
	 * Validate API connection
	 */
	public function validate_connection( $url, $api_key ) {
		$result = $this->send_request(
			$url,
			$api_key,
			'/auth/validate',
			'POST',
			array(
				'api_key' => $api_key,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['valid'] ?? false;
	}

	/**
	 * Get target site plugins
	 */
	public function get_target_plugins( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/validate/plugins' );
	}

	/**
	 * Send custom post types
	 */
	public function send_custom_post_types( $url, $api_key, $cpts ) {
		return $this->send_request( $url, $api_key, '/import/cpts', 'POST', $cpts );
	}

	/**
	 * Send ACF field groups
	 */
	public function send_acf_field_groups( $url, $api_key, $field_groups ) {
		return $this->send_request( $url, $api_key, '/import/acf-field-groups', 'POST', $field_groups );
	}

	/**
	 * Send MetaBox configurations
	 */
	public function send_metabox_configs( $url, $api_key, $configs ) {
		return $this->send_request( $url, $api_key, '/import/metabox-configs', 'POST', $configs );
	}

	/**
	 * Send CSS classes
	 */
	public function send_css_classes( $url, $api_key, $css_classes ) {
		return $this->send_request( $url, $api_key, '/import/css-classes', 'POST', $css_classes );
	}

	/**
	 * Send post
	 */
	public function send_post( $url, $api_key, $post, $etch_content = null ) {
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

		return $this->send_request( $url, $api_key, '/import/post', 'POST', $post_data );
	}

	/**
	 * Send post meta
	 */
	public function send_post_meta( $url, $api_key, $post_id, $meta_data ) {
		$data = array(
			'post_id' => $post_id,
			'meta'    => $meta_data,
		);

		return $this->send_request( $url, $api_key, '/import/post-meta', 'POST', $data );
	}

	/**
	 * Send media data to target site
	 */
	public function send_media_data( $url, $api_key, $media_data ) {
		return $this->send_request( $url, $api_key, '/receive-media', 'POST', $media_data );
	}

	/**
	 * Send CSS styles to target site
	 */
	public function send_css_styles( $url, $api_key, $etch_styles ) {
		$styles_count = is_array( $etch_styles ) ? count( $etch_styles ) : 0;

		$result = $this->send_request( $url, $api_key, '/import/css-classes', 'POST', $etch_styles );

		if ( is_wp_error( $result ) ) {
		} else {
		}

		return $result;
	}

	/**
	 * Get migrated content count from target site
	 */
	public function get_migrated_content_count( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/migrated-count' );
	}

	/**
	 * Get posts list from target site
	 */
	public function get_posts_list( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/export/posts' );
	}

	/**
	 * Get post content from target site
	 */
	public function get_post_content( $url, $api_key, $post_id ) {
		return $this->send_request( $url, $api_key, '/export/post/' . $post_id );
	}

	/**
	 * Get CSS classes from target site
	 */
	public function get_css_classes( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/export/css-classes' );
	}

	/**
	 * Send media file to target site
	 */
	public function send_media_file( $url, $api_key, $media_data ) {
		return $this->send_request( $url, $api_key, '/import/media', 'POST', $media_data );
	}

	/**
	 * Get custom post types from target site
	 */
	public function get_custom_post_types( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/export/cpts' );
	}

	/**
	 * Get ACF field groups from target site
	 */
	public function get_acf_field_groups( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/export/acf-field-groups' );
	}

	/**
	 * Get MetaBox configurations from target site
	 */
	public function get_metabox_configs( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/export/metabox-configs' );
	}

	/**
	 * Test API connection with detailed response
	 */
	public function test_connection( $url, $api_key ) {
		$result = array(
			'valid'   => false,
			'plugins' => array(),
			'errors'  => array(),
		);

		// Test basic connection
		$connection_test = $this->validate_connection( $url, $api_key );

		if ( is_wp_error( $connection_test ) ) {
			$result['errors'][] = $connection_test->get_error_message();
			return $result;
		}

		if ( ! $connection_test ) {
			$result['errors'][] = 'Invalid API key';
			return $result;
		}

		$result['valid'] = true;

		// Get plugin status
		$plugins_response = $this->get_target_plugins( $url, $api_key );

		if ( is_wp_error( $plugins_response ) ) {
			$result['errors'][] = 'Failed to get plugin status: ' . $plugins_response->get_error_message();
		} else {
			$result['plugins'] = $plugins_response;
		}

		return $result;
	}

	/**
	 * Generate API key for target site
	 */
	public function generate_api_key() {
		return 'efs_' . wp_generate_password( 32, false );
	}

	/**
	 * Set API key on target site
	 */
	public function set_api_key( $url, $api_key ) {
		// This would need to be implemented on the target site
		// For now, we'll just return the generated key
		return $api_key;
	}

	/**
	 * Check if API key is valid
	 */
	public function is_api_key_valid( $url, $api_key ) {
		$result = $this->validate_connection( $url, $api_key );
		return ! is_wp_error( $result ) && true === $result;
	}

	/**
	 * Get API key expiration time
	 */
	public function get_api_key_expiration( $api_key ) {
		// API keys are valid for 8 hours
		$created_time = get_option( 'efs_api_key_created_' . md5( $api_key ) );

		if ( ! $created_time ) {
			return null;
		}

		$expiration_time = $created_time + ( 8 * HOUR_IN_SECONDS );
		return $expiration_time;
	}

	/**
	 * Check if API key is expired
	 */
	public function is_api_key_expired( $api_key ) {
		$expiration_time = $this->get_api_key_expiration( $api_key );

		if ( ! $expiration_time ) {
			return true;
		}

		return time() > $expiration_time;
	}

	/**
	 * Create API key with expiration
	 */
	public function create_api_key() {
		$api_key      = $this->generate_api_key();
		$created_time = time();

		// Store creation time
		update_option( 'efs_api_key_created_' . md5( $api_key ), $created_time );

		// Store the key
		update_option( 'efs_api_key', $api_key );

		return $api_key;
	}

	/**
	 * Cleanup expired API keys
	 */
	public function cleanup_expired_keys() {
		global $wpdb;

		// Get all API key creation times
		$keys = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE 'b2e_api_key_created_%'"
		);

		$current_time = time();
		$expired_keys = array();

		foreach ( $keys as $key ) {
			$created_time    = intval( $key->option_value );
			$expiration_time = $created_time + ( 8 * HOUR_IN_SECONDS );

			if ( $current_time > $expiration_time ) {
				$key_hash       = str_replace( 'b2e_api_key_created_', '', $key->option_name );
				$expired_keys[] = $key_hash;

				// Delete the creation time record
				delete_option( $key->option_name );
			}
		}

		return $expired_keys;
	}

	/**
	 * Validate API key on target site
	 */
	public function validate_api_key( $url, $api_key ) {
		return $this->send_request( $url, $api_key, '/validate-api-key' );
	}

	/**
	 * Validate migration token on target site
	 *
	 * @param string $url Target site URL
	 * @param string $token Migration token
	 * @param int $expires Token expiration timestamp
	 * @return array|WP_Error Validation result with API key
	 */
	public function validate_migration_token( $url, $token, $expires ) {
		// Send token validation request to target site
		$full_url = rtrim( $url, '/' ) . '/wp-json/b2e/v1/validate';

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'token'         => $token,
					'source_domain' => home_url(),
					'expires'       => $expires,
				)
			),
		);

		$response = wp_remote_request( $full_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'url'    => $full_url,
					'error'  => $response->get_error_message(),
					'action' => 'Token validation request failed',
				)
			);
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( $response_code >= 400 ) {
			$decoded_error = json_decode( $response_body, true );
			$error_message = 'Token validation failed with code: ' . $response_code;

			if ( isset( $decoded_error['message'] ) ) {
				$error_message .= ' - ' . $decoded_error['message'];
			} elseif ( isset( $decoded_error['data'] ) && is_string( $decoded_error['data'] ) ) {
				$error_message .= ' - ' . $decoded_error['data'];
			}

			$this->error_handler->log_error(
				'E103',
				array(
					'url'           => $full_url,
					'response_code' => $response_code,
					'response_body' => $response_body,
					'action'        => 'Token validation returned error',
				)
			);

			return new \WP_Error( 'token_validation_error', $error_message );
		}

		$decoded_response = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'url'           => $full_url,
					'json_error'    => json_last_error_msg(),
					'response_body' => $response_body,
					'action'        => 'Failed to decode token validation response',
				)
			);
			return new \WP_Error( 'json_decode_error', 'Failed to decode token validation response' );
		}

		return $decoded_response;
	}
}
