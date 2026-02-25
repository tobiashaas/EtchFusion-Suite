<?php
/**
 * Migration Logger Service
 *
 * Writes structured NDJSON log lines for migration runs to per-migration log files
 * in the WordPress uploads directory. Log files are only written when WP_DEBUG_LOG
 * is explicitly set to true.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_Logger
 *
 * Zero-dependency service for per-migration structured logging.
 */
class EFS_Migration_Logger {

	/** @var array */
	private $initialized_dirs = array();

	/**
	 * Sanitize a migration ID for use in a file name.
	 *
	 * @param string $id
	 * @return string
	 */
	private function sanitize_migration_id( string $id ): string {
		return preg_replace( '/[^a-zA-Z0-9]/', '-', $id );
	}

	/**
	 * Get the base log directory.
	 *
	 * @return string
	 */
	private function get_log_dir(): string {
		return wp_upload_dir()['basedir'] . '/efs-migration-logs';
	}

	/**
	 * Ensure a log directory exists and is protected from direct access.
	 *
	 * @param string $log_dir
	 */
	private function ensure_log_directory( string $log_dir ): void {
		if ( in_array( $log_dir, $this->initialized_dirs, true ) ) {
			return;
		}

		wp_mkdir_p( $log_dir );

		$htaccess = $log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, 'Deny from all' );
		}

		$index = $log_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		$this->initialized_dirs[] = $log_dir;
	}

	/**
	 * Get the full path to the log file for a migration.
	 *
	 * Validates that the resulting path stays inside the log directory as
	 * defense-in-depth against path traversal, even though sanitize_migration_id()
	 * already strips all non-alphanumeric characters.
	 *
	 * @param string $migration_id
	 * @return string
	 */
	public function get_log_path( string $migration_id ): string {
		$log_dir   = $this->get_log_dir();
		$sanitized = $this->sanitize_migration_id( $migration_id );
		$log_path  = $log_dir . '/migration-' . $sanitized . '.log';

		// Normalize both paths using WordPress helper (handles mixed separators on Windows).
		$norm_dir  = trailingslashit( wp_normalize_path( $log_dir ) );
		$norm_path = wp_normalize_path( $log_path );

		if ( 0 !== strpos( $norm_path, $norm_dir ) ) {
			// Path traversal detected – return a safe fallback that never matches a real file.
			return $log_dir . '/invalid.log';
		}

		return $log_path;
	}

	/**
	 * Get the URL to the log file for a migration.
	 *
	 * @param string $migration_id
	 * @return string
	 */
	public function get_log_url( string $migration_id ): string {
		$sanitized = $this->sanitize_migration_id( $migration_id );
		return wp_upload_dir()['baseurl'] . '/efs-migration-logs/migration-' . $sanitized . '.log';
	}

	/**
	 * Delete the log file for a migration.
	 *
	 * @param string $migration_id
	 */
	public function delete_log( string $migration_id ): void {
		$path = $this->get_log_path( $migration_id );
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Delete all migration log files from the log directory.
	 *
	 * Only removes files matching the expected `migration-*.log` pattern
	 * so that the .htaccess / index.php guard files are left intact.
	 */
	public function delete_all_logs(): void {
		$log_dir = $this->get_log_dir();
		if ( ! is_dir( $log_dir ) ) {
			return;
		}
		$files = glob( $log_dir . '/migration-*.log' );
		if ( ! is_array( $files ) ) {
			return;
		}
		foreach ( $files as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Append a structured NDJSON log line to the migration log file.
	 *
	 * Only writes when WP_DEBUG_LOG is explicitly true.
	 *
	 * @param string $migration_id
	 * @param string $level   Log level (e.g. 'info', 'warning', 'error').
	 * @param string $message Human-readable log message.
	 * @param array  $context Optional structured context data.
	 */
	public function log( string $migration_id, string $level, string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || WP_DEBUG_LOG !== true ) {
			return;
		}

		$line = json_encode(
			array(
				'ts'    => current_time( 'Y-m-d H:i:s' ),
				'level' => $level,
				'msg'   => $message,
				'ctx'   => $context,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$log_path = $this->get_log_path( $migration_id );
		$this->ensure_log_directory( dirname( $log_path ) );
		file_put_contents( $log_path, $line . "\n", FILE_APPEND | LOCK_EX );
	}
}
