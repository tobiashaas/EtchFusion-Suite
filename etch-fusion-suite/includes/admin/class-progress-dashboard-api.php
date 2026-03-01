<?php
/**
 * REST API Endpoints for Migration Progress Dashboard
 *
 * Provides endpoints for dashboard to fetch real-time migration logs and progress.
 *
 * @package Bricks2Etch\Admin
 */

namespace Bricks2Etch\Admin;

use Bricks2Etch\Controllers\EFS_Migration_Progress_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Progress_Dashboard_API
 *
 * Registers REST endpoints for dashboard logging:
 * - GET /efs/v1/migration/{migration_id}/progress
 * - GET /efs/v1/migration/{migration_id}/errors
 * - GET /efs/v1/migration/{migration_id}/logs/{category}
 */
class EFS_Progress_Dashboard_API {
	use EFS_Migration_Progress_Logger;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// Real-time progress endpoint.
		register_rest_route(
			'efs/v1',
			'/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/progress',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_progress_request' ),
				'permission_callback' => array( __CLASS__, 'check_dashboard_access' ),
				'args'                => array(
					'migration_id' => array(
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && strlen( $param ) > 0;
						},
					),
				),
			)
		);

		// Errors endpoint.
		register_rest_route(
			'efs/v1',
			'/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/errors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_errors_request' ),
				'permission_callback' => array( __CLASS__, 'check_dashboard_access' ),
			)
		);

		// Category-filtered logs endpoint.
		register_rest_route(
			'efs/v1',
			'/migration/(?P<migration_id>[a-zA-Z0-9\-]+)/logs/(?P<category>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_category_request' ),
				'permission_callback' => array( __CLASS__, 'check_dashboard_access' ),
			)
		);
	}

	/**
	 * Check if user has access to dashboard logs.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool True if user can access dashboard, false otherwise.
	 */
	public static function check_dashboard_access( \WP_REST_Request $request ) {
		// Only logged-in admins can view migration logs.
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Get migration progress endpoint (REST callback).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_progress_request( \WP_REST_Request $request ) {
		$instance = new self();
		$migration_id = $request->get_param( 'migration_id' );
		$result = $instance->get_migration_progress( $migration_id );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => $result->get_error_message(),
				)
			)->set_status( 404 );
		}

		return rest_ensure_response( $result )->set_status( 200 );
	}

	/**
	 * Get migration errors endpoint (REST callback).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_errors_request( \WP_REST_Request $request ) {
		$instance = new self();
		$migration_id = $request->get_param( 'migration_id' );
		$errors = $instance->get_migration_errors( $migration_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'errors'  => $errors,
				'count'   => count( $errors ),
			)
		)->set_status( 200 );
	}

	/**
	 * Get migration logs by category endpoint (REST callback).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public static function handle_category_request( \WP_REST_Request $request ) {
		$instance = new self();
		$migration_id = $request->get_param( 'migration_id' );
		$category = $request->get_param( 'category' );
		$logs = $instance->get_migration_logs_by_category( $migration_id, $category );

		return rest_ensure_response(
			array(
				'success'  => true,
				'category' => $category,
				'logs'     => $logs,
				'count'    => count( $logs ),
			)
		)->set_status( 200 );
	}
}
