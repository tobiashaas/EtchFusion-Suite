<?php
/**
 * API CORS Handling for Etch Fusion Suite
 *
 * Handles CORS validation and headers for REST API endpoints
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Security\EFS_CORS_Manager;
use Bricks2Etch\Security\EFS_Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API CORS class.
 *
 * Provides CORS validation and header injection for REST API endpoints.
 *
 * @package Bricks2Etch\Api
 */
class EFS_API_CORS {

	/**
	 * @var EFS_CORS_Manager|null
	 */
	private static $cors_manager;

	/**
	 * @var EFS_Audit_Logger|null
	 */
	private static $audit_logger;

	/**
	 * Set the CORS manager instance.
	 *
	 * @param EFS_CORS_Manager $cors_manager
	 */
	public static function set_cors_manager( EFS_CORS_Manager $cors_manager ) {
		self::$cors_manager = $cors_manager;
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
	 * Check CORS origin for REST API endpoint
	 *
	 * @return true|\WP_Error True if origin allowed, WP_Error if denied.
	 */
	public static function check_cors_origin() {
		if ( ! self::$cors_manager ) {
			return true;
		}

		$origin = '';
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}

		if ( '' === $origin ) {
			return true;
		}

		if ( ! self::$cors_manager->is_origin_allowed( $origin ) ) {
			if ( self::$audit_logger ) {
				self::$audit_logger->log_security_event(
					'cors_violation',
					'medium',
					'REST API request from unauthorized origin',
					array( 'origin' => $origin )
				);
			}

			return new \WP_Error(
				'cors_violation',
				'Origin not allowed',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Add CORS headers to REST API responses
	 *
	 * Hooked to rest_pre_serve_request (priority 10), which fires before any output
	 * is written, so header() calls are always safe.
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param \WP_REST_Response $result  Result to send to the client.
	 * @param \WP_REST_Request  $request The request object.
	 * @param \WP_REST_Server   $server  Server instance.
	 * @return bool $served unchanged.
	 */
	public static function add_cors_headers_to_request( $served, $result, $request, $server ) {
		$route = $request->get_route();
		if ( strpos( $route, '/efs/v1/' ) !== 0 ) {
			return $served;
		}

		$cors_check = self::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			return $served;
		}

		if ( self::$cors_manager ) {
			self::$cors_manager->add_cors_headers();
		} else {
			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
			if ( ! empty( $origin ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
				header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce, X-EFS-Source-Origin' );
				header( 'Vary: Origin' );
			}
		}

		return $served;
	}

	/**
	 * Enforce CORS globally for all REST API requests
	 *
	 * This filter runs before any REST API callback and provides a safety net
	 * to ensure no endpoint can bypass CORS validation.
	 *
	 * @param  \WP_HTTP_Response|\WP_Error $response Result to send to the client.
	 * @param  \WP_REST_Server             $server   Server instance.
	 * @param  \WP_REST_Request            $request  Request used to generate the response.
	 * @return \WP_HTTP_Response|\WP_Error Modified response or error.
	 */
	public static function enforce_cors_globally( $response, $server, $request ) {
		if ( $request->get_method() === 'OPTIONS' ) {
			return $response;
		}

		$route = $request->get_route();
		if ( strpos( $route, '/efs/v1/' ) !== 0 ) {
			return $response;
		}

		$cors_check = self::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			if ( self::$audit_logger ) {
				$origin = '';
				if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
					$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
				}
				self::$audit_logger->log_security_event(
					'cors_violation',
					'medium',
					'Global CORS enforcement blocked request',
					array(
						'origin' => $origin,
						'route'  => $route,
						'method' => $request->get_method(),
					)
				);
			}
			return $cors_check;
		}

		if ( self::$cors_manager ) {
			self::$cors_manager->add_cors_headers();
		} else {
			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
			if ( ! empty( $origin ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
				header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce, X-EFS-Source-Origin' );
				header( 'Vary: Origin' );
			}
		}

		return $response;
	}

	/**
	 * Normalize origin URL for comparison (scheme + host + port + path, no trailing slash).
	 *
	 * @param  string $url Raw URL or origin.
	 * @return string Normalized string; empty if unparseable.
	 */
	public static function normalize_origin_for_compare( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		$parsed = parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return rtrim( $url, '/' );
		}
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : 'http';
		$host   = strtolower( $parsed['host'] );
		$port   = isset( $parsed['port'] ) ? (int) $parsed['port'] : null;
		$path   = isset( $parsed['path'] ) && '' !== $parsed['path'] ? $parsed['path'] : '/';
		$path   = rtrim( $path, '/' );
		if ( '' === $path ) {
			$path = '/';
		}
		$default_port = ( 'https' === $scheme ) ? 443 : 80;
		$port_suffix  = ( null !== $port && $port !== $default_port ) ? ':' . $port : '';
		return $scheme . '://' . $host . $port_suffix . $path;
	}
}
