<?php
/**
 * Pre-Flight AJAX Handler
 *
 * Handles pre-flight environment checks for migration.
 *
 * @package Etch_Fusion_Suite
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Services\EFS_Pre_Flight_Checker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Pre_Flight_Ajax_Handler
 */
class EFS_Pre_Flight_Ajax_Handler extends EFS_Base_Ajax_Handler {

	/**
	 * Pre-flight checker service.
	 *
	 * @var EFS_Pre_Flight_Checker|null
	 */
	private $preflight_checker;

	/**
	 * Constructor.
	 *
	 * @param EFS_Pre_Flight_Checker|null                   $preflight_checker Pre-flight checker service.
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null   $rate_limiter      Rate limiter instance.
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator   Input validator instance.
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null   $audit_logger      Audit logger instance.
	 */
	public function __construct( $preflight_checker = null, $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->preflight_checker = $preflight_checker;

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	/**
	 * Register hooks.
	 */
	protected function register_hooks() {
		add_action( 'wp_ajax_efs_run_preflight_check', array( $this, 'handle_run_checks' ) );
		add_action( 'wp_ajax_efs_invalidate_preflight_cache', array( $this, 'handle_invalidate_cache' ) );
	}

	/**
	 * Handle run checks request.
	 */
	public function handle_run_checks() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'preflight_check', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}

		if ( ! ( $this->preflight_checker instanceof EFS_Pre_Flight_Checker ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Pre-flight checker service is unavailable.', 'etch-fusion-suite' ),
				),
				503
			);
			return;
		}

		$target_url = $this->get_post( 'target_url', '', 'url' );
		$mode       = $this->get_post( 'mode', 'browser', 'text' );

		$result = $this->preflight_checker->run_checks( (string) $target_url, (string) $mode );

		wp_send_json_success( $result );
	}

	/**
	 * Handle invalidate cache request.
	 */
	public function handle_invalidate_cache() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! ( $this->preflight_checker instanceof EFS_Pre_Flight_Checker ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Pre-flight checker service is unavailable.', 'etch-fusion-suite' ),
				),
				503
			);
			return;
		}

		$this->preflight_checker->invalidate_cache();

		wp_send_json_success( array( 'invalidated' => true ) );
	}
}
