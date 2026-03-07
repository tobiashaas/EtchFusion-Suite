<?php
/**
 * API Rate Limiting for Etch Fusion Suite
 *
 * Handles rate limiting for REST API endpoints
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Security\EFS_Rate_Limiter;
use Bricks2Etch\Security\EFS_Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Rate Limiting class.
 *
 * Provides rate limiting functionality for REST API endpoints.
 *
 * @package Bricks2Etch\Api
 */
class EFS_API_Rate_Limiting {

	/**
	 * @var EFS_Rate_Limiter|null
	 */
	private static $rate_limiter;

	/**
	 * @var EFS_Audit_Logger|null
	 */
	private static $audit_logger;

	/**
	 * Set the rate limiter instance.
	 *
	 * @param EFS_Rate_Limiter $rate_limiter
	 */
	public static function set_rate_limiter( EFS_Rate_Limiter $rate_limiter ) {
		self::$rate_limiter = $rate_limiter;
	}

	/**
	 * Set the audit logger instance.
	 *
	 * @param EFS_Audit_Logger $audit_logger
	 */
	public static function set_audit_logger( EFS_Audit_Logger $audit_logger ) {
		self::$audit_logger = $audit_logger;
	}

	/**
	 * Check rate limit without recording on success.
	 *
	 * @param string $action Action name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window  Window in seconds.
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit( $action, $limit, $window = 60 ) {
		return self::handle_rate_limit( $action, $limit, $window );
	}

	/**
	 * Internal helper to check and record rate limits.
	 *
	 * @param string $action Action key.
	 * @param int    $limit  Limit count.
	 * @param int    $window  Window seconds.
	 * @return true|\WP_Error
	 */
	private static function handle_rate_limit( $action, $limit, $window ) {
		if ( ! self::$rate_limiter ) {
			return true;
		}

		$identifier = self::$rate_limiter->get_identifier();

		if ( self::$rate_limiter->check_rate_limit( $identifier, $action, $limit, $window ) ) {
			$retry_after = max( 1, (int) $window );
			if ( ! headers_sent() ) {
				header( 'Retry-After: ' . $retry_after );
			}

			if ( self::$audit_logger ) {
				self::$audit_logger->log_rate_limit_exceeded( $identifier, $action );
			}

			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Rate limit exceeded. Please try again later.', 'etch-fusion-suite' ),
				array( 'status' => 429 )
			);
		}

		self::$rate_limiter->record_request( $identifier, $action, $window );

		return true;
	}
}
