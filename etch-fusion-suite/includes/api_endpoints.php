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

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
	return true;
}

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
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_ajax_efs_dismiss_migration_run', array( __CLASS__, 'dismiss_migration_run' ) );
		add_action( 'wp_ajax_efs_get_dismissed_migration_runs', array( __CLASS__, 'get_dismissed_migration_runs' ) );
		add_action( 'wp_ajax_efs_revoke_migration_key', array( __CLASS__, 'revoke_migration_key' ) );

		// Add global CORS enforcement filter
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enforce_cors_globally' ), 10, 3 );
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
	 * Handle template extraction via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function extract_template_rest( $request ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new \WP_Error( 'framer_disabled', __( 'Framer template extraction is not available.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		$rate = self::enforce_template_rate_limit( 'extract', 15 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$template_controller = self::resolve( 'template_controller' );
		if ( ! $template_controller ) {
			return new \WP_Error( 'template_service_unavailable', __( 'Template extractor is not available.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$raw_params = $request->get_json_params();
		$params     = self::validate_request_data(
			is_array( $raw_params ) ? $raw_params : array(),
			array(
				'source'      => array(
					'type'       => 'text',
					'required'   => true,
					'max_length' => 2048,
				),
				'source_type' => array(
					'type'       => 'text',
					'required'   => true,
					'max_length' => 10,
				),
			)
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$source      = $params['source'];
		$source_type = $params['source_type'];

		$result = $template_controller->extract_template( $source, $source_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Provide saved templates listing via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_saved_templates_rest( $request ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new \WP_Error( 'framer_disabled', __( 'Framer template extraction is not available.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

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
	 * Preview template content via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_template_rest( $request ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new \WP_Error( 'framer_disabled', __( 'Framer template extraction is not available.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		$rate = self::enforce_template_rate_limit( 'preview', 25 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$template_controller = self::resolve( 'template_controller' );
		if ( ! $template_controller ) {
			return new \WP_Error( 'template_service_unavailable', __( 'Template controller not available.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$template_id = (int) $request->get_param( 'id' );
		if ( $template_id <= 0 ) {
			return new \WP_Error( 'invalid_template_id', __( 'Template ID must be a positive integer.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$result = $template_controller->preview_template( $template_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Delete a saved template via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_template_rest( $request ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new \WP_Error( 'framer_disabled', __( 'Framer template extraction is not available.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		$rate = self::enforce_template_rate_limit( 'delete', 15 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$template_controller = self::resolve( 'template_controller' );
		if ( ! $template_controller ) {
			return new \WP_Error( 'template_service_unavailable', __( 'Template controller not available.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$template_id = (int) $request->get_param( 'id' );
		if ( $template_id <= 0 ) {
			return new \WP_Error( 'invalid_template_id', __( 'Template ID must be provided.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$result = $template_controller->delete_template( $template_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Import template payload via REST.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_template_rest( $request ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new \WP_Error( 'framer_disabled', __( 'Framer template extraction is not available.', 'etch-fusion-suite' ), array( 'status' => 403 ) );
		}

		$rate = self::enforce_template_rate_limit( 'import', 10 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$template_controller = self::resolve( 'template_controller' );
		if ( ! $template_controller ) {
			return new \WP_Error( 'template_service_unavailable', __( 'Template controller not available.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$raw_params = $request->get_json_params();
		$params     = self::validate_request_data(
			is_array( $raw_params ) ? $raw_params : array(),
			array(
				'payload' => array(
					'type'     => 'array',
					'required' => true,
				),
				'name'    => array(
					'type'       => 'text',
					'required'   => false,
					'max_length' => 255,
				),
			)
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$payload = $params['payload'];
		$name    = isset( $params['name'] ) ? $params['name'] : null;

		$result = $template_controller->import_template( $payload, $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'id' => $result ), 201 );
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
	private static function resolve( $id ) {
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

		// /generate-key is intentionally CORS-exempt: the Bricks source dashboard calls it
		// cross-origin and the source origin is unknown at setup time. The pairing code
		// provides authentication; CORS here would create a chicken-and-egg deadlock.
		if ( false !== strpos( $route, '/generate-key' ) ) {
			return $response;
		}

		// Perform CORS check
		$cors_check = self::check_cors_origin();
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

		return $response;
	}

	/**
	 * Get plugin status
	 */
	public static function get_plugin_status( $request ) {
		// Check rate limit (30 requests per minute)
		$rate_check = self::check_rate_limit( 'get_plugin_status', 30 );
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
		$rate = self::check_rate_limit( 'handle_key_migration', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Check CORS origin
		$cors_check = self::check_cors_origin();
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
		$rate = self::check_rate_limit( 'generate_pairing_code', 5, 60 );
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
		$rate = self::check_rate_limit( 'generate_migration_key', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// No CORS check here: this endpoint is intentionally open to cross-origin requests.
		// The Bricks source dashboard calls it from an unknown origin; the pairing code
		// provides authentication. See enforce_cors_globally() for the bypass.

		// Pairing code validation (required).
		$pairing_code = $request->get_param( 'pairing_code' );
		$pairing_code = is_string( $pairing_code ) ? trim( $pairing_code ) : '';
		if ( '' === $pairing_code ) {
			return new \WP_Error( 'missing_pairing_code', 'A pairing code is required.', array( 'status' => 403 ) );
		}
		$token_manager_for_pairing = self::resolve( 'token_manager' );
		if ( $token_manager_for_pairing ) {
			$pairing_check = $token_manager_for_pairing->validate_and_consume_pairing_code( $pairing_code );
			if ( is_wp_error( $pairing_check ) ) {
				return new \WP_Error( 'invalid_pairing_code', $pairing_check->get_error_message(), array( 'status' => 403 ) );
			}
		}

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
		$rate = self::check_rate_limit( 'validate_migration_token', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// Check CORS origin
		$cors_check = self::check_cors_origin();
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
	public static function enforce_template_rate_limit( $action, $limit, $window = 60 ) {
		return self::handle_rate_limit( $action, $limit, $window );
	}

	/**
	 * Check rate limit without recording on success.
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
	private static function validate_bearer_migration_token( $request ) {
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
	 * Resolve migration repository from container fallback.
	 *
	 * @return \Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface|null
	 */
	private static function resolve_migration_repository() {
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
	 * Record receiving activity for target-side progress polling.
	 *
	 * @param  array                 $token_payload   Validated token payload.
	 * @param  string                $phase           Current import phase.
	 * @param  int                   $items_increment How much to increment items received.
	 * @param  \WP_REST_Request|null $request         Optional. Request object to read X-EFS-Source-Origin (real source site).
	 * @return void
	 */
	private static function touch_receiving_state( array $token_payload, string $phase, int $items_increment = 0, $request = null ): void {
		$repository = self::resolve_migration_repository();
		if ( ! $repository || ! method_exists( $repository, 'get_receiving_state' ) || ! method_exists( $repository, 'save_receiving_state' ) ) {
			return;
		}

		$current = $repository->get_receiving_state();
		$current = is_array( $current ) ? $current : array();

		$now        = current_time( 'mysql' );
		$started_at = isset( $current['started_at'] ) && '' !== (string) $current['started_at'] ? $current['started_at'] : $now;
		$items      = isset( $current['items_received'] ) ? (int) $current['items_received'] : 0;

		$source_site        = '';
		$items_total_header = 0;
		if ( $request instanceof \WP_REST_Request ) {
			$header = $request->get_header( 'X-EFS-Source-Origin' );
			if ( is_string( $header ) && '' !== trim( $header ) ) {
				$source_site = esc_url_raw( trim( $header ) );
			}
			$items_total_raw    = $request->get_header( 'X-EFS-Items-Total' );
			$items_total_header = is_string( $items_total_raw ) ? (int) $items_total_raw : 0;
		}
		if ( '' === $source_site && isset( $token_payload['domain'] ) ) {
			$source_site = esc_url_raw( (string) $token_payload['domain'] );
		}

		$repository->save_receiving_state(
			array(
				'status'         => 'receiving',
				'source_site'    => $source_site,
				'migration_id'   => isset( $token_payload['jti'] ) ? sanitize_text_field( (string) $token_payload['jti'] ) : '',
				'started_at'     => $started_at,
				'last_activity'  => $now,
				'last_updated'   => $now,
				'current_phase'  => sanitize_key( $phase ),
				'items_received' => max( 0, $items + $items_increment ),
				'items_total'    => $items_total_header > 0 ? $items_total_header : (int) ( $current['items_total'] ?? 0 ),
				'is_stale'       => false,
			)
		);
	}

	/**
	 * Export list of post types available on this site (for mapping dropdown on source).
	 * GET, public read-only discovery endpoint; protected by rate limiting and global CORS enforcement.
	 * No token required — the source dashboard fetches this before a migration token exists.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function export_post_types( $request ) {
		$rate = self::check_rate_limit( 'export_post_types', 60, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$exclude = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'acf-field-group', 'acf-field' );
		$list    = array();

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $exclude, true ) ) {
				continue;
			}
			$list[] = array(
				'slug'  => $post_type->name,
				'label' => $post_type->label,
			);
		}

		return new \WP_REST_Response(
			array(
				'post_types' => $list,
			),
			200
		);
	}

	/**
	 * Import custom post types.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_cpts( $request ) {
		$rate = self::check_rate_limit( 'import_cpts', 60, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'cpts', 0, $request );

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$cpt_migrator = self::resolve( 'cpt_migrator' );
		if ( ! $cpt_migrator || ! method_exists( $cpt_migrator, 'import_custom_post_types' ) ) {
			return new \WP_Error( 'cpt_import_unavailable', __( 'CPT import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $cpt_migrator->import_custom_post_types( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import ACF field groups.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_acf_field_groups( $request ) {
		$rate = self::check_rate_limit( 'import_acf_field_groups', 60, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'acf', 0, $request );

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$acf_migrator = self::resolve( 'acf_migrator' );
		if ( ! $acf_migrator || ! method_exists( $acf_migrator, 'import_field_groups' ) ) {
			return new \WP_Error( 'acf_import_unavailable', __( 'ACF import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $acf_migrator->import_field_groups( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import MetaBox configurations.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_metabox_configs( $request ) {
		$rate = self::check_rate_limit( 'import_metabox_configs', 60, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'metabox', 0, $request );

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$metabox_migrator = self::resolve( 'metabox_migrator' );
		if ( ! $metabox_migrator || ! method_exists( $metabox_migrator, 'import_metabox_configs' ) ) {
			return new \WP_Error( 'metabox_import_unavailable', __( 'MetaBox import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $metabox_migrator->import_metabox_configs( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import Bricks global CSS into Etch's global stylesheets option.
	 *
	 * Receives a single stylesheet entry {id, name, css, type} and merges it
	 * into etch_global_stylesheets, keyed by the provided id.  Existing entries
	 * with other IDs (e.g. Etch's default 'Main') are preserved.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_global_css( $request ) {
		$rate = self::check_rate_limit( 'import_global_css', 10, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'css', 0, $request );

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$id  = isset( $data['id'] ) ? sanitize_key( $data['id'] ) : 'bricks-global';
		$css = isset( $data['css'] ) ? wp_unslash( $data['css'] ) : '';
		$css = '' !== $css ? $css : '';

		if ( '' === $css ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'skipped' => true,
				),
				200
			);
		}

		$style_repository = self::resolve( 'style_repository' );
		if ( ! $style_repository ) {
			return new \WP_Error( 'style_repo_unavailable', __( 'Style repository unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		// Merge into existing stylesheets so Etch's built-in entries survive.
		$stylesheets        = $style_repository->get_global_stylesheets();
		$stylesheets[ $id ] = array(
			'name' => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : __( 'Migrated from Bricks', 'etch-fusion-suite' ),
			'css'  => $css,
			'type' => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'custom',
		);

		$style_repository->save_global_stylesheets( $stylesheets );

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Import CSS classes from source Bricks site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_css_classes( $request ) {
		$rate = self::check_rate_limit( 'import_css_classes', 120, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'css', 0, $request );

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$css_converter = self::resolve( 'css_converter' );
		if ( ! $css_converter || ! method_exists( $css_converter, 'import_etch_styles' ) ) {
			return new \WP_Error( 'css_import_unavailable', __( 'CSS import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $css_converter->import_etch_styles( $data );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import post content into target site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_post( $request ) {
		$rate = self::check_rate_limit( 'import_post', 240, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'posts', 1, $request );

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$post    = isset( $payload['post'] ) && is_array( $payload['post'] ) ? $payload['post'] : array();

		if ( empty( $post ) ) {
			return new \WP_Error( 'invalid_post_payload', __( 'Invalid post payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $post['post_type'] ) ? sanitize_key( $post['post_type'] ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$post_title  = isset( $post['post_title'] ) ? sanitize_text_field( $post['post_title'] ) : '';
		$post_slug   = isset( $post['post_name'] ) ? sanitize_title( $post['post_name'] ) : sanitize_title( $post_title );
		$post_date   = isset( $post['post_date'] ) ? sanitize_text_field( $post['post_date'] ) : current_time( 'mysql' );
		$post_status = isset( $post['post_status'] ) ? sanitize_key( $post['post_status'] ) : 'draft';
		if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$post_status = 'draft';
		}

		$etch_content = isset( $payload['etch_content'] ) ? (string) $payload['etch_content'] : '';
		if ( '' === trim( $etch_content ) ) {
			$etch_content = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
		}

		// Preserve Gutenberg/Etch block-comment payloads verbatim.
		// wp_kses_post() escapes portions of valid block comments when labels contain
		// angle brackets (e.g. "<ul>", "<li>"), which breaks structure/order.
		$is_block_content = false !== strpos( $etch_content, '<!-- wp:' );
		$post_content     = $is_block_content ? $etch_content : wp_kses_post( $etch_content );
		self::sync_etch_loops_from_payload( isset( $payload['etch_loops'] ) && is_array( $payload['etch_loops'] ) ? $payload['etch_loops'] : array() );

		$source_post_id  = isset( $post['ID'] ) ? absint( $post['ID'] ) : 0;
		$existing        = null;
		$resolution_path = 'new';

		// Tier 1: meta lookup by _efs_original_post_id (authoritative). Also checks legacy _b2e_original_post_id.
		if ( $source_post_id > 0 ) {
			$meta_matches = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'   => '_efs_original_post_id',
							'value' => $source_post_id,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => '_b2e_original_post_id',
							'value' => $source_post_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);
			if ( ! empty( $meta_matches ) ) {
				$existing        = get_post( $meta_matches[0] );
				$resolution_path = 'meta';
			}
		}

		// Tier 2: option mapping lookup via efs_post_mappings (falls back to legacy b2e_post_mappings).
		if ( null === $existing && $source_post_id > 0 ) {
			$mappings = get_option( 'efs_post_mappings', get_option( 'b2e_post_mappings', array() ) );
			$mappings = is_array( $mappings ) ? $mappings : array();
			if ( isset( $mappings[ $source_post_id ] ) ) {
				$mapped_post = get_post( $mappings[ $source_post_id ] );
				if ( $mapped_post instanceof \WP_Post ) {
					$existing        = $mapped_post;
					$resolution_path = 'option';
				}
			}
		}

		// Tier 3: slug fallback (existing behaviour).
		if ( null === $existing ) {
			$existing = $post_slug ? get_page_by_path( $post_slug, OBJECT, $post_type ) : null;
			if ( $existing instanceof \WP_Post ) {
				$resolution_path = 'slug';
			}
		}

		$postarr = array(
			'post_title'   => $post_title,
			'post_name'    => $post_slug,
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_date'    => $post_date,
			'post_content' => $post_content,
		);

		if ( $existing instanceof \WP_Post ) {
			$postarr['ID'] = $existing->ID;

			// Handle post_type mismatch when resolved via meta or option mapping.
			if ( in_array( $resolution_path, array( 'meta', 'option' ), true ) && $existing->post_type !== $post_type ) {
				if ( post_type_exists( $post_type ) ) {
					$postarr['post_type'] = $post_type;
				} else {
					unset( $postarr['post_type'] );
					self::resolve( 'error_handler' )->log_warning(
						'W009',
						array(
							'source_id'           => $source_post_id,
							'target_id'           => $existing->ID,
							'requested_post_type' => $post_type,
							'kept_post_type'      => $existing->post_type,
						)
					);
				}
			}
		}

		// Block grammar must survive wp_insert_post verbatim.
		// wp_insert_post applies content_save_pre → wp_kses_post internally, which encodes
		// HTML comment delimiters (<!-- → &lt;!--) when the JSON attribute value contains
		// allowed HTML tags (e.g. <b>). Bypass kses for block content only; content was
		// already sanitised by wp_kses_post on the source before conversion.
		if ( $is_block_content ) {
			kses_remove_filters();
		}
		$post_id = wp_insert_post( $postarr, true );
		if ( $is_block_content ) {
			kses_init_filters();
		}
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'post_import_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		if ( $source_post_id > 0 ) {
			$mappings                    = get_option( 'efs_post_mappings', get_option( 'b2e_post_mappings', array() ) );
			$mappings                    = is_array( $mappings ) ? $mappings : array();
			$mappings[ $source_post_id ] = (int) $post_id;
			update_option( 'efs_post_mappings', $mappings );

			update_post_meta( $post_id, '_efs_original_post_id', $source_post_id );
		}

		if ( in_array( $resolution_path, array( 'meta', 'option', 'slug' ), true ) ) {
			self::resolve( 'error_handler' )->log_info(
				'Idempotent upsert: existing post resolved',
				array(
					'source_id'       => $source_post_id,
					'target_id'       => (int) $post_id,
					'resolution_path' => $resolution_path,
				)
			);
		}

		update_post_meta( $post_id, '_efs_migrated_from_bricks', 1 );
		update_post_meta( $post_id, '_efs_migration_date', current_time( 'mysql' ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'post_id' => (int) $post_id,
			),
			200
		);
	}

	/**
	 * Batch-import multiple posts in a single HTTP request.
	 *
	 * Performs auth, CORS, and source-origin verification once for the whole batch,
	 * loads efs_post_mappings once, processes each post, and saves mappings once.
	 * Returns per-post results so the caller can track individual failures.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_posts( $request ) {
		$rate = self::check_rate_limit( 'import_posts', 30, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$items   = isset( $payload['posts'] ) && is_array( $payload['posts'] ) ? $payload['posts'] : array();

		if ( empty( $items ) ) {
			return new \WP_Error( 'invalid_batch_payload', __( 'No posts in batch payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		self::touch_receiving_state( $token, 'posts', count( $items ), $request );

		// Merge and sync all Etch loop presets ONCE for the entire batch.
		$all_loops = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['etch_loops'] ) && is_array( $item['etch_loops'] ) ) {
				$all_loops = array_merge( $all_loops, $item['etch_loops'] );
			}
		}
		if ( ! empty( $all_loops ) ) {
			self::sync_etch_loops_from_payload( $all_loops );
		}

		// Load post mappings ONCE for the whole batch.
		$mappings = get_option( 'efs_post_mappings', get_option( 'b2e_post_mappings', array() ) );
		$mappings = is_array( $mappings ) ? $mappings : array();

		$results = array();

		foreach ( $items as $item ) {
			$post_raw = isset( $item['post'] ) && is_array( $item['post'] ) ? $item['post'] : array();
			if ( empty( $post_raw ) ) {
				$results[] = array(
					'source_id' => 0,
					'status'    => 'failed',
					'error'     => 'Missing post data in batch item.',
				);
				continue;
			}

			$etch_content   = isset( $item['etch_content'] ) ? (string) $item['etch_content'] : '';
			$source_post_id = isset( $post_raw['ID'] ) ? absint( $post_raw['ID'] ) : 0;
			$post_type      = isset( $post_raw['post_type'] ) ? sanitize_key( $post_raw['post_type'] ) : 'post';
			if ( ! post_type_exists( $post_type ) ) {
				$post_type = 'post';
			}
			$post_title  = isset( $post_raw['post_title'] ) ? sanitize_text_field( $post_raw['post_title'] ) : '';
			$post_slug   = isset( $post_raw['post_name'] ) ? sanitize_title( $post_raw['post_name'] ) : sanitize_title( $post_title );
			$post_date   = isset( $post_raw['post_date'] ) ? sanitize_text_field( $post_raw['post_date'] ) : current_time( 'mysql' );
			$post_status = isset( $post_raw['post_status'] ) ? sanitize_key( $post_raw['post_status'] ) : 'draft';
			if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
				$post_status = 'draft';
			}

			if ( '' === trim( $etch_content ) ) {
				$etch_content = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
			}
			$is_block_content = false !== strpos( $etch_content, '<!-- wp:' );
			$post_content     = $is_block_content ? $etch_content : wp_kses_post( $etch_content );

			$existing        = null;
			$resolution_path = 'new';

			// Tier 1: meta lookup by _efs_original_post_id.
			if ( $source_post_id > 0 ) {
				$meta_matches = get_posts(
					array(
						'post_type'      => 'any',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							'relation' => 'OR',
							array(
								'key'   => '_efs_original_post_id',
								'value' => $source_post_id,
								'type'  => 'NUMERIC',
							),
							array(
								'key'   => '_b2e_original_post_id',
								'value' => $source_post_id,
								'type'  => 'NUMERIC',
							),
						),
					)
				);
				if ( ! empty( $meta_matches ) ) {
						$existing        = get_post( $meta_matches[0] );
						$resolution_path = 'meta';
				}
			}

			// Tier 2: in-memory mapping (no extra get_option per post).
			if ( null === $existing && $source_post_id > 0 && isset( $mappings[ $source_post_id ] ) ) {
				$mapped_post = get_post( $mappings[ $source_post_id ] );
				if ( $mapped_post instanceof \WP_Post ) {
					$existing        = $mapped_post;
					$resolution_path = 'option';
				}
			}

			// Tier 3: slug fallback.
			if ( null === $existing ) {
				$existing = $post_slug ? get_page_by_path( $post_slug, OBJECT, $post_type ) : null;
				if ( $existing instanceof \WP_Post ) {
					$resolution_path = 'slug';
				}
			}

			$postarr = array(
				'post_title'   => $post_title,
				'post_name'    => $post_slug,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_date'    => $post_date,
				'post_content' => $post_content,
			);

			if ( $existing instanceof \WP_Post ) {
				$postarr['ID'] = $existing->ID;
				if ( in_array( $resolution_path, array( 'meta', 'option' ), true ) && $existing->post_type !== $post_type ) {
					if ( post_type_exists( $post_type ) ) {
						$postarr['post_type'] = $post_type;
					} else {
						unset( $postarr['post_type'] );
					}
				}
			}

			if ( $is_block_content ) {
				kses_remove_filters();
			}
			$post_id = wp_insert_post( $postarr, true );
			if ( $is_block_content ) {
				kses_init_filters();
			}

			if ( is_wp_error( $post_id ) ) {
				$results[] = array(
					'source_id' => $source_post_id,
					'status'    => 'failed',
					'error'     => $post_id->get_error_message(),
				);
				continue;
			}

			// Update in-memory mapping (no update_option per post).
			if ( $source_post_id > 0 ) {
				$mappings[ $source_post_id ] = (int) $post_id;
				update_post_meta( $post_id, '_efs_original_post_id', $source_post_id );
			}

			update_post_meta( $post_id, '_efs_migrated_from_bricks', 1 );
			update_post_meta( $post_id, '_efs_migration_date', current_time( 'mysql' ) );

			$results[] = array(
				'source_id' => $source_post_id,
				'post_id'   => (int) $post_id,
				'status'    => 'ok',
			);
		}

		// Save mappings ONCE for the whole batch.
		update_option( 'efs_post_mappings', $mappings );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'count'   => count( $results ),
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Merge incoming Etch loop presets into local etch_loops option.
	 *
	 * @param  array<string, array<string, mixed>> $incoming_loops Loop presets payload.
	 * @return void
	 */
	private static function sync_etch_loops_from_payload( $incoming_loops ) {
		if ( empty( $incoming_loops ) || ! is_array( $incoming_loops ) ) {
			return;
		}

		$current_loops = get_option( 'etch_loops', array() );
		$current_loops = is_array( $current_loops ) ? $current_loops : array();

		foreach ( $incoming_loops as $loop_id => $preset ) {
			$id = sanitize_key( (string) $loop_id );
			if ( '' === $id || ! is_array( $preset ) ) {
				continue;
			}

			$config = isset( $preset['config'] ) && is_array( $preset['config'] ) ? $preset['config'] : array();
			if ( empty( $config ) ) {
				continue;
			}

			$current_loops[ $id ] = array(
				'name'   => isset( $preset['name'] ) ? sanitize_text_field( (string) $preset['name'] ) : $id,
				'key'    => isset( $preset['key'] ) ? sanitize_key( (string) $preset['key'] ) : $id,
				'global' => isset( $preset['global'] ) ? (bool) $preset['global'] : true,
				'config' => $config,
			);
		}

		update_option( 'etch_loops', $current_loops );
	}

	/**
	 * Import post meta into target site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_post_meta( $request ) {
		$rate = self::check_rate_limit( 'import_post_meta', 240, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'posts', 0, $request );

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

		if ( empty( $meta ) ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'updated' => 0,
				),
				200
			);
		}

		$source_post_id = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : 0;
		$target_post_id = isset( $payload['target_post_id'] ) ? absint( $payload['target_post_id'] ) : 0;

		if ( $target_post_id <= 0 && $source_post_id > 0 ) {
			$mappings = get_option( 'efs_post_mappings', get_option( 'b2e_post_mappings', array() ) );
			if ( is_array( $mappings ) && isset( $mappings[ $source_post_id ] ) ) {
				$target_post_id = absint( $mappings[ $source_post_id ] );
			}
		}

		if ( $target_post_id <= 0 && $source_post_id > 0 ) {
			$target_post_id = $source_post_id;
		}

		if ( $target_post_id <= 0 || ! get_post( $target_post_id ) ) {
			return new \WP_Error( 'target_post_not_found', __( 'Target post not found for meta import.', 'etch-fusion-suite' ), array( 'status' => 404 ) );
		}

		$updated = 0;
		foreach ( $meta as $meta_key => $meta_value ) {
			$key = sanitize_key( (string) $meta_key );
			if ( '' === $key ) {
				continue;
			}
			update_post_meta( $target_post_id, $key, $meta_value );
			++$updated;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'updated' => $updated,
				'post_id' => (int) $target_post_id,
			),
			200
		);
	}

	/**
	 * Mark an incoming migration as completed on the Etch (receiving) side.
	 *
	 * Called by the Bricks source site after all data has been sent. Transitions
	 * the receiving state from 'receiving' to 'completed' so the Etch-side popup
	 * shows the correct completion status instead of going stale after the TTL.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function complete_migration( $request ) {
		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$repository = self::resolve_migration_repository();
		if ( ! $repository || ! method_exists( $repository, 'get_receiving_state' ) || ! method_exists( $repository, 'save_receiving_state' ) ) {
			return new \WP_Error( 'service_unavailable', __( 'Migration repository unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$current = $repository->get_receiving_state();
		$current = is_array( $current ) ? $current : array();

		$repository->save_receiving_state(
			array_merge(
				$current,
				array(
					'status'       => 'completed',
					'last_updated' => current_time( 'mysql' ),
					'is_stale'     => false,
				)
			)
		);

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Receive media payload and create attachment.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive_media( $request ) {
		$rate = self::check_rate_limit( 'receive_media', 120, 60 );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = self::validate_bearer_migration_token( $request );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = self::check_source_origin( $request, $token );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}
		self::touch_receiving_state( $token, 'media', 1, $request );

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$filename     = isset( $payload['filename'] ) ? sanitize_file_name( $payload['filename'] ) : '';
		$title        = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
		$mime_type    = isset( $payload['mime_type'] ) ? sanitize_text_field( $payload['mime_type'] ) : '';
		$file_content = isset( $payload['file_content'] ) ? base64_decode( (string) $payload['file_content'], true ) : false;

		if ( empty( $filename ) || false === $file_content ) {
			return new \WP_Error( 'invalid_media_payload', __( 'Invalid media payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$upload = wp_upload_bits( $filename, null, $file_content );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'media_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => ! empty( $title ) ? $title : preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'media_insert_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		include_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $payload['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $payload['alt_text'] ) );
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'media_id' => (int) $attachment_id,
			),
			200
		);
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_routes() {
		$namespace = 'efs/v1';

		// Template extractor routes are only exposed when Framer extraction is enabled at the PHP level.
		if ( \efs_is_framer_enabled() ) {
			register_rest_route(
				$namespace,
				'/template/extract',
				array(
					'callback'            => array( __CLASS__, 'extract_template_rest' ),
					'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
					'methods'             => \WP_REST_Server::CREATABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/saved',
				array(
					'callback'            => array( __CLASS__, 'get_saved_templates_rest' ),
					'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
					'methods'             => \WP_REST_Server::READABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/preview/(?P<id>\d+)',
				array(
					'callback'            => array( __CLASS__, 'preview_template_rest' ),
					'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
					'methods'             => \WP_REST_Server::READABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/(?P<id>\d+)',
				array(
					'callback'            => array( __CLASS__, 'delete_template_rest' ),
					'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
					'methods'             => \WP_REST_Server::DELETABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/import',
				array(
					'callback'            => array( __CLASS__, 'import_template_rest' ),
					'permission_callback' => array( __CLASS__, 'require_admin_permission' ),
					'methods'             => \WP_REST_Server::CREATABLE,
				)
			);
		}

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
				// CORS must not block it: the Bricks source dashboard calls cross-origin
				// and the origin is unknown at setup time. Rate limiting + single-use
				// pairing codes are the access controls.
				'permission_callback' => '__return_true',
				'methods'             => \WP_REST_Server::READABLE . ', ' . \WP_REST_Server::CREATABLE,
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
				'callback'            => array( __CLASS__, 'export_post_types' ),
				'permission_callback' => '__return_true', // Public read-only discovery; rate-limited + CORS-enforced globally.
				'methods'             => \WP_REST_Server::READABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/cpts',
			array(
				'callback'            => array( __CLASS__, 'import_cpts' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/acf-field-groups',
			array(
				'callback'            => array( __CLASS__, 'import_acf_field_groups' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/metabox-configs',
			array(
				'callback'            => array( __CLASS__, 'import_metabox_configs' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/css-classes',
			array(
				'callback'            => array( __CLASS__, 'import_css_classes' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/global-css',
			array(
				'callback'            => array( __CLASS__, 'import_global_css' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/post',
			array(
				'callback'            => array( __CLASS__, 'import_post' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/posts',
			array(
				'callback'            => array( __CLASS__, 'import_posts' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/post-meta',
			array(
				'callback'            => array( __CLASS__, 'import_post_meta' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/receive-media',
			array(
				'callback'            => array( __CLASS__, 'receive_media' ),
				'permission_callback' => array( __CLASS__, 'require_migration_token_permission' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/import/complete',
			array(
				'callback'            => array( __CLASS__, 'complete_migration' ),
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
	 * Enforces that the caller is a logged-in user with manage_options capability,
	 * authenticated via WordPress cookie + REST nonce or application password.
	 * Use this for admin-browser-facing routes such as key generation, migration
	 * triggering, and template management.
	 *
	 * @param  \WP_REST_Request $request REST request.
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
	public static function require_admin_with_cookie_fallback( $request ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// Standard path: REST middleware already set the current user.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Fallback: validate the logged-in cookie directly. Some hosting
		// environments strip authentication headers or cookies from REST
		// requests before they reach WordPress, causing current_user_can()
		// to return false even for a fully authenticated admin session.
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
	 * Used for cross-site server-to-server endpoints called by the source
	 * site's migration process. Access is controlled by the signed migration
	 * token rather than a WordPress user session, since the calling server
	 * has no cookie session on this site.
	 *
	 * @param  \WP_REST_Request $request REST request.
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
		// Accept logged-in administrators (cookie + REST nonce or application password).
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

		/**
	* @var EFS_Migration_Token_Manager $token_manager
*/
		$token_manager = self::resolve( 'token_manager' );
		if ( ! $token_manager ) {
			return new \WP_Error(
				'token_manager_unavailable',
				__( 'Token manager unavailable.', 'etch-fusion-suite' ),
				array( 'status' => 500 )
			);
		}

		$result = $token_manager->validate_migration_token( sanitize_text_field( $body['migration_key'] ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Invalid or expired migration token.', 'etch-fusion-suite' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}
}
