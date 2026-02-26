<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Core\EFS_Migration_Manager;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Api\EFS_API_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Controller {
	private $manager;
	private $api_client;
	private $token_manager;

	/**
	 * Constructor
	 *
	 * @param EFS_Migration_Manager          $manager
	 * @param EFS_API_Client                 $api_client
	 * @param EFS_Migration_Token_Manager|null $token_manager Optional; used to decode migration keys for target-URL resolution.
	 */
	public function __construct( EFS_Migration_Manager $manager, EFS_API_Client $api_client, ?EFS_Migration_Token_Manager $token_manager = null ) {
		$this->manager       = $manager;
		$this->api_client    = $api_client;
		$this->token_manager = $token_manager;
	}

	/**
	 * Validate if a string is a valid UUID v4 format.
	 *
	 * @param string $value The value to validate.
	 * @return bool True if valid UUID v4 format, false otherwise.
	 */
	private function is_valid_uuid( $value ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	public function start_migration( array $data ) {
		$migration_key = isset( $data['migration_key'] ) ? sanitize_textarea_field( $data['migration_key'] ) : '';
		$batch         = isset( $data['batch_size'] ) ? intval( $data['batch_size'] ) : 50;

		if ( empty( $migration_key ) ) {
			$settings      = get_option( 'efs_settings', array() );
			$migration_key = isset( $settings['migration_key'] ) ? sanitize_textarea_field( $settings['migration_key'] ) : '';
		}

		if ( empty( $migration_key ) ) {
			return new \WP_Error( 'missing_migration_key', __( 'Migration key is required to start the migration.', 'etch-fusion-suite' ) );
		}

		$target_url = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';

		if ( empty( $target_url ) && $this->token_manager ) {
			$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );
			if ( ! is_wp_error( $decoded ) && isset( $decoded['payload']['target_url'] ) ) {
				$target_url = esc_url_raw( $decoded['payload']['target_url'] );
			}
		}

		$target_url = ! empty( $target_url ) ? $target_url : $this->extract_target_url_from_settings( $migration_key );

		if ( empty( $target_url ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Target URL could not be determined from the migration key.', 'etch-fusion-suite' ) );
		}

		$raw_mode = isset( $data['mode'] ) ? sanitize_key( $data['mode'] ) : 'browser';
		$mode     = in_array( $raw_mode, array( 'browser', 'headless' ), true ) ? $raw_mode : 'browser';

		$options = array(
			'selected_post_types'  => array(),
			'post_type_mappings'   => array(),
			'include_media'        => true,
			'restrict_css_to_used' => true,
			'mode'                 => $mode,
		);
		if ( isset( $data['include_media'] ) ) {
			$options['include_media'] = filter_var( $data['include_media'], FILTER_VALIDATE_BOOLEAN );
		}
		$options['restrict_css_to_used'] = isset( $data['restrict_css_to_used'] )
			? filter_var( $data['restrict_css_to_used'], FILTER_VALIDATE_BOOLEAN )
			: true;
		if ( ! empty( $data['selected_post_types'] ) && is_array( $data['selected_post_types'] ) ) {
			$options['selected_post_types'] = array_map( 'sanitize_key', $data['selected_post_types'] );
		}
		if ( ! empty( $data['post_type_mappings'] ) && is_array( $data['post_type_mappings'] ) ) {
			foreach ( $data['post_type_mappings'] as $source => $target ) {
				$options['post_type_mappings'][ sanitize_key( (string) $source ) ] = sanitize_key( (string) $target );
			}
		}
		if ( ! empty( $options['selected_post_types'] ) && ! empty( $options['post_type_mappings'] ) ) {
			$validation_errors = $this->validate_post_type_mappings(
				$options['selected_post_types'],
				$options['post_type_mappings'],
				$target_url,
				$migration_key
			);

			if ( ! empty( $validation_errors ) ) {
				return new \WP_Error(
					'invalid_post_type_mappings',
					__( 'Invalid post type mappings: ', 'etch-fusion-suite' ) . implode( '; ', $validation_errors ),
					array( 'status' => 400 )
				);
			}
		}

		$nonce  = isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : '';
		$result = $this->manager->start_migration_async( $migration_key, $target_url, $batch, $options, $nonce );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'message'     => isset( $result['message'] ) ? $result['message'] : __( 'Migration started.', 'etch-fusion-suite' ),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'token'       => $migration_key,
			'queued'      => isset( $result['queued'] ) ? (bool) $result['queued'] : false,
		);
	}

	/**
	 * Run migration execution in background (called by efs_run_migration_background).
	 *
	 * @param string $migration_id
	 * @param string $bg_token One-time token from transient.
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '', $bg_token = '' ) {
		if ( '' === $migration_id || '' === $bg_token ) {
			return new \WP_Error( 'invalid_request', __( 'Missing migration ID or token.', 'etch-fusion-suite' ) );
		}
		$stored = get_transient( 'efs_bg_' . $migration_id );
		if ( false === $stored || $stored !== $bg_token ) {
			return new \WP_Error( 'invalid_token', __( 'Invalid or expired background token.', 'etch-fusion-suite' ) );
		}
		delete_transient( 'efs_bg_' . $migration_id );
		return $this->manager->run_migration_execution( $migration_id );
	}

	public function get_progress( array $data = array() ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		if ( ! empty( $migration_id ) && ! $this->is_valid_uuid( $migration_id ) ) {
			return new \WP_Error( 'invalid_migration_id', __( 'Invalid migration ID format.', 'etch-fusion-suite' ) );
		}
		$result = $this->manager->get_progress( $migration_id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'migrationId' => $migration_id ) );
			return $result;
		}

		$progress          = isset( $result['progress'] ) && is_array( $result['progress'] ) ? $result['progress'] : array();
		$steps             = isset( $result['steps'] ) && is_array( $result['steps'] ) ? $result['steps'] : array();
		$progress['steps'] = $steps;

		return array(
			'progress'                 => $progress,
			'steps'                    => $steps,
			'migrationId'              => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'last_updated'             => isset( $progress['last_updated'] ) ? $progress['last_updated'] : '',
			'is_stale'                 => ! empty( $progress['is_stale'] ),
			'estimated_time_remaining' => isset( $progress['estimated_time_remaining'] ) ? $progress['estimated_time_remaining'] : null,
			'completed'                => ! empty( $result['completed'] ),
		);
	}

	/**
	 * Detect currently running migration for dashboard auto-resume.
	 *
	 * @return array
	 */
	public function detect_in_progress_migration(): array {
		$service = $this->get_migration_service();
		if ( ! $service || ! method_exists( $service, 'detect_in_progress_migration' ) ) {
			$progress = $this->get_progress();
			$status   = isset( $progress['progress']['status'] ) ? $progress['progress']['status'] : 'idle';
			$running  = in_array( $status, array( 'running', 'receiving' ), true );

			return array(
				'migrationId' => $running ? ( $progress['migrationId'] ?? '' ) : '',
				'progress'    => $progress['progress'] ?? array(),
				'steps'       => $progress['steps'] ?? array(),
				'is_stale'    => ! empty( $progress['is_stale'] ),
				'resumable'   => $running,
			);
		}

		return $service->detect_in_progress_migration();
	}

	public function process_batch( array $data ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		if ( ! empty( $migration_id ) && ! $this->is_valid_uuid( $migration_id ) ) {
			return new \WP_Error( 'invalid_migration_id', __( 'Invalid migration ID format.', 'etch-fusion-suite' ) );
		}
		$batch               = isset( $data['batch'] ) ? (array) $data['batch'] : array();
		$batch['batch_size'] = isset( $data['batch_size'] ) ? max( 1, (int) $data['batch_size'] ) : 10;
		$result              = $this->manager->process_batch( $migration_id, $batch );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'migrationId' => $migration_id ) );
			return $result;
		}
		return array(
			'progress'        => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'           => isset( $result['steps'] ) ? $result['steps'] : array(),
			'migrationId'     => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'completed'       => ! empty( $result['completed'] ),
			'remaining'       => isset( $result['remaining'] ) ? (int) $result['remaining'] : 0,
			'current_item'    => isset( $result['current_item'] ) ? $result['current_item'] : array(),
			'memory_pressure' => ! empty( $result['memory_pressure'] ),
		);
	}

	/**
	 * Resume a JS-driven batch loop after timeout or error.
	 *
	 * @param array $data Request data. Expects 'migrationId'.
	 * @return array|\WP_Error
	 */
	public function resume_migration( array $data ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		if ( empty( $migration_id ) ) {
			return new \WP_Error( 'missing_migration_id', __( 'Migration ID is required.', 'etch-fusion-suite' ) );
		}
		if ( ! $this->is_valid_uuid( $migration_id ) ) {
			return new \WP_Error( 'invalid_migration_id', __( 'Invalid migration ID format.', 'etch-fusion-suite' ) );
		}
		$result = $this->manager->resume_migration_execution( $migration_id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'migrationId' => $migration_id ) );
			return $result;
		}
		return array(
			'message'     => isset( $result['message'] ) ? $result['message'] : __( 'Migration resumed.', 'etch-fusion-suite' ),
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : $migration_id,
			'resumed'     => ! empty( $result['resumed'] ),
		);
	}

	public function cancel_migration( array $data = array() ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		if ( ! empty( $migration_id ) && ! $this->is_valid_uuid( $migration_id ) ) {
			return new \WP_Error( 'invalid_migration_id', __( 'Invalid migration ID format.', 'etch-fusion-suite' ) );
		}
		$result = $this->manager->cancel_migration( $migration_id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'migrationId' => $migration_id ) );
			return $result;
		}
		return array(
			'message'     => isset( $result['message'] ) ? $result['message'] : __( 'Migration cancelled.', 'etch-fusion-suite' ),
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'completed'   => ! empty( $result['completed'] ),
		);
	}

	public function generate_report() {
		$result = $this->manager->generate_report();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'report' => $result,
		);
	}

	public function generate_migration_key( array $data ) {
		return $this->settings_controller()->generate_migration_key( $data );
	}

	private function extract_target_url_from_settings( $migration_key ) {
		$settings = get_option( 'efs_settings', array() );
		$target   = isset( $settings['target_url'] ) ? esc_url_raw( $settings['target_url'] ) : '';

		if ( $target ) {
			return $target;
		}

		if ( ! $this->token_manager ) {
			return '';
		}

		$decoded = $this->token_manager->decode_migration_key_locally( $migration_key );
		if ( is_wp_error( $decoded ) ) {
			return '';
		}

		return isset( $decoded['payload']['target_url'] ) ? esc_url_raw( $decoded['payload']['target_url'] ) : '';
	}

	/**
	 * Validate post type mappings against available target post types.
	 *
	 * @param array  $selected_post_types Source post types selected for migration.
	 * @param array  $post_type_mappings  Source => target post type mappings.
	 * @param string $target_url          Target site URL.
	 * @param string $migration_key       Migration key for API auth.
	 * @return array
	 */
	private function validate_post_type_mappings( $selected_post_types, $post_type_mappings, $target_url, $migration_key ) {
		$errors = array();

		if ( empty( $selected_post_types ) ) {
			return $errors;
		}

		$available_result = $this->api_client->get_target_post_types( $target_url, $migration_key );
		if ( is_wp_error( $available_result ) ) {
			$errors[] = 'Unable to fetch target post types: ' . $available_result->get_error_message();
			return $errors;
		}

		$available_slugs = array();
		if ( isset( $available_result['post_types'] ) && is_array( $available_result['post_types'] ) ) {
			foreach ( $available_result['post_types'] as $pt ) {
				if ( isset( $pt['slug'] ) ) {
					$available_slugs[] = $pt['slug'];
				}
			}
		}

		if ( empty( $available_slugs ) ) {
			$errors[] = 'No post types available on target site';
			return $errors;
		}

		foreach ( $selected_post_types as $source_type ) {
			if ( ! isset( $post_type_mappings[ $source_type ] ) || '' === $post_type_mappings[ $source_type ] ) {
				/* translators: %s is the source post type slug */
				$errors[] = sprintf( __( 'Missing mapping for post type: %s', 'etch-fusion-suite' ), $source_type );
				continue;
			}

			$target_type = $post_type_mappings[ $source_type ];
			if ( ! in_array( $target_type, $available_slugs, true ) ) {
				/* translators: 1: source post type, 2: target post type */
				$errors[] = sprintf( __( 'Invalid target post type "%2$s" for source type "%1$s" (not available on target)', 'etch-fusion-suite' ), $source_type, $target_type );
			}
		}

		return $errors;
	}

	private function settings_controller() {
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'settings_controller' ) ) {
				return $container->get( 'settings_controller' );
			}
		}

		return null;
	}

	/**
	 * Resolve migration service from container.
	 *
	 * @return mixed|null
	 */
	private function get_migration_service() {
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$container = etch_fusion_suite_container();
			if ( $container->has( 'migration_service' ) ) {
				return $container->get( 'migration_service' );
			}
		}

		return null;
	}
}
