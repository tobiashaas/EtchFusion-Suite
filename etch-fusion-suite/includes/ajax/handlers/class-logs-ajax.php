<?php
namespace Bricks2Etch\Ajax\Handlers;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Services\EFS_Migration_Logger;
use Bricks2Etch\Services\EFS_Pre_Flight_Checker;

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
	 * Pre-flight checker instance (optional).
	 *
	 * @var EFS_Pre_Flight_Checker|null
	 */
	protected $preflight_checker;

	/**
	 * Migration runs repository instance.
	 *
	 * @var \Bricks2Etch\Repositories\EFS_Migration_Runs_Repository|null
	 */
	protected $migration_runs_repository;

	/**
	 * Migration logger instance.
	 *
	 * @var EFS_Migration_Logger|null
	 */
	protected $migration_logger;

	/**
	 * Error handler instance (holds the efs_migration_log WP option).
	 *
	 * @var EFS_Error_Handler|null
	 */
	protected $error_handler;

	/**
	 * Constructor
	 *
	 * @param EFS_Audit_Logger|null                                                   $audit_logger              Audit logger instance.
	 * @param \Bricks2Etch\Repositories\EFS_Migration_Runs_Repository|null           $migration_runs_repository Migration runs repository (optional).
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null                            $rate_limiter              Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null                         $input_validator           Input validator instance (optional).
	 * @param EFS_Migration_Logger|null                                               $migration_logger          Migration logger instance (optional).
	 * @param EFS_Pre_Flight_Checker|null                                             $preflight_checker         Pre-flight checker (optional).
	 * @param EFS_Error_Handler|null                                                  $error_handler             Error handler (optional).
	 */
	public function __construct( ?EFS_Audit_Logger $audit_logger = null, ?\Bricks2Etch\Repositories\EFS_Migration_Runs_Repository $migration_runs_repository = null, $rate_limiter = null, $input_validator = null, ?EFS_Migration_Logger $migration_logger = null, ?EFS_Pre_Flight_Checker $preflight_checker = null, ?EFS_Error_Handler $error_handler = null ) {
		$this->migration_logger          = $migration_logger;
		$this->audit_logger              = $audit_logger;
		$this->migration_runs_repository = $migration_runs_repository;
		$this->preflight_checker         = $preflight_checker;
		$this->error_handler             = $error_handler;

		if ( null === $this->migration_runs_repository && function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$container = etch_fusion_suite_container();
				if ( $container->has( 'migration_runs_repository' ) ) {
					$this->migration_runs_repository = $container->get( 'migration_runs_repository' );
				}
			} catch ( \Exception $e ) {
				// Silently fail if container not available.
			}
		}

		parent::__construct( $rate_limiter, $input_validator, $audit_logger );
	}

	protected function register_hooks() {
		add_action( 'wp_ajax_efs_clear_logs', array( $this, 'clear_logs' ) );
		add_action( 'wp_ajax_efs_get_logs', array( $this, 'get_logs' ) );
		add_action( 'wp_ajax_efs_get_migration_log', array( $this, 'handle_get_migration_log' ) );
		add_action( 'wp_ajax_efs_invalidate_preflight_cache', array( $this, 'invalidate_preflight_cache' ) );
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

		$security_cleared = $this->audit_logger->clear_security_logs();

		if ( ! $security_cleared ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to clear audit logs.', 'etch-fusion-suite' ),
					'code'    => 'clear_failed',
				),
				500
			);
			return;
		}

		// Clear the efs_migration_log WP option written by EFS_Error_Handler.
		if ( $this->error_handler ) {
			$this->error_handler->clear_log();
		} else {
			// Fallback: delete the option directly when the handler is not injected.
			delete_option( 'efs_migration_log' );
		}

		// Delete per-migration log files from uploads/efs-migration-logs/.
		if ( $this->migration_logger ) {
			$this->migration_logger->delete_all_logs();
		}

		if ( $this->migration_runs_repository ) {
			$this->migration_runs_repository->clear_runs();
		}

		wp_send_json_success(
			array(
				'message' => __( 'Audit logs cleared successfully.', 'etch-fusion-suite' ),
			)
		);
	}

	public function handle_get_migration_log(): void {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_rate_limit( 'migration_log_fetch', 30, 60 ) ) {
			return;
		}

		$migration_id = $this->get_post( 'migration_id', '', 'text' );
		if ( '' === $migration_id ) {
			wp_send_json_error(
				array(
					'message' => 'Missing migration_id',
					'code'    => 'missing_param',
				),
				400
			);
			return;
		}

		// Validate migration_id format: only alphanumerics, hyphens, and underscores; max 64 chars.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $migration_id ) ) {
			wp_send_json_error(
				array(
					'message' => 'Invalid migration_id format',
					'code'    => 'invalid_param',
				),
				400
			);
			return;
		}

		if ( null === $this->migration_logger ) {
			wp_send_json_error(
				array(
					'message' => 'Logger service unavailable',
					'code'    => 'service_unavailable',
				),
				503
			);
			return;
		}

		$log_path = $this->migration_logger->get_log_path( $migration_id );

		if ( ! file_exists( $log_path ) ) {
			wp_send_json_error(
				array(
					'message' => 'Log file not found',
					'code'    => 'not_found',
				),
				404
			);
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $log_path );
		if ( false === $content ) {
			wp_send_json_error(
				array(
					'message' => 'Failed to read log file',
					'code'    => 'read_error',
				),
				500
			);
			return;
		}

		wp_send_json_success( array( 'content' => $content ) );
	}

	/**
	 * Invalidate pre-flight check cache.
	 */
	public function invalidate_preflight_cache(): void {
		if ( ! $this->verify_request( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->check_rate_limit( 'invalidate_preflight', 30, MINUTE_IN_SECONDS ) ) {
			return;
		}
		if ( $this->preflight_checker instanceof EFS_Pre_Flight_Checker ) {
			$this->preflight_checker->invalidate_cache();
		}
		wp_send_json_success( array( 'invalidated' => true ) );
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

		$logs           = $this->audit_logger->get_security_logs();
		$logs           = array_values(
			array_filter(
				$logs,
				static function ( $entry ) {
					return ( $entry['event_type'] ?? '' ) !== 'logs_accessed';
				}
			)
		);
		$migration_runs = $this->migration_runs_repository ? $this->migration_runs_repository->get_runs( 50 ) : array();

		wp_send_json_success(
			array(
				'security_logs'  => $logs,
				'migration_runs' => $migration_runs,
				'filter'         => 'all',
			)
		);
	}
}
