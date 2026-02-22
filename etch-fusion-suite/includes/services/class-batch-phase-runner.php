<?php
/**
 * Batch Phase Runner Service
 *
 * Owns the delegating phase-dispatch logic for a single batch iteration.
 * Extracted from EFS_Batch_Processor to keep that class within the line-count
 * ceiling while preserving all phase-handling behaviour verbatim.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\Interfaces\Checkpoint_Repository_Interface;
use Bricks2Etch\Services\Interfaces\Phase_Handler_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Batch_Phase_Runner
 *
 * Executes one batch iteration for a given phase handler and updates progress.
 */
class EFS_Batch_Phase_Runner {

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Migration_Run_Finalizer */
	private $run_finalizer;

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var Checkpoint_Repository_Interface */
	private $checkpoint_repository;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Migration_Logger */
	private $migration_logger;

	/**
	 * @param EFS_Error_Handler               $error_handler
	 * @param EFS_Migration_Run_Finalizer     $run_finalizer
	 * @param EFS_Progress_Manager            $progress_manager
	 * @param Checkpoint_Repository_Interface $checkpoint_repository
	 * @param EFS_API_Client                  $api_client
	 * @param EFS_Migration_Logger            $migration_logger
	 */
	public function __construct(
		EFS_Error_Handler $error_handler,
		EFS_Migration_Run_Finalizer $run_finalizer,
		EFS_Progress_Manager $progress_manager,
		Checkpoint_Repository_Interface $checkpoint_repository,
		EFS_API_Client $api_client,
		EFS_Migration_Logger $migration_logger
	) {
		$this->error_handler         = $error_handler;
		$this->run_finalizer         = $run_finalizer;
		$this->progress_manager      = $progress_manager;
		$this->checkpoint_repository = $checkpoint_repository;
		$this->api_client            = $api_client;
		$this->migration_logger      = $migration_logger;
	}

	/**
	 * Run one batch iteration for the given phase handler.
	 *
	 * @param Phase_Handler_Interface $active_handler
	 * @param string                  $phase
	 * @param array                   $checkpoint
	 * @param array                   $batch
	 * @param string                  $migration_id
	 * @param string                  $target_url
	 * @param string                  $migration_key
	 * @param array                   $active_migration_options
	 * @return array|\WP_Error
	 */
	public function run_phase(
		Phase_Handler_Interface $active_handler,
		string $phase,
		array $checkpoint,
		array $batch,
		string $migration_id,
		string $target_url,
		string $migration_key,
		array $active_migration_options
	) {
		// --- Delegating path: all phase logic driven by the handler interface ---

		// Derive checkpoint keys.
		$total_key      = 'media' === $phase ? 'total_media_count' : 'total_count';
		$attempts_key   = 'media' === $phase ? 'media_attempts' : 'post_attempts';
		$failed_ids_key = 'media' === $phase ? 'failed_media_ids_final' : 'failed_post_ids_final';
		$processed_key  = 'media' === $phase ? 'processed_media_count' : 'processed_count';

		// Read state from checkpoint.
		$remaining       = $active_handler->get_remaining( $checkpoint );
		$processed_count = isset( $checkpoint[ $processed_key ] ) ? (int) $checkpoint[ $processed_key ] : 0;
		$total           = isset( $checkpoint[ $total_key ] ) ? (int) $checkpoint[ $total_key ] : ( count( $remaining ) + $processed_count );
		$attempts        = isset( $checkpoint[ $attempts_key ] ) && is_array( $checkpoint[ $attempts_key ] ) ? $checkpoint[ $attempts_key ] : array();
		$failed_ids      = isset( $checkpoint[ $failed_ids_key ] ) ? (array) $checkpoint[ $failed_ids_key ] : array();

		// Batch size: honor caller override for both phases.
		$batch_size = isset( $batch['batch_size'] ) ? max( 1, (int) $batch['batch_size'] ) : $active_handler->get_batch_size();

		// Splice the next batch out of the remaining IDs.
		$current_batch = array_splice( $remaining, 0, $batch_size );

		// Send the combined media + posts total so the receiving-side ETA covers both phases.
		$other_total = 'media' === $phase
			? (int) ( $checkpoint['total_count'] ?? 0 )
			: (int) ( $checkpoint['total_media_count'] ?? 0 );
		$this->api_client->set_items_total( $total + $other_total );

		// Build context for process_item().
		$context = array(
			'target_url'    => $target_url,
			'migration_key' => $migration_key,
		);
		if ( 'posts' === $phase ) {
			$context['api_client']         = $this->api_client;
			$context['post_type_mappings'] = isset( $active_migration_options['post_type_mappings'] ) && is_array( $active_migration_options['post_type_mappings'] ) ? $active_migration_options['post_type_mappings'] : array();
		}

		$this->migration_logger->log(
			$migration_id,
			'info',
			'Batch started',
			[
				'migration_id' => $migration_id,
				'phase'        => $phase,
				'batch_size'   => count( $current_batch ),
			]
		);

		// Process each item in the batch.
		foreach ( $current_batch as $id ) {
			$id     = (int) $id;
			$id_key = (string) $id;

			$attempts[ $id_key ] = isset( $attempts[ $id_key ] ) ? (int) $attempts[ $id_key ] + 1 : 1;
			$result              = $active_handler->process_item( $id, $context );

			// Treat a boolean false return as a generic item-processing failure.
			if ( false === $result ) {
				$result = new \WP_Error( 'process_item_false', __( 'Item processing returned false.', 'etch-fusion-suite' ) );
			}

			if ( is_wp_error( $result ) ) {
				$this->migration_logger->log(
					$migration_id,
					'warning',
					'Item failed',
					[
						'migration_id' => $migration_id,
						'id'           => $id,
						'attempt'      => $attempts[ $id_key ],
						'error'        => $result->get_error_message(),
					]
				);
				if ( $attempts[ $id_key ] < $active_handler->get_max_retries() ) {
					$remaining[] = $id;
					if ( 'posts' === $phase ) {
						$this->error_handler->log_warning(
							'W010',
							array(
								'post_id'       => $id,
								'attempt'       => $attempts[ $id_key ],
								'error_message' => $result->get_error_message(),
								'action'        => 'Batch post conversion retry scheduled',
							)
						);
					}
				} else {
					$failed_ids[] = $id;
					if ( 'media' === $phase ) {
						$this->error_handler->log_warning(
							'W012',
							array(
								'media_id'      => $id,
								'attempts_made' => $attempts[ $id_key ],
								'error_message' => $result->get_error_message(),
								'action'        => 'Media migration failed after retries',
							)
						);
					} else {
						$this->error_handler->log_warning(
							'W011',
							array(
								'post_id'       => $id,
								'attempts_made' => $attempts[ $id_key ],
								'error_message' => $result->get_error_message(),
								'action'        => 'Batch post conversion failed after max retries',
							)
						);
						$this->error_handler->log_error(
							'E107',
							array(
								'post_id'       => $id,
								'attempts_made' => $attempts[ $id_key ],
								'error_message' => $result->get_error_message(),
								'action'        => 'Failed to send post to target site after all retry attempts',
							)
						);
					}
				}
				continue;
			}

			++$processed_count;
			$checkpoint['current_item_id'] = $id;
			$wp_post                       = get_post( $id );
			$checkpoint['current_item_title'] = ( $wp_post instanceof \WP_Post ) ? (string) $wp_post->post_title : '';
		}

		// Save progress via handler (remaining IDs + processed count), then persist attempt/failed state.
		$active_handler->save_progress( $checkpoint, $remaining, $processed_count );
		$checkpoint[ $attempts_key ]   = $attempts;
		$checkpoint[ $failed_ids_key ] = $failed_ids;
		$this->checkpoint_repository->save_checkpoint( $checkpoint );

		$this->migration_logger->log(
			$migration_id,
			'info',
			'Batch complete',
			[
				'migration_id' => $migration_id,
				'processed'    => $processed_count,
				'remaining'    => count( $remaining ),
			]
		);

		// Calculate percentage within this phase's range.
		$range      = $active_handler->get_percentage_range();
		$percentage = $total > 0
			? min( $range[1], (int) round( $range[0] + ( $processed_count / $total * ( $range[1] - $range[0] ) ) ) )
			: $range[0];

		// Build message and update progress.
		if ( 'media' === $phase ) {
			$message = sprintf(
				/* translators: 1: processed media count, 2: total media count. */
				__( 'Migrating media… %1$d/%2$d', 'etch-fusion-suite' ),
				$processed_count,
				$total
			);
		} else {
			$message = sprintf(
				/* translators: 1: processed count, 2: total count. */
				__( 'Migrating posts... %1$d/%2$d', 'etch-fusion-suite' ),
				$processed_count,
				$total
			);
		}
		$this->progress_manager->update_progress( $phase, $percentage, $message, $processed_count, $total, false );

		// Phase transition (media only): when all media are processed, advance to posts phase.
		if ( 'media' === $phase && empty( $remaining ) ) {
			$checkpoint['phase']           = 'posts';
			$checkpoint['processed_count'] = 0;
			$this->checkpoint_repository->save_checkpoint( $checkpoint );
			$remaining_post_ids = (array) ( $checkpoint['remaining_post_ids'] ?? array() );
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

		// Finalization (posts only): when all posts are processed, finalize migration.
		if ( 'posts' === $phase && empty( $remaining ) ) {
			$failed_media_ids_final = isset( $checkpoint['failed_media_ids_final'] ) ? (array) $checkpoint['failed_media_ids_final'] : array();
			$failed_media_count     = count( $failed_media_ids_final );
			$failed_count           = count( $failed_ids );

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
				'total'                  => $total,
				'migrated'               => $processed_count,
				'failed_post_ids_final'  => $failed_ids,
				'failed_media_ids_final' => $failed_media_ids_final,
				'failed_media_count'     => $failed_media_count,
			);

			$this->progress_manager->update_progress( 'finalization', 95, __( 'Finalizing migration...', 'etch-fusion-suite' ) );
			$checkpoint              = $this->checkpoint_repository->get_checkpoint();
			$batch_migrator_warnings = isset( $checkpoint['migrator_warnings'] ) ? $checkpoint['migrator_warnings'] : array();

			// Build counts_by_post_type from totals stored in the checkpoint when it was created.
			$totals_by_type      = isset( $checkpoint['counts_by_post_type_totals'] ) && is_array( $checkpoint['counts_by_post_type_totals'] )
				? $checkpoint['counts_by_post_type_totals']
				: array();
			$counts_by_post_type = array();
			if ( ! empty( $totals_by_type ) ) {
				$total_selected      = array_sum( $totals_by_type );
				$remaining_to_assign = min( $processed_count, $total_selected );
				foreach ( $totals_by_type as $post_type => $type_total ) {
					$type_migrated        = min( (int) $type_total, (int) $remaining_to_assign );
					$remaining_to_assign -= $type_migrated;
					$counts_by_post_type[ $post_type ] = array(
						'total'    => max( 0, (int) $type_total ),
						'migrated' => max( 0, (int) $type_migrated ),
					);
				}
			}
			$posts_result['counts_by_post_type'] = $counts_by_post_type;

			$this->run_finalizer->finalize_migration( $posts_result, $batch_migrator_warnings );
			$this->progress_manager->update_progress( 'completed', 100, $completion_message );
			$this->progress_manager->store_active_migration( array() );
			$this->checkpoint_repository->delete_checkpoint();
			$this->progress_manager->mark_stats_completed();

			return array(
				'completed'             => true,
				'remaining'             => 0,
				'migrationId'           => $migration_id,
				'progress'              => $this->progress_manager->get_progress_data(),
				'steps'                 => $this->progress_manager->get_steps_state(),
				'message'               => $completion_message,
				'failed_media_count'    => $failed_media_count,
				'failed_media_ids'      => $failed_media_ids_final,
				'failed_post_ids_final' => $failed_ids,
			);
		}

		// Normal (mid-phase) return.
		return array(
			'completed'    => false,
			'remaining'    => count( $remaining ),
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
