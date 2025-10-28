<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Security\EFS_Audit_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Logs_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * Audit logger instance
	 *
	 * @var EFS_Audit_Logger
	 */
	protected $audit_logger;

	/**
	 * Constructor
	 *
	 * @param EFS_Audit_Logger|null                           $audit_logger    Audit logger instance.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null   $rate_limiter   Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 */
	public function __construct( EFS_Audit_Logger $audit_logger = null, $rate_limiter = null, $input_validator = null ) {
		$this->audit_logger = $audit_logger;
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	protected function register_hooks() {
		add_action( 'wp_ajax_efs_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'wp_ajax_efs_get_logs', array( $this, 'get_logs' ) );
	}

	public function clear_logs() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (10 requests per minute - sensitive operation)
		if ( ! $this->check_rate_limit( 'logs_clear', 10, 60 ) ) {
			return;
		}

		if ( ! $this->audit_logger ) {
			wp_send_json_error(
				array(
					'message' => __( 'Audit logger service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$this->audit_logger->log_security_event( 'logs_cleared', 'critical', 'Audit log cleared from admin interface', array() );

		$result = $this->audit_logger->clear_security_logs();

		if ( ! $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to clear audit logs.', 'etch-fusion-suite' ),
					'code'    => 'clear_failed',
				),
				500
			);
			return;
		}

		wp_send_json_success(
			array(
				'message' => __( 'Audit logs cleared successfully.', 'etch-fusion-suite' ),
			)
		);
	}

	public function get_logs() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		// Check rate limit (60 requests per minute)
		if ( ! $this->check_rate_limit( 'logs_fetch', 60, 60 ) ) {
			return;
		}

		if ( ! $this->audit_logger ) {
			wp_send_json_error(
				array(
					'message' => __( 'Audit logger service unavailable.', 'etch-fusion-suite' ),
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$this->audit_logger->log_security_event( 'logs_accessed', 'low', 'Audit log viewed in admin', array() );

		$logs = $this->audit_logger->get_security_logs();

		wp_send_json_success(
			array(
				'logs' => $logs,
			)
		);
	}
}
