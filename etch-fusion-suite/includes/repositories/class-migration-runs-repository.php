<?php
/**
 * Migration Runs Repository
 *
 * Stores and retrieves historical migration run records with 10-day retention.
 *
 * @package EtchFusion\Repositories
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories;

/**
 * Class EFS_Migration_Runs_Repository
 *
 * Manages historical migration run records using the WordPress Options API.
 */
class EFS_Migration_Runs_Repository {

	/**
	 * Option key for stored runs.
	 */
	const OPTION_KEY = 'efs_migration_runs';

	/**
	 * Retention period in days.
	 */
	const RETENTION_DAYS = 10;

	/**
	 * Persist a new migration run record at the front of the list.
	 *
	 * @param array $record Migration run record.
	 * @return void
	 */
	public function save_run( array $record ): void {
		$runs = get_option( self::OPTION_KEY, array() );
		$runs = is_array( $runs ) ? $runs : array();

		array_unshift( $runs, $record );
		$runs = $this->apply_retention( $runs );

		update_option( self::OPTION_KEY, $runs );
	}

	/**
	 * Retrieve recent migration runs, applying retention before returning.
	 *
	 * @param int $limit Maximum number of runs to return.
	 * @return array
	 */
	public function get_runs( int $limit = 50 ): array {
		$runs = get_option( self::OPTION_KEY, array() );
		$runs = is_array( $runs ) ? $runs : array();

		$runs = $this->apply_retention( $runs );

		return array_slice( $runs, 0, $limit );
	}

	/**
	 * Retrieve a single run record by migration ID.
	 *
	 * @param string $migration_id Migration ID to look up.
	 * @return array|null Run record array, or null if not found.
	 */
	public function get_run_by_id( string $migration_id ): ?array {
		$runs = get_option( self::OPTION_KEY, array() );
		$runs = is_array( $runs ) ? $runs : array();

		foreach ( $runs as $record ) {
			if ( is_array( $record ) && isset( $record['migrationId'] ) && $record['migrationId'] === $migration_id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Filter runs to only those within the retention window.
	 *
	 * Records without timestamps are kept to avoid accidental data loss.
	 *
	 * @param array $runs Raw runs list.
	 * @return array
	 */
	private function apply_retention( array $runs ): array {
		$cutoff = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );

		return array_values(
			array_filter(
				$runs,
				function ( $record ) use ( $cutoff ) {
					if ( ! is_array( $record ) ) {
						return false;
					}

					$reference = isset( $record['timestamp_completed_at'] ) && '' !== $record['timestamp_completed_at']
						? $record['timestamp_completed_at']
						: ( $record['timestamp_started_at'] ?? '' );

					if ( '' === $reference ) {
						return true; // Keep records without timestamps to avoid data loss.
					}

					$ts = strtotime( $reference );

					return false === $ts || $ts >= $cutoff;
				}
			)
		);
	}
}
