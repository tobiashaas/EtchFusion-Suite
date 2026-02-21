<?php
/**
 * Legacy Batch Fallback
 *
 * Handles the inline batch processing loop for the legacy migration_manager.php
 * instantiation path (where no Phase_Handler_Interface instances are injected).
 *
 * This class contains the media-phase and posts-phase inline logic that was
 * previously inlined in EFS_Migration_Service::process_batch().
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\EFS_Migration_Runs_Repository;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Legacy_Batch_Fallback
 *
 * Inline batch processing for the legacy path (no Phase_Handler_Interface dispatch).
 */
class EFS_Legacy_Batch_Fallback {

	/** Maximum number of per-post retry attempts before marking as failed. */
	private const MAX_RETRIES = 3;

	/** @var EFS_Media_Service */
	private $media_service;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/** @var EFS_Migration_Runs_Repository|null */
	private $migration_runs_repository;

	/** @var EFS_Migration_Run_Finalizer|null */
	private $run_finalizer;

	/**
	 * @param EFS_Media_Service                      $media_service
	 * @param EFS_Content_Service                    $content_service
	 * @param EFS_API_Client                         $api_client
	 * @param EFS_Error_Handler                      $error_handler
	 * @param EFS_Progress_Manager                   $progress_manager
	 * @param Migration_Repository_Interface         $migration_repository
	 * @param EFS_Migration_Runs_Repository|null     $migration_runs_repository
	 * @param EFS_Migration_Run_Finalizer|null       $run_finalizer
	 */
	public function __construct(
		EFS_Media_Service $media_service,
		EFS_Content_Service $content_service,
		EFS_API_Client $api_client,
		EFS_Error_Handler $error_handler,
		EFS_Progress_Manager $progress_manager,
		Migration_Repository_Interface $migration_repository,
		?EFS_Migration_Runs_Repository $migration_runs_repository = null,
		?EFS_Migration_Run_Finalizer $run_finalizer = null
	) {
		$this->media_service             = $media_service;
		$this->content_service           = $content_service;
		$this->api_client                = $api_client;
		$this->error_handler             = $error_handler;
		$this->progress_manager          = $progress_manager;
		$this->migration_repository      = $migration_repository;
		$this->migration_runs_repository = $migration_runs_repository;
		$this->run_finalizer             = $run_finalizer;
	}

	/**
	 * Process a single batch of media or posts for the JS-driven batch loop.
	 *
	 * Reads the active migration record and checkpoint internally; callers need
	 * only pass the migration ID and the batch options from the AJAX request.
	 *
	 * @param string $migration_id
	 * @param array  $batch        Accepts key 'batch_size' (int).
	 * @return array|\WP_Error
	 */
	public function process_batch( string $migration_id, array $batch ) {
		$active        = $this->migration_repository->get_active_migration();
		$target_url    = is_array( $active ) && isset( $active['target_url'] ) ? (string) $active['target_url'] : '';
		$migration_key = is_array( $active ) && isset( $active['migration_key'] ) ? (string) $active['migration_key'] : '';
		$active_mid    = is_array( $active ) && isset( $active['migration_id'] ) ? (string) $active['migration_id'] : '';

		$active_migration_options = is_array( $active ) && isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();

		$checkpoint = $this->migration_repository->get_checkpoint();

		if ( empty( $checkpoint ) || (string) ( $checkpoint['migrationId'] ?? '' ) !== (string) $migration_id ) {
			return new \WP_Error( 'no_checkpoint', __( 'No checkpoint found for this migration.', 'etch-fusion-suite' ) );
		}

		if ( '' === $active_mid || $active_mid !== (string) $migration_id || '' === $target_url || '' === $migration_key ) {
			return new \WP_Error( 'missing_active_migration', __( 'Active migration record is missing required fields or does not match the current migration ID.', 'etch-fusion-suite' ) );
		}

		$phase = isset( $checkpoint['phase'] ) ? (string) $checkpoint['phase'] : 'posts';

		if ( 'media' === $phase ) {
			return $this->process_media_phase( $migration_id, $checkpoint, $target_url, $migration_key );
		}

		return $this->process_posts_phase( $migration_id, $batch, $checkpoint, $target_url, $migration_key, $active_migration_options );
	}

	// -------------------------------------------------------------------------
	// Private phase helpers
	// -------------------------------------------------------------------------

	/**
	 * Process a batch of media items.
	 *
	 * @param string $migration_id
	 * @param array  $checkpoint    Current checkpoint state.
	 * @param string $target_url
	 * @param string $migration_key
	 * @return array
	 */
	private function process_media_phase( string $migration_id, array $checkpoint, string $target_url, string $migration_key ): array {
		$remaining_media_ids    = isset( $checkpoint['remaining_media_ids'] ) ? (array) $checkpoint['remaining_media_ids'] : array();
		$processed_media_count  = isset( $checkpoint['processed_media_count'] ) ? (int) $checkpoint['processed_media_count'] : 0;
		$total_media_count      = isset( $checkpoint['total_media_count'] ) ? (int) $checkpoint['total_media_count'] : ( count( $remaining_media_ids ) + $processed_media_count );
		$media_attempts         = isset( $checkpoint['media_attempts'] ) && is_array( $checkpoint['media_attempts'] ) ? $checkpoint['media_attempts'] : array();
		$failed_media_ids_final = isset( $checkpoint['failed_media_ids_final'] ) ? (array) $checkpoint['failed_media_ids_final'] : array();
		$media_batch_size       = 3;

		$current_media_batch = array_splice( $remaining_media_ids, 0, $media_batch_size );
		$current_item_id     = null;
		$current_item_title  = '';

		$total_posts_for_combined = isset( $checkpoint['total_count'] ) ? (int) $checkpoint['total_count'] : 0;
		$this->api_client->set_items_total( $total_media_count + $total_posts_for_combined );

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
		$this->progress_manager->update_progress( 'media', $percentage, $message, $processed_media_count, $total_media_count, false );

		if ( empty( $remaining_media_ids ) ) {
			$checkpoint['phase']           = 'posts';
			$checkpoint['processed_count'] = 0;
			$this->migration_repository->save_checkpoint( $checkpoint );
			$remaining_post_ids = isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array();
			$this->progress_manager->update_progress( 'posts', 80, __( 'Ready to migrate posts...', 'etch-fusion-suite' ), 0, count( $remaining_post_ids ), false );

			return array(
				'completed'    => false,
				'remaining'    => count( $remaining_post_ids ),
				'migrationId'  => $migration_id,
				'progress'     => $this->progress_manager->get_progress_data(),
				'steps'        => $this->progress_manager->get_steps_state(),
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
			'progress'     => $this->progress_manager->get_progress_data(),
			'steps'        => $this->progress_manager->get_steps_state(),
			'current_item' => array(
				'id'    => $current_item_id,
				'title' => $current_item_title,
			),
		);
	}

	/**
	 * Process a batch of posts.
	 *
	 * @param string $migration_id
	 * @param array  $batch                    Accepts 'batch_size' (int).
	 * @param array  $checkpoint               Current checkpoint state.
	 * @param string $target_url
	 * @param string $migration_key
	 * @param array  $active_migration_options
	 * @return array|\WP_Error
	 */
	private function process_posts_phase( string $migration_id, array $batch, array $checkpoint, string $target_url, string $migration_key, array $active_migration_options ) {
		$remaining_post_ids    = isset( $checkpoint['remaining_post_ids'] ) ? (array) $checkpoint['remaining_post_ids'] : array();
		$processed_count       = isset( $checkpoint['processed_count'] ) ? (int) $checkpoint['processed_count'] : 0;
		$post_attempts         = isset( $checkpoint['post_attempts'] ) && is_array( $checkpoint['post_attempts'] ) ? $checkpoint['post_attempts'] : array();
		$failed_post_ids_final = isset( $checkpoint['failed_post_ids_final'] ) ? (array) $checkpoint['failed_post_ids_final'] : array();
		$total_count           = isset( $checkpoint['total_count'] ) ? (int) $checkpoint['total_count'] : ( count( $remaining_post_ids ) + $processed_count );
		$batch_size            = isset( $batch['batch_size'] ) ? max( 1, (int) $batch['batch_size'] ) : 10;
		$post_type_mappings    = isset( $active_migration_options['post_type_mappings'] ) && is_array( $active_migration_options['post_type_mappings'] ) ? $active_migration_options['post_type_mappings'] : array();

		$current_batch = array_splice( $remaining_post_ids, 0, $batch_size );

		$total_media_for_combined = isset( $checkpoint['total_media_count'] ) ? (int) $checkpoint['total_media_count'] : 0;
		$this->api_client->set_items_total( $total_media_for_combined + $total_count );

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
					$this->error_handler->log_error(
						'E107',
						array(
							'post_id'       => $post_id,
							'attempts_made' => $current_attempts,
							'error_message' => $result->get_error_message(),
							'action'        => 'Failed to send post to target site after all retry attempts',
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
		$this->progress_manager->update_progress( 'posts', $percentage, $message, $processed_count, $total_count, false );

		if ( empty( $remaining_post_ids ) ) {
			$failed_media_ids_final = isset( $checkpoint['failed_media_ids_final'] ) ? (array) $checkpoint['failed_media_ids_final'] : array();
			$failed_media_count     = count( $failed_media_ids_final );
			$failed_count           = count( $failed_post_ids_final );

			if ( $failed_media_count > 0 && $failed_count > 0 ) {
				$completion_message = sprintf(
					/* translators: 1: failed posts count, 2: failed media count. */
					__( 'Migration completed. %1$d post(s) and %2$d media item(s) failed after retries.', 'etch-fusion-suite' ),
					$failed_count,
					$failed_media_count
				);
			} elseif ( $failed_count > 0 ) {
				$completion_message = sprintf(
					/* translators: %d: failed posts count. */
					__( 'Migration completed. %d post(s) failed after retries.', 'etch-fusion-suite' ),
					$failed_count
				);
			} elseif ( $failed_media_count > 0 ) {
				$completion_message = sprintf(
					/* translators: %d: failed media count. */
					__( 'Migration completed. %d media item(s) failed after retries.', 'etch-fusion-suite' ),
					$failed_media_count
				);
			} else {
				$completion_message = __( 'Migration completed successfully!', 'etch-fusion-suite' );
			}

			$posts_result = array(
				'total'                  => $total_count,
				'migrated'               => $processed_count,
				'failed_post_ids_final'  => $failed_post_ids_final,
				'failed_media_ids_final' => $failed_media_ids_final,
				'failed_media_count'     => $failed_media_count,
			);

			$this->progress_manager->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$checkpoint              = $this->migration_repository->get_checkpoint();
			$batch_migrator_warnings = isset( $checkpoint['migrator_warnings'] ) ? $checkpoint['migrator_warnings'] : array();
			if ( $this->run_finalizer ) {
				$this->run_finalizer->finalize_migration( $posts_result, $batch_migrator_warnings );
			}
			$this->progress_manager->update_progress( 'completed', 100, $completion_message );
			$this->progress_manager->store_active_migration( array() );
			$this->migration_repository->delete_checkpoint();

			$migration_stats                   = $this->migration_repository->get_stats();
			$migration_stats['last_migration'] = current_time( 'mysql' );
			$migration_stats['status']         = 'completed';
			$this->migration_repository->save_stats( $migration_stats );

			return array(
				'completed'             => true,
				'remaining'             => 0,
				'migrationId'           => $migration_id,
				'progress'              => $this->progress_manager->get_progress_data(),
				'steps'                 => $this->progress_manager->get_steps_state(),
				'message'               => $completion_message,
				'failed_media_count'    => $failed_media_count,
				'failed_media_ids'      => $failed_media_ids_final,
				'failed_post_ids_final' => $failed_post_ids_final,
			);
		}

		return array(
			'completed'    => false,
			'remaining'    => count( $remaining_post_ids ),
			'migrationId'  => $migration_id,
			'progress'     => $this->progress_manager->get_progress_data(),
			'steps'        => $this->progress_manager->get_steps_state(),
			'current_item' => array(
				'id'    => $checkpoint['current_item_id'] ?? null,
				'title' => $checkpoint['current_item_title'] ?? '',
			),
		);
	}

}

