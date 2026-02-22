<?php
/**
 * Migration ETA Calculator Service
 *
 * Stateless helper for estimating remaining migration time from progress data.
 * Extracted from EFS_Progress_Manager to satisfy <300 LoC acceptance criterion.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_ETA_Calculator
 *
 * Pure calculation; no I/O or constructor dependencies.
 */
class EFS_Migration_ETA_Calculator {

	/**
	 * Running progress with no updates for this period is stale (seconds).
	 */
	public const PROGRESS_STALE_TTL = 600;

	/**
	 * Estimate remaining time in seconds based on elapsed time and percentage.
	 *
	 * @param array $progress Progress payload.
	 * @return int|null
	 */
	public function estimate_time_remaining( array $progress ): ?int {
		$percentage = isset( $progress['percentage'] ) ? (float) $progress['percentage'] : 0.0;
		$status     = isset( $progress['status'] ) ? (string) $progress['status'] : 'idle';
		if ( $percentage <= 0 || $percentage >= 100 || in_array( $status, array( 'completed', 'error', 'stale', 'idle' ), true ) ) {
			return null;
		}

		$items_processed = isset( $progress['items_processed'] ) ? (int) $progress['items_processed'] : 0;
		$items_total     = isset( $progress['items_total'] ) ? (int) $progress['items_total'] : 0;
		$current_step    = isset( $progress['current_step'] ) ? (string) $progress['current_step'] : '';

		// For item-based batch phases use throughput rate (items/s).
		// This is far more accurate than the overall-elapsed/percentage approach, because
		// setup phases (CSS, validation, etc.) are much slower than the batch transfer.
		if ( $items_processed > 0 && $items_total > $items_processed && in_array( $current_step, array( 'media', 'posts' ), true ) ) {
			$batch_started_at = isset( $progress['batch_phase_started_at'] )
				? strtotime( (string) $progress['batch_phase_started_at'] . ' UTC' )
				: false;
			if ( false !== $batch_started_at && $batch_started_at > 0 ) {
				$batch_elapsed = max( 1, time() - $batch_started_at );
				$rate          = $items_processed / $batch_elapsed;
				if ( $rate > 0 ) {
					return (int) ceil( ( $items_total - $items_processed ) / $rate );
				}
			}
		}

		// Fallback: linear projection from overall started_at + percentage.
		$started_at = isset( $progress['started_at'] ) ? strtotime( (string) $progress['started_at'] . ' UTC' ) : false;
		if ( false === $started_at || $started_at <= 0 ) {
			return null;
		}

		$elapsed = max( 1, time() - $started_at );
		$total   = (int) round( $elapsed / ( $percentage / 100 ) );

		return max( 0, $total - $elapsed );
	}
}
