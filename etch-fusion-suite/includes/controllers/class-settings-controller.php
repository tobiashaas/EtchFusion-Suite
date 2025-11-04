<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Repositories\Interfaces\Settings_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Settings_Controller {
	private $api_client;
	private $settings_repository;
	private $token_manager;

	/**
	 * Constructor
	 *
	 * @param EFS_API_Client $api_client
	 * @param Settings_Repository_Interface $settings_repository
	 */
	public function __construct( EFS_API_Client $api_client, Settings_Repository_Interface $settings_repository ) {
		$this->api_client          = $api_client;
		$this->settings_repository = $settings_repository;
		$this->token_manager       = function_exists( 'etch_fusion_suite_container' ) && etch_fusion_suite_container()->has( 'token_manager' )
			? etch_fusion_suite_container()->get( 'token_manager' )
			: null;
	}

	public function save_settings( array $data ) {
		$settings = $this->sanitize_settings( $data );
		$this->settings_repository->save_migration_settings( $settings );
		return array(
			'message'  => __( 'Settings saved successfully.', 'etch-fusion-suite' ),
			'settings' => $settings,
		);
	}

	public function get_settings() {
		$settings = $this->settings_repository->get_migration_settings();
		return is_array( $settings ) ? $settings : array();
	}

	public function generate_migration_key( array $data ) {
		$target_url = isset( $data['target_url'] ) ? $this->sanitize_url( $data['target_url'] ) : '';
		$target_url = ! empty( $target_url ) ? $target_url : home_url();

		if ( ! $this->token_manager ) {
			return new \WP_Error( 'token_manager_unavailable', __( 'Token manager is unavailable.', 'etch-fusion-suite' ) );
		}

		$token_data = $this->token_manager->generate_migration_token( $target_url );

		return array(
			'message'    => __( 'Migration key generated.', 'etch-fusion-suite' ),
			'key'        => $token_data['token'],
			'expiration' => $token_data['expires'],
			'domain'     => $token_data['domain'],
		);
	}

	private function sanitize_settings( array $data ) {
		return array(
			'target_url'    => isset( $data['target_url'] ) ? $this->sanitize_url( $data['target_url'] ) : '',
			'migration_key' => isset( $data['migration_key'] ) ? $this->sanitize_textarea( $data['migration_key'] ) : '',
		);
	}

	private function sanitize_url( $url ) {
		return esc_url_raw( $url );
	}

	private function sanitize_text( $text ) {
		return sanitize_text_field( $text );
	}

	private function sanitize_textarea( $text ) {
		return sanitize_textarea_field( $text );
	}
}
