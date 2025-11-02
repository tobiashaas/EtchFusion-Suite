<?php
/**
 * CORS Manager
 *
 * Manages Cross-Origin Resource Sharing (CORS) with whitelist-based origin validation.
 * Replaces wildcard CORS with secure, configurable origin checking.
 *
 * @package    EtchFusion
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

use Bricks2Etch\Repositories\Interfaces\Settings_Repository_Interface;

/**
 * CORS Manager Class
 *
 * Provides whitelist-based CORS policy management with configurable allowed origins.
 */
class EFS_CORS_Manager {

	/**
	 * Settings Repository instance
	 *
	 * @var Settings_Repository_Interface
	 */
	private $settings_repository;

	/**
	 * Constructor
	 *
	 * @param Settings_Repository_Interface $settings_repository Settings repository instance.
	 */
	public function __construct( Settings_Repository_Interface $settings_repository ) {
		$this->settings_repository = $settings_repository;
	}

	/**
	 * Get allowed CORS origins
	 *
	 * Returns array of allowed origins from settings with fallback to development defaults.
	 *
	 * @return array Array of allowed origin URLs.
	 */
	public function get_allowed_origins() {
		$origins = $this->settings_repository->get_cors_allowed_origins();

		// Fallback to development defaults if no origins configured
		if ( empty( $origins ) ) {
			$origins = $this->get_default_origins();
		}

		return $origins;
	}

	/**
	 * Get default CORS origins for development
	 *
	 * @return array Array of default development origin URLs.
	 */
	public function get_default_origins() {
		return array(
			'http://localhost:8888',
			'http://localhost:8889',
			'http://127.0.0.1:8888',
			'http://127.0.0.1:8889',
		);
	}

	/**
	 * Check if origin is allowed
	 *
	 * @param string $origin Origin URL to check.
	 * @return bool True if origin is allowed, false otherwise.
	 */
	public function is_origin_allowed( $origin ) {
		if ( empty( $origin ) ) {
			// Requests originating from the server or same-origin browsers omit the Origin header.
			// Treat these as allowed to prevent false CORS denials for server-to-server calls.
			return true;
		}

		$allowed_origins = $this->get_allowed_origins();

		// Normalize origin (remove trailing slash)
		$origin = rtrim( $origin, '/' );

		// Check if origin is in whitelist
		foreach ( $allowed_origins as $allowed ) {
			$allowed = rtrim( $allowed, '/' );
			if ( $allowed === $origin ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add CORS headers to response
	 *
	 * Sets CORS headers only if the request origin is in the whitelist.
	 * Called via WordPress rest_pre_serve_request filter.
	 *
	 * @return void
	 */
	public function add_cors_headers() {
		$origin = '';

		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}

		// Check if origin is allowed
		if ( ! $this->is_origin_allowed( $origin ) ) {
			// Log CORS violation if audit logger is available
			if ( function_exists( 'etch_fusion_suite_container' ) ) {
				try {
					$container = etch_fusion_suite_container();
					if ( $container->has( 'audit_logger' ) ) {
						$container
							->get( 'audit_logger' )
							->log_security_event(
								'cors_violation',
								'medium',
								'CORS request from unauthorized origin',
								array( 'origin' => $origin )
							);
					}
				} catch ( \Exception $e ) {
					// Silently fail if container not available
				}
			}

			// Don't set CORS headers for unauthorized origins
			return;
		}

		$methods = implode( ', ', $this->get_allowed_methods() );
		$headers = implode( ', ', $this->get_allowed_headers() );

		// Set CORS headers for allowed origin
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: ' . $methods );
		header( 'Access-Control-Allow-Headers: ' . $headers );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin' );

		$max_age = $this->get_max_age();
		if ( $max_age > 0 ) {
			header( 'Access-Control-Max-Age: ' . absint( $max_age ) );
		}
	}

	/**
	 * Handle OPTIONS preflight requests
	 *
	 * Responds to CORS preflight requests with appropriate headers.
	 *
	 * @return void
	 */
	public function handle_preflight_request() {
		$method = '';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		}

		if ( 'OPTIONS' === $method ) {
			$this->add_cors_headers();
			if ( ! headers_sent() ) {
				status_header( 204 );
				header( 'Content-Length: 0' );
			}
			exit;
		}
	}

	/**
	 * Retrieve allowed HTTP methods for CORS.
	 *
	 * @return array
	 */
	protected function get_allowed_methods() {
		/**
		 * Filter the allowed HTTP methods for CORS responses.
		 *
		 * @since 0.5.2
		 *
		 * @param array $methods Allowed HTTP methods.
		 */
		$methods = apply_filters( 'etch_fusion_suite_cors_allowed_methods', array( 'GET', 'POST', 'OPTIONS' ) );

		return $this->normalise_header_values( $methods );
	}

	/**
	 * Retrieve allowed request headers for CORS.
	 *
	 * @return array
	 */
	protected function get_allowed_headers() {
		/**
		 * Filter the allowed request headers for CORS responses.
		 *
		 * @since 0.5.2
		 *
		 * @param array $headers Allowed request headers.
		 */
		$headers = apply_filters( 'efs_cors_allowed_headers', array( 'Content-Type', 'Authorization', 'X-WP-Nonce' ) );

		return $this->normalise_header_values( $headers );
	}

	/**
	 * Retrieve the Access-Control-Max-Age value.
	 *
	 * @return int Max age in seconds.
	 */
	protected function get_max_age() {
		/**
		 * Filter the CORS max-age value in seconds.
		 *
		 * @since 0.5.2
		 *
		 * @param int $max_age Access-Control-Max-Age value.
		 */
		$max_age = apply_filters( 'etch_fusion_suite_cors_max_age', 600 );

		return max( 0, (int) $max_age );
	}

	/**
\t * Normalise header values by trimming whitespace and removing empties.
	 *
	 * @param array $values Raw header values.
	 * @return array
	 */
	protected function normalise_header_values( array $values ) {
		$values = array_map( 'trim', $values );
		$values = array_filter( $values, 'strlen' );

		return array_values( $values );
	}
}
