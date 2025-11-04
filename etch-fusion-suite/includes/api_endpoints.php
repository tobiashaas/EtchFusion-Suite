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

		// Add global CORS enforcement filter
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enforce_cors_globally' ), 10, 3 );
	}

	/**
	 * Handle template extraction via REST.
	 *
	 * @param \WP_REST_Request $request REST request.
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
	 * @param \WP_REST_Request $request REST request.
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
	 * @param \WP_REST_Request $request REST request.
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
	 * @param \WP_REST_Request $request REST request.
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
	 * @param \WP_REST_Request $request REST request.
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
	 * @param \WP_HTTP_Response|\WP_Error $response Result to send to the client.
	 * @param \WP_REST_Server             $server   Server instance.
	 * @param \WP_REST_Request            $request  Request used to generate the response.
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
		// Check CORS origin
		$cors_check = self::check_cors_origin();
		if ( is_wp_error( $cors_check ) ) {
			return $cors_check;
		}

		try {
			$params        = $request->get_params();
			$migration_key = $params['migration_key'] ?? null;
			$target_url    = isset( $params['target_url'] ) ? esc_url_raw( $params['target_url'] ) : '';

			if ( empty( $migration_key ) ) {
				return new \WP_Error( 'missing_migration_key', __( 'Migration key is required.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
			}

			/** @var EFS_Migration_Token_Manager $token_manager */
			$token_manager = self::resolve( 'token_manager' );
			$decoded       = $token_manager ? $token_manager->decode_migration_key_locally( $migration_key ) : new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

			if ( is_wp_error( $decoded ) ) {
				return new \WP_Error( 'invalid_migration_key', $decoded->get_error_message(), array( 'status' => 400 ) );
			}

			$payload = $decoded['payload'] ?? array();
			$target  = ! empty( $target_url ) ? $target_url : ( $payload['target_url'] ?? '' );
			$source  = $payload['domain'] ?? '';
			$expires = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;

			if ( empty( $target ) ) {
				return new \WP_Error( 'missing_target', __( 'Target URL could not be determined from migration key.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
			}

			if ( $expires && time() > $expires ) {
				return new \WP_Error( 'migration_key_expired', __( 'Migration key has expired.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
			}

			/** @var EFS_Migration_Manager $migration_manager */
			$migration_manager = self::resolve( 'migration_manager' );
			if ( ! $migration_manager ) {
				return new \WP_Error( 'migration_service_unavailable', __( 'Migration service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
			}

			$result = $migration_manager->start_migration( $migration_key, $target );

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
	 * Generate migration key endpoint
	 * Creates a new migration token and returns the migration key URL
	 */
	public static function generate_migration_key( $request ) {
		try {
			/** @var EFS_Migration_Token_Manager $token_manager */
			$token_manager = self::resolve( 'token_manager' );
			$token_data    = $token_manager ? $token_manager->generate_migration_token( $request->get_param( 'target_url' ) ) : new \WP_Error( 'token_manager_unavailable', __( 'Token manager unavailable.', 'etch-fusion-suite' ) );

			if ( is_wp_error( $token_data ) ) {
				return new \WP_Error( 'token_generation_failed', $token_data->get_error_message(), array( 'status' => 500 ) );
			}

			return new \WP_REST_Response(
				array(
					'success'       => true,
					'migration_key' => $token_data['token'],
					'domain'        => home_url(),
					'expires'       => $token_data['expires'],
					'expires_at'    => $token_data['expires_at'],
					'generated_at'  => current_time( 'mysql' ),
					'payload'       => $token_data['payload'],
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

			/** @var EFS_Migration_Token_Manager $token_manager */
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
	 * Validate request payload using input validator.
	 *
	 * @param array $data  Request data.
		}
	}

	/**
	 * Enforce template rate limits.
	 *
	 * @param string $action Action name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Window in seconds.
	 * @return true|\WP_Error
	 */
	public static function enforce_template_rate_limit( $action, $limit, $window = 60 ) {
		return self::handle_rate_limit( $action, $limit, $window );
	}

	/**
	 * Check rate limit without recording on success.
	 *
	 * @param string $action Action name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Window in seconds.
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit( $action, $limit, $window = 60 ) {
		return self::handle_rate_limit( $action, $limit, $window );
	}

	/**
	 * Internal helper to check and record rate limits.
	 *
	 * @param string $action        Action key.
	 * @param int    $limit         Limit count.
	 * @param int    $window        Window seconds.
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
	 * @param \WP_REST_Request $request REST request.
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

		/** @var EFS_Migration_Token_Manager $token_manager */
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
					'permission_callback' => array( __CLASS__, 'allow_public_request' ),
					'methods'             => \WP_REST_Server::CREATABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/saved',
				array(
					'callback'            => array( __CLASS__, 'get_saved_templates_rest' ),
					'permission_callback' => array( __CLASS__, 'allow_public_request' ),
					'methods'             => \WP_REST_Server::READABLE,
				)
			);

			register_rest_route(
				$namespace,
				'/template/preview/(?P<id>\d+)',
				array(
					'callback'            => array( __CLASS__, 'preview_template_rest' ),
					'permission_callback' => array( __CLASS__, 'allow_public_request' ),
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
					'permission_callback' => array( __CLASS__, 'allow_public_request' ),
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
					'permission_callback' => array( __CLASS__, 'allow_public_request' ),
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
				'permission_callback' => array( __CLASS__, 'allow_public_request' ),
				'methods'             => \WP_REST_Server::READABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/generate-key',
			array(
				'callback'            => array( __CLASS__, 'generate_migration_key' ),
				'permission_callback' => array( __CLASS__, 'allow_public_request' ),
				'methods'             => \WP_REST_Server::CREATABLE,
			)
		);

		register_rest_route(
			$namespace,
			'/validate',
			array(
				'callback'            => array( __CLASS__, 'validate_migration_token' ),
				'permission_callback' => array( __CLASS__, 'allow_public_request' ),
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
	}

	/**
	 * Permission callback allowing public access while enforcing CORS.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function allow_public_request( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$cors = self::check_cors_origin();
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		return true;
	}
}
