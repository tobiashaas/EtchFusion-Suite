<?php
/**
 * API Permission Callbacks for Etch Fusion Suite
 *
 * Handles REST API permission callbacks for authentication and authorization
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Security\EFS_CORS_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API Permission callbacks class.
 *
 * Provides permission callbacks for REST API endpoints.
 * Uses static methods for compatibility with WordPress REST API.
 *
 * @package Bricks2Etch\Api
 */
class EFS_API_Permissions {

	/**
	 * @var EFS_Migration_Token_Manager|null
	 */
	private static $token_manager;

	/**
	 * @var EFS_CORS_Manager|null
	 */
	private static $cors_manager;

	/**
	 * Set the token manager instance.
	 *
	 * @param EFS_Migration_Token_Manager $token_manager
	 */
	public static function set_token_manager( EFS_Migration_Token_Manager $token_manager ) {
		self::$token_manager = $token_manager;
	}

	/**
	 * Set the CORS manager instance.
	 *
	 * @param EFS_CORS_Manager $cors_manager
	 */
	public static function set_cors_manager( EFS_CORS_Manager $cors_manager ) {
		self::$cors_manager = $cors_manager;
	}

	/**
	 * Get bearer token from request.
	 *
	 * @param \WP_REST_Request $request
	 * @return string|null
	 */
	private static function get_bearer_token( $request ) {
		$auth = $request->get_header( 'Authorization' );
		if ( ! $auth ) {
			return null;
		}

		if ( 0 === strpos( $auth, 'Bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		return null;
	}

	/**
	 * Check CORS origin.
	 *
	 * @return true|\WP_Error
	 */
	private static function check_cors_origin() {
		if ( ! self::$cors_manager ) {
			return true; // No CORS check if manager not available
		}

		$origin = '';
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}

		// Non-browser or same-origin server-to-server requests often omit Origin.
		// CORS is a browser concern, so allow empty Origin values.
		if ( '' === $origin ) {
			return true;
		}

		if ( ! self::$cors_manager->is_origin_allowed( $origin ) ) {
			return new \WP_Error(
				'rest_cors_not_allowed',
				__( 'CORS origin not allowed.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate bearer migration token.
	 *
	 * @param \WP_REST_Request $request
	 * @return array|\WP_Error
	 */
	private static function validate_bearer_migration_token( $request ) {
		$token = self::get_bearer_token( $request );
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_authorization', __( 'Missing Bearer token.', 'etch-fusion-suite' ), array( 'status' => 401 ) );
		}

		if ( ! self::$token_manager ) {
			return new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$validated = self::$token_manager->validate_migration_token( $token );
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error( 'invalid_migration_token', $validated->get_error_message(), array( 'status' => 401 ) );
		}

		// Dynamically whitelist the source domain for CORS (1 hour TTL)
		if ( ! empty( $validated['domain'] ) && self::$cors_manager && method_exists( self::$cors_manager, 'whitelist_domain_temporarily' ) ) {
			self::$cors_manager->whitelist_domain_temporarily( $validated['domain'] );
		}

		return $validated;
	}

	/**
	 * Normalize origin URL for comparison.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function normalize_origin_for_compare( $url ) {
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

	/**
	 * Check source origin against token payload.
	 *
	 * @param \WP_REST_Request $request
	 * @param array             $token_payload
	 * @return true|\WP_Error
	 */
	private static function check_source_origin( $request, array $token_payload ) {
		$expected_source = isset( $token_payload['domain'] ) ? (string) $token_payload['domain'] : '';
		$expected_source = self::normalize_origin_for_compare( $expected_source );
		$source_header   = $request->get_header( 'X-EFS-Source-Origin' );
		$source_header   = self::normalize_origin_for_compare( $source_header );

		if ( '' === $expected_source ) {
			return new \WP_Error( 'source_mismatch', __( 'Source origin invalid.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		if ( '' === $source_header || ! hash_equals( $expected_source, $source_header ) ) {
			return new \WP_Error( 'source_mismatch', __( 'Source origin invalid.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Permission callback that allows public access.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function allow_public_request( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		return true;
	}

	/**
	 * Permission callback requiring WordPress administrator capability.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function require_admin_permission( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback with cookie fallback for proxy environments.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function require_admin_with_cookie_fallback( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// Standard path: REST middleware already set the current user.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Fallback: validate the logged-in cookie directly.
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( $user_id ) {
			wp_set_current_user( $user_id );
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access this endpoint.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Permission callback requiring a valid Bearer migration token.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function require_migration_token_permission( $request ) {
		$token_result = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token_result ) ) {
			return $token_result;
		}

		return true;
	}

	/**
	 * Permission callback accepting admin OR migration token in body.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function require_admin_or_body_migration_token_permission( $request ) {
		// Accept logged-in administrators.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Accept requests that carry a valid migration token in the JSON body.
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		if ( empty( $body['migration_key'] ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Authentication required: provide admin credentials or a valid migration token.', 'etch-fusion-suite' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::$token_manager ) {
			return new \WP_Error(
				'token_manager_unavailable',
				__( 'Token manager unavailable.', 'etch-fusion-suite' ),
				array( 'status' => 500 )
			);
		}

		$result = self::$token_manager->validate_migration_token( sanitize_text_field( $body['migration_key'] ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid or expired migration token.', 'etch-fusion-suite' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for /generate-key endpoint (validates pairing code).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function require_valid_pairing_code_permission( $request ) {
		$pairing_code = $request->get_param( 'pairing_code' );
		$pairing_code = is_string( $pairing_code ) ? trim( $pairing_code ) : '';

		if ( '' === $pairing_code ) {
			return new \WP_Error(
				'missing_pairing_code',
				__( 'Pairing code is required.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( ! self::$token_manager ) {
			return new \WP_Error(
				'token_manager_unavailable',
				__( 'Token manager unavailable.', 'etch-fusion-suite' ),
				array( 'status' => 500 )
			);
		}

		$pairing_check = self::$token_manager->validate_and_consume_pairing_code( $pairing_code );
		if ( is_wp_error( $pairing_check ) ) {
			return new \WP_Error(
				'invalid_pairing_code',
				$pairing_check->get_error_message(),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for read-only discovery endpoints.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function allow_read_only_discovery_permission( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return true;
	}
}
