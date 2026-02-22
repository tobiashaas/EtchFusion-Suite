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
	const OPTION_RECEIVING_STATE  = 'efs_receiving_migration';
	const OPTION_CHECKPOINT       = 'efs_migration_checkpoint';

	/**
	 * Cache expiration for stats/tokens (10 minutes).
	 */
	const CACHE_EXPIRATION_LONG   = 600;
	const OPTION_TOKEN_DATA       = 'efs_migration_token';
	const OPTION_TOKEN_VALUE      = 'efs_migration_token_value';
	const OPTION_TOKEN_EXPIRES    = 'efs_migration_token_expires';
	const RECEIVING_STALE_TTL     = 300;
	const RECEIVING_RETENTION_TTL = 3600;

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

		$token_data = get_option( self::OPTION_TOKEN_DATA, array() );

		if ( is_array( $token_data ) && ! isset( $token_data['expires_timestamp'] ) && isset( $token_data['expires_at'] ) ) {
			$expires = strtotime( (string) $token_data['expires_at'] );
			if ( false !== $expires ) {
				$token_data['expires_timestamp'] = (int) $expires;
			}
		}

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
		if ( isset( $token_data['expires_timestamp'] ) ) {
			update_option( self::OPTION_TOKEN_EXPIRES, (int) $token_data['expires_timestamp'] );
		}
		return update_option( self::OPTION_TOKEN_DATA, $token_data );
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

		$token = get_option( self::OPTION_TOKEN_VALUE, '' );
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
		return update_option( self::OPTION_TOKEN_VALUE, $token );
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
		$result = delete_option( self::OPTION_TOKEN_DATA ) && $result;
		$result = delete_option( self::OPTION_TOKEN_VALUE ) && $result;
		$result = delete_option( self::OPTION_TOKEN_EXPIRES ) && $result;

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
	 * Persist receiving state metadata.
	 *
	 * @param array $state Receiving state.
	 * @return bool
	 */
	public function save_receiving_state( array $state ): bool {
		$this->invalidate_cache( 'efs_cache_receiving_state' );

		$current = get_option( self::OPTION_RECEIVING_STATE, array() );
		$current = is_array( $current ) ? $current : array();
		$state   = $this->normalize_receiving_state( array_merge( $current, $state ) );

		return update_option( self::OPTION_RECEIVING_STATE, $state );
	}

	/**
	 * Retrieve normalized receiving state with stale + cleanup handling.
	 *
	 * @return array
	 */
	public function get_receiving_state(): array {
		$cache_key = 'efs_cache_receiving_state';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$state = get_option( self::OPTION_RECEIVING_STATE, array() );
		$state = is_array( $state ) ? $this->normalize_receiving_state( $state ) : $this->idle_receiving_state();

		$last_activity_ts = $this->timestamp_from_maybe_string( $state['last_activity'] ?? '' );
		$is_stale         = $last_activity_ts > 0 && ( time() - $last_activity_ts ) >= self::RECEIVING_STALE_TTL;

		if ( 'receiving' === $state['status'] && $is_stale ) {
			$state['status']       = 'stale';
			$state['is_stale']     = true;
			$state['last_updated'] = current_time( 'mysql' );
			update_option( self::OPTION_RECEIVING_STATE, $state );
		}

		$status = $state['status'] ?? 'idle';
		if ( in_array( $status, array( 'stale', 'completed' ), true ) ) {
			$reference_ts = $this->timestamp_from_maybe_string( $state['last_updated'] ?? '' );
			if ( $reference_ts <= 0 ) {
				$reference_ts = $last_activity_ts;
			}

			if ( $reference_ts > 0 && ( time() - $reference_ts ) >= self::RECEIVING_RETENTION_TTL ) {
				$this->clear_receiving_state();
				$state = $this->idle_receiving_state();
			}
		}

		$state['is_stale'] = 'stale' === $state['status'];
		set_transient( $cache_key, $state, self::CACHE_EXPIRATION_SHORT );

		return $state;
	}

	/**
	 * Clear receiving state.
	 *
	 * @return bool
	 */
	public function clear_receiving_state(): bool {
		$this->invalidate_cache( 'efs_cache_receiving_state' );
		return delete_option( self::OPTION_RECEIVING_STATE );
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
	 * Save migration checkpoint for JS-driven batch loop.
	 *
	 * @param array $checkpoint Checkpoint data.
	 * @return bool True on success, false on failure.
	 */
	public function save_checkpoint( array $checkpoint ): bool {
		$this->invalidate_cache( 'efs_cache_migration_checkpoint' );
		return update_option( self::OPTION_CHECKPOINT, $checkpoint );
	}

	/**
	 * Get migration checkpoint.
	 *
	 * @return array Checkpoint data, or empty array if none exists.
	 */
	public function get_checkpoint(): array {
		$cache_key = 'efs_cache_migration_checkpoint';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		$checkpoint = get_option( self::OPTION_CHECKPOINT, array() );
		$checkpoint = is_array( $checkpoint ) ? $checkpoint : array();
		set_transient( $cache_key, $checkpoint, self::CACHE_EXPIRATION_SHORT );

		return $checkpoint;
	}

	/**
	 * Delete migration checkpoint.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function delete_checkpoint(): bool {
		$this->invalidate_cache( 'efs_cache_migration_checkpoint' );
		return delete_option( self::OPTION_CHECKPOINT );
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

	/**
	 * Normalize receiving state into canonical schema.
	 *
	 * @param array $state Raw state payload.
	 * @return array
	 */
	private function normalize_receiving_state( array $state ): array {
		$now          = current_time( 'mysql' );
		$defaults     = $this->idle_receiving_state();
		$normalized   = array_merge( $defaults, $state );
		$status       = sanitize_key( (string) ( $normalized['status'] ?? 'idle' ) );
		$allowed      = array( 'idle', 'receiving', 'stale', 'completed' );
		$is_receiving = 'receiving' === $status;

		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'idle';
		}

		$normalized['status']         = $status;
		$normalized['source_site']    = esc_url_raw( (string) ( $normalized['source_site'] ?? '' ) );
		$normalized['migration_id']   = sanitize_text_field( (string) ( $normalized['migration_id'] ?? '' ) );
		$normalized['current_phase']  = sanitize_key( (string) ( $normalized['current_phase'] ?? '' ) );
		$normalized['items_received'] = max( 0, absint( $normalized['items_received'] ?? 0 ) );
		$normalized['items_total']    = max( 0, absint( $normalized['items_total'] ?? 0 ) );
		$normalized['started_at']     = sanitize_text_field( (string) ( $normalized['started_at'] ?? '' ) );
		$normalized['last_activity']  = sanitize_text_field( (string) ( $normalized['last_activity'] ?? '' ) );
		$normalized['last_updated']   = sanitize_text_field( (string) ( $normalized['last_updated'] ?? '' ) );

		if ( '' === $normalized['started_at'] && $is_receiving ) {
			$normalized['started_at'] = $now;
		}
		if ( '' === $normalized['last_activity'] && $is_receiving ) {
			$normalized['last_activity'] = $now;
		}
		if ( '' === $normalized['last_updated'] ) {
			if ( $is_receiving ) {
				$normalized['last_updated'] = $now;
			} elseif ( '' !== $normalized['last_activity'] ) {
				$normalized['last_updated'] = $normalized['last_activity'];
			} else {
				$normalized['last_updated'] = $now;
			}
		}

		return $normalized;
	}

	/**
	 * Return idle receiving state shape.
	 *
	 * @return array
	 */
	private function idle_receiving_state(): array {
		return array(
			'status'         => 'idle',
			'source_site'    => '',
			'migration_id'   => '',
			'started_at'     => '',
			'last_activity'  => '',
			'last_updated'   => '',
			'current_phase'  => '',
			'items_received' => 0,
			'items_total'    => 0,
			'is_stale'       => false,
		);
	}

	/**
	 * Convert possibly formatted time value to unix timestamp.
	 *
	 * @param string $value Time value.
	 * @return int
	 */
	private function timestamp_from_maybe_string( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? 0 : (int) $timestamp;
	}
}
