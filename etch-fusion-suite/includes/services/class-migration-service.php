<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

class EFS_Migration_Service {
	/**
	 * Canonical phase order for dashboard progress.
	 */
	private const PHASES = array(
		'validation'    => 'Validation',
		'analyzing'     => 'Analyzing',
		'cpts'          => 'Custom Post Types',
		'acf'           => 'ACF Field Groups',
		'metabox'       => 'MetaBox Configs',
		'custom_fields' => 'Custom Fields',
		'media'         => 'Media',
		'css'           => 'CSS',
		'posts'         => 'Posts',
		'finalization'  => 'Finalization',
	);

	/**
	 * Running progress with no updates for this period is stale.
	 */
	private const PROGRESS_STALE_TTL = 300;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Plugin_Detector */
	private $plugin_detector;

	/** @var EFS_Content_Parser */
	private $content_parser;

	/** @var EFS_CSS_Service */
	private $css_service;

	/** @var EFS_Media_Service */
	private $media_service;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var \Bricks2Etch\Core\EFS_Migration_Token_Manager */
	private $token_manager;

	/** @var EFS_Migrator_Registry */
	private $migrator_registry;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/**
	 * @param EFS_Error_Handler                $error_handler
	 * @param EFS_Plugin_Detector              $plugin_detector
	 * @param EFS_Content_Parser               $content_parser
	 * @param EFS_CSS_Service                  $css_service
	 * @param EFS_Media_Service                $media_service
	 * @param EFS_Content_Service              $content_service
	 * @param EFS_API_Client                   $api_client
	 * @param EFS_Migrator_Registry            $migrator_registry
	 * @param Migration_Repository_Interface   $migration_repository
	 * @param \Bricks2Etch\Core\EFS_Migration_Token_Manager $token_manager
	 */
	public function __construct(
		EFS_Error_Handler $error_handler,
		EFS_Plugin_Detector $plugin_detector,
		EFS_Content_Parser $content_parser,
		EFS_CSS_Service $css_service,
		EFS_Media_Service $media_service,
		EFS_Content_Service $content_service,
		EFS_API_Client $api_client,
		EFS_Migrator_Registry $migrator_registry,
		Migration_Repository_Interface $migration_repository,
		?\Bricks2Etch\Core\EFS_Migration_Token_Manager $token_manager = null
	) {
		$this->error_handler        = $error_handler;
		$this->plugin_detector      = $plugin_detector;
		$this->content_parser       = $content_parser;
		$this->css_service          = $css_service;
		$this->media_service        = $media_service;
		$this->content_service      = $content_service;
		$this->api_client           = $api_client;
		$this->migrator_registry    = $migrator_registry;
		$this->migration_repository = $migration_repository;
		$this->token_manager        = $token_manager;
	}

	/**
	 * Start migration workflow.
	 *
	 * @param string $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options       Optional. selected_post_types, post_type_mappings.
	 *
	 * @return array|\WP_Error
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null, $options = array() ) {
		try {
			$in_progress = $this->detect_in_progress_migration();
			if ( ! empty( $in_progress['migrationId'] ) && empty( $in_progress['is_stale'] ) ) {
				return new \WP_Error( 'migration_in_progress', __( 'A migration is already in progress.', 'etch-fusion-suite' ) );
			}

			$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );

			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$payload = $decoded['payload'];
			$target  = ! empty( $target_url ) ? $target_url : ( $payload['target_url'] ?? '' );

			if ( empty( $target ) ) {
				return new \WP_Error( 'missing_target_url', __( 'Migration key does not contain a target URL.', 'etch-fusion-suite' ) );
			}

			// Convert localhost URLs to Docker internal URLs for wp-env
			$target = str_replace( 'http://localhost:8889', 'http://tests-wordpress', $target );
			$target = str_replace( 'https://localhost:8889', 'http://tests-wordpress', $target );

			$expires = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;

			if ( ! $expires || time() > $expires ) {
				return new \WP_Error( 'token_expired', __( 'Migration key has expired.', 'etch-fusion-suite' ) );
			}

			$batch_size   = $batch_size ? max( 1, (int) $batch_size ) : 50;
			$migration_id = $this->generate_migration_id();
			$this->init_progress( $migration_id, $options );
			$this->store_active_migration(
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

			$this->update_progress( 'validation', 10, __( 'Validating migration requirements...', 'etch-fusion-suite' ) );
			$validation_result = $this->validate_target_site_requirements();

			if ( ! $validation_result['valid'] ) {
				$error_message = 'Migration validation failed: ' . implode( ', ', $validation_result['errors'] );
				$this->error_handler->log_error(
					'E103',
					array(
						'validation_errors' => $validation_result['errors'],
						'action'            => 'Target site validation failed',
					)
				);
				$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
				$this->update_progress( 'error', $current_percentage, $error_message );
				$this->store_active_migration( array() );

				return new \WP_Error( 'validation_failed', $error_message );
			}

			$this->update_progress( 'analyzing', 20, __( 'Analyzing content...', 'etch-fusion-suite' ) );
			$analysis = $this->content_service->analyze_content();
			$this->update_progress(
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

			$migrator_result = $this->execute_migrators( $target, $migration_key );
			if ( is_wp_error( $migrator_result ) ) {
				return $migrator_result;
			}

			$steps        = $this->get_steps_state();
			$media_result = array( 'skipped' => true );
			if ( isset( $steps['media'] ) ) {
				$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
				$this->update_progress( 'media', 60, __( 'Migrating media files...', 'etch-fusion-suite' ) );
				$media_result = $this->media_service->migrate_media( $target, $migration_key, $selected_post_types );
				if ( is_wp_error( $media_result ) ) {
					return $media_result;
				}
			}

			$this->update_progress( 'css', 70, __( 'Converting CSS classes...', 'etch-fusion-suite' ) );
			$css_result = $this->css_service->migrate_css_classes( $target, $migration_key );
			if ( is_wp_error( $css_result ) || ( is_array( $css_result ) && isset( $css_result['success'] ) && ! $css_result['success'] ) ) {
				return is_wp_error( $css_result ) ? $css_result : new \WP_Error( 'css_migration_failed', $css_result['message'] );
			}

			$this->update_progress( 'posts', 80, __( 'Migrating posts and content...', 'etch-fusion-suite' ) );
			$post_type_mappings  = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] ) ? $options['post_type_mappings'] : array();
			$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
			$posts_result        = $this->content_service->migrate_posts( $target, $migration_key, $this->api_client, $post_type_mappings, $selected_post_types );
			if ( is_wp_error( $posts_result ) ) {
				return $posts_result;
			}
			$posts_total_sync   = isset( $posts_result['total'] ) ? (int) $posts_result['total'] : 0;
			$posts_migrated_sync = isset( $posts_result['migrated'] ) ? (int) $posts_result['migrated'] : 0;
			if ( $posts_total_sync > 0 && $posts_migrated_sync === 0 ) {
				$this->update_progress( 'error', 80, __( 'No posts could be transferred to the target site.', 'etch-fusion-suite' ) );
				$this->store_active_migration( array() );
				return new \WP_Error(
					'posts_migration_failed',
					__( 'No posts could be transferred. Check the connection to the target site, the migration key, and that Page/Post types are mapped correctly.', 'etch-fusion-suite' )
				);
			}

			$this->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$finalization_result = $this->finalize_migration( $posts_result );
			if ( is_wp_error( $finalization_result ) ) {
				return $finalization_result;
			}

			$this->update_progress( 'completed', 100, __( 'Migration completed successfully!', 'etch-fusion-suite' ) );
			$this->store_active_migration( array() );

			$migration_stats                   = $this->migration_repository->get_stats();
			$migration_stats['last_migration'] = current_time( 'mysql' );
			$migration_stats['status']         = 'completed';
			$this->migration_repository->save_stats( $migration_stats );

			return array(
				'progress'    => $this->get_progress_data(),
				'steps'       => $this->get_steps_state(),
				'migrationId' => $migration_id,
				'completed'   => true,
				'message'     => __( 'Migration completed successfully!', 'etch-fusion-suite' ),
				'details'     => array(
					'media' => $media_result,
					'css'   => $css_result,
					'posts' => $posts_result,
				),
			);
		} catch ( \Exception $exception ) {
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

			$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
			$this->update_progress( 'error', $current_percentage, $error_message );
			$this->store_active_migration( array() );

			return new \WP_Error( 'migration_failed', $error_message );
		}
	}

	/**
	 * Start migration asynchronously: prepare state, spawn background request, return immediately.
	 *
	 * @param string $migration_key
	 * @param string|null $target_url
	 * @param int|null    $batch_size
	 * @param array       $options  selected_post_types, post_type_mappings, include_media.
	 * @param string      $nonce    Nonce for the background AJAX request.
	 * @return array|\WP_Error
	 */
	public function start_migration_async( $migration_key, $target_url = null, $batch_size = null, $options = array(), $nonce = '' ) {
		try {
			$in_progress = $this->detect_in_progress_migration();
			if ( ! empty( $in_progress['migrationId'] ) && empty( $in_progress['is_stale'] ) ) {
				return new \WP_Error( 'migration_in_progress', __( 'A migration is already in progress.', 'etch-fusion-suite' ) );
			}

			$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$payload = $decoded['payload'];
			$target  = ! empty( $target_url ) ? $target_url : ( $payload['target_url'] ?? '' );
			if ( empty( $target ) ) {
				return new \WP_Error( 'missing_target_url', __( 'Migration key does not contain a target URL.', 'etch-fusion-suite' ) );
			}

			$target = str_replace( 'http://localhost:8889', 'http://tests-wordpress', $target );
			$target = str_replace( 'https://localhost:8889', 'http://tests-wordpress', $target );

			$expires = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
			if ( ! $expires || time() > $expires ) {
				return new \WP_Error( 'token_expired', __( 'Migration key has expired.', 'etch-fusion-suite' ) );
			}

			$batch_size   = $batch_size ? max( 1, (int) $batch_size ) : 50;
			$migration_id = $this->generate_migration_id();
			$this->init_progress( $migration_id, $options );
			$this->store_active_migration(
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

			$this->spawn_migration_background_request( $migration_id, $nonce );

			return array(
				'progress'    => $this->get_progress_data(),
				'steps'       => $this->get_steps_state(),
				'migrationId' => $migration_id,
				'completed'   => false,
				'message'     => __( 'Migration started.', 'etch-fusion-suite' ),
			);
		} catch ( \Exception $exception ) {
			$error_message = sprintf(
				'Migration start failed: %s (File: %s, Line: %d)',
				$exception->getMessage(),
				basename( $exception->getFile() ),
				$exception->getLine()
			);
			$this->error_handler->log_error( 'E201', array( 'message' => $exception->getMessage(), 'action' => 'start_migration_async' ) );
			return new \WP_Error( 'migration_start_failed', $error_message );
		}
	}

	/**
	 * Run the long-running migration steps (called by background AJAX request).
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '' ) {
		$active = $this->migration_repository->get_active_migration();
		if ( ! is_array( $active ) || empty( $active['migration_id'] ) || (string) $active['migration_id'] !== (string) $migration_id ) {
			return new \WP_Error( 'migration_not_found', __( 'No active migration found for this ID.', 'etch-fusion-suite' ) );
		}

		$target        = isset( $active['target_url'] ) ? $active['target_url'] : '';
		$migration_key = isset( $active['migration_key'] ) ? $active['migration_key'] : '';
		$batch_size    = isset( $active['batch_size'] ) ? max( 1, (int) $active['batch_size'] ) : 50;
		$options       = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();

		try {
			$this->update_progress( 'validation', 10, __( 'Validating migration requirements...', 'etch-fusion-suite' ) );
			$validation_result = $this->validate_target_site_requirements();
			if ( ! $validation_result['valid'] ) {
				$error_message = 'Migration validation failed: ' . implode( ', ', $validation_result['errors'] );
				$this->error_handler->log_error( 'E103', array( 'validation_errors' => $validation_result['errors'], 'action' => 'Target site validation failed' ) );
				$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
				$this->update_progress( 'error', $current_percentage, $error_message );
				$this->store_active_migration( array() );
				return new \WP_Error( 'validation_failed', $error_message );
			}

			$this->update_progress( 'analyzing', 20, __( 'Analyzing content...', 'etch-fusion-suite' ) );
			$analysis = $this->content_service->analyze_content();
			$this->update_progress(
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

			$migrator_result = $this->execute_migrators( $target, $migration_key );
			if ( is_wp_error( $migrator_result ) ) {
				return $migrator_result;
			}

			$steps        = $this->get_steps_state();
			$media_result = array( 'skipped' => true );
			if ( isset( $steps['media'] ) ) {
				$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
				$this->update_progress( 'media', 60, __( 'Migrating media files...', 'etch-fusion-suite' ) );
				$media_result = $this->media_service->migrate_media( $target, $migration_key, $selected_post_types );
				if ( is_wp_error( $media_result ) ) {
					return $media_result;
				}
			}

			$this->update_progress( 'css', 70, __( 'Converting CSS classes...', 'etch-fusion-suite' ) );
			$css_result = $this->css_service->migrate_css_classes( $target, $migration_key );
			if ( is_wp_error( $css_result ) || ( is_array( $css_result ) && isset( $css_result['success'] ) && ! $css_result['success'] ) ) {
				return is_wp_error( $css_result ) ? $css_result : new \WP_Error( 'css_migration_failed', $css_result['message'] );
			}

			$this->update_progress( 'posts', 80, __( 'Migrating posts and content...', 'etch-fusion-suite' ) );
			$post_type_mappings  = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] ) ? $options['post_type_mappings'] : array();
			$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
			$posts_result        = $this->content_service->migrate_posts( $target, $migration_key, $this->api_client, $post_type_mappings, $selected_post_types );
			if ( is_wp_error( $posts_result ) ) {
				return $posts_result;
			}
			$posts_total    = isset( $posts_result['total'] ) ? (int) $posts_result['total'] : 0;
			$posts_migrated  = isset( $posts_result['migrated'] ) ? (int) $posts_result['migrated'] : 0;
			if ( $posts_total > 0 && $posts_migrated === 0 ) {
				$this->update_progress( 'error', 80, __( 'No posts could be transferred to the target site.', 'etch-fusion-suite' ) );
				$this->store_active_migration( array() );
				return new \WP_Error(
					'posts_migration_failed',
					__( 'No posts could be transferred. Check the connection to the target site, the migration key, and that Page/Post types are mapped correctly.', 'etch-fusion-suite' )
				);
			}

			$this->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$finalization_result = $this->finalize_migration( $posts_result );
			if ( is_wp_error( $finalization_result ) ) {
				return $finalization_result;
			}

			$this->update_progress( 'completed', 100, __( 'Migration completed successfully!', 'etch-fusion-suite' ) );
			$this->store_active_migration( array() );

			$migration_stats                   = $this->migration_repository->get_stats();
			$migration_stats['last_migration']  = current_time( 'mysql' );
			$migration_stats['status']         = 'completed';
			$this->migration_repository->save_stats( $migration_stats );

			return array(
				'progress'    => $this->get_progress_data(),
				'steps'       => $this->get_steps_state(),
				'migrationId' => $migration_id,
				'completed'   => true,
				'message'     => __( 'Migration completed successfully!', 'etch-fusion-suite' ),
				'details'     => array(
					'media' => $media_result,
					'css'   => $css_result,
					'posts' => $posts_result,
				),
			);
		} catch ( \Exception $exception ) {
			$error_message = sprintf(
				'Migration process failed: %s (File: %s, Line: %d)',
				$exception->getMessage(),
				basename( $exception->getFile() ),
				$exception->getLine()
			);
			$this->error_handler->log_error( 'E201', array( 'message' => $exception->getMessage(), 'action' => 'run_migration_execution' ) );
			$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
			$this->update_progress( 'error', $current_percentage, $error_message );
			$this->store_active_migration( array() );
			return new \WP_Error( 'migration_failed', $error_message );
		}
	}

	/**
	 * Trigger a non-blocking request to run the migration in the background.
	 *
	 * @param string $migration_id
	 * @param string $nonce Unused; kept for API. Background auth is via short-lived token.
	 */
	/**
	 * Build URL for the background self-request (spawn). In Docker, admin_url() may use
	 * localhost:8888 which is not reachable from inside the container; use internal host.
	 *
	 * @return string URL to admin-ajax.php for efs_run_migration_background.
	 */
	private function get_spawn_url() {
		$url = admin_url( 'admin-ajax.php' );
		if ( function_exists( 'etch_fusion_suite_resolve_bricks_internal_host' ) ) {
			$internal = etch_fusion_suite_resolve_bricks_internal_host();
			if ( ! empty( $internal ) && is_string( $internal ) ) {
				$url = untrailingslashit( $internal ) . '/wp-admin/admin-ajax.php';
			}
		}
		return (string) apply_filters( 'etch_fusion_suite_spawn_url', $url );
	}

	private function spawn_migration_background_request( $migration_id, $nonce ) {
		$bg_token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32, true ) : bin2hex( random_bytes( 16 ) );
		set_transient( 'efs_bg_' . $migration_id, $bg_token, 120 );

		$url  = $this->get_spawn_url();
		$body = array(
			'action'       => 'efs_run_migration_background',
			'migration_id' => $migration_id,
			'bg_token'     => $bg_token,
		);

		wp_remote_post(
			$url,
			array(
				'timeout'  => 0.01,
				'blocking' => false,
				'body'     => $body,
			)
		);
	}

	/**
	 * Retrieve current progress.
	 *
	 * @param string $migration_id
	 *
	 * @return array
	 */
	public function get_progress( $migration_id = '' ) {
		$progress_data = $this->get_progress_data();
		$steps         = $this->get_steps_state();
		$migration_id  = isset( $progress_data['migrationId'] ) ? $progress_data['migrationId'] : '';
		$progress_data['steps'] = $steps;
		$progress_data['is_stale'] = ! empty( $progress_data['is_stale'] );

		return array(
			'progress'    => $progress_data,
			'steps'       => $steps,
			'migrationId' => $migration_id,
			'last_updated' => isset( $progress_data['last_updated'] ) ? $progress_data['last_updated'] : '',
			'is_stale'    => ! empty( $progress_data['is_stale'] ),
			'estimated_time_remaining' => isset( $progress_data['estimated_time_remaining'] ) ? $progress_data['estimated_time_remaining'] : null,
			'completed'   => $this->is_migration_complete(),
		);
	}

	/**
	 * Detect resumable in-progress migration state.
	 *
	 * @return array
	 */
	public function detect_in_progress_migration() {
		$state       = $this->get_progress();
		$progress    = isset( $state['progress'] ) && is_array( $state['progress'] ) ? $state['progress'] : array();
		$migration_id = isset( $state['migrationId'] ) ? (string) $state['migrationId'] : '';
		$status      = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		$is_stale    = ! empty( $progress['is_stale'] ) || 'stale' === $status;
		$is_running  = in_array( $status, array( 'running', 'receiving' ), true );

		if ( '' !== $migration_id && $is_running ) {
			return array(
				'migrationId' => $migration_id,
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => $is_stale,
				'resumable'   => true,
			);
		}

		if ( in_array( $status, array( 'completed', 'error' ), true ) ) {
			return array(
				'migrationId' => '',
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => false,
				'resumable'   => false,
			);
		}

		$active = $this->migration_repository->get_active_migration();
		if ( is_array( $active ) && ! empty( $active['migration_id'] ) ) {
			$expires_at      = isset( $active['expires_at'] ) ? (int) $active['expires_at'] : 0;
			$active_is_stale = $is_stale || ( $expires_at > 0 && time() > $expires_at );
			$progress['migrationId'] = (string) $active['migration_id'];
			return array(
				'migrationId' => (string) $active['migration_id'],
				'progress'    => $progress,
				'steps'       => $state['steps'],
				'is_stale'    => $active_is_stale,
				'resumable'   => ! $active_is_stale,
			);
		}

		return array(
			'migrationId' => '',
			'progress'    => $progress,
			'steps'       => $state['steps'],
			'is_stale'    => false,
			'resumable'   => false,
		);
	}

	/**
	 * Process batch placeholder for async migrations.
	 *
	 * @param string $migration_id
	 * @param array  $batch
	 *
	 * @return array
	 */
	public function process_batch( $migration_id, $batch ) {
		return $this->get_progress( $migration_id );
	}

	/**
	 * Cancel migration and reset progress.
	 *
	 * @param string $migration_id
	 *
	 * @return array
	 */
	public function cancel_migration( $migration_id = '' ) {
		$this->migration_repository->delete_progress();
		$this->migration_repository->delete_steps();
		$this->migration_repository->delete_token_data();
		$this->store_active_migration( array() );

		$this->error_handler->log_warning(
			'W900',
			array(
				'action' => 'Migration cancelled by user',
			)
		);

		return array(
			'message'     => __( 'Migration cancelled.', 'etch-fusion-suite' ),
			'progress'    => $this->get_progress_data(),
			'steps'       => $this->get_steps_state(),
			'migrationId' => '',
			'completed'   => false,
		);
	}

	/**
	 * Generate migration report.
	 *
	 * @return array
	 */
	public function generate_report() {
		$progress = $this->get_progress_data();
		$stats    = $this->migration_repository->get_stats();
		$steps    = $this->get_steps_state();

		return array(
			'progress' => $progress,
			'stats'    => $stats,
			'steps'    => $steps,
		);
	}

	/**
	 * Migrate a single post (used for batch processing).
	 *
	 * @param \WP_Post $post
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_single_post( $post, $migration_key, $target_url = null ) {
		$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$target = ! empty( $target_url ) ? $target_url : ( $decoded['payload']['target_url'] ?? '' );

		if ( empty( $target ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Target URL could not be determined from migration key.', 'etch-fusion-suite' ) );
		}

		return $this->content_service->convert_bricks_to_gutenberg( $post->ID, $this->api_client, $target, $migration_key );
	}

	/**
	 * Validate target site requirements.
	 *
	 * @return array
	 */
	public function validate_target_site_requirements() {
		$result = $this->plugin_detector->validate_migration_requirements();

		if ( ! is_array( $result ) ) {
			return array(
				'valid'  => false,
				'errors' => array( __( 'Unknown validation response.', 'etch-fusion-suite' ) ),
			);
		}

		return $result;
	}

	/**
	 * Initialize progress state.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $options      Optional. selected_post_types, post_type_mappings, include_media. Used to build only relevant steps.
	 */
	public function init_progress( $migration_id, array $options = array() ) {
		$progress = array(
			'migrationId'               => sanitize_text_field( $migration_id ),
			'status'                    => 'running',
			'current_step'              => 'validation',
			'current_phase_name'        => self::PHASES['validation'],
			'current_phase_status'      => 'active',
			'percentage'                => 0,
			'started_at'                => current_time( 'mysql' ),
			'last_updated'              => current_time( 'mysql' ),
			'completed_at'              => null,
			'items_processed'           => 0,
			'items_total'               => 0,
			'estimated_time_remaining'  => null,
		);

		$this->migration_repository->save_progress( $progress );
		$this->set_steps_state( $this->initialize_steps( $options ) );
	}

	/**
	 * Generate a migration identifier.
	 *
	 * @return string
	 */
	private function generate_migration_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'efs_migration_', true );
	}

	/**
	 * Update progress data.
	 *
	 * @param string $step
	 * @param int    $percentage
	 * @param string $message
	 */
	public function update_progress( $step, $percentage, $message, $items_processed = null, $items_total = null ) {
		$progress                 = $this->migration_repository->get_progress();
		$previous_percentage      = isset( $progress['percentage'] ) ? (float) $progress['percentage'] : 0.0;
		$normalized_percentage    = max( $previous_percentage, min( 100, (float) $percentage ) );
		$progress['current_step'] = sanitize_key( (string) $step );
		$progress['percentage']   = $normalized_percentage;
		$progress['message']      = $message;
		$progress['last_updated'] = current_time( 'mysql' );
		$progress['is_stale']     = false;

		if ( isset( self::PHASES[ $step ] ) ) {
			$progress['current_phase_name'] = self::PHASES[ $step ];
		}

		if ( null !== $items_processed ) {
			$progress['items_processed'] = max( 0, (int) $items_processed );
		}
		if ( null !== $items_total ) {
			$progress['items_total'] = max( 0, (int) $items_total );
		}

		$progress['estimated_time_remaining'] = $this->estimate_time_remaining( $progress );

		if ( 'completed' === $step ) {
			$progress['status']       = 'completed';
			$progress['current_phase_status'] = 'completed';
			$progress['completed_at'] = current_time( 'mysql' );
		} elseif ( 'error' === $step ) {
			$progress['status']       = 'error';
			$progress['current_phase_status'] = 'failed';
			$progress['completed_at'] = current_time( 'mysql' );
		} else {
			$progress['status'] = 'running';
			$progress['current_phase_status'] = 'active';
		}

		$this->migration_repository->save_progress( $progress );

		$steps = $this->get_steps_state();
		if ( ! empty( $steps ) ) {
			foreach ( $steps as $key => &$step_data ) {
				if ( ! is_array( $step_data ) ) {
					continue;
				}

				if ( 'error' === $step ) {
					if ( ! empty( $step_data['active'] ) ) {
						$step_data['status'] = 'failed';
						$step_data['active'] = false;
					}
				} elseif ( $key === $step ) {
					$step_data['status'] = 'completed';
					$step_data['active'] = false;
					$step_data['completed'] = true;
				} elseif ( empty( $step_data['completed'] ) && ! empty( $step_data['active'] ) ) {
					$step_data['status'] = 'completed';
					$step_data['active'] = false;
					$step_data['completed'] = true;
				} elseif ( empty( $step_data['completed'] ) && 'pending' === ( $step_data['status'] ?? 'pending' ) ) {
					$step_data['status'] = 'pending';
				}

				$step_data['updated_at'] = current_time( 'mysql' );
			}
			unset( $step_data );

			if ( 'completed' !== $step && 'error' !== $step ) {
				$next_phase = $this->get_next_phase_key( $step, $steps );
				if ( $next_phase && isset( $steps[ $next_phase ] ) && empty( $steps[ $next_phase ]['completed'] ) ) {
					$steps[ $next_phase ]['status']  = 'active';
					$steps[ $next_phase ]['active']  = true;
					$steps[ $next_phase ]['updated_at'] = current_time( 'mysql' );
				}
			}

			$this->set_steps_state( $steps );
		}
	}

	/**
	 * Get raw progress data.
	 *
	 * @return array
	 */
	public function get_progress_data() {
		$progress = $this->migration_repository->get_progress();

		if ( empty( $progress ) ) {
			return array(
				'status'       => 'idle',
				'current_step' => '',
				'percentage'   => 0,
				'last_updated' => '',
				'is_stale'     => false,
			);
		}

		if ( empty( $progress['last_updated'] ) ) {
			$progress['last_updated'] = isset( $progress['started_at'] ) ? $progress['started_at'] : current_time( 'mysql' );
		}

		$status = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		if ( in_array( $status, array( 'running', 'receiving' ), true ) ) {
			$last_updated_ts       = strtotime( (string) $progress['last_updated'] );
			$is_stale              = false !== $last_updated_ts && ( time() - $last_updated_ts ) >= self::PROGRESS_STALE_TTL;
			$progress['is_stale']  = $is_stale;
			if ( $is_stale ) {
				$progress['status'] = 'stale';
			}
		} else {
			$progress['is_stale'] = false;
		}

		$progress['estimated_time_remaining'] = $this->estimate_time_remaining( $progress );

		return $progress;
	}

	/**
	 * Retrieve step state map.
	 *
	 * @return array
	 */
	public function get_steps_state() {
		$steps = $this->migration_repository->get_steps();

		return is_array( $steps ) ? $steps : array();
	}

	/**
	 * Persist step state map.
	 *
	 * @param array $steps
	 */
	public function set_steps_state( array $steps ) {
		$this->migration_repository->save_steps( $steps );
	}

	/**
	 * Check if migration is complete.
	 *
	 * @return bool
	 */
	public function is_migration_complete() {
		$progress = $this->get_progress_data();

		return isset( $progress['status'] ) && 'completed' === $progress['status'];
	}

	/**
	 * Persist metadata about the currently running migration.
	 *
	 * @param array $data
	 */
	private function store_active_migration( array $data ) {
		$this->migration_repository->save_active_migration( $data );
	}

	/**
	 * Initialize steps for progress UI and execution. Only includes phases that are relevant
	 * (e.g. no ACF/MetaBox/Custom Fields if no such plugins; no Media if not selected).
	 *
	 * @param array $options Optional. include_media (bool). Plugin presence drives ACF/MetaBox/custom_fields.
	 * @return array
	 */
	private function initialize_steps( array $options = array() ) {
		$include_media = ! isset( $options['include_media'] ) || ! empty( $options['include_media'] );
		$has_acf       = $this->plugin_detector->is_acf_active();
		$has_metabox   = $this->plugin_detector->is_metabox_active();
		$has_custom_fields = $has_acf || $has_metabox;

		$phases_to_include = array(
			'validation'    => true,
			'analyzing'     => true,
			'cpts'          => true,
			'acf'           => $has_acf,
			'metabox'       => $has_metabox,
			'custom_fields' => $has_custom_fields,
			'media'         => $include_media,
			'css'           => true,
			'posts'         => true,
			'finalization'  => true,
		);

		$steps     = array();
		$index     = 0;
		$timestamp = current_time( 'mysql' );
		foreach ( self::PHASES as $phase_key => $label ) {
			if ( empty( $phases_to_include[ $phase_key ] ) ) {
				continue;
			}
			$steps[ $phase_key ] = array(
				'label'           => $label,
				'status'          => 0 === $index ? 'active' : 'pending',
				'active'          => 0 === $index,
				'completed'       => false,
				'failed'          => false,
				'items_processed' => 0,
				'items_total'     => 0,
				'updated_at'      => $timestamp,
			);
			++$index;
		}

		return $steps;
	}

	/**
	 * Finalize migration.
	 *
	 * @param array $results
	 *
	 * @return true|\WP_Error
	 */
	private function finalize_migration( array $results ) {
		$this->error_handler->log_error(
			'I010',
			array(
				'action'  => 'Finalizing migration',
				'results' => $results,
			)
		);

		return true;
	}

	/**
	 * Execute all registered migrators via registry.
	 *
	 * @param string $target_url
	 * @param string $jwt_token
	 * @return bool|\WP_Error
	 */
	private function execute_migrators( $target_url, $jwt_token ) {
		$supported_migrators = $this->migrator_registry->get_supported();
		$steps               = $this->get_steps_state();

		if ( empty( $supported_migrators ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'message' => 'No migrators available to execute.',
					'action'  => 'Migrator execution skipped',
				)
			);

			return true;
		}

		$enabled_migrators = array();
		foreach ( $supported_migrators as $migrator ) {
			$step_key = $this->get_migrator_step_key( $migrator );
			if ( isset( $steps[ $step_key ] ) ) {
				$enabled_migrators[] = $migrator;
			}
		}

		$count           = count( $enabled_migrators );
		$base_percentage = 30;
		$end_percentage  = 60;
		$range           = max( 1, $end_percentage - $base_percentage );
		$increment       = $count > 0 ? $range / $count : 0;
		$index           = 0;

		foreach ( $enabled_migrators as $migrator ) {
			$progress = (int) floor( $base_percentage + ( $increment * $index ) );
			$step_key = $this->get_migrator_step_key( $migrator );
			$this->update_progress(
				$step_key,
				$progress,
				sprintf(
					/* translators: %s: Migrator name. */
					__( 'Migrating %s...', 'etch-fusion-suite' ),
					$migrator->get_name()
				)
			);

			$validation = $migrator->validate();
			if ( isset( $validation['valid'] ) && ! $validation['valid'] ) {
				$this->error_handler->log_warning(
					'W002',
					array(
						'migrator' => $migrator->get_type(),
						'errors'   => $validation['errors'] ?? array(),
						'action'   => 'Migrator validation failed',
					)
				);
				++$index;
				continue;
			}

			$result = $migrator->migrate( $target_url, $jwt_token );
			if ( is_wp_error( $result ) ) {
				$this->error_handler->log_error(
					'E201',
					array(
						'migrator' => $migrator->get_type(),
						'error'    => $result->get_error_message(),
						'action'   => 'Migrator execution failed',
					)
				);

				return $result;
			}

			++$index;
		}

		return true;
	}

	/**
	 * Derive progress step key from migrator type.
	 */
	private function get_migrator_step_key( Migrator_Interface $migrator ) {
		$type = $migrator->get_type();

		$mapping = array(
			'cpt'           => 'cpts',
			'acf'           => 'acf',
			'metabox'       => 'metabox',
			'custom_fields' => 'custom_fields',
			'css_classes'   => 'css',
		);

		return $mapping[ $type ] ?? $type;
	}

	/**
	 * Get next phase key in canonical order. If $steps is provided, returns the next key that exists in steps.
	 *
	 * @param string $phase Current phase.
	 * @param array  $steps Optional. Current steps state; if provided, next key must be in this set.
	 * @return string
	 */
	private function get_next_phase_key( $phase, array $steps = array() ) {
		$keys  = array_keys( self::PHASES );
		$index = array_search( $phase, $keys, true );

		if ( false === $index ) {
			return '';
		}

		$start = $index + 1;
		for ( $i = $start; $i < count( $keys ); $i++ ) {
			$key = $keys[ $i ];
			if ( empty( $steps ) || isset( $steps[ $key ] ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Estimate remaining time in seconds based on elapsed time and percentage.
	 *
	 * @param array $progress Progress payload.
	 * @return int|null
	 */
	private function estimate_time_remaining( array $progress ) {
		$percentage = isset( $progress['percentage'] ) ? (float) $progress['percentage'] : 0.0;
		$status     = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		if ( $percentage <= 0 || $percentage >= 100 || in_array( $status, array( 'completed', 'error', 'stale', 'idle' ), true ) ) {
			return null;
		}

		$started_at = isset( $progress['started_at'] ) ? strtotime( (string) $progress['started_at'] ) : false;
		if ( false === $started_at || $started_at <= 0 ) {
			return null;
		}

		$elapsed = max( 1, time() - $started_at );
		$total   = (int) round( $elapsed / ( $percentage / 100 ) );
		$remain  = max( 0, $total - $elapsed );

		return $remain;
	}
}
