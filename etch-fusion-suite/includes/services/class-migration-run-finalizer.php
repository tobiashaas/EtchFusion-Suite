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

	/** @var EFS_Phase_Timer */
	private $phase_timer;

	/**
	 * @param EFS_Progress_Manager                   $progress_manager
	 * @param Migration_Repository_Interface         $migration_repository
	 * @param EFS_Migration_Runs_Repository|null     $migration_runs_repository
	 * @param EFS_Error_Handler                      $error_handler
	 * @param EFS_Content_Service                    $content_service
	 * @param EFS_Phase_Timer                        $phase_timer
	 */
	public function __construct(
		EFS_Progress_Manager $progress_manager,
		Migration_Repository_Interface $migration_repository,
		?EFS_Migration_Runs_Repository $migration_runs_repository,
		EFS_Error_Handler $error_handler,
		EFS_Content_Service $content_service,
		EFS_Phase_Timer $phase_timer = null
	) {
		$this->progress_manager          = $progress_manager;
		$this->migration_repository      = $migration_repository;
		$this->migration_runs_repository = $migration_runs_repository;
		$this->error_handler             = $error_handler;
		$this->content_service           = $content_service;
		$this->phase_timer               = $phase_timer;
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
		$failed_media_count     = $this->get_failed_media_count( $results, $failed_media_ids_final );

		$counts_by_post_type = $this->build_counts_by_post_type( $results, $options );

		// Extract media_stats if available (from enhanced logging).
		$media_stats = isset( $results['media_stats'] ) && is_array( $results['media_stats'] )
			? $results['media_stats']
			: array();

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

		// Add media_stats if available.
		if ( ! empty( $media_stats ) ) {
			$record['media_stats'] = $media_stats;
		}

		// Add phase timing if available.
		if ( $this->phase_timer ) {
			$this->phase_timer->add_phase_timing( $record );
			// End finalization phase timing.
			$this->phase_timer->end_phase();
		}

		$this->migration_runs_repository->save_run( $record );

		return true;
	}

	/**
	 * Build a completion payload for the target receiving state.
	 *
	 * The target only knows about requested totals. When the source finishes
	 * with warnings, failed or skipped items must be reconciled so the target
	 * validates against the items that were actually expected to arrive.
	 *
	 * @param array $results Final migration results payload.
	 * @return array
	 */
	public function build_completion_payload( array $results ): array {
		$options                = $this->get_active_options();
		$counts_by_post_type    = $this->build_counts_by_post_type( $results, $options );
		$expected_receipts      = array();
		$media_expected_receipt = $this->get_expected_media_receipts( $results );

		foreach ( $counts_by_post_type as $post_type => $counts ) {
			$expected_receipts[ $post_type ] = $this->get_expected_receipts_for_counts( is_array( $counts ) ? $counts : array() );
		}

		if ( null !== $media_expected_receipt ) {
			$expected_receipts['media'] = $media_expected_receipt;
		}

		if ( empty( $expected_receipts ) ) {
			return array();
		}

		return array(
			'expected_receipts_by_type' => $expected_receipts,
		);
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
	 * Get the active migration options from the repository.
	 *
	 * @return array
	 */
	private function get_active_options(): array {
		$active = $this->migration_repository->get_active_migration();

		return isset( $active['options'] ) && is_array( $active['options'] ) ? $active['options'] : array();
	}

	/**
	 * Calculate how many items of a type were actually expected to arrive.
	 *
	 * @param array $counts Count payload with success/failed/skipped or legacy migrated keys.
	 * @return int
	 */
	private function get_expected_receipts_for_counts( array $counts ): int {
		if ( isset( $counts['success'] ) ) {
			return max( 0, (int) $counts['success'] );
		}

		if ( isset( $counts['migrated'] ) ) {
			return max( 0, (int) $counts['migrated'] );
		}

		$total   = isset( $counts['total'] ) ? (int) $counts['total'] : 0;
		$failed  = isset( $counts['failed'] ) ? (int) $counts['failed'] : 0;
		$skipped = isset( $counts['skipped'] ) ? (int) $counts['skipped'] : 0;

		return max( 0, $total - $failed - $skipped );
	}

	/**
	 * Collapse available media result structures into one summary array.
	 *
	 * @param array $results Final migration results payload.
	 * @return array
	 */
	private function get_media_summary( array $results ): array {
		if ( isset( $results['media_summary'] ) && is_array( $results['media_summary'] ) ) {
			return $results['media_summary'];
		}

		$media_stats = isset( $results['media_stats'] ) && is_array( $results['media_stats'] ) ? $results['media_stats'] : array();
		if ( empty( $media_stats ) ) {
			return array();
		}

		$summary = array(
			'total_media'    => 0,
			'migrated_media' => 0,
			'skipped_media'  => 0,
			'failed_media'   => 0,
		);

		foreach ( $media_stats as $stats ) {
			if ( ! is_array( $stats ) ) {
				continue;
			}

			$summary['total_media']    += isset( $stats['total'] ) ? (int) $stats['total'] : 0;
			$summary['migrated_media'] += isset( $stats['success'] ) ? (int) $stats['success'] : 0;
			$summary['skipped_media']  += isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0;
			$summary['failed_media']   += isset( $stats['failed'] ) ? (int) $stats['failed'] : 0;
		}

		return $summary;
	}

	/**
	 * Determine how many media items were expected to reach the target.
	 *
	 * @param array $results Final migration results payload.
	 * @return int|null
	 */
	private function get_expected_media_receipts( array $results ): ?int {
		$summary = $this->get_media_summary( $results );
		if ( empty( $summary ) ) {
			return null;
		}

		if ( isset( $summary['migrated_media'] ) ) {
			return max( 0, (int) $summary['migrated_media'] );
		}

		$total   = isset( $summary['total_media'] ) ? (int) $summary['total_media'] : 0;
		$failed  = isset( $summary['failed_media'] ) ? (int) $summary['failed_media'] : 0;
		$skipped = isset( $summary['skipped_media'] ) ? (int) $summary['skipped_media'] : 0;

		return max( 0, $total - $failed - $skipped );
	}

	/**
	 * Determine failed media count from the available result shapes.
	 *
	 * @param array $results Final migration results payload.
	 * @param array $failed_media_ids_final Normalized list of permanently failed media IDs.
	 * @return int
	 */
	private function get_failed_media_count( array $results, array $failed_media_ids_final ): int {
		if ( isset( $results['failed_media_count'] ) ) {
			return max( 0, (int) $results['failed_media_count'] );
		}

		if ( ! empty( $failed_media_ids_final ) ) {
			return count( $failed_media_ids_final );
		}

		$summary = $this->get_media_summary( $results );
		if ( isset( $summary['failed_media'] ) ) {
			return max( 0, (int) $summary['failed_media'] );
		}

		return 0;
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
					// Support new enhanced structure (success/failed/skipped).
					if ( isset( $value['success'] ) || isset( $value['failed'] ) || isset( $value['skipped'] ) ) {
						$total   = isset( $value['total'] ) ? (int) $value['total'] : 0;
						$success = isset( $value['success'] ) ? (int) $value['success'] : 0;
						$failed  = isset( $value['failed'] ) ? (int) $value['failed'] : 0;
						$skipped = isset( $value['skipped'] ) ? (int) $value['skipped'] : 0;

						$normalized[ $key ] = array(
							'total'   => max( 0, $total ),
							'success' => max( 0, $success ),
							'failed'  => max( 0, $failed ),
							'skipped' => max( 0, $skipped ),
						);
					} else {
						// Legacy structure (total/migrated or total/items_processed).
						$total    = isset( $value['total'] ) ? (int) $value['total'] : ( isset( $value['items_total'] ) ? (int) $value['items_total'] : 0 );
						$migrated = isset( $value['migrated'] ) ? (int) $value['migrated'] : ( isset( $value['items_processed'] ) ? (int) $value['items_processed'] : $total );

						$normalized[ $key ] = array(
							'total'   => max( 0, $total ),
							'success' => max( 0, $migrated ),
							'failed'  => 0,
							'skipped' => 0,
						);
					}
				} else {
					// Scalar value (legacy).
					$total              = (int) $value;
					$normalized[ $key ] = array(
						'total'   => max( 0, $total ),
						'success' => max( 0, $total ),
						'failed'  => 0,
						'skipped' => 0,
					);
				}
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
