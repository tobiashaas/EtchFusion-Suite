<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Services\EFS_Discovery_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Discovery_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * @var EFS_Discovery_Service
	 */
	private $discovery_service;

	/**
	 * @var EFS_API_Client
	 */
	private $api_client;

	public function __construct(
		EFS_Discovery_Service $discovery_service,
		EFS_API_Client $api_client,
		$rate_limiter = null,
		$input_validator = null,
		$audit_logger = null
	) {
		$this->discovery_service = $discovery_service;
		$this->api_client        = $api_client;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	protected function register_hooks() {
		add_action( 'wp_ajax_efs_validate_migration_url', array( $this, 'validate_migration_url' ) );
		add_action( 'wp_ajax_efs_discover_content', array( $this, 'discover_content' ) );
		add_action( 'wp_ajax_efs_analyze_dynamic_data_full', array( $this, 'analyze_dynamic_data_full' ) );
	}

	/**
	 * Validate migration URL and key before discovery.
	 */
	public function validate_migration_url() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'discovery_validate_url', 10, 60 ) ) {
			return;
		}

		try {
			$validated = $this->validate_input(
				array(
					'migration_url' => $this->get_post( 'migration_url', '', 'raw' ),
				),
				array(
					'migration_url' => array(
						'type'       => 'text',
						'required'   => true,
						'max_length' => 2048,
					),
				)
			);
		} catch ( \Exception $e ) {
			return;
		}

		$parsed = $this->extract_target_and_key( (string) $validated['migration_url'] );
		if ( is_wp_error( $parsed ) ) {
			wp_send_json_error(
				array(
					'message' => $parsed->get_error_message(),
					'code'    => $parsed->get_error_code(),
				),
				400
			);
			return;
		}

		$target_url    = $parsed['target_url'];
		$migration_key = $parsed['migration_key'];
		$internal_url  = $this->convert_to_internal_url( $target_url );

		$result = $this->api_client->validate_migration_key_on_target( $internal_url, $migration_key );
		if ( is_wp_error( $result ) ) {
			$status = 400;
			$data   = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code() ? sanitize_key( (string) $result->get_error_code() ) : 'migration_validation_failed',
				),
				$status
			);
			return;
		}

		wp_send_json_success(
			array(
				'valid'         => true,
				'target_url'    => $target_url,
				'migration_key' => $migration_key,
				'payload'       => $this->mask_sensitive_values( $result['payload'] ?? array() ),
				'expiration'    => $result['expires'] ?? null,
			)
		);
	}

	/**
	 * Run source+target discovery and return a merged payload.
	 */
	public function discover_content() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'discovery_discover_content', 8, 60 ) ) {
			return;
		}

		try {
			$validated = $this->validate_input(
				array(
					'target_url'    => $this->get_post( 'target_url', '', 'url' ),
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
					'sample_size'   => $this->get_post( 'sample_size', 25, 'int' ),
				),
				array(
					'target_url'    => array(
						'type'     => 'url',
						'required' => true,
					),
					'migration_key' => array(
						'type'     => 'migration_key',
						'required' => true,
					),
					'sample_size'   => array(
						'type'     => 'integer',
						'required' => false,
						'min'      => 1,
						'max'      => 100,
					),
				)
			);
		} catch ( \Exception $e ) {
			return;
		}

		$target_url    = $validated['target_url'];
		$migration_key = $validated['migration_key'];
		$sample_size   = isset( $validated['sample_size'] ) ? (int) $validated['sample_size'] : 25;
		$internal_url  = $this->convert_to_internal_url( $target_url );

		$local_discovery = $this->discovery_service->generate_discovery_response( $sample_size, false );
		$remote_discovery = $this->api_client->send_authorized_request(
			$internal_url,
			$migration_key,
			'/discover',
			'POST',
			array(
				'sample_size' => $sample_size,
				'full_scan'   => false,
			)
		);

		if ( is_wp_error( $remote_discovery ) ) {
			$message = $remote_discovery->get_error_message();
			$status  = str_contains( strtolower( $message ), 'timed out' ) ? 504 : 503;

			wp_send_json_error(
				array(
					'message' => $message,
					'code'    => 'remote_discovery_failed',
				),
				$status
			);
			return;
		}

		wp_send_json_success(
			array(
				'source'       => $local_discovery,
				'target'       => $remote_discovery,
				'discovered_at'=> current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Perform full-scan dynamic-data analysis on the local site.
	 */
	public function analyze_dynamic_data_full() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'discovery_dynamic_data_full', 4, 60 ) ) {
			return;
		}

		$post_types = $this->get_post( 'post_types', array(), 'array' );
		$post_types = is_array( $post_types ) ? array_values( array_map( 'sanitize_key', $post_types ) ) : array();

		$result = $this->discovery_service->analyze_dynamic_data( $post_types, 0, true );

		wp_send_json_success(
			array(
				'dynamic_data' => $result,
			)
		);
	}

	/**
	 * @param string $migration_url
	 * @return array<string,string>|\WP_Error
	 */
	private function extract_target_and_key( string $migration_url ) {
		$migration_url = trim( $migration_url );
		if ( '' === $migration_url ) {
			return new \WP_Error( 'missing_migration_url', __( 'Migration URL is required.', 'etch-fusion-suite' ) );
		}

		// Support direct token paste as a fallback.
		if ( 2 === substr_count( $migration_url, '.' ) && ! str_contains( $migration_url, '://' ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Migration URL must include the target site URL.', 'etch-fusion-suite' ) );
		}

		$parts = wp_parse_url( $migration_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new \WP_Error( 'invalid_migration_url', __( 'Invalid migration URL format.', 'etch-fusion-suite' ) );
		}

		$target_url = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$target_url .= ':' . (int) $parts['port'];
		}

		$query_args = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query_args );
		}

		$migration_key = '';
		if ( isset( $query_args['token'] ) && is_string( $query_args['token'] ) ) {
			$migration_key = trim( $query_args['token'] );
		} elseif ( isset( $query_args['migration_key'] ) && is_string( $query_args['migration_key'] ) ) {
			$migration_key = trim( $query_args['migration_key'] );
		}

		if ( '' === $migration_key ) {
			return new \WP_Error( 'missing_migration_key', __( 'Migration token is missing from the URL.', 'etch-fusion-suite' ) );
		}

		return array(
			'target_url'    => esc_url_raw( $target_url ),
			'migration_key' => $migration_key,
		);
	}
}

