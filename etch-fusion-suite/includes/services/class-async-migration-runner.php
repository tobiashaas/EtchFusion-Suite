<?php
/**
 * Async Migration Runner Service
 *
 * Runs the long-running migration steps (called by the background AJAX request).
 * Extracted from EFS_Migration_Orchestrator.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Async_Migration_Runner
 *
 * Performs the CSS, media-collection, and post-collection phases for the
 * JS-driven batch loop, then stores the initial batch checkpoint.
 */
class EFS_Async_Migration_Runner {

	/** @var EFS_Migrator_Executor */
	private $migrator_executor;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var EFS_Migration_Run_Finalizer */
	private $run_finalizer;

	/** @var EFS_CSS_Service */
	private $css_service;

	/** @var EFS_Media_Service */
	private $media_service;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_Content_Parser */
	private $content_parser;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Plugin_Detector */
	private $plugin_detector;

	/** @var EFS_Migration_Logger */
	private $migration_logger;

	/**
	 * @param EFS_Migrator_Executor          $migrator_executor
	 * @param EFS_Progress_Manager           $progress_manager
	 * @param EFS_Migration_Run_Finalizer    $run_finalizer
	 * @param EFS_CSS_Service                $css_service
	 * @param EFS_Media_Service              $media_service
	 * @param EFS_Content_Service            $content_service
	 * @param EFS_Content_Parser             $content_parser
	 * @param EFS_API_Client                 $api_client
	 * @param Migration_Repository_Interface $migration_repository
	 * @param EFS_Error_Handler              $error_handler
	 * @param EFS_Plugin_Detector            $plugin_detector
	 * @param EFS_Migration_Logger           $migration_logger
	 */
	public function __construct(
		EFS_Migrator_Executor $migrator_executor,
		EFS_Progress_Manager $progress_manager,
		EFS_Migration_Run_Finalizer $run_finalizer,
		EFS_CSS_Service $css_service,
		EFS_Media_Service $media_service,
		EFS_Content_Service $content_service,
		EFS_Content_Parser $content_parser,
		EFS_API_Client $api_client,
		Migration_Repository_Interface $migration_repository,
		EFS_Error_Handler $error_handler,
		EFS_Plugin_Detector $plugin_detector,
		EFS_Migration_Logger $migration_logger
	) {
		$this->migrator_executor    = $migrator_executor;
		$this->progress_manager     = $progress_manager;
		$this->run_finalizer        = $run_finalizer;
		$this->css_service          = $css_service;
		$this->media_service        = $media_service;
		$this->content_service      = $content_service;
		$this->content_parser       = $content_parser;
		$this->api_client           = $api_client;
		$this->migration_repository = $migration_repository;
		$this->error_handler        = $error_handler;
		$this->plugin_detector      = $plugin_detector;
		$this->migration_logger     = $migration_logger;
	}

	/**
	 * Run the long-running migration steps (called by background AJAX request).
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '' ) {
		set_time_limit( 0 );
		ignore_user_abort( true );
		$is_cron_context = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		$is_ajax_context = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		$migration_id_for_shutdown  = $migration_id;
		$progress_manager_shutdown  = $this->progress_manager;
		$migration_logger_shutdown  = $this->migration_logger;
		register_shutdown_function(
			function () use ( $migration_id_for_shutdown, $progress_manager_shutdown, $migration_logger_shutdown ) {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					$progress_manager_shutdown->update_progress( 'error', 0, 'Fatal error: ' . $error['message'] );
					if ( $migration_logger_shutdown ) {
						$migration_logger_shutdown->log( $migration_id_for_shutdown, 'error', 'Fatal shutdown: ' . $error['message'] );
					}
				}
			}
		);
		$this->migration_logger->log( $migration_id, 'info', 'Migration execution started' );
		$active = $this->migration_repository->get_active_migration();
		if ( ! is_array( $active ) || empty( $active['migration_id'] ) || (string) $active['migration_id'] !== (string) $migration_id ) {
			return new \WP_Error( 'migration_not_found', __( 'No active migration found for this ID.', 'etch-fusion-suite' ) );
		}

		$target              = isset( $active['target_url'] ) ? $active['target_url'] : '';
		$migration_key       = isset( $active['migration_key'] ) ? $active['migration_key'] : '';
		$batch_size          = isset( $active['batch_size'] ) ? max( 1, (int) $active['batch_size'] ) : 50;
		$options             = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();
		$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();

		try {
			$this->progress_manager->update_progress( 'validation', 10, __( 'Validating migration requirements...', 'etch-fusion-suite' ) );
			$validation_result = $this->plugin_detector->validate_migration_requirements();
			if ( ! is_array( $validation_result ) ) {
				$validation_result = array(
					'valid'  => false,
					'errors' => array( __( 'Unknown validation response.', 'etch-fusion-suite' ) ),
				);
			}
			if ( ! $validation_result['valid'] ) {
				$error_message = 'Migration validation failed: ' . implode( ', ', $validation_result['errors'] );
				$this->error_handler->log_error(
					'E103',
					array(
						'validation_errors' => $validation_result['errors'],
						'action'            => 'Target site validation failed',
					)
				);
				$current_percentage = isset( $this->progress_manager->get_progress_data()['percentage'] ) ? (int) $this->progress_manager->get_progress_data()['percentage'] : 0;
				$this->progress_manager->update_progress( 'error', $current_percentage, $error_message );
				$this->run_finalizer->write_failed_run_record( $migration_id, $error_message );
				$this->progress_manager->store_active_migration( array() );
				return new \WP_Error( 'validation_failed', $error_message );
			}

			$this->progress_manager->update_progress( 'analyzing', 20, __( 'Analyzing content...', 'etch-fusion-suite' ) );
			$analysis = $this->content_service->analyze_content( $selected_post_types );
			$this->progress_manager->update_progress(
				'analyzing',
				25,
				sprintf(
					/* translators: 1: Bricks post count, 2: Gutenberg post count, 3: media file count, 4: total items. */
					__( 'Found %1$d Bricks posts, %2$d Gutenberg posts, %3$d media files (%4$d total)', 'etch-fusion-suite' ),
					$analysis['bricks_posts'],
					$analysis['gutenberg_posts'],
					$analysis['media'],
					$analysis['total']
				)
			);

			$steps            = $this->progress_manager->get_steps_state();
			$progress_manager = $this->progress_manager;
			$on_progress      = function ( $step_key, $pct, $migrator_name ) use ( $progress_manager ) {
				$progress_manager->update_progress(
					$step_key,
					$pct,
					sprintf(
						/* translators: %s: Migrator name. */
						__( 'Migrating %s...', 'etch-fusion-suite' ),
						$migrator_name
					)
				);
				$progress_manager->touch_progress_heartbeat();
			};

			$this->progress_manager->touch_progress_heartbeat();
			$migrator_result = $this->migrator_executor->execute_migrators( $target, $migration_key, $steps, $on_progress );
			$this->progress_manager->touch_progress_heartbeat();

			if ( is_wp_error( $migrator_result ) ) {
				return $migrator_result;
			}
			$async_migrator_warnings = isset( $migrator_result['warnings'] ) ? $migrator_result['warnings'] : array();
			$this->migration_logger->log( $migration_id, 'info', 'CPTs registered' );

			$this->progress_manager->update_progress( 'css', 70, __( 'Converting CSS classes...', 'etch-fusion-suite' ) );
			$css_result = $this->css_service->migrate_css_classes( $target, $migration_key );
			if ( is_wp_error( $css_result ) || ( is_array( $css_result ) && isset( $css_result['success'] ) && ! $css_result['success'] ) ) {
				return is_wp_error( $css_result ) ? $css_result : new \WP_Error( 'css_migration_failed', $css_result['message'] );
			}
			$this->migration_logger->log( $migration_id, 'info', 'CSS classes migrated: ' . ( $css_result['migrated'] ?? 0 ) );

			$steps         = $this->progress_manager->get_steps_state();
			$include_media = ! empty( $options['include_media'] ) && isset( $steps['media'] );
			$media_ids     = $include_media ? $this->media_service->get_media_ids( $selected_post_types ) : array();

			// Collect post IDs for JS-driven batch loop.
			$bricks_posts    = $this->content_parser->get_bricks_posts( $selected_post_types );
			$gutenberg_posts = $this->content_parser->get_gutenberg_posts( $selected_post_types );
			$all_posts       = array_merge(
				is_array( $bricks_posts ) ? $bricks_posts : array(),
				is_array( $gutenberg_posts ) ? $gutenberg_posts : array()
			);

			$post_ids    = array_values(
				array_map(
					function ( $post ) {
						return (int) $post->ID;
					},
					$all_posts
				)
			);
			$total_count = count( $post_ids );

			$counts_by_post_type_totals = array();
			foreach ( $all_posts as $post ) {
				if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
					continue;
				}
				$post_type_key = sanitize_key( (string) $post->post_type );
				if ( '' === $post_type_key ) {
					continue;
				}
				if ( ! isset( $counts_by_post_type_totals[ $post_type_key ] ) ) {
					$counts_by_post_type_totals[ $post_type_key ] = 0;
				}
				++$counts_by_post_type_totals[ $post_type_key ];
			}

			$phase = ( $include_media && ! empty( $media_ids ) ) ? 'media' : 'posts';
			$this->migration_logger->log( $migration_id, 'info', 'Post IDs collected: ' . $total_count );

			$this->migration_repository->save_checkpoint(
				array(
					'phase'                      => $phase,
					'migrationId'                => $migration_id,
					'remaining_media_ids'        => $media_ids,
					'processed_media_count'      => 0,
					'total_media_count'          => count( $media_ids ),
					'media_attempts'             => array(),
					'failed_media_ids_final'     => array(),
					'remaining_post_ids'         => $post_ids,
					'processed_count'            => 0,
					'total_count'                => $total_count,
					'current_item_id'            => null,
					'current_item_title'         => '',
					'migrator_warnings'          => $async_migrator_warnings,
					'counts_by_post_type_totals' => $counts_by_post_type_totals,
				)
			);

			// Set step active only; do not mark completed until batching exhausts items.
			if ( 'media' === $phase ) {
				$this->progress_manager->update_progress( 'media', 60, __( 'Migrating media…', 'etch-fusion-suite' ), 0, count( $media_ids ), false );
			} else {
				$this->progress_manager->update_progress( 'posts', 80, __( 'Ready to migrate posts...', 'etch-fusion-suite' ), 0, $total_count, false );
			}

			return array(
				'posts_ready' => true,
				'migrationId' => $migration_id,
				'total'       => 'media' === $phase ? count( $media_ids ) : $total_count,
				'progress'    => $this->progress_manager->get_progress_data(),
				'steps'       => $this->progress_manager->get_steps_state(),
			);
		} catch ( \Throwable $exception ) {
			$error_message = sprintf(
				'Migration process failed: %s (File: %s, Line: %d)',
				$exception->getMessage(),
				basename( $exception->getFile() ),
				$exception->getLine()
			);
			$this->migration_logger->log( $migration_id, 'error', $exception->getMessage() );
			$this->error_handler->log_error(
				'E201',
				array(
					'message' => $exception->getMessage(),
					'action'  => 'run_migration_execution',
				)
			);
			$current_percentage = isset( $this->progress_manager->get_progress_data()['percentage'] ) ? (int) $this->progress_manager->get_progress_data()['percentage'] : 0;
			$this->progress_manager->update_progress( 'error', $current_percentage, $error_message );
			$this->run_finalizer->write_failed_run_record( $migration_id, $error_message );
			$this->progress_manager->store_active_migration( array() );
			return new \WP_Error( 'migration_failed', $error_message );
		}
	}
}
