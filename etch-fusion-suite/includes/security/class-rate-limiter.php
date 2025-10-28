<?php
/**
 * Rate Limiter
 *
 * Implements transient-based rate limiting with sliding window algorithm.
 * Protects AJAX and REST API endpoints from abuse.
 *
 * @package    EtchFusion
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Rate Limiter Class
 *
 * Provides rate limiting functionality using WordPress transients and sliding window algorithm.
 */
class EFS_Rate_Limiter {

	/**
	 * Default rate limits by endpoint type
	 *
	 * @var array
	 */
	private $default_limits = array(
		'ajax'      => 60,  // 60 requests per minute
		'rest'      => 30,  // 30 requests per minute
		'auth'      => 10,  // 10 requests per minute for authentication
		'sensitive' => 5,   // 5 requests per minute for sensitive operations
	);

	/**
	 * Default time window in seconds
	 *
	 * @var int
	 */
	private $default_window = 60;

	/**
	 * Check if rate limit is exceeded
	 *
	 * @param string $identifier Unique identifier (IP, user ID, API key hash).
	 * @param string $action     Action name (e.g., 'validate_api_key').
	 * @param int    $limit      Maximum requests allowed (default: 60).
	 * @param int    $window     Time window in seconds (default: 60).
	 * @return bool True if limit exceeded, false otherwise.
	 */
	public function check_rate_limit( $identifier, $action, $limit = 60, $window = 60 ) {
		$transient_key = $this->get_transient_key( $identifier, $action );
		$timestamps    = get_transient( $transient_key );

		// Initialize if not exists
		if ( false === $timestamps || ! is_array( $timestamps ) ) {
			$timestamps = array();
		}

		// Filter timestamps to only include those within the window
		$current_time = time();
		$timestamps   = array_filter(
			$timestamps,
			function ( $timestamp ) use ( $current_time, $window ) {
				return ( $current_time - $timestamp ) < $window;
			}
		);

		// Check if limit exceeded
		if ( count( $timestamps ) >= $limit ) {
			return true;
		}

		return false;
	}

	/**
	 * Record a request
	 *
	 * Increments the request counter for the given identifier and action.
	 *
	 * @param string $identifier Unique identifier.
	 * @param string $action     Action name.
	 * @param int    $window     Time window in seconds (default: 60).
	 * @return void
	 */
	public function record_request( $identifier, $action, $window = 60 ) {
		$transient_key = $this->get_transient_key( $identifier, $action );
		$timestamps    = get_transient( $transient_key );

		// Initialize if not exists
		if ( false === $timestamps || ! is_array( $timestamps ) ) {
			$timestamps = array();
		}

		// Add current timestamp
		$timestamps[] = time();

		// Save transient with window expiration
		set_transient( $transient_key, $timestamps, $window );
	}

	/**
	 * Get remaining attempts
	 *
	 * Returns the number of remaining requests allowed within the window.
	 *
	 * @param string $identifier Unique identifier.
	 * @param string $action     Action name.
	 * @param int    $limit      Maximum requests allowed (default: 60).
	 * @param int    $window     Time window in seconds (default: 60).
	 * @return int Number of remaining attempts.
	 */
	public function get_remaining_attempts( $identifier, $action, $limit = 60, $window = 60 ) {
		$transient_key = $this->get_transient_key( $identifier, $action );
		$timestamps    = get_transient( $transient_key );

		if ( false === $timestamps || ! is_array( $timestamps ) ) {
			return $limit;
		}

		// Filter timestamps to only include those within the window
		$current_time = time();
		$timestamps   = array_filter(
			$timestamps,
			function ( $timestamp ) use ( $current_time, $window ) {
				return ( $current_time - $timestamp ) < $window;
			}
		);

		$remaining = $limit - count( $timestamps );
		return max( 0, $remaining );
	}

	/**
	 * Reset rate limit for identifier and action
	 *
	 * Clears the transient, effectively resetting the rate limit.
	 *
	 * @param string $identifier Unique identifier.
	 * @param string $action     Action name.
	 * @return bool True on success, false on failure.
	 */
	public function reset_limit( $identifier, $action ) {
		$transient_key = $this->get_transient_key( $identifier, $action );
		return delete_transient( $transient_key );
	}

	/**
	 * Get identifier for current request
	 *
	 * Returns IP address or user ID as identifier.
	 *
	 * @return string Identifier (IP or user_ID).
	 */
	public function get_identifier() {
		// Use user ID if logged in
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user_' . $user_id;
		}

		// Otherwise use IP address
		return $this->get_client_ip();
	}

	/**
	 * Get client IP address
	 *
	 * Safely retrieves client IP with proxy header support.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		// Check for proxy headers (in order of preference)
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_REAL_IP',        // Nginx proxy
			'HTTP_X_FORWARDED_FOR',  // Standard proxy header
			'REMOTE_ADDR',           // Direct connection
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

				// X-Forwarded-For can contain multiple IPs, take the first one
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}

				// Validate IP
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					break;
				}
			}
		}

		// Fallback to unknown if no valid IP found
		return ! empty( $ip ) ? $ip : 'unknown';
	}

	/**
	 * Get transient key
	 *
	 * Generates a unique transient key for the identifier and action.
	 *
	 * @param string $identifier Unique identifier.
	 * @param string $action     Action name.
	 * @return string Transient key.
	 */
	private function get_transient_key( $identifier, $action ) {
		// Hash identifier to keep key length reasonable
		$identifier_hash = md5( $identifier );
		return 'efs_rate_limit_' . $action . '_' . $identifier_hash;
	}

	/**
	 * Get default limit for action type
	 *
	 * @param string $type Action type (ajax, rest, auth, sensitive).
	 * @return int Default limit.
	 */
	public function get_default_limit( $type = 'ajax' ) {
		return isset( $this->default_limits[ $type ] ) ? $this->default_limits[ $type ] : 60;
	}
}
