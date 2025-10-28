<?php
/**
 * Security Headers
 *
 * Adds security headers to HTTP responses including CSP, X-Frame-Options, etc.
 * Protects against XSS, clickjacking, and other common web vulnerabilities.
 *
 * @package    Bricks2Etch
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Security Headers Class
 *
 * Manages HTTP security headers with environment-aware CSP policies.
 */
class EFS_Security_Headers {

	/**
	 * Add security headers to response
	 *
	 * Sets all security headers via header() function.
	 * Called via WordPress send_headers action.
	 *
	 * @return void
	 */
	public function add_security_headers() {
		// Don't add headers if we shouldn't
		if ( ! $this->should_add_headers() ) {
			return;
		}

		// X-Frame-Options: Prevent clickjacking
		header( 'X-Frame-Options: SAMEORIGIN' );

		// X-Content-Type-Options: Prevent MIME sniffing
		header( 'X-Content-Type-Options: nosniff' );

		// X-XSS-Protection: Enable XSS filter
		header( 'X-XSS-Protection: 1; mode=block' );

		// Referrer-Policy: Control referrer information
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		// Permissions-Policy: Disable unnecessary browser features
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );

		// Content-Security-Policy: Comprehensive XSS protection
		$csp = $this->get_csp_policy();
		if ( ! empty( $csp ) ) {
			header( 'Content-Security-Policy: ' . $csp );
		}
	}

	/**
	 * Get Content Security Policy
	 *
	 * Returns CSP string based on current page context.
	 * Uses relaxed policy for admin pages and frontend to accommodate WordPress behavior.
	 *
	 * @return string CSP policy string.
	 */
	public function get_csp_policy() {
		$context    = $this->is_admin_page() ? 'admin' : 'frontend';
		$directives = $this->is_admin_page() ? $this->get_admin_csp_directives() : $this->get_frontend_csp_directives();

		/**
		 * Filter the CSP directives before they are compiled into a header string.
		 *
		 * @since 0.5.1
		 *
		 * @param array  $directives Directive map.
		 * @param string $context    Either "admin" or "frontend".
		 */
		$directives = apply_filters( 'efs_security_headers_csp_directives', $directives, $context );

		return $this->compile_csp_directives( $directives );
	}

	/**
	 * Retrieve CSP directives for admin-facing requests.
	 *
	 * @return array
	 */
	protected function get_admin_csp_directives() {
		return array(
			'default-src'     => array( "'self'" ),
			'script-src'      => array_merge( array( "'self'", "'unsafe-inline'" ), $this->get_admin_script_sources() ),
			'style-src'       => array_merge( array( "'self'", "'unsafe-inline'" ), $this->get_admin_style_sources() ),
			'img-src'         => array( "'self'", 'data:', 'https:' ),
			'font-src'        => array( "'self'", 'data:' ),
			'connect-src'     => $this->get_default_connect_sources(),
			'frame-ancestors' => array( "'self'" ),
			'form-action'     => array( "'self'" ),
			'base-uri'        => array( "'self'" ),
			'object-src'      => array( "'none'" ),
		);
	}

	/**
	 * Retrieve CSP directives for frontend requests.
	 *
	 * @return array
	 */
	protected function get_frontend_csp_directives() {
		return array(
			'default-src'     => array( "'self'" ),
			'script-src'      => array_merge( array( "'self'", "'unsafe-inline'" ), $this->get_frontend_script_sources() ),
			'style-src'       => array_merge( array( "'self'", "'unsafe-inline'" ), $this->get_frontend_style_sources() ),
			'img-src'         => array( "'self'", 'data:', 'https:' ),
			'font-src'        => array( "'self'", 'data:' ),
			'connect-src'     => $this->get_default_connect_sources(),
			'frame-ancestors' => array( "'self'" ),
			'form-action'     => array( "'self'" ),
			'base-uri'        => array( "'self'" ),
			'object-src'      => array( "'none'" ),
		);
	}

	/**
	 * Get default connect-src values shared by contexts.
	 *
	 * @return array
	 */
	protected function get_default_connect_sources() {
		/**
		 * Filter the default connect-src hosts applied to the CSP.
		 *
		 * @since 0.5.1
		 *
		 * @param array $sources Default connect-src values.
		 */
		return apply_filters( 'efs_security_headers_csp_connect_src', array( "'self'" ) );
	}

	/**
	 * Allow additional admin script sources such as WordPress dashicons or CDN endpoints.
	 *
	 * @return array
	 */
	protected function get_admin_script_sources() {
		/**
		 * Filter additional admin script sources.
		 *
		 * @since 0.5.2
		 *
		 * @param array $sources Additional script-src values.
		 */
		return apply_filters( 'efs_security_headers_admin_script_sources', array() );
	}

	/**
	 * Allow additional admin style sources such as Google Fonts in dev environments.
	 *
	 * @return array
	 */
	protected function get_admin_style_sources() {
		/**
		 * Filter additional admin style sources.
		 *
		 * @since 0.5.2
		 *
		 * @param array $sources Additional style-src values.
		 */
		return apply_filters( 'efs_security_headers_admin_style_sources', array() );
	}

	/**
	 * Additional frontend script sources.
	 *
	 * @return array
	 */
	protected function get_frontend_script_sources() {
		/**
		 * Filter additional frontend script sources.
		 *
		 * @since 0.5.2
		 *
		 * @param array $sources Additional script-src values.
		 */
		return apply_filters( 'efs_security_headers_frontend_script_sources', array() );
	}

	/**
	 * Additional frontend style sources.
	 *
	 * @return array
	 */
	protected function get_frontend_style_sources() {
		/**
		 * Filter additional frontend style sources.
		 *
		 * @since 0.5.2
		 *
		 * @param array $sources Additional style-src values.
		 */
		return apply_filters( 'efs_security_headers_frontend_style_sources', array() );
	}

	/**
	 * Compile directive map into CSP header string.
	 *
	 * @param array $directives Directive map.
	 * @return string
	 */
	protected function compile_csp_directives( array $directives ) {
		$segments = array();

		foreach ( $directives as $directive => $values ) {
			$values = array_filter( array_map( 'trim', (array) $values ) );

			if ( empty( $values ) ) {
				continue;
			}

			$segments[] = sprintf( '%s %s', $directive, implode( ' ', $values ) );
		}

		return implode( '; ', $segments );
	}

	/**
	 * Check if current request is admin page
	 *
	 * @return bool True if admin page, false otherwise.
	 */
	public function is_admin_page() {
		// Check if we're in admin area
		if ( is_admin() ) {
			return true;
		}

		// Check if this is an AJAX request from admin
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			// Check referer to determine if it's from admin
			$referer = wp_get_referer();
			if ( $referer && strpos( $referer, admin_url() ) === 0 ) {
				return true;
			}
		}

		// Check request URI
		$request_uri = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		if ( strpos( $request_uri, '/wp-admin/' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if headers should be added
	 *
	 * Determines if security headers should be added to current request.
	 *
	 * @return bool True if headers should be added, false otherwise.
	 */
	public function should_add_headers() {
		// Don't add headers if already sent
		if ( headers_sent() ) {
			return false;
		}

		// Don't add headers for wp-login.php (can interfere with login)
		$request_uri = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}
		if ( strpos( $request_uri, 'wp-login.php' ) !== false ) {
			return false;
		}

		// Don't add headers for wp-cron.php
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}

		// Don't add headers for REST API OPTIONS requests (handled by CORS)
		$request_method = '';
		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$request_method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		}

		if ( 'OPTIONS' === $request_method ) {
			return false;
		}

		return true;
	}
}
