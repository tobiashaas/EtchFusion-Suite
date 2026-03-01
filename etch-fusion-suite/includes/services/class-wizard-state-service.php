<?php
/**
 * Wizard State Service
 *
 * Persists migration wizard state in user-scoped transients.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Wizard_State_Service
 */
class EFS_Wizard_State_Service {

	/**
	 * Wizard state transient prefix.
	 */
	private const TRANSIENT_PREFIX = 'efs_wizard_state_';

	/**
	 * Wizard state expiration (30 minutes).
	 */
	private const EXPIRATION = 30 * MINUTE_IN_SECONDS;

	/**
	 * Save wizard state.
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce   Wizard nonce.
	 * @param array  $state   Partial or full wizard state.
	 * @return array|false Saved state on success, false on failure.
	 */
	public function save_state( int $user_id, string $nonce, array $state ) {
		$user_id = absint( $user_id );
		$nonce   = $this->sanitize_nonce( $nonce );

		if ( $user_id <= 0 || '' === $nonce ) {
			return false;
		}

		$current = $this->get_state( $user_id, $nonce );
		$merged  = $this->merge_state( $current, $state );

		$key    = $this->get_transient_key( $user_id, $nonce );
		$stored = set_transient( $key, $merged, self::EXPIRATION );

		if ( $stored ) {
			return $merged;
		}

		// set_transient() returns false both when the write fails AND when WordPress
		// skips the DB write because the serialized value is unchanged (update_option
		// optimization). We cannot distinguish the two cases without a second read,
		// so we verify the transient still exists — if it does, the state is safe
		// (either just written or already current) and we report success.
		if ( false !== get_transient( $key ) ) {
			return $merged;
		}

		return false;
	}

	/**
	 * Get wizard state.
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce   Wizard nonce.
	 * @return array
	 */
	public function get_state( int $user_id, string $nonce ): array {
		$user_id = absint( $user_id );
		$nonce   = $this->sanitize_nonce( $nonce );

		if ( $user_id <= 0 || '' === $nonce ) {
			return $this->default_state();
		}

		$key   = $this->get_transient_key( $user_id, $nonce );
		$state = get_transient( $key );

		if ( ! is_array( $state ) ) {
			return $this->default_state();
		}

		return $this->normalize_state( $state );
	}

	/**
	 * Clear wizard state.
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce   Wizard nonce.
	 * @return bool
	 */
	public function clear_state( int $user_id, string $nonce ): bool {
		$user_id = absint( $user_id );
		$nonce   = $this->sanitize_nonce( $nonce );

		if ( $user_id <= 0 || '' === $nonce ) {
			return false;
		}

		$key = $this->get_transient_key( $user_id, $nonce );
		if ( false === get_transient( $key ) ) {
			return true;
		}

		return delete_transient( $key );
	}

	/**
	 * Update only current wizard step.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $nonce        Wizard nonce.
	 * @param int    $current_step Current step (1-4).
	 * @return array|false Updated state on success, false on failure.
	 */
	public function update_step( int $user_id, string $nonce, int $current_step ) {
		return $this->save_state(
			$user_id,
			$nonce,
			array(
				'current_step' => $current_step,
			)
		);
	}

	/**
	 * Get transient expiration in seconds.
	 *
	 * @return int
	 */
	public function get_expiration_seconds(): int {
		return self::EXPIRATION;
	}

	/**
	 * Build transient key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $nonce   Wizard nonce.
	 * @return string
	 */
	private function get_transient_key( int $user_id, string $nonce ): string {
		return self::TRANSIENT_PREFIX . $user_id . '_' . $nonce;
	}

	/**
	 * Sanitize nonce used in transient key.
	 *
	 * @param string $nonce Raw nonce.
	 * @return string
	 */
	private function sanitize_nonce( string $nonce ): string {
		return sanitize_key( $nonce );
	}

	/**
	 * Merge stored and incoming state then normalize.
	 *
	 * @param array $current Existing state.
	 * @param array $incoming Incoming partial/full state.
	 * @return array
	 */
	private function merge_state( array $current, array $incoming ): array {
		$merged  = $this->default_state();
		$current = $this->normalize_state( $current );
		$merged  = array_merge( $merged, $current );

		if ( array_key_exists( 'current_step', $incoming ) ) {
			$merged['current_step'] = $incoming['current_step'];
		}

		if ( array_key_exists( 'migration_url', $incoming ) ) {
			$merged['migration_url'] = $incoming['migration_url'];
		}

		if ( array_key_exists( 'migration_key', $incoming ) ) {
			$merged['migration_key'] = $incoming['migration_key'];
		}

		if ( array_key_exists( 'target_url', $incoming ) ) {
			$merged['target_url'] = $incoming['target_url'];
		}

		if ( array_key_exists( 'discovery_data', $incoming ) ) {
			$merged['discovery_data'] = is_array( $incoming['discovery_data'] ) ? $incoming['discovery_data'] : array();
		}

		if ( array_key_exists( 'selected_post_types', $incoming ) ) {
			$merged['selected_post_types'] = is_array( $incoming['selected_post_types'] ) ? $incoming['selected_post_types'] : array();
		}

		if ( array_key_exists( 'post_type_mappings', $incoming ) ) {
			$merged['post_type_mappings'] = is_array( $incoming['post_type_mappings'] ) ? $incoming['post_type_mappings'] : array();
		}

		if ( array_key_exists( 'include_media', $incoming ) ) {
			$merged['include_media'] = (bool) $incoming['include_media'];
		}

		if ( array_key_exists( 'batch_size', $incoming ) ) {
			$merged['batch_size'] = $incoming['batch_size'];
		}

		if ( array_key_exists( 'mode', $incoming ) ) {
			$merged['mode'] = in_array( $incoming['mode'], array( 'browser', 'headless' ), true ) ? $incoming['mode'] : 'headless';
		}

		return $this->normalize_state( $merged );
	}

	/**
	 * Normalize state to expected schema.
	 *
	 * @param array $state Raw state.
	 * @return array
	 */
	private function normalize_state( array $state ): array {
		$normalized = $this->default_state();

		$current_step = isset( $state['current_step'] ) ? absint( $state['current_step'] ) : $normalized['current_step'];
		if ( $current_step < 1 ) {
			$current_step = 1;
		}
		if ( $current_step > 4 ) {
			$current_step = 4;
		}

		$migration_url = isset( $state['migration_url'] ) ? esc_url_raw( (string) $state['migration_url'] ) : '';
		$migration_key = isset( $state['migration_key'] ) ? sanitize_text_field( (string) $state['migration_key'] ) : '';
		$target_url    = isset( $state['target_url'] ) ? esc_url_raw( (string) $state['target_url'] ) : '';
		$discovery     = isset( $state['discovery_data'] ) && is_array( $state['discovery_data'] ) ? $this->sanitize_recursive( $state['discovery_data'] ) : array();
		$selected      = isset( $state['selected_post_types'] ) && is_array( $state['selected_post_types'] ) ? array_values( array_map( 'sanitize_key', $state['selected_post_types'] ) ) : array();
		$mappings      = isset( $state['post_type_mappings'] ) && is_array( $state['post_type_mappings'] ) ? $this->sanitize_recursive( $state['post_type_mappings'] ) : array();
		$include_media = isset( $state['include_media'] ) ? (bool) $state['include_media'] : true;

		$batch_size = isset( $state['batch_size'] ) ? absint( $state['batch_size'] ) : 50;
		if ( $batch_size < 1 ) {
			$batch_size = 1;
		}
		if ( $batch_size > 500 ) {
			$batch_size = 500;
		}

		$mode = isset( $state['mode'] ) && in_array( $state['mode'], array( 'browser', 'headless' ), true )
			? $state['mode']
			: 'headless';

		$normalized['current_step']        = $current_step;
		$normalized['migration_url']       = $migration_url;
		$normalized['migration_key']       = $migration_key;
		$normalized['target_url']          = $target_url;
		$normalized['discovery_data']      = $discovery;
		$normalized['selected_post_types'] = $selected;
		$normalized['post_type_mappings']  = $mappings;
		$normalized['include_media']       = $include_media;
		$normalized['batch_size']          = $batch_size;
		$normalized['mode']                = $mode;

		return $normalized;
	}

	/**
	 * Default state schema.
	 *
	 * @return array
	 */
	private function default_state(): array {
		return array(
			'current_step'        => 1,
			'migration_url'       => '',
			'migration_key'       => '',
			'target_url'          => '',
			'discovery_data'      => array(),
			'selected_post_types' => array(),
			'post_type_mappings'  => array(),
			'include_media'       => true,
			'batch_size'          => 50,
			'mode'                => 'headless',
		);
	}

	/**
	 * Recursively sanitize mixed arrays.
	 *
	 * @param array $value Raw array.
	 * @return array
	 */
	private function sanitize_recursive( array $value ): array {
		$sanitized = array();

		foreach ( $value as $key => $item ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : $key;

			if ( is_array( $item ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_recursive( $item );
				continue;
			}

			if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
				$sanitized[ $clean_key ] = $item;
				continue;
			}

			if ( is_string( $item ) ) {
				$sanitized[ $clean_key ] = sanitize_text_field( $item );
				continue;
			}

			$sanitized[ $clean_key ] = null;
		}

		return $sanitized;
	}
}
