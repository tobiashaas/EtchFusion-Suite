<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Controllers\EFS_Template_Controller;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Security\EFS_Rate_Limiter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler for template extraction flow.
 */
class EFS_Template_Ajax_Handler extends EFS_Base_Ajax_Handler {
	/**
	 * @var EFS_Template_Controller
	 */
	protected $template_controller;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Template_Controller $template_controller, EFS_Rate_Limiter $rate_limiter, EFS_Input_Validator $input_validator, EFS_Audit_Logger $audit_logger ) {
		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
		$this->template_controller = $template_controller;
	}

	/**
	 * Registers AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_efs_extract_template', array( $this, 'extract_template' ) );
		add_action( 'wp_ajax_efs_get_extraction_progress', array( $this, 'get_extraction_progress' ) );
		add_action( 'wp_ajax_efs_save_template', array( $this, 'save_template' ) );
		add_action( 'wp_ajax_efs_get_saved_templates', array( $this, 'get_saved_templates' ) );
		add_action( 'wp_ajax_efs_delete_template', array( $this, 'delete_template' ) );
	}

	/**
	 * Initiates template extraction.
	 */
	public function extract_template() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! \efs_is_framer_enabled() ) {
			$this->log_security_event(
				'ajax_action',
				'Blocked template extraction attempt while Framer feature disabled.',
				array(),
				'low'
			);

			wp_send_json_error(
				array(
					'code'    => 'framer_disabled',
					'message' => __( 'Framer template extraction is not enabled on this site.', 'etch-fusion-suite' ),
				),
				403
			);
			return;
		}

		if ( ! $this->check_rate_limit( 'template_extract', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$source      = $this->get_post( 'source', '', 'text' );
		$source_type = $this->get_post( 'source_type', 'url', 'key' );

		$result = $this->template_controller->extract_template( $source, $source_type );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'template_extract_failed';
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			$this->log_security_event(
				'ajax_action',
				'Failed to extract template: ' . $result->get_error_message(),
				array(
					'code'        => $error_code,
					'source_type' => $source_type,
				),
				'medium'
			);

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $error_code,
				),
				$status
			);
		}

		$this->log_security_event(
			'ajax_action',
			'Extracted template successfully.',
			array( 'source_type' => $source_type )
		);

		wp_send_json_success( $result );
	}

	/**
	 * Returns extraction progress.
	 */
	public function get_extraction_progress() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! \efs_is_framer_enabled() ) {
			$this->log_security_event(
				'ajax_action',
				'Blocked extraction progress request while Framer feature disabled.',
				array(),
				'low'
			);

			wp_send_json_error(
				array(
					'code'    => 'framer_disabled',
					'message' => __( 'Framer template extraction is not enabled on this site.', 'etch-fusion-suite' ),
				),
				403
			);
			return;
		}

		if ( ! $this->check_rate_limit( 'template_progress', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$result = $this->template_controller->get_extraction_progress();
		wp_send_json_success( $result );
	}

	/**
	 * Saves template.
	 */
	public function save_template() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! \efs_is_framer_enabled() ) {
			$this->log_security_event(
				'ajax_action',
				'Blocked template save attempt while Framer feature disabled.',
				array(),
				'low'
			);

			wp_send_json_error(
				array(
					'code'    => 'framer_disabled',
					'message' => __( 'Framer template extraction is not enabled on this site.', 'etch-fusion-suite' ),
				),
				403
			);
			return;
		}

		if ( ! $this->check_rate_limit( 'template_save', 20, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$template_data_raw = $this->get_post( 'template_data', '[]', 'raw' );
		$template_name     = $this->get_post( 'template_name', '', 'text' );

		$template_data = json_decode( is_string( $template_data_raw ) ? $template_data_raw : '[]', true );
		if ( null === $template_data && JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Template data payload is invalid JSON.', 'etch-fusion-suite' ),
					'code'    => 'invalid_template_payload',
				),
				400
			);
			return;
		}
		$template_data = is_array( $template_data ) ? $this->sanitize_array( $template_data, 'template_data' ) : array();

		$result = $this->template_controller->save_template( $template_data, $template_name );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'template_save_failed';
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			$this->log_security_event(
				'ajax_action',
				'Failed to save template: ' . $result->get_error_message(),
				array(
					'code' => $error_code,
				)
			);

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $error_code,
				),
				$status
			);
		}

		$this->log_security_event(
			'ajax_action',
			'Saved template successfully.',
			array( 'template_id' => $result )
		);

		wp_send_json_success( array( 'template_id' => $result ) );
	}

	/**
	 * Retrieves saved templates.
	 */
	public function get_saved_templates() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! \efs_is_framer_enabled() ) {
			$this->log_security_event(
				'ajax_action',
				'Blocked saved templates request while Framer feature disabled.',
				array(),
				'low'
			);

			wp_send_json_error(
				array(
					'code'    => 'framer_disabled',
					'message' => __( 'Framer template extraction is not enabled on this site.', 'etch-fusion-suite' ),
				),
				403
			);
			return;
		}

		if ( ! $this->check_rate_limit( 'template_saved_list', 60, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$result = $this->template_controller->get_saved_templates();

		wp_send_json_success( $result );
	}

	/**
	 * Deletes template.
	 */
	public function delete_template() {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! \efs_is_framer_enabled() ) {
			$this->log_security_event(
				'ajax_action',
				'Blocked template delete attempt while Framer feature disabled.',
				array(),
				'low'
			);

			wp_send_json_error(
				array(
					'code'    => 'framer_disabled',
					'message' => __( 'Framer template extraction is not enabled on this site.', 'etch-fusion-suite' ),
				),
				403
			);
			return;
		}

		if ( ! $this->check_rate_limit( 'template_delete', 10, MINUTE_IN_SECONDS ) ) {
			return;
		}

		$template_id = $this->get_post( 'template_id', 0, 'int' );

		$result = $this->template_controller->delete_template( $template_id );

		if ( is_wp_error( $result ) ) {
			$this->log_security_event(
				'ajax_action',
				'Failed to delete template: ' . $result->get_error_message(),
				array(
					'template_id' => $template_id,
				)
			);

			$error_code = $result->get_error_code() ? sanitize_key( $result->get_error_code() ) : 'template_delete_failed';
			$status     = 400;
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			}

			if ( 'not_found' === $error_code ) {
				$status = 404;
			}

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $error_code,
				),
				$status
			);
		}

		$this->log_security_event(
			'ajax_action',
			'Deleted template successfully.',
			array( 'template_id' => $template_id )
		);

		wp_send_json_success( array( 'deleted' => true ) );
	}
}
