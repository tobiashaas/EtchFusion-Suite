<?php
/**
 * Debug AJAX Handler
 *
 * Dev-only AJAX endpoints for test support.
 *
 * @package Etch_Fusion_Suite
 */

namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Debug_Ajax_Handler
 */
class EFS_Debug_Ajax_Handler extends EFS_Base_Ajax_Handler {
	/**
	 * Register hooks.
	 */
	protected function register_hooks() {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		add_action( 'wp_ajax_efs_query_db', array( $this, 'query_db' ) );
	}

	/**
	 * Execute a dev-only SELECT query.
	 */
	public function query_db() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'etch-fusion-suite' ),
					'code'    => 'forbidden',
				),
				403
			);
			return;
		}

		// Verify nonce even for dev-only endpoints to prevent CSRF.
		$raw_nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $raw_nonce, 'efs_nonce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed.', 'etch-fusion-suite' ),
					'code'    => 'invalid_nonce',
				),
				403
			);
			return;
		}

		$sql = isset( $_POST['sql'] ) ? sanitize_text_field( wp_unslash( $_POST['sql'] ) ) : '';
		$sql = is_string( $sql ) ? trim( $sql ) : '';

		if ( '' === $sql || 0 !== strpos( strtoupper( $sql ), 'SELECT' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Only SELECT queries are allowed.', 'etch-fusion-suite' ),
					'code'    => 'forbidden_query',
				),
				400
			);
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dev-only SELECT runner; query validated above (SELECT only).
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		wp_send_json( is_array( $rows ) ? $rows : array() );
	}
}
