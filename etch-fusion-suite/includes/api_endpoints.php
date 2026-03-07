<?php
/**
 * API Endpoints for Etch Fusion Suite
 *
 * Handles REST API endpoints for communication between source and target sites
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Core\EFS_Migration_Manager;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Security\EFS_CORS_Manager;
use Bricks2Etch\Api\EFS_API_Permissions;
use Bricks2Etch\Api\EFS_API_Migration_Endpoints;
use Bricks2Etch\Api\EFS_API_Rate_Limiting;
use Bricks2Etch\Api\EFS_API_CORS;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
	return true;
}

// Load API permissions class
require_once __DIR__ . '/class-permissions.php';

// Load rate limiting class
require_once __DIR__ . '/class-rate-limiting.php';

// Load CORS class
require_once __DIR__ . '/class-cors.php';

// Load migration endpoints class
require_once __DIR__ . '/class-migration-endpoints.php';

/**
 * EFS_API_Endpoints class.
 *
 * Handles REST API endpoints for communication between source and target sites.
 * Input validation mirrors the AJAX handlers by routing data through
 * EFS_Input_Validator::validate_request_data(), maintaining consistent sanitization rules.
 *
 * @package Bricks2Etch\Api
 */
class EFS_API_Endpoints {

	/**
	 * @var \Bricks2Etch\Container\EFS_Service_Container|null
	 */
	private static $container;

	/**
	 * @var \Bricks2Etch\Repositories\Interfaces\Settings_Repository_Interface|null
	 */
	private static $settings_repository;

	/**
	 * @var \Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface|null
	 */
	private static $migration_repository;

	/**
	 * @var \Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface|null
	 */
	private static $style_repository;

	/**
	 * @var \Bricks2Etch\Security\EFS_Rate_Limiter|null
	 */
	private static $rate_limiter;

	/**
	 * @var \Bricks2Etch\Security\EFS_Input_Validator|null
	 */
	private static $input_validator;

	/**
	 * @var \Bricks2Etch\Security\EFS_Audit_Logger|null
	 */
	private static $audit_logger;

	/**
	 * @var \Bricks2Etch\Security\EFS_CORS_Manager|null
	 */
	private static $cors_manager;

	/**
	 * @var EFS_Migrator_Registry|null
	 */
	private static $migrator_registry;

	/**
	 * Cached template controller instance.
	 *
	 * @var \Bricks2Etch\Controllers\EFS_Template_Controller|null
	 */
	private static $template_controller;

	/**
	 * Initialize the API endpoints
	 */
	public static function init() {
		\add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		\add_action( 'wp_ajax_efs_dismiss_migration_run', array( __CLASS__, 'dismiss_migration_run' ) );
		\add_action( 'wp_ajax_efs_get_dismissed_migration_runs', array( __CLASS__, 'get_dismissed_migration_runs' ) );
		\add_action( 'wp_ajax_efs_revoke_migration_key', array( __CLASS__, 'revoke_migration_key' ) );

		// Add CORS headers via rest_pre_serve_request, which fires before any output is sent.
		// This ensures headers are settable via header() for all /efs/v1/* responses,
		// including 4xx errors that the browser must be able to read cross-origin.
		\add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers_to_request' ), 10, 4 );
	}

	/**
	 * Persist dismissed migration run identifiers.
	 *
	 * @return void
	 */
	public static function dismiss_migration_run() {
		check_ajax_referer( 'efs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$migration_id = '';
		if ( isset( $_POST['migrationId'] ) ) {
			$migration_id = sanitize_text_field( wp_unslash( $_POST['migrationId'] ) );
		} elseif ( isset( $_POST['migration_id'] ) ) {
			$migration_id = sanitize_text_field( wp_unslash( $_POST['migration_id'] ) );
		}

		if ( '' === $migration_id ) {
			wp_send_json_error( array( 'code' => 'missing_migration_id' ), 400 );
		}

		$dismissed = get_option( 'efs_dismissed_migration_runs', array() );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		if ( ! in_array( $migration_id, $dismissed, true ) ) {
			$dismissed[] = $migration_id;
		}

		update_option( 'efs_dismissed_migration_runs', $dismissed );
		wp_send_json_success( array( 'dismissed' => $dismissed ) );
	}

	/**
	 * Return dismissed migration run identifiers.
	 *
	 * @return void
	 */
	public static function get_dismissed_migration_runs() {
		check_ajax_referer( 'efs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		$dismissed = get_option( 'efs_dismissed_migration_runs', array() );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		wp_send_json_success( array( 'dismissed' => $dismissed ) );
	}

	/**
	 * Revoke the currently active migration key.
	 *
	 * @return void
	 */
	public static function revoke_migration_key() {
		check_ajax_referer( 'efs_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}

		/**
	* @var EFS_Migration_Token_Manager $token_manager
*/
		$token_manager = self::resolve( 'token_manager' );
		if ( ! $token_manager ) {
			wp_send_json_error(
				array(
					'code'    => 'token_manager_unavailable',
					'message' => __( 'Token manager unavailable.', 'etch-fusion-suite' ),
				),
				500
			);
		}

		$revoked = $token_manager->revoke_current_migration_key();
		if ( ! $revoked ) {
			wp_send_json_error(
				array(
					'code'    => 'revoke_failed',
					'message' => __( 'Failed to revoke migration key.', 'etch-fusion-suite' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Migration key revoked.', 'etch-fusion-suite' ),
			)
		);
	}

	/**
	 * Provide saved templates listing via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_saved_templates_rest( $request ) {
		$rate = self::enforce_template_rate_limit( 'list', 30 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$template_controller = self::resolve( 'template_controller' );
		if ( ! $template_controller ) {
			return new \WP_Error( 'template_service_unavailable', __( 'Template controller not available.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response( $template_controller->get_saved_templates(), 200 );
	}

	/**
	 * Set the service container instance.
	 *
	 * @param \Bricks2Etch\Container\EFS_Service_Container $container
	 */
	public static function set_container( $container ) {
		self::$container = $container;

		// Resolve repositories from container
		if ( $container->has( 'settings_repository' ) ) {
			self::$settings_repository = $container->get( 'settings_repository' );
		}
		if ( $container->has( 'migration_repository' ) ) {
			self::$migration_repository = $container->get( 'migration_repository' );
		}
		if ( $container->has( 'style_repository' ) ) {
			self::$style_repository = $container->get( 'style_repository' );
		}

		// Resolve security services from container
		if ( $container->has( 'rate_limiter' ) ) {
			self::$rate_limiter = $container->get( 'rate_limiter' );
		}
		if ( $container->has( 'input_validator' ) ) {
			self::$input_validator = $container->get( 'input_validator' );
		}
		if ( $container->has( 'audit_logger' ) ) {
			self::$audit_logger = $container->get( 'audit_logger' );
		}
		if ( $container->has( 'cors_manager' ) ) {
			self::$cors_manager = $container->get( 'cors_manager' );
		}

		// Initialize permissions class with required services
		if ( $container->has( 'token_manager' ) ) {
			EFS_API_Permissions::set_token_manager( $container->get( 'token_manager' ) );
		}
		if ( $container->has( 'cors_manager' ) ) {
			EFS_API_Permissions::set_cors_manager( $container->get( 'cors_manager' ) );
		}

		if ( $container->has( 'migrator_registry' ) ) {
			self::$migrator_registry = $container->get( 'migrator_registry' );
		}
		if ( $container->has( 'template_controller' ) ) {
			self::$template_controller = $container->get( 'template_controller' );
		}
	}

	/**
	 * Resolve a service from the container.
	 *
	 * @param string $id
	 *
	 * @return mixed
	 */
	public static function resolve( $id ) {
		if ( 'template_controller' === $id && self::$template_controller ) {
			return self::$template_controller;
		}
		if ( self::$container && self::$container->has( $id ) ) {
			return self::$container->get( $id );
		}

		if ( class_exists( $id ) ) {
			return new $id();
		}

		return null;
	}

	/**
	 * Retrieve shared input validator instance.
	 *
	 * @return EFS_Input_Validator
	 */
	private static function get_input_validator() {
		if ( self::$input_validator instanceof EFS_Input_Validator ) {
			return self::$input_validator;
		}

		if ( self::$container && self::$container->has( 'input_validator' ) ) {
			self::$input_validator = self::$container->get( 'input_validator' );
			return self::$input_validator;
		}

		self::$input_validator = new EFS_Input_Validator();

		return self::$input_validator;
	}

	/**
	 * Validate request data using EFS_Input_Validator
	 *
	 * @param array $data
	 * @param array $rules
	 *
	 * @return array|WP_Error
	 */
	private static function validate_request_data( $data, $rules ) {
		try {
			return self::get_input_validator()->validate_request_data( $data, $rules );
		} catch ( \InvalidArgumentException $e ) {
			$details   = EFS_Input_Validator::get_last_error_details();
			$error_msg = EFS_Input_Validator::get_user_error_message( $details );

			return new \WP_Error(
				'invalid_input',
				sanitize_text_field( $error_msg ),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Check CORS origin for REST API endpoint
	 *
	 * @return \WP_Error|bool True if origin allowed, WP_Error if denied.
	 */
	private static function check_cors_origin() {
		if ( ! self::$cors_manager ) {
			return true; // No CORS check if manager not available
		}

		$origin = '';
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			// Direct superglobal access is safe because the value is immediately sanitized.
			// This is the only location where $_SERVER is touched directly for origin validation.
			$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
		}

		// Non-browser or same-origin server-to-server requests often omit Origin.
		// CORS is a browser concern, so allow empty Origin values.
		if ( '' === $origin ) {
			return true;
		}

		if ( ! self::$cors_manager->is_origin_allowed( $origin ) ) {
			// Log CORS violation
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
	 * is written, so header() calls are always safe. Covers every /efs/v1/* response
	 * including 4xx errors that the browser must be able to read cross-origin.
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param \WP_REST_Response $result  Result to send to the client.
	 * @param \WP_REST_Request  $request The request object.
	 * @param \WP_REST_Server   $server  Server instance.
	 * @return bool $served unchanged — we never short-circuit serving.
	 */
	public static function add_cors_headers_to_request( $served, $result, $request, $server ) {
		// Only handle /efs/v1/* endpoints; leave all other REST namespaces untouched.
		$route = $request->get_route();
		if ( strpos( $route, '/efs/v1/' ) !== 0 ) {
			return $served;
		}

		// Validate CORS origin; skip header injection for unauthorized origins.
		$cors_check = EFS_API_CORS::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			return $served;
		}

		// Inject CORS headers via the manager when available.
		// Fallback: manager may be absent during very early requests that arrive before
		// plugins_loaded has fired set_container(). In that case check_cors_origin() returns
		// true without validating the origin, so we still need to emit headers here.
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
		// Skip OPTIONS preflight requests (headers already handled by EFS_CORS_Manager::add_cors_headers())
		if ( $request->get_method() === 'OPTIONS' ) {
			return $response;
		}

		// Only check our own endpoints
		$route = $request->get_route();
		if ( strpos( $route, '/efs/v1/' ) !== 0 ) {
			return $response;
		}

		// Perform CORS check and set headers if allowed
		$cors_check = EFS_API_CORS::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			// Log the violation with route information
			if ( self::$audit_logger ) {
				$origin = '';
				// Origin is retrieved again so the audit log records the raw request value
				// even if check_cors_origin() wasn't executed for this request.
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

		// CORS check passed: add CORS headers via manager.
		// Apply the same fallback as add_cors_headers_to_request for consistency.
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
	 * Get plugin status
	 */
	public static function get_plugin_status( $request ) {
		// Check rate limit (30 requests per minute)
		$rate_check = EFS_API_Rate_Limiting::check_rate_limit( 'get_plugin_status', 30 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$plugin_detector = self::resolve( 'plugin_detector' );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'ok',
				'version' => ETCH_FUSION_SUITE_VERSION,
				'plugins' => array(
					'bricks_active' => $plugin_detector->is_bricks_active(),
					'etch_active'   => $plugin_detector->is_etch_active(),
				),
			),
			200
		);
	}

	/**
	 * Handle key-based migration request
	 */
	public static function handle_key_migration( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'handle_key_migration', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Check CORS origin
		$cors_check = EFS_API_CORS::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			return $cors_check;
		}

		try {
			$params        = $request->get_params();
			$migration_key = $params['migration_key'] ?? ( $params['token'] ?? null );
			$target_url    = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';

			if ( empty( $migration_key ) ) {
				return new \WP_Error( 'missing_migration_key', __( 'Migration key is required.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
			}

			/**
		* @var EFS_Migration_Token_Manager $token_manager
*/
			$token_manager     = self::resolve( 'token_manager' );
			$validation_result = $token_manager ? $token_manager->validate_migration_token( $migration_key ) : new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

			if ( is_wp_error( $validation_result ) ) {
				$error_code = $validation_result->get_error_code();
				$status     = in_array( $error_code, array( 'invalid_token', 'token_expired' ), true ) ? 401 : 400;
				$validation_result->add_data( array( 'status' => $status ) );
				return $validation_result;
			}

			$validated_token = $migration_key;
			$payload         = is_array( $validation_result ) ? $validation_result : array();
			$target          = ! empty( $target_url ) ? $target_url : ( $payload['target_url'] ?? '' );
			$source          = $payload['domain'] ?? '';

			if ( empty( $target ) ) {
				return new \WP_Error( 'missing_target', __( 'Target URL could not be determined from migration key.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
			}

			/**
		* @var EFS_Migration_Manager $migration_manager
*/
			$migration_manager = self::resolve( 'migration_manager' );
			if ( ! $migration_manager ) {
				return new \WP_Error( 'migration_service_unavailable', __( 'Migration service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
			}

			$result = $migration_manager->start_migration( $validated_token, $target );

			if ( is_wp_error( $result ) ) {
				return new \WP_Error( 'migration_failed', $result->get_error_message(), array( 'status' => $result->get_error_data()['status'] ?? 500 ) );
			}

			return new \WP_REST_Response(
				array(
					'success'       => true,
					'payload'       => $payload,
					'source_domain' => $source,
					'target_url'    => $target,
					'migration_id'  => $result['migrationId'] ?? '',
					'progress'      => $result['progress'] ?? array(),
					'validated_at'  => current_time( 'mysql' ),
				),
				200
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'migration_error', 'Migration failed: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')', array( 'status' => 500 ) );
		}
	}

	/**
	 * Generate a one-time pairing code (admin-only REST endpoint).
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_pairing_code_endpoint( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'generate_pairing_code', 5, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$token_manager = self::resolve( 'token_manager' );
		if ( ! $token_manager ) {
			return new \WP_Error( 'token_manager_unavailable', 'Token manager unavailable.', array( 'status' => 500 ) );
		}

		$code = $token_manager->generate_pairing_code();

		return new \WP_REST_Response(
			array(
				'success'    => true,
				'code'       => strtoupper( substr( $code, 0, 4 ) ) . '-' . strtoupper( substr( $code, 4 ) ),
				'raw_code'   => $code,
				'expires_in' => 15 * MINUTE_IN_SECONDS,
				'expires_at' => wp_date( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS, new \DateTimeZone( 'UTC' ) ),
			),
			200
		);
	}

	/**
	 * Generate migration key endpoint.
	 *
	 * Supports two call patterns:
	 *
	 * 1. Admin-generate (legacy): target admin calls this with optional `target_url`.
	 *    Token domain = home_url() (this site).
	 *
	 * 2. Reverse-generation (new): source dashboard calls this with `source_url` set
	 *    to the source site's home URL. Token is generated and stored on this (target)
	 *    site; domain = source_url so that check_source_origin() validates import calls.
	 *    Response includes an `endpoints` map so the source can discover REST paths.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function generate_migration_key( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'generate_migration_key', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// No CORS check here: this endpoint is intentionally open to cross-origin requests.
		// The Bricks source dashboard calls it from an unknown origin; the pairing code
		// provides authentication. See enforce_cors_globally() for the bypass.

		// Pairing validation is handled in require_valid_pairing_code_permission().
		// Do not consume the one-time code again here.

		$source_url = $request->get_param( 'source_url' );
		$source_url = ( is_string( $source_url ) && '' !== trim( $source_url ) )
		? esc_url_raw( trim( $source_url ) )
		: '';

		try {
			/**
		* @var EFS_Migration_Token_Manager $token_manager
*/
			$token_manager = self::resolve( 'token_manager' );
			$token_data    = $token_manager
			? $token_manager->generate_migration_token( null, null, $source_url )
			: new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

			if ( is_wp_error( $token_data ) ) {
				return new \WP_Error( 'token_generation_failed', $token_data->get_error_message(), array( 'status' => 500 ) );
			}

			return new \WP_REST_Response(
				array(
					'success'                    => true,
					'message'                    => $token_data['message'] ?? __( 'Migration key generated.', 'etch-fusion-suite' ),
					'migration_key'              => $token_data['token'],
					'migration_url'              => $token_data['migration_url'] ?? '',
					'domain'                     => $token_data['domain'] ?? home_url(),
					'expires'                    => $token_data['expires'],
					'expires_at'                 => $token_data['expires_at'],
					'expiration_seconds'         => $token_data['expiration_seconds'] ?? EFS_Migration_Token_Manager::TOKEN_EXPIRATION,
					'generated_at'               => current_time( 'mysql' ),
					'https_warning'              => $token_data['https_warning'] ?? false,
					'security_warning'           => $token_data['security_warning'] ?? '',
					'security'                   => $token_data['security'] ?? array(),
					'treat_as_password_note'     => $token_data['treat_as_password_note'] ?? '',
					'invalidated_previous_token' => $token_data['invalidated_previous_token'] ?? false,
					'payload'                    => $token_data['payload'],
					// Endpoint map returned to the source so it can discover REST paths.
					'endpoints'                  => array(
						'exportPostTypes' => '/wp-json/efs/v1/export/post-types',
						'importCpts'      => '/wp-json/efs/v1/import/cpts',
						'importAcf'       => '/wp-json/efs/v1/import/acf-field-groups',
						'importMetabox'   => '/wp-json/efs/v1/import/metabox-configs',
						'importCss'       => '/wp-json/efs/v1/import/css-classes',
						'importPost'      => '/wp-json/efs/v1/import/post',
						'importPostMeta'  => '/wp-json/efs/v1/import/post-meta',
						'receiveMedia'    => '/wp-json/efs/v1/receive-media',
					),
				),
				200
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'generation_error', 'Failed to generate migration key: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Validate migration token endpoint
	 * Used by the "Validate Key" button to test API connection
	 */
	public static function validate_migration_token( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'validate_migration_token', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Check CORS origin
		$cors_check = EFS_API_CORS::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			return $cors_check;
		}

		try {
			$raw_body = $request->get_json_params();
			$body     = is_array( $raw_body ) ? $raw_body : array();

			if ( empty( $body['migration_key'] ) ) {
				return new \WP_Error( 'missing_parameters', 'Migration key parameter is required', array( 'status' => 400 ) );
			}

			$validated = self::validate_request_data(
				$body,
				array(
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
				)
			);

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$migration_key = $validated['migration_key'];

			/**
		* @var EFS_Migration_Token_Manager $token_manager
*/
			$token_manager     = self::resolve( 'token_manager' );
			$validation_result = $token_manager ? $token_manager->validate_migration_token( $migration_key ) : new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

			if ( is_wp_error( $validation_result ) ) {
				$sanitized_message = $validation_result->get_error_message();
				return new \WP_Error( 'migration_key_validation_failed', $sanitized_message, array( 'status' => 400 ) );
			}

			$payload       = is_array( $validation_result ) ? $validation_result : array();
			$target_domain = $payload['domain'] ?? home_url();

			return new \WP_REST_Response(
				array(
					'success'           => true,
					'message'           => 'Migration token verified successfully.',
					'target_domain'     => $target_domain,
					'site_name'         => get_bloginfo( 'name' ),
					'wordpress_version' => get_bloginfo( 'version' ),
					'etch_active'       => class_exists( 'Etch\Plugin' ) || function_exists( 'etch_run_plugin' ),
					'validated_at'      => current_time( 'mysql' ),
					'payload'           => $payload,
				),
				200
			);

		} catch ( \Exception $e ) {
			return new \WP_Error( 'validation_error', 'Token validation failed: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Enforce template rate limits.
	 *
	 * @param  string $action Action name.
	 * @param  int    $limit  Requests per window.
	 * @param  int    $window Window in seconds.
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit( $action, $limit, $window = 60 ) {
		return self::handle_rate_limit( $action, $limit, $window );
	}

	/**
	 * Internal helper to check and record rate limits.
	 *
	 * @param  string $action Action key.
	 * @param  int    $limit  Limit count.
	 * @param  int    $window Window seconds.
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

	/**
	 * Validate connection credentials.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function validate_connection( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$validated = self::validate_request_data(
			$params,
			array(
				'migration_key' => array(
					'type'     => 'migration_key',
					'required' => true,
				),
			)
		);

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		/**
	* @var EFS_Migration_Token_Manager $token_manager
*/
		$token_manager = self::resolve( 'token_manager' );
		$decoded       = $token_manager ? $token_manager->decode_migration_key_locally( $validated['migration_key'] ) : new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

		if ( is_wp_error( $decoded ) ) {
			return new \WP_Error( 'migration_key_invalid', $decoded->get_error_message(), array( 'status' => 400 ) );
		}

		return new \WP_REST_Response(
			array(
				'valid'      => true,
				'message'    => __( 'Connection validated successfully.', 'etch-fusion-suite' ),
				'payload'    => $decoded['payload'] ?? array(),
				'target_url' => $decoded['payload']['target_url'] ?? '',
			),
			200
		);
	}

	/**
	 * Extract Bearer token from Authorization header.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return string
	 */
	private static function get_bearer_token( $request ) {
		$header   = $request->get_header( 'authorization' );
		$fallback = $request->get_header( 'x-efs-migration-key' );

		if ( is_string( $fallback ) && '' !== trim( $fallback ) ) {
			return trim( $fallback );
		}

		if ( empty( $header ) && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( ! is_string( $header ) ) {
			return '';
		}

		if ( preg_match( '/Bearer\s+(.+)/i', $header, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Validate Bearer migration token for import endpoints.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return array|\WP_Error
	 */
	public static function validate_bearer_migration_token( $request ) {
		$token = self::get_bearer_token( $request );
		if ( empty( $token ) ) {
			return new \WP_Error( 'missing_authorization', __( 'Missing Bearer token.', 'etch-fusion-suite' ), array( 'status' => 401 ) );
		}

		/**
	* @var EFS_Migration_Token_Manager $token_manager
*/
		$token_manager = self::resolve( 'token_manager' );
		if ( ! $token_manager ) {
			return new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$validated = $token_manager->validate_migration_token( $token );
		if ( is_wp_error( $validated ) ) {
			return new \WP_Error( 'invalid_migration_token', $validated->get_error_message(), array( 'status' => 401 ) );
		}

		// Dynamically whitelist the source domain for CORS (1 hour TTL)
		// This eliminates the need for manual CORS whitelist configuration in production
		if ( ! empty( $validated['domain'] ) ) {
			$cors_manager = self::resolve( 'cors_manager' );
			if ( $cors_manager && method_exists( $cors_manager, 'whitelist_domain_temporarily' ) ) {
				$cors_manager->whitelist_domain_temporarily( $validated['domain'] );
			}
		}

		return $validated;
	}

	/**
	 * Normalize origin URL for comparison (scheme + host + port + path, no trailing slash).
	 *
	 * @param  string $url Raw URL or origin.
	 * @return string Normalized string; empty if unparseable.
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
	 * Verify X-EFS-Source-Origin header matches the token's domain (SEC-001 hardening).
	 *
	 * Call after token validation in import/receive handlers. Blocks abuse from
	 * external origins while preserving source→target migration calls.
	 * Comparison uses normalized origins (scheme/host/port/path) to avoid 403 from
	 * trailing slash or case differences.
	 *
	 * @param  \WP_REST_Request $request       REST request.
	 * @param  array            $token_payload Validated token payload (must contain 'domain').
	 * @return true|\WP_Error True if header matches; WP_Error 403 on mismatch or missing.
	 */
	public static function check_source_origin( $request, array $token_payload ) {
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
	 * Resolve migration repository from container fallback.
	 *
	 * @return \Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface|null
	 */
	public static function resolve_migration_repository() {
		if ( self::$migration_repository ) {
			return self::$migration_repository;
		}

		$repository = self::resolve( 'migration_repository' );
		if ( $repository ) {
			self::$migration_repository = $repository;
		}

		return self::$migration_repository;
	}

	/**
	 * Update receiving state from the latest persisted value.
	 *
	 * @param callable $mutator Callback that receives the current state and returns the next state.
	 * @return void
	 */
	private static function mutate_receiving_state( callable $mutator ): void {
		$repository = self::resolve_migration_repository();
		if ( ! $repository || ! method_exists( $repository, 'get_receiving_state' ) || ! method_exists( $repository, 'save_receiving_state' ) ) {
			return;
		}

		if ( method_exists( $repository, 'update_receiving_state' ) ) {
			$repository->update_receiving_state( $mutator );
			return;
		}

		$current = $repository->get_receiving_state();
		$current = is_array( $current ) ? $current : array();
		$next    = $mutator( $current );

		if ( is_array( $next ) ) {
			$repository->save_receiving_state( $next );
		}
	}

	/**
	 * Record receiving activity for target-side progress polling.
	 * Used for single-type phases (media, css) where all items share one type key.
	 *
	 * Also reads X-EFS-Phase-Total to populate the per-type total for the current phase,
	 * enabling the Etch-side UI to show a breakdown like "Media: 150/897".
	 *
	 * @param  array                 $token_payload   Validated token payload.
	 * @param  string                $phase           Current import phase ('media', 'css', etc.).
	 * @param  int                   $items_increment How much to increment items received.
	 * @param  \WP_REST_Request|null $request         Optional. Request object for EFS headers.
	 * @return void
	 */
	public static function touch_receiving_state( array $token_payload, string $phase, int $items_increment = 0, $request = null ): void {
		$source_site        = '';
		$items_total_header = 0;
		$phase_total_header = 0;
		if ( $request instanceof \WP_REST_Request ) {
			$header = $request->get_header( 'X-EFS-Source-Origin' );
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				$source_site = esc_url_raw( trim( $header ) );
			}
			$items_total_raw    = $request->get_header( 'X-EFS-Items-Total' );
			$items_total_header = is_string( $items_total_raw ) ? (int) $items_total_raw : 0;
			$phase_total_raw    = $request->get_header( 'X-EFS-Phase-Total' );
			$phase_total_header = is_string( $phase_total_raw ) ? (int) $phase_total_raw : 0;
		}
		if ( '' === $source_site && isset( $token_payload['domain'] ) ) {
			$source_site = esc_url_raw( (string) $token_payload['domain'] );
		}

		self::mutate_receiving_state(
			static function ( array $current ) use ( $items_increment, $items_total_header, $phase, $phase_total_header, $source_site, $token_payload ): array {
				$now        = current_time( 'mysql', true );
				$started_at = isset( $current['started_at'] ) && '' !== (string) $current['started_at'] ? $current['started_at'] : $now;
				$items      = isset( $current['items_received'] ) ? (int) $current['items_received'] : 0;
				$items_by_type = isset( $current['items_by_type'] ) && is_array( $current['items_by_type'] ) ? $current['items_by_type'] : array();
				$type_key      = sanitize_key( $phase );

				if ( ! isset( $items_by_type[ $type_key ] ) ) {
					$items_by_type[ $type_key ] = array(
						'received' => 0,
						'total'    => 0,
					);
				}

				$items_by_type[ $type_key ]['received'] = max( 0, $items_by_type[ $type_key ]['received'] + $items_increment );
				if ( $phase_total_header > 0 ) {
					$items_by_type[ $type_key ]['total'] = $phase_total_header;
				}

				$total_received = array_sum( array_column( $items_by_type, 'received' ) );
				$total_items    = array_sum( array_column( $items_by_type, 'total' ) );

				return array(
					'status'         => 'receiving',
					'source_site'    => $source_site,
					'migration_id'   => isset( $token_payload['jti'] ) ? sanitize_text_field( (string) $token_payload['jti'] ) : '',
					'started_at'     => $started_at,
					'last_activity'  => $now,
					'last_updated'   => $now,
					'current_phase'  => $type_key,
					'items_received' => max( 0, $total_received > 0 ? $total_received : ( $items + $items_increment ) ),
					'items_total'    => max( $items_total_header, $total_items ),
					'items_by_type'  => $items_by_type,
					'is_stale'       => false,
				);
			}
		);
	}

	/**
	 * Initialize receiving state with all phase totals upfront.
	 * Called at the start of migration so Etch UI can show 0/X for all phases.
	 *
	 * @param array                 $token_payload Validated token payload.
	 * @param array                 $totals        Associative array: [ 'media' => 500, 'css' => 3000, 'posts' => ['post' => 100, 'page' => 250] ].
	 * @param \WP_REST_Request|null $request       Optional. Request object for EFS headers.
	 * @return void
	 */
	public static function init_receiving_state_totals( array $token_payload, array $totals, $request = null ): void {
		$source_site = '';
		if ( $request instanceof \WP_REST_Request ) {
			$header = $request->get_header( 'X-EFS-Source-Origin' );
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				$source_site = esc_url_raw( trim( $header ) );
			}
		}
		if ( '' === $source_site && isset( $token_payload['domain'] ) ) {
			$source_site = esc_url_raw( (string) $token_payload['domain'] );
		}

		self::mutate_receiving_state(
			static function ( array $current ) use ( $source_site, $token_payload, $totals ): array {
				$now                  = current_time( 'mysql', true );
				$new_migration_id     = isset( $token_payload['jti'] ) ? sanitize_text_field( (string) $token_payload['jti'] ) : '';
				$current_migration_id = isset( $current['migration_id'] ) ? (string) $current['migration_id'] : '';
				$started_at           = $new_migration_id !== $current_migration_id
					? $now
					: ( isset( $current['started_at'] ) && '' !== (string) $current['started_at'] ? $current['started_at'] : $now );
				$items_by_type        = array();

				foreach ( $totals as $phase => $data ) {
					$type_key = sanitize_key( $phase );
					if ( is_array( $data ) ) {
						foreach ( $data as $sub_type => $count ) {
							$sub_key = sanitize_key( $sub_type );
							$items_by_type[ $sub_key ] = array(
								'received' => 0,
								'total'    => max( 0, (int) $count ),
							);
						}
					} else {
						$items_by_type[ $type_key ] = array(
							'received' => 0,
							'total'    => max( 0, (int) $data ),
						);
					}
				}

				$items_total = 0;
				foreach ( $items_by_type as $type_data ) {
					$items_total += $type_data['total'];
				}

				return array(
					'status'         => 'receiving',
					'source_site'    => $source_site,
					'migration_id'   => $new_migration_id,
					'started_at'     => $started_at,
					'last_activity'  => $now,
					'last_updated'   => $now,
					'current_phase'  => '',
					'items_received' => 0,
					'items_total'    => $items_total,
					'items_by_type'  => $items_by_type,
					'is_stale'       => false,
				);
			}
		);
	}

	/**
	 * Record receiving activity for a post-batch where items span multiple post types.
	 *
	 * Reads X-EFS-PostType-Totals to set per-type totals, and increments per-type received
	 * counts from $type_increments, enabling the Etch-side UI to show e.g. "Posts: 3/200 · Pages: 1/150".
	 *
	 * @param  array                 $token_payload   Validated token payload.
	 * @param  string                $phase           Current import phase (typically 'posts').
	 * @param  array<string,int>     $type_increments Map of post_type → count for this batch.
	 * @param  \WP_REST_Request|null $request         Optional. Request object for EFS headers.
	 * @return void
	 */
	public static function touch_receiving_state_by_types( array $token_payload, string $phase, array $type_increments, $request = null ): void {
		$source_site        = '';
		$items_total_header = 0;
		$post_type_totals   = array();
		if ( $request instanceof \WP_REST_Request ) {
			$header = $request->get_header( 'X-EFS-Source-Origin' );
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				$source_site = esc_url_raw( trim( $header ) );
			}
			$items_total_raw    = $request->get_header( 'X-EFS-Items-Total' );
			$items_total_header = is_string( $items_total_raw ) ? (int) $items_total_raw : 0;
			// X-EFS-PostType-Totals is a JSON object: { "post": 200, "page": 150, ... }.
			$pt_totals_raw = $request->get_header( 'X-EFS-PostType-Totals' );
			if ( is_string( $pt_totals_raw ) && '' !== $pt_totals_raw ) {
				$decoded = json_decode( $pt_totals_raw, true );
				if ( is_array( $decoded ) ) {
					$post_type_totals = $decoded;
				}
			}
		}
		if ( '' === $source_site && isset( $token_payload['domain'] ) ) {
			$source_site = esc_url_raw( (string) $token_payload['domain'] );
		}

		self::mutate_receiving_state(
			static function ( array $current ) use ( $items_total_header, $phase, $post_type_totals, $source_site, $token_payload, $type_increments ): array {
				$now        = current_time( 'mysql', true );
				$started_at = isset( $current['started_at'] ) && '' !== (string) $current['started_at'] ? $current['started_at'] : $now;
				$items_by_type = isset( $current['items_by_type'] ) && is_array( $current['items_by_type'] ) ? $current['items_by_type'] : array();

				foreach ( $post_type_totals as $pt => $total ) {
					$pt = sanitize_key( (string) $pt );
					if ( '' === $pt ) {
						continue;
					}
					if ( ! isset( $items_by_type[ $pt ] ) ) {
						$items_by_type[ $pt ] = array(
							'received' => 0,
							'total'    => 0,
						);
					}
					$items_by_type[ $pt ]['total'] = max( $items_by_type[ $pt ]['total'], (int) $total );
				}

				foreach ( $type_increments as $pt => $count ) {
					$pt    = sanitize_key( (string) $pt );
					$count = max( 0, (int) $count );
					if ( '' === $pt || 0 === $count ) {
						continue;
					}
					if ( ! isset( $items_by_type[ $pt ] ) ) {
						$items_by_type[ $pt ] = array(
							'received' => 0,
							'total'    => 0,
						);
					}
					$items_by_type[ $pt ]['received'] += $count;
				}

				$total_received = array_sum( array_column( $items_by_type, 'received' ) );
				$total_items    = array_sum( array_column( $items_by_type, 'total' ) );

				return array(
					'status'         => 'receiving',
					'source_site'    => $source_site,
					'migration_id'   => isset( $token_payload['jti'] ) ? sanitize_text_field( (string) $token_payload['jti'] ) : '',
					'started_at'     => $started_at,
					'last_activity'  => $now,
					'last_updated'   => $now,
					'current_phase'  => sanitize_key( $phase ),
					'items_received' => max( 0, $total_received ),
					'items_total'    => max( $items_total_header, $total_items ),
					'items_by_type'  => $items_by_type,
					'is_stale'       => false,
				);
			}
		);
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		$namespace = 'efs/v1';

		register_rest_route(
			$namespace,
			'/status',
			array(
				'callback'            => array( __CLASS__, 'get_plugin_status' ),
				'permission_callback' => array( __CLASS__, 'allow_public_request' ),
				'methods'             => \WP_REST_Server::READABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/migrate',
			array(
				'callback'            => array( __CLASS__, 'handle_key_migration' ),
				'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
				'methods'             => \WP_REST_Server::READABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/generate-key',
			array(
				'callback'            => array( __CLASS__, 'generate_migration_key' ),
				// The pairing code in the request IS the authentication for this endpoint.
				// Validation happens in require_valid_pairing_code_permission().
				// CORS enforcement happens globally; rate limiting: 10 req/60s.
				'permission_callback' => array( __CLASS__, 'require_valid_pairing_code_permission' ),
				'methods'             => array( 'GET', 'POST' ),
			)
		);

		register_rest_route(
			$namespace,
			'/generate-pairing-code',
			array(
				'callback'            => array( __CLASS__, 'generate_pairing_code_endpoint' ),
				'permission_callback' => array( __CLASS__, 'require_admin_with_cookie_fallback' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/validate',
			array(
				'callback'            => array( __CLASS__, 'validate_migration_token' ),
				'permission_callback' => array( __CLASS__, 'require_admin_or_body_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/auth/validate',
			array(
				'callback'            => array( __CLASS__, 'validate_connection' ),
				'permission_callback' => array( __CLASS__, 'allow_public_request' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/export/post-types',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'export_post_types' ),
				'permission_callback' => array( __CLASS__, 'allow_read_only_discovery_permission' ),
				'methods'             => \WP_REST_Server::READABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/cpts',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_cpts' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/acf-field-groups',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_acf_field_groups' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/metabox-configs',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_metabox_configs' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/init-totals',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_init_totals' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/css-classes',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_css_classes' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/global-css',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_global_css' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/post',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_post' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/posts',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_posts' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/post-meta',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'import_post_meta' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/receive-media',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'receive_media' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/complete',
			array(
				'callback'            => array( 'Bricks2Etch\Api\EFS_API_Migration_Endpoints', 'complete_migration' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);
	}

	/**
	 * Permission callback allowing public access while enforcing CORS.
	 *
	 * Used only for genuinely public read-only endpoints (e.g. /status, /auth/validate,
	 * /export/post-types). Sensitive write endpoints (/validate, all /import/*, /receive-media)
	 * use require_admin_or_body_migration_token_permission or require_migration_token_permission
	 * so that unauthenticated requests are rejected before the handler executes.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function allow_public_request( $request ) {
		return EFS_API_Permissions::allow_public_request( $request );
	}

	/**
	 * Permission callback requiring WordPress administrator capability.
	 *
	 * Enforces that the caller is a logged-in user with manage_options capability,
	 * authenticated via WordPress cookie + REST nonce or application password.
	 * Use this for admin-browser-facing routes such as key generation, migration
	 * triggering, and template management.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_admin_permission( $request ) {
		return EFS_API_Permissions::require_admin_permission( $request );
	}

	/**
	 * Permission callback for admin-only endpoints on hosts where the standard
	 * WordPress REST cookie authentication middleware may be intercepted or
	 * stripped by a proxy/CDN before reaching WordPress.
	 *
	 * First tries the standard REST auth path (current_user_can). If that fails
	 * it falls back to validating the WordPress logged-in cookie directly via
	 * wp_validate_auth_cookie(), which reads $_COOKIE and bypasses the REST
	 * authentication middleware entirely.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public static function require_admin_with_cookie_fallback( $request ) {
		return EFS_API_Permissions::require_admin_with_cookie_fallback( $request );
	}

	/**
	 * Permission callback requiring a valid Bearer migration token.
	 *
	 * Used for cross-site server-to-server endpoints called by the source
	 * site's migration process. Access is controlled by the signed migration
	 * token rather than a WordPress user session, since the calling server
	 * has no cookie session on this site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_migration_token_permission( $request ) {
		return EFS_API_Permissions::require_migration_token_permission( $request );
	}

	/**
	 * Permission callback accepting a logged-in admin OR a valid migration token in the request body.
	 *
	 * Used for the /validate endpoint which may be called either by a browser admin
	 * session (cookie + nonce) or by a server-to-server request that includes the
	 * migration_key in the JSON body. Unauthenticated requests — those presenting
	 * neither a manage_options WordPress session nor a verifiable token — are
	 * rejected before the handler executes.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_admin_or_body_migration_token_permission( $request ) {
		return EFS_API_Permissions::require_admin_or_body_migration_token_permission( $request );
	}

	/**
	 * Permission callback for /generate-key endpoint.
	 *
	 * Validates the pairing code provided in the request. The pairing code is a
	 * single-use 6-digit code generated by an admin on the Etch dashboard. This
	 * callback extracts and validates the code before the handler is invoked.
	 *
	 * Rate limiting (10 req/60s) and CORS enforcement are applied globally.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function require_valid_pairing_code_permission( $request ) {
		return EFS_API_Permissions::require_valid_pairing_code_permission( $request );
	}

	/**
	 * Permission callback for /export/post-types endpoint.
	 *
	 * Public read-only endpoint for discovering available post types. No sensitive
	 * data is mutated. Access is protected by global rate limiting (60 req/60s) and
	 * CORS enforcement. Returns only structural metadata (post type names).
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function allow_read_only_discovery_permission( $request ) {
		return EFS_API_Permissions::allow_read_only_discovery_permission( $request );
	}
}
