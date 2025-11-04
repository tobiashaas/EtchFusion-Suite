<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Api\EFS_API_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Cleanup_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * API client instance
	 *
	 * @var mixed
	 */
	private $api_client;

	/**
	 * Constructor
	 *
	 * @param mixed $api_client API client instance.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $api_client = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->api_client = $api_client;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	protected function register_hooks() {
		add_action( 'wp_ajax_efs_cleanup_etch', array( $this, 'cleanup_etch' ) );
	}

	public function cleanup_etch() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (5 requests per minute - very sensitive operation)
		if ( ! $this->check_rate_limit( 'cleanup_execute', 5, 60 ) ) {
			return;
		}

		// Get and validate parameters
		try {
			$validated = $this->validate_input(
				array(
					'target_url'    => $this->get_post( 'target_url', '' ),
					'migration_key' => $this->get_post( 'migration_key', '', 'raw' ),
					'confirm'       => $this->get_post( 'confirm', false ),
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
					'confirm'       => array(
						'type'     => 'text',
						'required' => false,
					),
				)
			);
		} catch ( \Exception $e ) {
			return; // Error already sent by validate_input
		}

		$target_url    = $validated['target_url'];
		$migration_key = $validated['migration_key'];
		$confirm       = $validated['confirm'] ?? false;
		$confirmed     = is_string( $confirm ) ? filter_var( $confirm, FILTER_VALIDATE_BOOLEAN ) : (bool) $confirm;

		// Require confirmation for destructive operation
		if ( ! $confirmed ) {
			$this->log_security_event( 'cleanup_denied', 'Cleanup operation rejected due to missing confirmation.', array( 'target_url' => $target_url ) );
			wp_send_json_error(
				array(
					'message' => __( 'Cleanup requires confirmation. Please confirm this destructive operation.', 'etch-fusion-suite' ),
					'code'    => 'cleanup_confirmation_required',
				),
				400
			);
			return;
		}

		$client = $this->api_client;
		if ( ! $client || ! $client instanceof EFS_API_Client ) {
			$this->log_security_event( 'cleanup_unavailable', 'Cleanup operation aborted: API client unavailable.', array( 'target_url' => $target_url ), 'high' );
			wp_send_json_error(
				array(
					'message' => __( 'Cleanup service unavailable. Please ensure the service container is initialised.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		// Validate migration key before issuing commands.
		$validation = $client->validate_migration_key_on_target( $this->convert_to_internal_url( $target_url ), $migration_key );
		if ( is_wp_error( $validation ) ) {
			$this->log_security_event( 'cleanup_denied', 'Cleanup operation rejected: migration key invalid.', array( 'target_url' => $target_url ), 'high' );
			wp_send_json_error(
				array(
					'message' => $validation->get_error_message(),
					'code'    => sanitize_key( ! empty( $validation->get_error_code() ) ? $validation->get_error_code() : 'migration_key_invalid' ),
				),
				400
			);
			return;
		}

		$commands = array(
			'wp post delete $(wp post list --post_type=post,page,attachment --format=ids) --force',
			'wp option delete etch_styles',
			'wp cache flush',
			'wp transient delete --all',
		);

		// Log cleanup attempt (critical severity - destructive operation)
		$this->log_security_event(
			'cleanup_executed',
			'Cleanup operation executed',
			array(
				'target_url' => $target_url,
			),
			'critical'
		);

		wp_send_json_success(
			array(
				'message'  => __( 'Cleanup commands generated.', 'etch-fusion-suite' ),
				'commands' => $commands,
			)
		);
	}
}
