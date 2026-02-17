<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Core\EFS_Migration_Manager;
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
	 * @param EFS_Migration_Manager $manager
	 * @param EFS_API_Client $api_client
	 */
	public function __construct( EFS_Migration_Manager $manager, EFS_API_Client $api_client ) {
		$this->manager       = $manager;
		$this->api_client    = $api_client;
		$this->token_manager = function_exists( 'etch_fusion_suite_container' ) && etch_fusion_suite_container()->has( 'token_manager' )
			? etch_fusion_suite_container()->get( 'token_manager' )
			: null;
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

		$options = array(
			'selected_post_types' => array(),
			'post_type_mappings'  => array(),
		);
		if ( ! empty( $data['selected_post_types'] ) && is_array( $data['selected_post_types'] ) ) {
			$options['selected_post_types'] = array_map( 'sanitize_key', $data['selected_post_types'] );
		}
		if ( ! empty( $data['post_type_mappings'] ) && is_array( $data['post_type_mappings'] ) ) {
			foreach ( $data['post_type_mappings'] as $source => $target ) {
				$options['post_type_mappings'][ sanitize_key( (string) $source ) ] = sanitize_key( (string) $target );
			}
		}

		$nonce = isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : '';
		$result = $this->manager->start_migration_async( $migration_key, $target_url, $batch, $options, $nonce );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'message'    => isset( $result['message'] ) ? $result['message'] : __( 'Migration started.', 'etch-fusion-suite' ),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'token'       => $migration_key,
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
		$result       = $this->manager->get_progress( $migration_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$progress = isset( $result['progress'] ) && is_array( $result['progress'] ) ? $result['progress'] : array();
		$steps    = isset( $result['steps'] ) && is_array( $result['steps'] ) ? $result['steps'] : array();
		$progress['steps'] = $steps;

		return array(
			'progress'    => $progress,
			'steps'       => $steps,
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'last_updated' => isset( $progress['last_updated'] ) ? $progress['last_updated'] : '',
			'is_stale'    => ! empty( $progress['is_stale'] ),
			'estimated_time_remaining' => isset( $progress['estimated_time_remaining'] ) ? $progress['estimated_time_remaining'] : null,
			'completed'   => ! empty( $result['completed'] ),
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
		$batch        = isset( $data['batch'] ) ? $data['batch'] : array();
		$result       = $this->manager->process_batch( $migration_id, $batch );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'completed'   => ! empty( $result['completed'] ),
		);
	}

	public function cancel_migration( array $data = array() ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		$result       = $this->manager->cancel_migration( $migration_id );
		if ( is_wp_error( $result ) ) {
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
