<?php
/**
 * Error Handler
 *
 * @package Bricks2Etch\Core
 */

namespace Bricks2Etch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Error_Handler
 *
 * Handles plugin errors and logging.
 */
class EFS_Error_Handler {
	/**
	 * Handle error
	 *
	 * @param string $message Error message.
	 * @param string $level   Error level (warning, error, critical).
	 */
	public function handle( $message, $level = 'error' ) {
		error_log( "EFS [{$level}]: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Debug log
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data    Optional data to log.
	 */
	public function debug_log( $message, $data = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "EFS [DEBUG]: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( $data ) {
				error_log( wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
