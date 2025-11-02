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

	/**
	 * Constructor
	 *
	 * @param EFS_Migration_Manager $manager
	 * @param EFS_API_Client $api_client
	 */
	public function __construct( EFS_Migration_Manager $manager, EFS_API_Client $api_client ) {
		$this->manager    = $manager;
		$this->api_client = $api_client;
	}

	public function start_migration( array $data ) {
		$token = isset( $data['migration_token'] ) ? sanitize_text_field( $data['migration_token'] ) : '';
		$migration_key = isset( $data['migration_key'] ) ? sanitize_textarea_field( $data['migration_key'] ) : '';
		$target_url = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';
		$api_key = isset( $data['api_key'] ) ? sanitize_text_field( $data['api_key'] ) : '';
		$token_data = array();

		if ( ! $token && $migration_key ) {
			$token_data = $this->api_client->validate_migration_key_on_target( $target_url, $migration_key );
			if ( is_wp_error( $token_data ) ) {
				return $token_data;
			}
			$token = isset( $token_data['token'] ) ? sanitize_text_field( $token_data['token'] ) : $token;
		}

		$batch = isset( $data['batch_size'] ) ? intval( $data['batch_size'] ) : 50;
		if ( ! $token ) {
			return new \WP_Error( 'missing_token', __( 'Migration token is required.', 'etch-fusion-suite' ) );
		}

		$settings = get_option( 'efs_settings', array() );

		if ( empty( $target_url ) ) {
			if ( isset( $token_data['target_url'] ) && $token_data['target_url'] ) {
				$target_url = esc_url_raw( $token_data['target_url'] );
			} elseif ( isset( $settings['target_url'] ) && $settings['target_url'] ) {
				$target_url = esc_url_raw( $settings['target_url'] );
			}
		}

		if ( empty( $api_key ) ) {
			if ( isset( $token_data['api_key'] ) && $token_data['api_key'] ) {
				$api_key = sanitize_text_field( $token_data['api_key'] );
			} elseif ( isset( $settings['api_key'] ) && $settings['api_key'] ) {
				$api_key = sanitize_text_field( $settings['api_key'] );
			}
		}

		if ( empty( $target_url ) ) {
			return new \WP_Error( 'missing_target_url', __( 'Target URL is required to start the migration.', 'etch-fusion-suite' ) );
		}

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'missing_api_key', __( 'Application Password is required to communicate with the Etch site.', 'etch-fusion-suite' ) );
		}
		$result = $this->manager->start_migration( $target_url, $api_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'message'     => __( 'Migration started.', 'etch-fusion-suite' ),
			'migrationId' => isset( $result['migrationId'] ) ? $result['migrationId'] : '',
			'progress'    => isset( $result['progress'] ) ? $result['progress'] : array(),
			'steps'       => isset( $result['steps'] ) ? $result['steps'] : array(),
			'token'       => $token,
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
		$target_url = isset( $data['target_url'] ) ? esc_url_raw( $data['target_url'] ) : '';
		$api_key    = isset( $data['api_key'] ) ? sanitize_text_field( $data['api_key'] ) : '';
		$result     = $this->api_client->generate_migration_key( $target_url, $api_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'message' => __( 'Migration key generated.', 'etch-fusion-suite' ),
			'key'     => isset( $result['key'] ) ? $result['key'] : '',
		);
	}
}
