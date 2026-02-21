<?php
/**
 * Migration Run Finalizer
 *
 * Responsible for persisting a completed (or failed) migration run record.
 * Extracted from EFS_Migration_Orchestrator to keep finalization logic in one place.
 *
 * Handles:
 * - finalize_migration()        — success-path run record with full stats
 * - write_failed_run_record()   — minimal failure record for error paths
 * - build_counts_by_post_type() — per-post-type migration counts (private helper)
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\EFS_Migration_Runs_Repository;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_Run_Finalizer
 *
 * Persists completed/failed run records to EFS_Migration_Runs_Repository.
 */
class EFS_Migration_Run_Finalizer {

	/** @var EFS_Progress_Manager */
	private $progress_manager;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/** @var EFS_Migration_Runs_Repository|null */
	private $migration_runs_repository;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Content_Service */
	private $content_service;

	/**
	 * @param EFS_Progress_Manager                   $progress_manager
	 * @param Migration_Repository_Interface         $migration_repository
	 * @param EFS_Migration_Runs_Repository|null     $migration_runs_repository
	 * @param EFS_Error_Handler                      $error_handler
	 * @param EFS_Content_Service                    $content_service
	 */
	public function __construct(
		EFS_Progress_Manager $progress_manager,
		Migration_Repository_Interface $migration_repository,
		?EFS_Migration_Runs_Repository $migration_runs_repository,
		EFS_Error_Handler $error_handler,
		EFS_Content_Service $content_service
	) {
		$this->progress_manager          = $progress_manager;
		$this->migration_repository      = $migration_repository;
		$this->migration_runs_repository = $migration_runs_repository;
		$this->error_handler             = $error_handler;
		$this->content_service           = $content_service;
	}

	/**
	 * Finalize migration and persist a run record.
	 *
	 * @param array $results           Posts migration results (total, migrated, failed_*).
	 * @param array $migrator_warnings Optional migrator types that failed non-fatally.
	 * @return true|\WP_Error
	 */
	public function finalize_migration( array $results, array $migrator_warnings = array() ) {
		$this->error_handler->log_info(
			'Finalizing migration',
			array( 'results' => $results )
		);

		if ( ! $this->migration_runs_repository ) {
			return true;
		}

		$progress_data = $this->progress_manager->get_progress_data();
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

		$failed_media_ids_final = isset( $results['failed_media_ids_final'] ) && is_array( $results['failed_media_ids_final'] )
			? array_values( $results['failed_media_ids_final'] )
			: array();
		$failed_media_count     = isset( $results['failed_media_count'] ) ? (int) $results['failed_media_count'] : count( $failed_media_ids_final );

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
						return isset( $w['title'] ) ? (string) $w['title'] : (string) ( $w['code'] ?? '' );
					},
					$warnings
				)
			)
			: null;
		$errors_summary   = count( $errors ) > 0
			? implode(
				'; ',
				array_map(
					function ( $e ) {
						return isset( $e['title'] ) ? (string) $e['title'] : (string) ( $e['code'] ?? '' );
					},
					$errors
				)
			)
			: null;

		$status       = ( $warnings_count > 0 || $failed_media_count > 0 || ! empty( $migrator_warnings ) ) ? 'success_with_warnings' : 'success';
		$duration_sec = ( $started_ts > 0 ) ? max( 0, time() - $started_ts ) : 0;

		$record = array(
			'migrationId'                => $migration_id,
			'timestamp_started_at'       => $started_at,
			'timestamp_completed_at'     => $completed_at,
			'source_site'                => home_url(),
			'target_url'                 => $target_url,
			'status'                     => $status,
			'counts_by_post_type'        => $counts_by_post_type,
			'post_type_mappings'         => $post_type_mappings,
			'failed_posts_count'         => $failed_posts_count,
			'failed_post_ids'            => $failed_post_ids_final,
			'failed_media_count'         => $failed_media_count,
			'failed_media_ids'           => $failed_media_ids_final,
			'warnings_count'             => $warnings_count,
			'warnings_summary'           => $warnings_summary,
			'errors_summary'             => $errors_summary,
			'duration_sec'               => $duration_sec,
			'optional_migrator_warnings' => $migrator_warnings,
		);

		$this->migration_runs_repository->save_run( $record );

		return true;
	}

	/**
	 * Write a minimal "failed" run record for error paths.
	 *
	 * @param string $migration_id  Migration ID (may be empty if error occurred before ID was generated).
	 * @param string $error_message Human-readable error message.
	 * @return void
	 */
	public function write_failed_run_record( string $migration_id, string $error_message ): void {
		if ( ! $this->migration_runs_repository || '' === $migration_id ) {
			return;
		}

		$progress_data = $this->progress_manager->get_progress_data();
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
			'source_site'            => home_url(),
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

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

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
}
