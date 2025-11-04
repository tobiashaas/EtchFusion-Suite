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

		$result = $this->manager->start_migration( $migration_key, $target_url, $batch );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			' message'    => __( 'Migration started.', 'etch-fusion-suite' ),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'token'       => $migration_key,
		);
	}

	public function get_progress( array $data = array() ) {
		$migration_id = isset( $data['migrationId'] ) ? sanitize_text_field( $data['migrationId'] ) : '';
		$result       = $this->manager->get_progress( $migration_id );
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
}
