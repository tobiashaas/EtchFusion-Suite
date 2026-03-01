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
	 * Current database schema version
	 */
	const DB_VERSION = '1.0.0';

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

		// Migration state table
		$migrations_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}efs_migrations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_uid VARCHAR(36) UNIQUE NOT NULL,
			source_url VARCHAR(255) NOT NULL,
			target_url VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			total_items INT UNSIGNED DEFAULT 0,
			processed_items INT UNSIGNED DEFAULT 0,
			progress_percent INT UNSIGNED DEFAULT 0,
			current_batch INT UNSIGNED DEFAULT 0,
			error_count INT UNSIGNED DEFAULT 0,
			error_message LONGTEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME,
			completed_at DATETIME,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY migration_uid (migration_uid),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		// Migration log table
		$logs_table = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}efs_migration_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			migration_uid VARCHAR(36) NOT NULL,
			log_level VARCHAR(10) NOT NULL,
			category VARCHAR(50),
			message TEXT NOT NULL,
			context JSON,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY migration_uid (migration_uid),
			KEY log_level (log_level),
			KEY created_at (created_at),
			FOREIGN KEY (migration_uid) REFERENCES {$wpdb->prefix}efs_migrations(migration_uid) ON DELETE CASCADE
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $migrations_table );
		dbDelta( $logs_table );

		// Store version
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Log installation
		error_log( '[EFS] Database tables created/updated at ' . current_time( 'mysql' ) );
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
