<?php
/**
 * WordPress Migration Repository
 *
 * WordPress-backed implementation of Migration Repository using Options API.
 *
 * @package EtchFusion\Repositories
 * @since 1.0.0
 */

namespace Bricks2Etch\Repositories;

use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

/**
 * Class EFS_WordPress_Migration_Repository
 *
 * Manages migration progress, steps, stats, and tokens with transient caching.
 */
class EFS_WordPress_Migration_Repository implements Migration_Repository_Interface {

	/**
	 * Cache expiration for progress/steps (2 minutes for real-time updates).
	 */
	const CACHE_EXPIRATION_SHORT  = 120;
	const OPTION_PROGRESS         = 'efs_migration_progress';
	const OPTION_LAST_MIGRATION   = 'efs_last_migration';
	const OPTION_CURRENT_ID       = 'efs_current_migration_id';
	const OPTION_ACTIVE_MIGRATION = 'efs_active_migration';

	/**
	 * Cache expiration for stats/tokens (10 minutes).
	 */
	const CACHE_EXPIRATION_LONG = 600;

	/**
	 * Get migration progress.
	 *
	 * @return array Progress data array.
	 */
	public function get_progress(): array {
		$cache_key = 'efs_cache_migration_progress';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$progress   = get_option( self::OPTION_PROGRESS, array() );
		$current_id = get_option( self::OPTION_CURRENT_ID, '' );

		if ( ! is_array( $progress ) || empty( $progress ) ) {
			if ( '' === $current_id ) {
				$progress = array();
			} else {
				$progress = array( 'migrationId' => $current_id );
			}
		} else {
			$progress['migrationId'] = $current_id;
		}
		set_transient( $cache_key, $progress, self::CACHE_EXPIRATION_SHORT );

		return $progress;
	}

	/**
	 * Save migration progress.
	 *
	 * @param array $progress Progress data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_progress( array $progress ): bool {
		$this->invalidate_cache( 'efs_cache_migration_progress' );
		$migration_id = isset( $progress['migrationId'] ) ? $progress['migrationId'] : '';
		if ( $migration_id ) {
			update_option( self::OPTION_CURRENT_ID, $migration_id );
			$progress['migrationId'] = $migration_id;
		}

		if ( isset( $progress['last_migration'] ) ) {
			update_option( self::OPTION_LAST_MIGRATION, $progress['last_migration'] );
		}

		return update_option( self::OPTION_PROGRESS, $progress );
	}

	/**
	 * Delete migration progress.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_progress(): bool {
		$this->invalidate_cache( 'efs_cache_migration_progress' );
		delete_option( self::OPTION_CURRENT_ID );
		return delete_option( self::OPTION_PROGRESS );
	}

	/**
	 * Get migration steps.
	 *
	 * @return array Steps data array.
	 */
	public function get_steps(): array {
		$cache_key = 'efs_cache_migration_steps';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$steps = get_option( 'efs_migration_steps', array() );
		set_transient( $cache_key, $steps, self::CACHE_EXPIRATION_SHORT );

		return $steps;
	}

	/**
	 * Save migration steps.
	 *
	 * @param array $steps Steps data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_steps( array $steps ): bool {
		$this->invalidate_cache( 'efs_cache_migration_steps' );
		return update_option( 'efs_migration_steps', $steps );
	}

	/**
	 * Delete migration steps.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_steps(): bool {
		$this->invalidate_cache( 'efs_cache_migration_steps' );
		return delete_option( 'efs_migration_steps' );
	}

	/**
	 * Get migration statistics.
	 *
	 * @return array Stats data array.
	 */
	public function get_stats(): array {
		$cache_key = 'efs_cache_migration_stats';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$stats = get_option( 'efs_migration_stats', array() );
		set_transient( $cache_key, $stats, self::CACHE_EXPIRATION_LONG );

		return $stats;
	}

	/**
	 * Save migration statistics.
	 *
	 * @param array $stats Stats data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_stats( array $stats ): bool {
		$this->invalidate_cache( 'efs_cache_migration_stats' );
		return update_option( 'efs_migration_stats', $stats );
	}

	/**
	 * Get token data.
	 *
	 * @return array Token data array.
	 */
	public function get_token_data(): array {
		$cache_key = 'efs_cache_migration_token_data';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$token_data = get_option( 'efs_migration_token', array() );
		set_transient( $cache_key, $token_data, self::CACHE_EXPIRATION_LONG );

		return $token_data;
	}

	/**
	 * Save token data.
	 *
	 * @param array $token_data Token data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_token_data( array $token_data ): bool {
		$this->invalidate_cache( 'efs_cache_migration_token_data' );
		return update_option( 'efs_migration_token', $token_data );
	}

	/**
	 * Get token value.
	 *
	 * @return string Token value or empty string if not set.
	 */
	public function get_token_value(): string {
		$cache_key = 'efs_cache_migration_token_value';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$token = get_option( 'efs_migration_token_value', '' );
		set_transient( $cache_key, $token, self::CACHE_EXPIRATION_LONG );

		return $token;
	}

	/**
	 * Save token value.
	 *
	 * @param string $token Token value to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_token_value( string $token ): bool {
		$this->invalidate_cache( 'efs_cache_migration_token_value' );
		return update_option( 'efs_migration_token_value', $token );
	}

	/**
	 * Delete token data and value.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_token_data(): bool {
		$this->invalidate_cache( 'efs_cache_migration_token_data' );
		$this->invalidate_cache( 'efs_cache_migration_token_value' );

		$result = true;
		$result = delete_option( 'efs_migration_token' ) && $result;
		$result = delete_option( 'efs_migration_token_value' ) && $result;

		return $result;
	}

	/**
	 * Persist active migration metadata.
	 */
	public function save_active_migration( array $data ): bool {
		$this->invalidate_cache( 'efs_cache_active_migration' );
		return update_option( self::OPTION_ACTIVE_MIGRATION, $data );
	}

	/**
	 * Retrieve active migration metadata.
	 */
	public function get_active_migration(): array {
		$cache_key = 'efs_cache_active_migration';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = get_option( self::OPTION_ACTIVE_MIGRATION, array() );
		set_transient( $cache_key, $data, self::CACHE_EXPIRATION_SHORT );

		return $data;
	}

	/**
	 * Get imported data by type.
	 *
	 * @param string $type Data type: 'cpts', 'acf_field_groups', or 'metabox_configs'.
	 * @return array Imported data array.
	 */
	public function get_imported_data( string $type ): array {
		$option_key = $this->get_imported_data_option_key( $type );
		$cache_key  = 'efs_cache_imported_' . $type;
		$cached     = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$data = get_option( $option_key, array() );
		set_transient( $cache_key, $data, self::CACHE_EXPIRATION_LONG );

		return $data;
	}

	/**
	 * Save imported data by type.
	 *
	 * @param string $type Data type: 'cpts', 'acf_field_groups', or 'metabox_configs'.
	 * @param array  $data Data to save.
	 * @return bool True on success, false on failure.
	 */
	public function save_imported_data( string $type, array $data ): bool {
		$option_key = $this->get_imported_data_option_key( $type );
		$cache_key  = 'b2e_cache_imported_' . $type;

		$this->invalidate_cache( $cache_key );
		return update_option( $option_key, $data );
	}

	/**
	 * Cleanup expired token transients.
	 *
	 * @return int Number of expired transients deleted.
	 */
	public function cleanup_expired_tokens(): int {
		global $wpdb;

		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_efs_token_' ) . '%',
				time()
			)
		);

		return (int) $count;
	}

	/**
	 * Get option key for imported data type.
	 *
	 * @param string $type Data type.
	 * @return string Option key.
	 */
	private function get_imported_data_option_key( string $type ): string {
		$key_map = array(
			'cpts'             => 'efs_imported_cpts',
			'acf_field_groups' => 'efs_imported_acf_field_groups',
			'metabox_configs'  => 'efs_imported_metabox_configs',
		);

		return $key_map[ $type ] ?? 'efs_imported_' . $type;
	}

	/**
	 * Invalidate a specific cache key.
	 *
	 * @param string $cache_key Cache key to invalidate.
	 */
	private function invalidate_cache( string $cache_key ): void {
		delete_transient( $cache_key );
	}
}
