<?php
/**
 * Database Installer
 *
 * Handles plugin database table creation, updates, and cleanup.
 * Uses WordPress dbDelta for safe table management.
 *
 * @package Bricks2Etch\Core
 */

namespace Bricks2Etch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_DB_Installer {

	/**
	 * Current database schema version.
	 * Bump this whenever schema changes so the upgrade hook reruns dbDelta.
	 * 1.1.0 – added wp_efs_settings table + fixed activation hook path
	 * 1.2.0 – added checkpoint_data / checkpoint_version columns for optimistic locking
	 * 1.3.0 – added migration_id index on wp_efs_migration_logs for large migrations
	 */
	const DB_VERSION = '1.3.0';

	/**
	 * Option key for stored DB version
	 */
	const DB_VERSION_OPTION = 'efs_db_version';

	/**
	 * Create required database tables on plugin activation
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		// Create migrations table.
		// checkpoint_data / checkpoint_version added in DB_VERSION 1.2.0 for optimistic locking.
		$migrations_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}efs_migrations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_uid VARCHAR(50) UNIQUE NOT NULL,
			source_url VARCHAR(255) NOT NULL,
			target_url VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			total_items INT UNSIGNED DEFAULT 0,
			processed_items INT UNSIGNED DEFAULT 0,
			progress_percent INT UNSIGNED DEFAULT 0,
			current_batch INT UNSIGNED DEFAULT 0,
			error_count INT UNSIGNED DEFAULT 0,
			error_message LONGTEXT,
			checkpoint_data LONGTEXT DEFAULT NULL,
			checkpoint_version INT UNSIGNED DEFAULT 0,
			lock_uuid VARCHAR(36) DEFAULT NULL,
			locked_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME,
			completed_at DATETIME,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Create logs table (without FK for MySQL 5.5+ compatibility)
		// migration_id index added in DB_VERSION 1.3.0 for log retrieval by migration
		$logs_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}efs_migration_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_uid VARCHAR(50) NOT NULL,
			log_level VARCHAR(10) NOT NULL,
			category VARCHAR(50),
			message TEXT NOT NULL,
			context LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY migration_uid (migration_uid),
			KEY migration_id (migration_uid),
			KEY log_level (log_level),
			KEY created_at (created_at)
		) $charset_collate;";

		// Settings table for plugin configuration (target_url, migration_key, etc.)
		// Uses ON DUPLICATE KEY UPDATE via UNIQUE(setting_key), so dbDelta is safe to re-run.
		$settings_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}efs_settings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			setting_key VARCHAR(191) NOT NULL,
			setting_value LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY setting_key (setting_key)
		) $charset_collate;";

		// Execute CREATE TABLE statements directly
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $migrations_table );
		$wpdb->query( $logs_table );
		$wpdb->query( $settings_table );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// Add new columns to existing tables for upgrades (idempotent via SHOW COLUMNS check).
		self::maybe_add_columns();

		// Store version
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Log installation
		error_log( '[EFS] Database tables created/updated at ' . current_time( 'mysql' ) );
	}

	/**
	 * Add columns introduced in later schema versions to existing tables.
	 *
	 * Uses SHOW COLUMNS to check before each ALTER TABLE, making every
	 * operation safe to call repeatedly without errors.
	 */
	private static function maybe_add_columns() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}efs_migrations" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $existing_columns || 0 === count( $existing_columns ) ) {
			// Table not created yet — no ALTER needed, CREATE TABLE already has all columns.
			return;
		}

		// Columns added in 1.2.0 — checkpoint_data, checkpoint_version, lock_uuid, locked_at.
		$additions = array(
			'checkpoint_data'    => 'ALTER TABLE ' . $wpdb->prefix . 'efs_migrations ADD COLUMN checkpoint_data LONGTEXT DEFAULT NULL',
			'checkpoint_version' => 'ALTER TABLE ' . $wpdb->prefix . 'efs_migrations ADD COLUMN checkpoint_version INT UNSIGNED DEFAULT 0',
			'lock_uuid'          => 'ALTER TABLE ' . $wpdb->prefix . 'efs_migrations ADD COLUMN lock_uuid VARCHAR(36) DEFAULT NULL',
			'locked_at'          => 'ALTER TABLE ' . $wpdb->prefix . 'efs_migrations ADD COLUMN locked_at DATETIME DEFAULT NULL',
		);

		foreach ( $additions as $column => $sql ) {
			if ( ! in_array( $column, $existing_columns, true ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Atomically refresh the migration's updated_at timestamp.
	 *
	 * Performs a single direct UPDATE without read-modify-write to avoid race conditions.
	 * Only touches rows that are currently in_progress.
	 *
	 * @param string $migration_id Migration UID.
	 * @return bool True if at least one row was updated.
	 */
	public static function touch_progress_heartbeat( string $migration_id ): bool {
		global $wpdb;

		return (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				 SET updated_at = %s
				 WHERE migration_uid = %s
				   AND status = 'in_progress'",
				current_time( 'mysql' ),
				$migration_id
			)
		);
	}

	/**
	 * Atomically save checkpoint data with optimistic locking.
	 *
	 * The UPDATE only succeeds when checkpoint_version matches $expected_version,
	 * preventing two concurrent requests from overwriting each other.
	 *
	 * @param string $migration_id      Migration UID.
	 * @param array  $checkpoint_data   Checkpoint array to persist as JSON.
	 * @param int    $expected_version  Version that must match for the UPDATE to proceed.
	 * @return int Number of rows updated (1 = success, 0 = version conflict or not found).
	 */
	public static function save_checkpoint_atomic( string $migration_id, array $checkpoint_data, int $expected_version = 0 ): int {
		global $wpdb;

		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				 SET checkpoint_data    = %s,
				     checkpoint_version = checkpoint_version + 1,
				     updated_at         = %s
				 WHERE migration_uid       = %s
				   AND checkpoint_version  = %d",
				wp_json_encode( $checkpoint_data ),
				current_time( 'mysql' ),
				$migration_id,
				$expected_version
			)
		);

		return (int) $rows;
	}

	/**
	 * Get checkpoint data together with its version for optimistic locking.
	 *
	 * Returns null when no migration row exists. Returns an array with keys:
	 *   'data'    (array)  — decoded checkpoint, or empty array when column is NULL
	 *   'version' (int)    — current checkpoint_version value
	 *
	 * @param string $migration_id Migration UID.
	 * @return array{data: array, version: int}|null
	 */
	public static function get_checkpoint_with_version( string $migration_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT checkpoint_data, checkpoint_version FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$decoded = null;
		if ( ! empty( $row['checkpoint_data'] ) ) {
			$decoded = json_decode( $row['checkpoint_data'], true );
		}

		return array(
			'data'    => is_array( $decoded ) ? $decoded : array(),
			'version' => (int) ( $row['checkpoint_version'] ?? 0 ),
		);
	}

	/**
	 * Atomically save checkpoint data and progress state in a single DB transaction.
	 *
	 * Combines what would otherwise be two separate UPDATEs on wp_efs_migrations into
	 * one transaction so that checkpoint_data and processed_items/status are always
	 * consistent — even if the PHP process crashes between the two writes.
	 *
	 * The checkpoint UPDATE uses optimistic locking (expected_version must match the
	 * current checkpoint_version). If it matches zero rows (version conflict or no
	 * row), the transaction is rolled back and false is returned; the caller should
	 * fall back to the individual wp_options-based writes.
	 *
	 * @param string $migration_id      Migration UID.
	 * @param array  $checkpoint_data   Full checkpoint array to persist as JSON.
	 * @param int    $expected_version  checkpoint_version that must match for the save to proceed.
	 * @param int    $processed_items   Items processed so far (for progress_percent calculation).
	 * @param int    $total_items       Total items in this migration.
	 * @param string $status            Migration status string (e.g. 'in_progress').
	 * @return bool True if both UPDATEs committed, false on conflict or DB error.
	 */
	public static function save_checkpoint_and_progress(
		string $migration_id,
		array $checkpoint_data,
		int $expected_version,
		int $processed_items,
		int $total_items,
		string $status = 'in_progress'
	): bool {
		global $wpdb;

		$progress_percent = $total_items > 0
			? min( 100, (int) round( ( $processed_items / $total_items ) * 100 ) )
			: 0;
		$now              = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		// Write 1: checkpoint with optimistic locking (checkpoint_version must match).
		$cp_rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				 SET checkpoint_data    = %s,
				     checkpoint_version = checkpoint_version + 1,
				     updated_at         = %s
				 WHERE migration_uid      = %s
				   AND checkpoint_version = %d",
				wp_json_encode( $checkpoint_data ),
				$now,
				$migration_id,
				$expected_version
			)
		);

		if ( $cp_rows < 1 ) {
			// Version conflict or row missing — roll back (nothing changed) and signal caller.
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		// Write 2: progress counters and status (no version guard — row is guaranteed to exist).
		$prog_rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				 SET processed_items = %d,
				     total_items     = %d,
				     progress_percent = %d,
				     status          = %s,
				     updated_at      = %s
				 WHERE migration_uid = %s",
				$processed_items,
				$total_items,
				$progress_percent,
				$status,
				$now,
				$migration_id
			)
		);

		$wpdb->query( 'COMMIT' );

		return $prog_rows > 0;
	}

	/**
	 * Save checkpoint and refresh heartbeat in a best-effort transactional flow.
	 *
	 * @param string $migration_id     Migration UID.
	 * @param array  $checkpoint_data  Checkpoint payload.
	 * @param int    $expected_version Expected checkpoint version.
	 * @return int Number of rows updated by checkpoint write (1 on success).
	 */
	public static function save_checkpoint_with_heartbeat_transaction( string $migration_id, array $checkpoint_data, int $expected_version = 0 ): int {
		$rows = self::save_checkpoint_atomic( $migration_id, $checkpoint_data, $expected_version );
		if ( $rows > 0 ) {
			self::touch_progress_heartbeat( $migration_id );
		}

		return (int) $rows;
	}

	/**
	 * Persist progress data in options storage for compatibility.
	 *
	 * @param string $migration_id Migration UID.
	 * @param array  $progress_data Progress payload.
	 * @return bool True on success.
	 */
	public static function save_progress_data( string $migration_id, array $progress_data ): bool {
		return update_option( 'efs_progress_data_' . sanitize_key( $migration_id ), $progress_data, false );
	}

	/**
	 * Retrieve persisted progress data.
	 *
	 * @param string $migration_id Migration UID.
	 * @return array|null
	 */
	public static function get_progress_data( string $migration_id ): ?array {
		$data = get_option( 'efs_progress_data_' . sanitize_key( $migration_id ), null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Persist step state in options storage for compatibility.
	 *
	 * @param string $migration_id Migration UID.
	 * @param array  $steps_data Step payload.
	 * @return bool True on success.
	 */
	public static function save_steps_data( string $migration_id, array $steps_data ): bool {
		return update_option( 'efs_steps_data_' . sanitize_key( $migration_id ), $steps_data, false );
	}

	/**
	 * Retrieve persisted step state.
	 *
	 * @param string $migration_id Migration UID.
	 * @return array|null
	 */
	public static function get_steps_data( string $migration_id ): ?array {
		$data = get_option( 'efs_steps_data_' . sanitize_key( $migration_id ), null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Persist aggregated migration stats in options storage for compatibility.
	 *
	 * @param string $migration_id Migration UID.
	 * @param array  $stats_data Stats payload.
	 * @return bool True on success.
	 */
	public static function save_stats_data( string $migration_id, array $stats_data ): bool {
		return update_option( 'efs_stats_data_' . sanitize_key( $migration_id ), $stats_data, false );
	}

	/**
	 * Retrieve persisted migration stats.
	 *
	 * @param string $migration_id Migration UID.
	 * @return array|null
	 */
	public static function get_stats_data( string $migration_id ): ?array {
		$data = get_option( 'efs_stats_data_' . sanitize_key( $migration_id ), null );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Check if database is properly installed
	 *
	 * @return bool True if all required tables exist
	 */
	public static function is_installed() {
		global $wpdb;

		$migrations_exists = $wpdb->query(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'efs_migrations' )
			)
		) > 0;

		$logs_exists = $wpdb->query(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $wpdb->prefix . 'efs_migration_logs' )
			)
		) > 0;

		return $migrations_exists && $logs_exists;
	}

	/**
	 * Uninstall: Delete all plugin data (called by uninstall hook)
	 *
	 * WARNING: This completely removes all plugin data from database.
	 * Called only when plugin is deleted (not deactivated).
	 */
	public static function uninstall() {
		global $wpdb;

		// Drop tables
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}efs_migration_logs" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}efs_migrations" );

		// Delete all plugin options
		$efs_options = array(
			'efs_settings',
			'efs_db_version',
			'efs_migration_progress',
			'efs_migration_token',
			'efs_migration_token_value',
			'efs_migration_token_expires',
			'efs_private_key',
			'efs_error_log',
			'efs_api_key',
			'efs_import_api_key',
			'efs_export_api_key',
			'efs_migration_settings',
			'efs_feature_flags',
			'efs_audit_log',
			'efs_cors_allowed_origins',
			'efs_security_settings',
			'efs_security_log',
			'efs_migration_jwt_secret',
		);

		foreach ( $efs_options as $option ) {
			delete_option( $option );
		}

		// Delete transients
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_efs_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_efs_' ) . '%'
			)
		);

		// Delete user meta
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				'%' . $wpdb->esc_like( 'efs_' ) . '%'
			)
		);

		// Delete all Action Scheduler tasks related to this plugin
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE %s",
				'%' . $wpdb->esc_like( 'efs_' ) . '%'
			)
		);

		// Clear cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		error_log( '[EFS] Plugin completely uninstalled - all data removed at ' . current_time( 'mysql' ) );
	}

	/**
	 * Create a new migration record
	 *
	 * @param string $source_url Source site URL
	 * @param string $target_url Target site URL
	 * @return string|false Migration UID on success, false on failure
	 */
	public static function create_migration( $source_url, $target_url ) {
		global $wpdb;

		$migration_uid = wp_generate_uuid4();

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_uid,
				'source_url'    => esc_url_raw( $source_url ),
				'target_url'    => esc_url_raw( $target_url ),
				'status'        => 'pending',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return $inserted ? $migration_uid : false;
	}

	/**
	 * Update migration progress
	 *
	 * @param string $migration_uid Migration UID
	 * @param int    $processed Items processed so far
	 * @param int    $total Total items to process
	 * @return bool True on success
	 */
	public static function update_progress( $migration_uid, $processed, $total ) {
		global $wpdb;

		$progress_percent = $total > 0 ? intval( ( $processed / $total ) * 100 ) : 0;

		return (bool) $wpdb->update(
			$wpdb->prefix . 'efs_migrations',
			array(
				'processed_items'  => $processed,
				'total_items'      => $total,
				'progress_percent' => $progress_percent,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'migration_uid' => $migration_uid ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Log migration event
	 *
	 * @param string $migration_uid Migration UID
	 * @param string $level Log level (info, warning, error)
	 * @param string $message Message
	 * @param string $category Category (migration, content, media, etc)
	 * @param array  $context Additional context data
	 * @return bool True on success
	 */
	public static function log_event( $migration_uid, $level, $message, $category = null, $context = array() ) {
		global $wpdb;

		return (bool) $wpdb->insert(
			$wpdb->prefix . 'efs_migration_logs',
			array(
				'migration_uid' => $migration_uid,
				'log_level'     => sanitize_key( $level ),
				'category'      => sanitize_key( $category ),
				'message'       => wp_kses_post( $message ),
				'context'       => ! empty( $context ) ? wp_json_encode( $context ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get migration by UID
	 *
	 * @param string $migration_uid Migration UID
	 * @return array|null Migration record or null if not found
	 */
	public static function get_migration( $migration_uid ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_uid
			),
			ARRAY_A
		);
	}

	/**
	 * Update migration status
	 *
	 * @param string $migration_uid Migration UID
	 * @param string $status New status (pending, in_progress, completed, failed, canceled)
	 * @param string $error_message Optional error message
	 * @return bool True on success
	 */
	public static function update_status( $migration_uid, $status, $error_message = null ) {
		global $wpdb;

		$data = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => current_time( 'mysql' ),
		);

		$format = array( '%s', '%s' );

		if ( 'completed' === $status ) {
			$data['completed_at'] = current_time( 'mysql' );
		} elseif ( 'in_progress' === $status ) {
			$data['started_at'] = current_time( 'mysql' );
		}

		if ( ! empty( $error_message ) ) {
			$data['error_message'] = wp_kses_post( $error_message );
			$format[]              = '%s';
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'efs_migrations',
			$data,
			array( 'migration_uid' => $migration_uid ),
			$format,
			array( '%s' )
		);
	}
}
