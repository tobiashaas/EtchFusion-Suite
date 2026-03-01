<?php
/**
 * Database-Backed Migration Persistence Layer
 *
 * Manages migration state with complete persistence, resumption, and audit trail.
 * Primary storage: wp_efs_migrations and wp_efs_migration_logs tables.
 * Fallback: WordPress Options API (for backward compatibility).
 *
 * @package Bricks2Etch\Repositories
 */

namespace Bricks2Etch\Repositories;

use Bricks2Etch\Core\EFS_DB_Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_DB_Migration_Persistence
 *
 * Provides database-first persistence for migrations with:
 * - Complete state tracking (creation → in_progress → completed/failed)
 * - Timestamps for start/end/duration
 * - Error tracking and logging
 * - Automatic crash detection (in_progress without recent heartbeat)
 * - Resume capability from last known state
 * - Full audit trail of all events
 */
class EFS_DB_Migration_Persistence {

	/**
	 * Create a new migration record in database.
	 *
	 * @param string $migration_id Unique migration identifier (UUID).
	 * @param string $source_url   Source site URL.
	 * @param string $target_url   Target site URL.
	 * @param array  $metadata     Optional metadata (user_id, ip, etc).
	 * @return string|false Migration ID if created, false on failure.
	 */
	public static function create_migration( string $migration_id, string $source_url, string $target_url, array $metadata = array() ) {
		global $wpdb;

		// Use EFS_DB_Installer to create, but it will generate its own UID
		// So we need to manually insert with our specific ID
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => esc_url_raw( $source_url ),
				'target_url'    => esc_url_raw( $target_url ),
				'status'        => 'pending',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $migration_id : false;
	}

	/**
	 * Get migration state by ID.
	 *
	 * Returns complete migration record with all tracking data.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array|null Full migration record or null if not found.
	 */
	public static function get_migration( string $migration_id ): ?array {
		return EFS_DB_Installer::get_migration( $migration_id );
	}

	/**
	 * Update migration progress with item counts.
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $processed    Items processed so far.
	 * @param int    $total        Total items to process.
	 * @return bool True on success.
	 */
	public static function update_progress( string $migration_id, int $processed, int $total ): bool {
		return EFS_DB_Installer::update_progress( $migration_id, $processed, $total );
	}

	/**
	 * Update migration status with timestamp tracking.
	 *
	 * Statuses: pending → in_progress → completed/failed/canceled
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $status       New status.
	 * @return bool True on success.
	 */
	public static function update_status( string $migration_id, string $status ): bool {
		return EFS_DB_Installer::update_status( $migration_id, $status );
	}

	/**
	 * Log a migration event (info/warning/error).
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $level        Log level (info/warning/error).
	 * @param string $message      Event message.
	 * @param string $category     Event category (optional).
	 * @param array  $context      Event context as JSON (optional).
	 * @return bool True on success.
	 */
	public static function log_event( string $migration_id, string $level, string $message, string $category = '', array $context = array() ): bool {
		return EFS_DB_Installer::log_event( $migration_id, $level, $message, $category, $context );
	}

	/**
	 * Mark migration as failed with error details.
	 *
	 * @param string $migration_id Migration ID.
	 * @param string $error_msg    Error message.
	 * @param array  $context      Error context.
	 * @return bool True on success.
	 */
	public static function mark_failed( string $migration_id, string $error_msg, array $context = array() ): bool {
		// Log the error
		self::log_event( $migration_id, 'error', $error_msg, 'migration_failed', $context );

		// Update status to failed
		return self::update_status( $migration_id, 'failed' );
	}

	/**
	 * Detect stuck/crashed migrations that need resumption.
	 *
	 * Returns migrations that are in "in_progress" state but haven't
	 * been updated in the last N seconds (stale timeout).
	 *
	 * @param int $stale_timeout Seconds since last update (default: 5 min).
	 * @return array Array of stale migration records.
	 */
	public static function get_stale_migrations( int $stale_timeout = 300 ): array {
		global $wpdb;

		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $stale_timeout );

		$migrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}efs_migrations 
				 WHERE status = 'in_progress' 
				 AND updated_at < %s
				 ORDER BY updated_at ASC",
				$cutoff_time
			),
			ARRAY_A
		);

		return is_array( $migrations ) ? $migrations : array();
	}

	/**
	 * Get migration audit trail (all events for a migration).
	 *
	 * @param string $migration_id Migration ID.
	 * @param int    $limit        Maximum events to return.
	 * @return array Array of log events.
	 */
	public static function get_audit_trail( string $migration_id, int $limit = 100 ): array {
		global $wpdb;

		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}efs_migration_logs 
				 WHERE migration_uid = %s
				 ORDER BY created_at DESC
				 LIMIT %d",
				$migration_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $logs ) ? $logs : array();
	}

	/**
	 * Get all recent migrations (for dashboard display).
	 *
	 * @param int $limit Maximum migrations to return.
	 * @return array Array of migration records.
	 */
	public static function get_recent_migrations( int $limit = 50 ): array {
		global $wpdb;

		$migrations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}efs_migrations 
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $migrations ) ? $migrations : array();
	}

	/**
	 * Get migration statistics.
	 *
	 * @return array Stats including total, completed, failed, etc.
	 */
	public static function get_statistics(): array {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations"
		);

		$completed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations WHERE status = %s",
				'completed'
			)
		);

		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations WHERE status = %s",
				'failed'
			)
		);

		$in_progress = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}efs_migrations WHERE status = %s",
				'in_progress'
			)
		);

		$avg_duration = $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at))
			 FROM {$wpdb->prefix}efs_migrations 
			 WHERE status = 'completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL"
		);

		return array(
			'total_migrations'  => $total,
			'completed'         => $completed,
			'failed'            => $failed,
			'in_progress'       => $in_progress,
			'success_rate'      => $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : 0,
			'avg_duration_secs' => $avg_duration ? (int) $avg_duration : 0,
		);
	}

	/**
	 * Clear old completed migrations (retention policy).
	 *
	 * @param int $days_old Delete migrations completed more than N days ago.
	 * @return int Number of migrations deleted.
	 */
	public static function cleanup_old_migrations( int $days_old = 30 ): int {
		global $wpdb;

		$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $days_old * 86400 ) );

		// Delete migration logs first (FK constraint)
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}efs_migration_logs 
				 WHERE migration_uid IN (
					SELECT migration_uid FROM {$wpdb->prefix}efs_migrations 
					WHERE status = 'completed' AND completed_at < %s
				 )",
				$cutoff_date
			)
		);

		// Delete migrations
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}efs_migrations 
				 WHERE status = 'completed' AND completed_at < %s",
				$cutoff_date
			)
		);

		return $deleted;
	}
}
