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
	private const MAX_RETRIES        = 3;

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

	/** @var \Bricks2Etch\Repositories\EFS_Migration_Runs_Repository|null */
	private $migration_runs_repository;

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
	 * @param \Bricks2Etch\Repositories\EFS_Migration_Runs_Repository|null $migration_runs_repository
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
		?\Bricks2Etch\Core\EFS_Migration_Token_Manager $token_manager = null,
		?\Bricks2Etch\Repositories\EFS_Migration_Runs_Repository $migration_runs_repository = null
	) {
		$this->error_handler             = $error_handler;
		$this->plugin_detector           = $plugin_detector;
		$this->content_parser            = $content_parser;
		$this->css_service               = $css_service;
		$this->media_service             = $media_service;
		$this->content_service           = $content_service;
		$this->api_client                = $api_client;
		$this->migrator_registry         = $migrator_registry;
		$this->migration_repository      = $migration_repository;
		$this->token_manager             = $token_manager;
		$this->migration_runs_repository = $migration_runs_repository;

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
				$this->write_failed_run_record( $migration_id, $error_message );
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
			$posts_total_sync    = isset( $posts_result['total'] ) ? (int) $posts_result['total'] : 0;
			$posts_migrated_sync = isset( $posts_result['migrated'] ) ? (int) $posts_result['migrated'] : 0;
			if ( $posts_total_sync > 0 && 0 === $posts_migrated_sync ) {
				$this->update_progress( 'error', 80, __( 'No posts could be transferred to the target site.', 'etch-fusion-suite' ) );
				$this->write_failed_run_record( $migration_id, __( 'No posts could be transferred. Check the connection to the target site, the migration key, and that Page/Post types are mapped correctly.', 'etch-fusion-suite' ) );
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

			$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
			$this->update_progress( 'error', $current_percentage, $error_message );
			$this->write_failed_run_record( isset( $migration_id ) ? $migration_id : '', $error_message );
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
				$this->error_handler->log_error(
					'E103',
					array(
						'validation_errors' => $validation_result['errors'],
						'action'            => 'Target site validation failed',
					)
				);
				$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
				$this->update_progress( 'error', $current_percentage, $error_message );
				$this->write_failed_run_record( $migration_id, $error_message );
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

			$this->update_progress( 'css', 70, __( 'Converting CSS classes...', 'etch-fusion-suite' ) );
			$css_result = $this->css_service->migrate_css_classes( $target, $migration_key );
			if ( is_wp_error( $css_result ) || ( is_array( $css_result ) && isset( $css_result['success'] ) && ! $css_result['success'] ) ) {
				return is_wp_error( $css_result ) ? $css_result : new \WP_Error( 'css_migration_failed', $css_result['message'] );
			}

			$steps               = $this->get_steps_state();
			$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] ) ? $options['selected_post_types'] : array();
			$include_media       = ! empty( $options['include_media'] ) && isset( $steps['media'] );
			$media_ids           = $include_media ? $this->media_service->get_media_ids( $selected_post_types ) : array();

			// Collect post IDs for JS-driven batch loop.
			$bricks_posts    = $this->content_parser->get_bricks_posts();
			$gutenberg_posts = $this->content_parser->get_gutenberg_posts();
			$all_posts       = array_merge(
				is_array( $bricks_posts ) ? $bricks_posts : array(),
				is_array( $gutenberg_posts ) ? $gutenberg_posts : array()
			);

			if ( ! empty( $selected_post_types ) ) {
				$all_posts = array_filter(
					$all_posts,
					function ( $post ) use ( $selected_post_types ) {
						return in_array( $post->post_type, $selected_post_types, true );
					}
				);
			}

			$post_ids    = array_values(
				array_map(
					function ( $post ) {
						return (int) $post->ID;
					},
					$all_posts
				)
			);
			$total_count = count( $post_ids );

			$phase = ( $include_media && ! empty( $media_ids ) ) ? 'media' : 'posts';

			$this->migration_repository->save_checkpoint(
				array(
					'phase'                  => $phase,
					'migrationId'            => $migration_id,
					'remaining_media_ids'    => $media_ids,
					'processed_media_count'  => 0,
					'total_media_count'      => count( $media_ids ),
					'media_attempts'         => array(),
					'failed_media_ids_final' => array(),
					'remaining_post_ids'     => $post_ids,
					'processed_count'        => 0,
					'total_count'            => $total_count,
					'current_item_id'        => null,
					'current_item_title'     => '',
				)
			);

			if ( 'media' === $phase ) {
				$this->update_progress( 'media', 60, __( 'Migrating media…', 'etch-fusion-suite' ), 0, count( $media_ids ) );
			} else {
				$this->update_progress( 'posts', 80, __( 'Ready to migrate posts...', 'etch-fusion-suite' ), 0, $total_count );
			}

			return array(
				'posts_ready' => true,
				'migrationId' => $migration_id,
				'total'       => 'media' === $phase ? count( $media_ids ) : $total_count,
				'progress'    => $this->get_progress_data(),
				'steps'       => $this->get_steps_state(),
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
					'action'  => 'run_migration_execution',
				)
			);
			$current_percentage = isset( $this->get_progress_data()['percentage'] ) ? (int) $this->get_progress_data()['percentage'] : 0;
			$this->update_progress( 'error', $current_percentage, $error_message );
			$this->write_failed_run_record( $migration_id, $error_message );
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
		$progress_data             = $this->get_progress_data();
		$steps                     = $this->get_steps_state();
		$migration_id              = isset( $progress_data['migrationId'] ) ? $progress_data['migrationId'] : '';
		$progress_data['steps']    = $steps;
		$progress_data['is_stale'] = ! empty( $progress_data['is_stale'] );

		return array(
			'progress'                 => $progress_data,
			'steps'                    => $steps,
			'migrationId'              => $migration_id,
			'last_updated'             => isset( $progress_data['last_updated'] ) ? $progress_data['last_updated'] : '',
			'is_stale'                 => ! empty( $progress_data['is_stale'] ),
			'estimated_time_remaining' => isset( $progress_data['estimated_time_remaining'] ) ? $progress_data['estimated_time_remaining'] : null,
			'completed'                => $this->is_migration_complete(),
		);
	}

	/**
	 * Detect resumable in-progress migration state.
	 *
	 * @return array
	 */
	public function detect_in_progress_migration() {
		$state        = $this->get_progress();
		$progress     = isset( $state['progress'] ) && is_array( $state['progress'] ) ? $state['progress'] : array();
		$migration_id = isset( $state['migrationId'] ) ? (string) $state['migrationId'] : '';
		$status       = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		$is_stale     = ! empty( $progress['is_stale'] ) || 'stale' === $status;
		$is_running   = in_array( $status, array( 'running', 'receiving' ), true );

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
			$expires_at              = isset( $active['expires_at'] ) ? (int) $active['expires_at'] : 0;
			$active_is_stale         = $is_stale || ( $expires_at > 0 && time() > $expires_at );
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
	 * Process a single batch of posts for JS-driven batch loop.
	 *
	 * @param string $migration_id Migration ID.
	 * @param array  $batch        Batch options. Accepts key 'batch_size' (int).
	 *
	 * @return array|\WP_Error
	 */
	public function process_batch( $migration_id, $batch ) {
		$checkpoint = $this->migration_repository->get_checkpoint();

		if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
			return new \WP_Error( 'no_checkpoint', __( 'No checkpoint found for this migration.', 'etch-fusion-suite' ) );
		}

		// Load target connection info from active migration.
		$active        = $this->migration_repository->get_active_migration();
		$active_mid    = is_array( $active ) && isset( $active['migration_id'] ) ? (string) $active['migration_id'] : '';
		$target_url    = is_array( $active ) && isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$migration_key = is_array( $active ) && isset( $active['migration_key'] ) ? (string) $active['migration_key'] : '';

		if ( '' === $active_mid || $active_mid !== (string) $migration_id || '' === $target_url || '' === $migration_key ) {
			return new \WP_Error( 'missing_active_migration', __( 'Active migration record is missing required fields or does not match the current migration ID.', 'etch-fusion-suite' ) );
		}

		$phase = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';

		// Media phase: process a small batch of media items (3 per request to limit base64 memory).
		if ( 'media' === $phase ) {
			$remaining_media_ids    = isset( $checkpoint['remaining_media_ids'] ) ? (array) $checkpoint['remaining_media_ids'] : array();
			$processed_media_count  = isset( $checkpoint['processed_media_count'] ) ? (int) $checkpoint['processed_media_count'] : 0;
			$total_media_count      = isset( $checkpoint['total_media_count'] ) ? (int) $checkpoint['total_media_count'] : ( count( $remaining_media_ids ) + $processed_media_count );
			$media_attempts         = isset( $checkpoint['media_attempts'] ) && is_array( $checkpoint['media_attempts'] ) ? $checkpoint['media_attempts'] : array();
			$failed_media_ids_final = isset( $checkpoint['failed_media_ids_final'] ) ? (array) $checkpoint['failed_media_ids_final'] : array();
			$media_batch_size       = 3;

			$current_media_batch = array_splice( $remaining_media_ids, 0, $media_batch_size );
			$current_item_id     = null;
			$current_item_title  = '';

			foreach ( $current_media_batch as $media_id ) {
				$media_id         = (int) $media_id;
				$media_id_key     = (string) $media_id;
				$current_attempts = isset( $media_attempts[ $media_id_key ] ) ? (int) $media_attempts[ $media_id_key ] : 0;
				++$current_attempts;
				$media_attempts[ $media_id_key ] = $current_attempts;

				$result = $this->media_service->migrate_media_by_id( $media_id, $target_url, $migration_key );

				if ( is_wp_error( $result ) ) {
					if ( $current_attempts < 2 ) {
						$remaining_media_ids[] = $media_id;
					} else {
						$failed_media_ids_final[] = $media_id;
						$this->error_handler->log_warning(
							'W012',
							array(
								'media_id'      => $media_id,
								'attempts_made' => $current_attempts,
								'error_message' => $result->get_error_message(),
								'action'        => 'Media migration failed after retries',
							)
						);
					}
					continue;
				}

				++$processed_media_count;
				$current_item_id    = $media_id;
				$current_item_title = isset( $result['title'] ) ? (string) $result['title'] : '';
			}

			$checkpoint['remaining_media_ids']    = $remaining_media_ids;
			$checkpoint['processed_media_count']  = $processed_media_count;
			$checkpoint['media_attempts']         = $media_attempts;
			$checkpoint['failed_media_ids_final'] = $failed_media_ids_final;
			$checkpoint['current_item_id']        = $current_item_id;
			$checkpoint['current_item_title']     = $current_item_title;
			$this->migration_repository->save_checkpoint( $checkpoint );

			$percentage = $total_media_count > 0 ? (int) round( 60 + ( $processed_media_count / $total_media_count * 19 ) ) : 60;
			$message    = sprintf(
				/* translators: 1: processed media count, 2: total media count. */
				__( 'Migrating media… %1$d/%2$d', 'etch-fusion-suite' ),
				$processed_media_count,
				$total_media_count
			);
			$this->update_progress( 'media', $percentage, $message, $processed_media_count, $total_media_count );

			if ( empty( $remaining_media_ids ) ) {
				$checkpoint['phase']           = 'posts';
				$checkpoint['processed_count'] = 0;
				$this->migration_repository->save_checkpoint( $checkpoint );
				$remaining_post_ids = isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array();
				$this->update_progress( 'posts', 80, __( 'Ready to migrate posts...', 'etch-fusion-suite' ), 0, count( $remaining_post_ids ) );
				return array(
					'completed'    => false,
					'remaining'    => count( $remaining_post_ids ),
					'migrationId'  => $migration_id,
					'progress'     => $this->get_progress_data(),
					'steps'        => $this->get_steps_state(),
					'current_item' => array(
						'id'    => null,
						'title' => '',
					),
				);
			}

			return array(
				'completed'    => false,
				'remaining'    => count( $remaining_media_ids ),
				'migrationId'  => $migration_id,
				'progress'     => $this->get_progress_data(),
				'steps'        => $this->get_steps_state(),
				'current_item' => array(
					'id'    => $current_item_id,
					'title' => $current_item_title,
				),
			);
		}

		// Posts phase (existing logic).
		$remaining_post_ids    = isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array();
		$processed_count       = isset( $checkpoint['processed_count'] ) ? (int) $checkpoint['processed_count'] : 0;
		$post_attempts         = isset( $checkpoint['post_attempts'] ) && is_array( $checkpoint['post_attempts'] ) ? $checkpoint['post_attempts'] : array();
		$failed_post_ids_final = isset( $checkpoint['failed_post_ids_final'] ) ? (array) $checkpoint['failed_post_ids_final'] : array();
		$total_count           = isset( $checkpoint['total_count'] ) ? (int) $checkpoint['total_count'] : ( count( $remaining_post_ids ) + $processed_count );
		$batch_size            = isset( $batch['batch_size'] ) ? max( 1, (int) $batch['batch_size'] ) : 10;
		$options               = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();
		$post_type_mappings    = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] ) ? $options['post_type_mappings'] : array();

		$current_batch = array_splice( $remaining_post_ids, 0, $batch_size );

		foreach ( $current_batch as $post_id ) {
			$post_id = (int) $post_id;
			$result  = $this->content_service->convert_bricks_to_gutenberg( $post_id, $this->api_client, $target_url, $migration_key, $post_type_mappings );

			if ( is_wp_error( $result ) ) {
				$post_id_key      = (string) $post_id;
				$current_attempts = isset( $post_attempts[ $post_id_key ] ) ? (int) $post_attempts[ $post_id_key ] : 0;
				++$current_attempts;
				$post_attempts[ $post_id_key ] = $current_attempts;

				if ( $current_attempts < self::MAX_RETRIES ) {
					$remaining_post_ids[] = $post_id;
					$this->error_handler->log_warning(
						'W010',
						array(
							'post_id'       => $post_id,
							'attempt'       => $current_attempts,
							'error_message' => $result->get_error_message(),
							'action'        => 'Batch post conversion retry scheduled',
						)
					);
				} else {
					$failed_post_ids_final[] = $post_id;
					$this->error_handler->log_warning(
						'W011',
						array(
							'post_id'       => $post_id,
							'attempts_made' => $current_attempts,
							'error_message' => $result->get_error_message(),
							'action'        => 'Batch post conversion failed after max retries',
						)
					);
				}
				continue;
			}

			++$processed_count;
			$wp_post                          = get_post( $post_id );
			$checkpoint['current_item_id']    = $post_id;
			$checkpoint['current_item_title'] = ( $wp_post instanceof \WP_Post ) ? (string) $wp_post->post_title : '';
		}

		$checkpoint['remaining_post_ids']    = $remaining_post_ids;
		$checkpoint['processed_count']       = $processed_count;
		$checkpoint['post_attempts']         = $post_attempts;
		$checkpoint['failed_post_ids_final'] = $failed_post_ids_final;
		$this->migration_repository->save_checkpoint( $checkpoint );

		$percentage = $total_count > 0 ? (int) round( 80 + ( $processed_count / $total_count * 15 ) ) : 80;
		$message    = sprintf(
			/* translators: 1: processed count, 2: total count. */
			__( 'Migrating posts... %1$d/%2$d', 'etch-fusion-suite' ),
			$processed_count,
			$total_count
		);
		$this->update_progress( 'posts', $percentage, $message, $processed_count, $total_count );

		if ( empty( $remaining_post_ids ) ) {
			$failed_count       = count( $failed_post_ids_final );
			$completion_message = $failed_count > 0
				? sprintf(
					/* translators: %d: failed posts count. */
					__( 'Migration completed. %d post(s) failed after retries.', 'etch-fusion-suite' ),
					$failed_count
				)
				: __( 'Migration completed successfully!', 'etch-fusion-suite' );

			$posts_result = array(
				'total'                 => $total_count,
				'migrated'              => $processed_count,
				'failed_post_ids_final' => $failed_post_ids_final,
			);

			$this->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$this->finalize_migration( $posts_result );
			$this->update_progress( 'completed', 100, $completion_message );
			$this->store_active_migration( array() );
			$this->migration_repository->delete_checkpoint();

			$migration_stats                   = $this->migration_repository->get_stats();
			$migration_stats['last_migration'] = current_time( 'mysql' );
			$migration_stats['status']         = 'completed';
			$this->migration_repository->save_stats( $migration_stats );

			return array(
				'completed'   => true,
				'remaining'   => 0,
				'migrationId' => $migration_id,
				'progress'    => $this->get_progress_data(),
				'steps'       => $this->get_steps_state(),
			);
		}

		return array(
			'completed'    => false,
			'remaining'    => count( $remaining_post_ids ),
			'migrationId'  => $migration_id,
			'progress'     => $this->get_progress_data(),
			'steps'        => $this->get_steps_state(),
			'current_item' => array(
				'id'    => $checkpoint['current_item_id'] ?? null,
				'title' => $checkpoint['current_item_title'] ?? '',
			),
		);
	}

	/**
	 * Resume a JS-driven batch loop after a timeout or error.
	 *
	 * Validates that a valid checkpoint exists for the given migration ID and resets the
	 * stale flag so the batch loop can restart from where it left off.
	 *
	 * @param string $migration_id Migration ID to resume.
	 *
	 * @return array|\WP_Error
	 */
	public function resume_migration_execution( $migration_id ) {
		$checkpoint = $this->migration_repository->get_checkpoint();

		if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
			return new \WP_Error( 'no_checkpoint', __( 'No checkpoint found for this migration ID.', 'etch-fusion-suite' ) );
		}

		$phase     = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';
		$remaining = 'media' === $phase
			? count( isset( $checkpoint['remaining_media_ids'] ) ? (array) $checkpoint['remaining_media_ids'] : array() )
			: count( isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array() );

		// Reset stale flag so the batch loop can restart.
		$progress = $this->migration_repository->get_progress();
		if ( is_array( $progress ) && isset( $progress['is_stale'] ) ) {
			$progress['is_stale']     = false;
			$progress['status']       = 'running';
			$progress['last_updated'] = current_time( 'mysql' );
			$this->migration_repository->save_progress( $progress );
		}

		return array(
			'resumed'     => true,
			'remaining'   => $remaining,
			'migrationId' => $migration_id,
			'progress'    => $this->get_progress_data(),
		);
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
			'migrationId'              => sanitize_text_field( $migration_id ),
			'status'                   => 'running',
			'current_step'             => 'validation',
			'current_phase_name'       => self::PHASES['validation'],
			'current_phase_status'     => 'active',
			'percentage'               => 0,
			'started_at'               => current_time( 'mysql' ),
			'last_updated'             => current_time( 'mysql' ),
			'completed_at'             => null,
			'items_processed'          => 0,
			'items_total'              => 0,
			'estimated_time_remaining' => null,
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
			$progress['status']               = 'completed';
			$progress['current_phase_status'] = 'completed';
			$progress['completed_at']         = current_time( 'mysql' );
		} elseif ( 'error' === $step ) {
			$progress['status']               = 'error';
			$progress['current_phase_status'] = 'failed';
			$progress['completed_at']         = current_time( 'mysql' );
		} else {
			$progress['status']               = 'running';
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
					$step_data['status']    = 'completed';
					$step_data['active']    = false;
					$step_data['completed'] = true;
				} elseif ( empty( $step_data['completed'] ) && ! empty( $step_data['active'] ) ) {
					$step_data['status']    = 'completed';
					$step_data['active']    = false;
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
					$steps[ $next_phase ]['status']     = 'active';
					$steps[ $next_phase ]['active']     = true;
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
			$last_updated_ts      = strtotime( (string) $progress['last_updated'] );
			$is_stale             = false !== $last_updated_ts && ( time() - $last_updated_ts ) >= self::PROGRESS_STALE_TTL;
			$progress['is_stale'] = $is_stale;
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
		$include_media     = ! isset( $options['include_media'] ) || ! empty( $options['include_media'] );
		$has_acf           = $this->plugin_detector->is_acf_active();
		$has_metabox       = $this->plugin_detector->is_metabox_active();
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
	 * Finalize migration and persist a run record.
	 *
	 * @param array $results Posts migration results (total, migrated).
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

		if ( ! $this->migration_runs_repository ) {
			return true;
		}

		$progress_data = $this->get_progress_data();
		$active        = $this->migration_repository->get_active_migration();
		$migration_id  = isset( $progress_data['migrationId'] ) ? (string) $progress_data['migrationId'] : '';
		$started_at    = isset( $progress_data['started_at'] ) ? (string) $progress_data['started_at'] : '';
		$completed_at  = current_time( 'mysql' );
		$target_url    = isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$options       = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();

		$post_type_mappings    = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] )
			? $options['post_type_mappings']
			: array();
		$failed_post_ids_final = isset( $results['failed_post_ids_final'] ) && is_array( $results['failed_post_ids_final'] )
			? array_values( $results['failed_post_ids_final'] )
			: array();
		$failed_posts_count    = count( $failed_post_ids_final );

		$counts_by_post_type = $this->build_counts_by_post_type( $results, $options );

		$started_ts = $started_at ? strtotime( $started_at ) : 0;
		$all_log    = get_option( 'efs_migration_log', array() );
		$all_log    = is_array( $all_log ) ? $all_log : array();

		$warnings = array_filter(
			$all_log,
			function ( $entry ) use ( $started_ts ) {
				if ( ! is_array( $entry ) || 'warning' !== ( $entry['type'] ?? '' ) ) {
					return false;
				}
				if ( $started_ts <= 0 ) {
					return true;
				}
				$ts = isset( $entry['timestamp'] ) ? strtotime( $entry['timestamp'] ) : false;
				return false !== $ts && $ts >= $started_ts;
			}
		);

		$errors = array_filter(
			$all_log,
			function ( $entry ) use ( $started_ts ) {
				if ( ! is_array( $entry ) || 'error' !== ( $entry['type'] ?? '' ) ) {
					return false;
				}
				if ( $started_ts <= 0 ) {
					return true;
				}
				$ts = isset( $entry['timestamp'] ) ? strtotime( $entry['timestamp'] ) : false;
				return false !== $ts && $ts >= $started_ts;
			}
		);

		$warnings_count   = count( $warnings );
		$warnings_summary = $warnings_count > 0
			? implode(
				'; ',
				array_map(
					function ( $w ) {
						return isset( $w['title'] ) ? (string) $w['title'] : (string) ( $w['code'] ?? '' ); },
					$warnings
				)
			)
			: null;
		$errors_summary   = count( $errors ) > 0
			? implode(
				'; ',
				array_map(
					function ( $e ) {
						return isset( $e['title'] ) ? (string) $e['title'] : (string) ( $e['code'] ?? '' ); },
					$errors
				)
			)
			: null;

		$status       = $warnings_count > 0 ? 'success_with_warnings' : 'success';
		$duration_sec = ( $started_ts > 0 ) ? max( 0, time() - $started_ts ) : 0;

		$record = array(
			'migrationId'            => $migration_id,
			'timestamp_started_at'   => $started_at,
			'timestamp_completed_at' => $completed_at,
			'source_site'            => $target_url,
			'target_url'             => $target_url,
			'status'                 => $status,
			'counts_by_post_type'    => $counts_by_post_type,
			'post_type_mappings'     => $post_type_mappings,
			'failed_posts_count'     => $failed_posts_count,
			'failed_post_ids'        => $failed_post_ids_final,
			'warnings_count'         => $warnings_count,
			'warnings_summary'       => $warnings_summary,
			'errors_summary'         => $errors_summary,
			'duration_sec'           => $duration_sec,
		);

		$this->migration_runs_repository->save_run( $record );

		return true;
	}

	/**
	 * Build per-post-type migration counts for run records.
	 *
	 * @param array $results Posts migration result payload.
	 * @param array $options Active migration options payload.
	 * @return array
	 */
	private function build_counts_by_post_type( array $results, array $options ): array {
		$raw_counts = array();
		if ( isset( $results['counts_by_post_type'] ) && is_array( $results['counts_by_post_type'] ) ) {
			$raw_counts = $results['counts_by_post_type'];
		} elseif ( isset( $results['by_post_type'] ) && is_array( $results['by_post_type'] ) ) {
			$raw_counts = $results['by_post_type'];
		}

		if ( ! empty( $raw_counts ) ) {
			$normalized = array();
			foreach ( $raw_counts as $post_type => $value ) {
				$key = sanitize_key( (string) $post_type );
				if ( '' === $key ) {
					continue;
				}

				if ( is_array( $value ) ) {
					$total    = isset( $value['total'] ) ? (int) $value['total'] : ( isset( $value['items_total'] ) ? (int) $value['items_total'] : 0 );
					$migrated = isset( $value['migrated'] ) ? (int) $value['migrated'] : ( isset( $value['items_processed'] ) ? (int) $value['items_processed'] : $total );
				} else {
					$total    = (int) $value;
					$migrated = (int) $value;
				}

				$normalized[ $key ] = array(
					'total'    => max( 0, $total ),
					'migrated' => max( 0, $migrated ),
				);
			}

			if ( ! empty( $normalized ) ) {
				return $normalized;
			}
		}

		$selected_post_types = isset( $options['selected_post_types'] ) && is_array( $options['selected_post_types'] )
			? $options['selected_post_types']
			: array();
		$post_type_mappings  = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] )
			? $options['post_type_mappings']
			: array();

		if ( empty( $selected_post_types ) && ! empty( $post_type_mappings ) ) {
			$selected_post_types = array_keys( $post_type_mappings );
		}

		$selected_post_types = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $selected_post_types ),
					function ( $slug ) {
						return '' !== $slug;
					}
				)
			)
		);

		if ( empty( $selected_post_types ) ) {
			return array();
		}

		$all_posts = array_merge(
			is_array( $this->content_service->get_bricks_posts() ) ? $this->content_service->get_bricks_posts() : array(),
			is_array( $this->content_service->get_gutenberg_posts() ) ? $this->content_service->get_gutenberg_posts() : array()
		);

		$totals_by_type = array_fill_keys( $selected_post_types, 0 );
		foreach ( $all_posts as $post ) {
			if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
				continue;
			}
			$post_type = sanitize_key( (string) $post->post_type );
			if ( isset( $totals_by_type[ $post_type ] ) ) {
				++$totals_by_type[ $post_type ];
			}
		}

		$total_migrated = isset( $results['migrated'] ) ? max( 0, (int) $results['migrated'] ) : 0;
		$total_selected = array_sum( $totals_by_type );
		$remaining      = min( $total_migrated, $total_selected );
		$counts         = array();

		foreach ( $totals_by_type as $post_type => $total ) {
			$migrated   = min( (int) $total, (int) $remaining );
			$remaining -= $migrated;

			$counts[ $post_type ] = array(
				'total'    => max( 0, (int) $total ),
				'migrated' => max( 0, (int) $migrated ),
			);
		}

		return $counts;
	}

	/**
	 * Write a minimal "failed" run record for error paths before active migration is cleared.
	 *
	 * @param string $migration_id   Migration ID (may be empty if error occurred before ID was generated).
	 * @param string $error_message  Human-readable error message.
	 * @return void
	 */
	private function write_failed_run_record( string $migration_id, string $error_message ): void {
		if ( ! $this->migration_runs_repository || '' === $migration_id ) {
			return;
		}

		$progress_data = $this->get_progress_data();
		$started_at    = isset( $progress_data['started_at'] ) ? (string) $progress_data['started_at'] : '';
		$completed_at  = current_time( 'mysql' );
		$active        = $this->migration_repository->get_active_migration();
		$target_url    = isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$options       = isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();

		$post_type_mappings = isset( $options['post_type_mappings'] ) && is_array( $options['post_type_mappings'] )
			? $options['post_type_mappings']
			: array();

		$started_ts   = $started_at ? strtotime( $started_at ) : 0;
		$duration_sec = $started_ts > 0 ? max( 0, time() - $started_ts ) : 0;

		$record = array(
			'migrationId'            => $migration_id,
			'timestamp_started_at'   => $started_at,
			'timestamp_completed_at' => $completed_at,
			'source_site'            => get_site_url(),
			'target_url'             => $target_url,
			'status'                 => 'failed',
			'counts_by_post_type'    => array(),
			'post_type_mappings'     => $post_type_mappings,
			'warnings_count'         => 0,
			'warnings_summary'       => null,
			'errors_summary'         => $error_message,
			'duration_sec'           => $duration_sec,
		);

		$this->migration_runs_repository->save_run( $record );
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
