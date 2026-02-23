<?php
/**
 * Wizard AJAX Handler
 *
 * Handles wizard state persistence and migration URL validation.
 *
 * @package Etch_Fusion_Suite
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Services\EFS_Wizard_State_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Wizard_Ajax_Handler
 */
class EFS_Wizard_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * Wizard state service.
	 *
	 * @var EFS_Wizard_State_Service|null
	 */
	private $wizard_state_service;

	/**
	 * Token manager.
	 *
	 * @var EFS_Migration_Token_Manager|null
	 */
	private $token_manager;

	/**
	 * Constructor.
	 *
	 * @param EFS_Wizard_State_Service|null $wizard_state_service Wizard state service.
	 * @param EFS_Migration_Token_Manager|null $token_manager      Token manager.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance.
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance.
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance.
	 */
	public function __construct( $wizard_state_service = null, $token_manager = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->wizard_state_service = $wizard_state_service;
		$this->token_manager        = $token_manager;

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register hooks.
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_wizard_save_state', array( $this, 'save_state' ) );
		add_action( 'wp_ajax_efs_wizard_get_state', array( $this, 'get_state' ) );
		add_action( 'wp_ajax_efs_wizard_clear_state', array( $this, 'clear_state' ) );
		add_action( 'wp_ajax_efs_wizard_validate_url', array( $this, 'validate_url' ) );
		add_action( 'wp_ajax_efs_get_target_post_types', array( $this, 'get_target_post_types' ) );
		add_action( 'wp_ajax_efs_get_css_preview', array( $this, 'get_css_preview' ) );
	}

	/**
	 * Save wizard state.
	 */
	public function save_state() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'wizard_save_state', 90, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$service = $this->get_wizard_state_service();
		if ( ! $service ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard state service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$user_id      = get_current_user_id();
		$wizard_nonce = $this->resolve_wizard_nonce();
		$payload      = $this->get_wizard_payload();

		if ( '' === $wizard_nonce ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard nonce is required.', 'etch-fusion-suite' ),
					'code'    => 'missing_wizard_nonce',
				),
				400
			);
			return;
		}

		$state = $service->save_state( $user_id, $wizard_nonce, $payload );

		if ( false === $state ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to save wizard state.', 'etch-fusion-suite' ),
					'code'    => 'wizard_save_failed',
				),
				500
			);
			return;
		}

		wp_send_json_success(
			array(
				'message'          => __( 'Wizard state saved.', 'etch-fusion-suite' ),
				'wizard_nonce'     => $wizard_nonce,
				'state'            => $state,
				'expires_in'       => $service->get_expiration_seconds(),
				'expires_in_human' => human_time_diff( time(), time() + $service->get_expiration_seconds() ),
			)
		);
	}

	/**
	 * Get wizard state.
	 */
	public function get_state() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'wizard_get_state', 120, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$service = $this->get_wizard_state_service();
		if ( ! $service ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard state service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$user_id      = get_current_user_id();
		$wizard_nonce = $this->resolve_wizard_nonce();

		if ( '' === $wizard_nonce ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard nonce is required.', 'etch-fusion-suite' ),
					'code'    => 'missing_wizard_nonce',
				),
				400
			);
			return;
		}

		$state = $service->get_state( $user_id, $wizard_nonce );

		wp_send_json_success(
			array(
				'wizard_nonce'     => $wizard_nonce,
				'state'            => $state,
				'expires_in'       => $service->get_expiration_seconds(),
				'expires_in_human' => human_time_diff( time(), time() + $service->get_expiration_seconds() ),
			)
		);
	}

	/**
	 * Clear wizard state.
	 */
	public function clear_state() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'wizard_clear_state', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$service = $this->get_wizard_state_service();
		if ( ! $service ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard state service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$user_id      = get_current_user_id();
		$wizard_nonce = $this->resolve_wizard_nonce();

		if ( '' === $wizard_nonce ) {
			wp_send_json_error(
				array(
					'message' => __( 'Wizard nonce is required.', 'etch-fusion-suite' ),
					'code'    => 'missing_wizard_nonce',
				),
				400
			);
			return;
		}

		$cleared = $service->clear_state( $user_id, $wizard_nonce );

		if ( ! $cleared ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to clear wizard state.', 'etch-fusion-suite' ),
					'code'    => 'wizard_clear_failed',
				),
				500
			);
			return;
		}

		wp_send_json_success(
			array(
				'message'      => __( 'Wizard state cleared.', 'etch-fusion-suite' ),
				'wizard_nonce' => $wizard_nonce,
			)
		);
	}

	/**
	 * Validate migration URL.
	 */
	public function validate_url() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'wizard_validate_url', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$url = $this->get_post( 'migration_url', '', 'url' );
		if ( '' === $url ) {
			$url = $this->get_post( 'target_url', '', 'url' );
		}

		if ( '' === $url ) {
			wp_send_json_error(
				array(
					'message' => __( 'Target URL is required.', 'etch-fusion-suite' ),
					'code'    => 'missing_url',
				),
				400
			);
			return;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Target URL is invalid.', 'etch-fusion-suite' ),
					'code'    => 'invalid_url',
				),
				400
			);
			return;
		}

		$token_manager = $this->get_token_manager();
		$security      = $token_manager ? $token_manager->analyze_url_security( $url ) : array();
		$host          = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';

		wp_send_json_success(
			array(
				'valid'          => true,
				'normalized_url' => untrailingslashit( $url ),
				'host'           => $host,
				'is_https'       => (bool) ( $security['is_https'] ?? false ),
				'https_warning'  => (bool) ( $security['https_warning'] ?? false ),
				'warning'        => isset( $security['warning_message'] ) ? $security['warning_message'] : '',
			)
		);
	}

	/**
	 * Resolve wizard state service from container if needed.
	 *
	 * @return EFS_Wizard_State_Service|null
	 */
	private function get_wizard_state_service() {
		if ( $this->wizard_state_service instanceof EFS_Wizard_State_Service ) {
			return $this->wizard_state_service;
		}

		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'wizard_state_service' ) ) {
				$this->wizard_state_service = $container->get( 'wizard_state_service' );
				return $this->wizard_state_service;
			}
		}

		return null;
	}

	/**
	 * Resolve token manager from container if needed.
	 *
	 * @return EFS_Migration_Token_Manager|null
	 */
	private function get_token_manager() {
		if ( $this->token_manager instanceof EFS_Migration_Token_Manager ) {
			return $this->token_manager;
		}

		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'token_manager' ) ) {
				$this->token_manager = $container->get( 'token_manager' );
				return $this->token_manager;
			}
		}

		return null;
	}

	/**
	 * Resolve wizard nonce from request data.
	 *
	 * @return string
	 */
	private function resolve_wizard_nonce(): string {
		$wizard_nonce = $this->get_post( 'wizard_nonce', '', 'text' );
		if ( '' !== $wizard_nonce ) {
			return sanitize_key( $wizard_nonce );
		}

		$state_nonce = $this->get_post( 'state_nonce', '', 'text' );
		if ( '' !== $state_nonce ) {
			return sanitize_key( $state_nonce );
		}

		$request_nonce = $this->get_post( 'nonce', '', 'text' );
		return sanitize_key( $request_nonce );
	}

	/**
	 * Build wizard payload from request.
	 *
	 * @return array
	 */
	private function get_wizard_payload(): array {
		$payload = array();

		$state_raw = $this->get_post( 'state', null, 'raw' );
		if ( is_string( $state_raw ) && '' !== trim( $state_raw ) ) {
			$decoded = json_decode( $state_raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		$current_step = $this->get_post( 'current_step', null, 'int' );
		if ( null !== $current_step ) {
			$payload['current_step'] = (int) $current_step;
		}

		$migration_url = $this->get_post( 'migration_url', '', 'url' );
		if ( '' !== $migration_url ) {
			$payload['migration_url'] = $migration_url;
		}

		$discovery = $this->get_post( 'discovery_data', array(), 'array' );
		if ( ! empty( $discovery ) ) {
			$payload['discovery_data'] = $discovery;
		}

		$selected_post_types = $this->get_post( 'selected_post_types', array(), 'array' );
		if ( ! empty( $selected_post_types ) ) {
			$payload['selected_post_types'] = $selected_post_types;
		}

		$post_type_mappings = $this->get_post( 'post_type_mappings', array(), 'array' );
		if ( ! empty( $post_type_mappings ) ) {
			$payload['post_type_mappings'] = $post_type_mappings;
		}

		$include_media = $this->get_post( 'include_media', null, 'bool' );
		if ( null !== $include_media ) {
			$payload['include_media'] = (bool) $include_media;
		}

		$batch_size = $this->get_post( 'batch_size', null, 'int' );
		if ( null !== $batch_size ) {
			$payload['batch_size'] = (int) $batch_size;
		}

		$mode = $this->get_post( 'mode', null, 'text' );
		if ( null !== $mode && in_array( $mode, array( 'browser', 'headless' ), true ) ) {
			$payload['mode'] = $mode;
		}

		return $payload;
	}

	/**
	 * Return CSS class counts for the Step 3 preview.
	 */
	public function get_css_preview() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'wizard_css_preview', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$selected_post_types  = $this->get_post( 'selected_post_types', array(), 'array' );
		$restrict_css_to_used = filter_var( $this->get_post( 'restrict_css_to_used', '1', 'text' ), FILTER_VALIDATE_BOOLEAN );

		$css_converter = null;
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'css_converter' ) ) {
				$css_converter = $container->get( 'css_converter' );
			}
		}

		if ( ! $css_converter || ! method_exists( $css_converter, 'get_css_class_counts' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'CSS converter service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$result = $css_converter->get_css_class_counts(
			array_map( 'sanitize_key', $selected_post_types ),
			$restrict_css_to_used
		);

		wp_send_json_success(
			array(
				'css_classes_total'      => $result['total'],
				'css_classes_to_migrate' => $result['to_migrate'],
			)
		);
	}

	/**
	 * Get post types available on the Etch (target) site for mapping dropdown.
	 * Requires target_url and migration_key (or migration_url) in request.
	 */
	public function get_target_post_types() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'get_target_post_types', 30, 60 ) ) {
			return;
		}

		$migration_key = $this->get_post( 'migration_key', '', 'raw' );
		$target_url    = $this->get_post( 'target_url', '', 'url' );
		$migration_url = $this->get_post( 'migration_url', '', 'url' );

		if ( '' !== $migration_url && ( '' === $target_url || '' === $migration_key ) ) {
			$parsed = wp_parse_url( $migration_url );
			if ( is_array( $parsed ) && ! empty( $parsed['host'] ) ) {
				$target_url = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : 'https://' ) . $parsed['host'];
				if ( ! empty( $parsed['port'] ) && 80 !== (int) $parsed['port'] && 443 !== (int) $parsed['port'] ) {
					$target_url .= ':' . $parsed['port'];
				}
				$target_url = untrailingslashit( $target_url );
				if ( '' === $migration_key && ! empty( $parsed['query'] ) ) {
					parse_str( $parsed['query'], $query );
					$migration_key = isset( $query['token'] ) ? $query['token'] : ( isset( $query['migration_key'] ) ? $query['migration_key'] : ( isset( $query['key'] ) ? $query['key'] : '' ) );
				}
			}
		}

		if ( '' === $target_url || '' === $migration_key ) {
			wp_send_json_error(
				array(
					'message' => __( 'Target URL and migration key are required.', 'etch-fusion-suite' ),
					'code'    => 'missing_params',
				),
				400
			);
			return;
		}

		if ( function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {
			$target_url = etch_fusion_suite_convert_to_internal_url( $target_url );
		}

		$api_client = function_exists( 'etch_fusion_suite_container' ) && etch_fusion_suite_container()->has( 'api_client' )
			? etch_fusion_suite_container()->get( 'api_client' )
			: null;

		if ( ! $api_client || ! method_exists( $api_client, 'get_target_post_types' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'API client unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$result = $api_client->get_target_post_types( $target_url, $migration_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code() ? $result->get_error_code() : 'api_error',
				),
				502
			);
			return;
		}

		$post_types = isset( $result['post_types'] ) && is_array( $result['post_types'] ) ? $result['post_types'] : array();
		wp_send_json_success( array( 'post_types' => $post_types ) );
	}
}
