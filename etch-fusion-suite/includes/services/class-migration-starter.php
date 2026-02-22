<?php
/**
 * Migration Starter Service
 *
 * Handles the two migration entry points: synchronous start and async start.
 * Extracted from EFS_Migration_Orchestrator.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_Starter
 *
 * Validates a migration key, initialises progress, and either runs the full
 * migration synchronously or fires a background spawn for the async path.
 */
class EFS_Migration_Starter {

	/** @var EFS_Migration_Token_Manager */
	private $token_manager;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var EFS_Migration_Run_Finalizer */
	private $run_finalizer;

	/** @var EFS_Migrator_Executor */
	private $migrator_executor;

	/** @var EFS_CSS_Service */
	private $css_service;

	/** @var EFS_Media_Service */
	private $media_service;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Background_Spawn_Handler */
	private $spawn_handler;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Plugin_Detector */
	private $plugin_detector;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/**
	 * @param EFS_Migration_Token_Manager    $token_manager       Non-nullable; caller must ensure token manager is configured.
	 * @param EFS_Progress_Manager           $progress_manager
	 * @param EFS_Migration_Run_Finalizer    $run_finalizer
	 * @param EFS_Migrator_Executor          $migrator_executor
	 * @param EFS_CSS_Service                $css_service
	 * @param EFS_Media_Service              $media_service
	 * @param EFS_Content_Service            $content_service
	 * @param EFS_API_Client                 $api_client
	 * @param EFS_Background_Spawn_Handler   $spawn_handler
	 * @param EFS_Error_Handler              $error_handler
	 * @param EFS_Plugin_Detector            $plugin_detector
	 * @param Migration_Repository_Interface $migration_repository
	 */
	public function __construct(
		EFS_Migration_Token_Manager $token_manager,
		EFS_Progress_Manager $progress_manager,
		EFS_Migration_Run_Finalizer $run_finalizer,
		EFS_Migrator_Executor $migrator_executor,
		EFS_CSS_Service $css_service,
		EFS_Media_Service $media_service,
		EFS_Content_Service $content_service,
		EFS_API_Client $api_client,
		EFS_Background_Spawn_Handler $spawn_handler,
		EFS_Error_Handler $error_handler,
		EFS_Plugin_Detector $plugin_detector,
		Migration_Repository_Interface $migration_repository
	) {
		$this->token_manager        = $token_manager;
		$this->progress_manager     = $progress_manager;
		$this->run_finalizer        = $run_finalizer;
		$this->migrator_executor    = $migrator_executor;
		$this->css_service          = $css_service;
		$this->media_service        = $media_service;
		$this->content_service      = $content_service;
		$this->api_client           = $api_client;
		$this->spawn_handler        = $spawn_handler;
		$this->error_handler        = $error_handler;
		$this->plugin_detector      = $plugin_detector;
		$this->migration_repository = $migration_repository;
	}

	/**
	 * Start migration workflow (synchronous path).
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options       Optional. selected_post_types, post_type_mappings.
	 *
	 * @return array|\WP_Error
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null, $options = array() ) {
		try {
			// Check for already-running migration.
			$progress_data   = $this->progress_manager->get_progress_data();
			$existing_id     = isset( $progress_data['migrationId'] ) ? (string) $progress_data['migrationId'] : '';
			$existing_status = isset( $progress_data['status'] ) ? (string) $progress_data['status'] : 'idle';
			$is_stale        = ! empty( $progress_data['is_stale'] ) || 'stale' === $existing_status;
			if ( '' !== $existing_id && in_array( $existing_status, array( 'running', 'receiving' ), true ) && ! $is_stale ) {
				return new \WP_Error( 'migration_in_progress', __( 'A migration is already in progress.', 'etch-fusion-suite' ) );
			}

			$context = $this->prepare_migration_context( $migration_key, $target_url );
			if ( is_wp_error( $context ) ) {
				return $context;
			}

			$target  = $context['target'];
			$payload = $context['payload'];
			$expires = $context['expires'];

			$batch_size   = $batch_size ? max( 1, (int) $batch_size ) : 50;
			$migration_id = $this->spawn_handler->generate_migration_id();
			$this->progress_manager->init_progress( $migration_id, $options );
			$this->progress_manager->store_active_migration(
				array(
					'migration_id'  => $migration_id,
					'migration_key' => $migration_key,
					'target_url'    => $target,
					'batch_size'    => $batch_size,
					'issued_at'     => $payload['iat'] ?? time(),
					'expires_at'    => $expires,
					'options'       => $options,
				)
			);

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
			$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
			$analysis            = $this->content_service->analyze_content( $selected_post_types );
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
			$sync_migrator_warnings = isset( $migrator_result['warnings'] ) ? $migrator_result['warnings'] : array();

			$steps        = $this->progress_manager->get_steps_state();
			$media_result = array( 'skipped' => true );
			if ( isset( $steps['media'] ) ) {
				$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
				$this->progress_manager->update_progress( 'media', 60, __( 'Migrating media files...', 'etch-fusion-suite' ) );
				$media_result = $this->media_service->migrate_media( $target, $migration_key, $selected_post_types );
				if ( is_wp_error( $media_result ) ) {
					return $media_result;
				}
			}

			$this->progress_manager->update_progress( 'css', 70, __( 'Converting CSS classes...', 'etch-fusion-suite' ) );
			$css_result = $this->css_service->migrate_css_classes( $target, $migration_key );
			if ( is_wp_error( $css_result ) || ( is_array( $css_result ) && isset( $css_result['success'] ) && ! $css_result['success'] ) ) {
				return is_wp_error( $css_result ) ? $css_result : new \WP_Error( 'css_migration_failed', $css_result['message'] );
			}

			$this->progress_manager->update_progress( 'posts', 80, __( 'Migrating posts and content...', 'etch-fusion-suite' ) );
			$post_type_mappings  = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] ) ? $options['post_type_mappings'] : array();
			$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
			$posts_result        = $this->content_service->migrate_posts( $target, $migration_key, $this->api_client, $post_type_mappings, $selected_post_types );
			if ( is_wp_error( $posts_result ) ) {
				return $posts_result;
			}
			$posts_total_sync    = isset( $posts_result['total'] ) ? (int) $posts_result['total'] : 0;
			$posts_migrated_sync = isset( $posts_result['migrated'] ) ? (int) $posts_result['migrated'] : 0;
			if ( $posts_total_sync > 0 && 0 === $posts_migrated_sync ) {
				$this->progress_manager->update_progress( 'error', 80, __( 'No posts could be transferred to the target site.', 'etch-fusion-suite' ) );
				$this->run_finalizer->write_failed_run_record( $migration_id, __( 'No posts could be transferred. Check the connection to the target site, the migration key, and that Page/Post types are mapped correctly.', 'etch-fusion-suite' ) );
				$this->progress_manager->store_active_migration( array() );
				return new \WP_Error(
					'posts_migration_failed',
					__( 'No posts could be transferred. Check the connection to the target site, the migration key, and that Page/Post types are mapped correctly.', 'etch-fusion-suite' )
				);
			}

			$this->progress_manager->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$finalization_result = $this->run_finalizer->finalize_migration( $posts_result, $sync_migrator_warnings );
			if ( is_wp_error( $finalization_result ) ) {
				return $finalization_result;
			}

			$this->progress_manager->update_progress( 'completed', 100, __( 'Migration completed successfully!', 'etch-fusion-suite' ) );
			$this->progress_manager->store_active_migration( array() );

			$migration_stats                   = $this->migration_repository->get_stats();
			$migration_stats['last_migration'] = current_time( 'mysql' );
			$migration_stats['status']         = 'completed';
			$this->migration_repository->save_stats( $migration_stats );

			return array(
				'progress'    => $this->progress_manager->get_progress_data(),
				'steps'       => $this->progress_manager->get_steps_state(),
				'migrationId' => $migration_id,
				'completed'   => true,
				'message'     => __( 'Migration completed successfully!', 'etch-fusion-suite' ),
				'details'     => array(
					'media' => $media_result,
					'css'   => $css_result,
					'posts' => $posts_result,
				),
			);
		} catch ( \Throwable $exception ) {
			$error_message = sprintf(
				'Migration process failed: %s (File: %s, Line: %d)',
				$exception->getMessage(),
				basename( $exception->getFile() ),
				$exception->getLine()
			);

			$this->error_handler->log_error(
				'E201',
				array(
					'message' => $exception->getMessage(),
					'file'    => $exception->getFile(),
					'line'    => $exception->getLine(),
					'trace'   => $exception->getTraceAsString(),
					'action'  => 'Migration process failed',
				)
			);

			$current_percentage = isset( $this->progress_manager->get_progress_data()['percentage'] ) ? (int) $this->progress_manager->get_progress_data()['percentage'] : 0;
			$this->progress_manager->update_progress( 'error', $current_percentage, $error_message );
			$this->run_finalizer->write_failed_run_record( isset( $migration_id ) ? $migration_id : '', $error_message );
			$this->progress_manager->store_active_migration( array() );

			return new \WP_Error( 'migration_failed', $error_message );
		}
	}

	/**
	 * Start migration asynchronously: prepare state, spawn background request, return immediately.
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options  selected_post_types, post_type_mappings, include_media.
	 * @param string      $nonce    Nonce for the background AJAX request.
	 * @return array|\WP_Error
	 */
	public function start_migration_async( $migration_key, $target_url = null, $batch_size = null, $options = array(), $nonce = '' ) {
		try {
			// Check for already-running migration.
			$progress_data   = $this->progress_manager->get_progress_data();
			$existing_id     = isset( $progress_data['migrationId'] ) ? (string) $progress_data['migrationId'] : '';
			$existing_status = isset( $progress_data['status'] ) ? (string) $progress_data['status'] : 'idle';
			$is_stale        = ! empty( $progress_data['is_stale'] ) || 'stale' === $existing_status;
			if ( '' !== $existing_id && in_array( $existing_status, array( 'running', 'receiving' ), true ) ) {
				if ( ! $is_stale ) {
					return new \WP_Error( 'migration_in_progress', __( 'A migration is already in progress.', 'etch-fusion-suite' ) );
				}
				// Stale run: clear state so a new migration can start.
				$this->migration_repository->delete_progress();
				$this->migration_repository->delete_steps();
				$this->migration_repository->delete_token_data();
				$this->progress_manager->store_active_migration( array() );
			}

			$context = $this->prepare_migration_context( $migration_key, $target_url );
			// #region agent log
			$efs_log_starter = defined( 'ETCH_FUSION_SUITE_DIR' ) ? ETCH_FUSION_SUITE_DIR . 'debug-916622.log' : null;
			if ( $efs_log_starter ) {
				file_put_contents( $efs_log_starter, json_encode( array( 'sessionId' => '916622', 'timestamp' => (int) ( microtime( true ) * 1000 ), 'location' => __FILE__ . ':' . __LINE__, 'message' => 'prepare_migration_context result (async)', 'data' => array( 'is_wp_error' => is_wp_error( $context ), 'code' => is_wp_error( $context ) ? $context->get_error_code() : null, 'message' => is_wp_error( $context ) ? $context->get_error_message() : null ), 'hypothesisId' => 'A' ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			// #endregion
			if ( is_wp_error( $context ) ) {
				return $context;
			}

			$target  = $context['target'];
			$payload = $context['payload'];
			$expires = $context['expires'];

			$batch_size   = $batch_size ? max( 1, (int) $batch_size ) : 50;
			$migration_id = $this->spawn_handler->generate_migration_id();
			$this->progress_manager->init_progress( $migration_id, $options );
			$this->progress_manager->store_active_migration(
				array(
					'migration_id'  => $migration_id,
					'migration_key' => $migration_key,
					'target_url'    => $target,
					'batch_size'    => $batch_size,
					'issued_at'     => $payload['iat'] ?? time(),
					'expires_at'    => $expires,
					'options'       => $options,
				)
			);

			$this->spawn_handler->spawn_migration_background_request( $migration_id, $nonce );

			// #region agent log
			if ( isset( $efs_log_starter ) && $efs_log_starter ) {
				file_put_contents( $efs_log_starter, json_encode( array( 'sessionId' => '916622', 'timestamp' => (int) ( microtime( true ) * 1000 ), 'location' => __FILE__ . ':' . __LINE__, 'message' => 'start_migration_async spawn called', 'data' => array( 'migration_id' => $migration_id ), 'hypothesisId' => 'A' ) ) . "\n", FILE_APPEND | LOCK_EX );
			}
			// #endregion
			return array(
				'progress'    => $this->progress_manager->get_progress_data(),
				'steps'       => $this->progress_manager->get_steps_state(),
				'migrationId' => $migration_id,
				'completed'   => false,
				'message'     => __( 'Migration started.', 'etch-fusion-suite' ),
			);
		} catch ( \Throwable $exception ) {
			$error_message = sprintf(
				'Migration start failed: %s (File: %s, Line: %d)',
				$exception->getMessage(),
				basename( $exception->getFile() ),
				$exception->getLine()
			);
			$this->error_handler->log_error(
				'E201',
				array(
					'message' => $exception->getMessage(),
					'action'  => 'start_migration_async',
				)
			);
			return new \WP_Error( 'migration_start_failed', $error_message );
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Decode the migration key, resolve the target URL, and check expiry.
	 *
	 * @param string      $migration_key
	 * @param string|null $target_url    Explicit override; falls back to payload target_url.
	 * @return array|\WP_Error { target: string, payload: array, expires: int } or WP_Error.
	 */
	private function prepare_migration_context( string $migration_key, ?string $target_url ) {
		$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$payload = $decoded['payload'];
		$target  = ! empty( $target_url ) ? $target_url : ( $payload['target_url'] ?? '' );

		if ( empty( $target ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Migration key does not contain a target URL.', 'etch-fusion-suite' ) );
		}

		// Convert localhost URLs to Docker internal URLs for wp-env.
		$target = str_replace( 'http://localhost:8889', 'http://tests-wordpress', $target );
		$target = str_replace( 'https://localhost:8889', 'http://tests-wordpress', $target );

		$expires = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;

		if ( ! $expires || time() > $expires ) {
			return new \WP_Error( 'token_expired', __( 'Migration key has expired.', 'etch-fusion-suite' ) );
		}

		return array(
			'target'  => $target,
			'payload' => $payload,
			'expires' => $expires,
		);
	}
}
