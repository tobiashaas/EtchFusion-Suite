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

	/**
	 * Constructor
	 *
	 * @param EFS_API_Client $api_client
	 * @param Settings_Repository_Interface $settings_repository
	 */
	public function __construct( EFS_API_Client $api_client, Settings_Repository_Interface $settings_repository ) {
		$this->api_client          = $api_client;
		$this->settings_repository = $settings_repository;
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

	public function test_connection( array $data ) {
		$settings = $this->sanitize_settings( $data );
		$url      = $settings['target_url'];
		$key      = $settings['api_key'];
		$result   = $this->api_client->test_connection( $url, $key );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'connection_failed', $result->get_error_message() );
		}

		if ( ! isset( $result['valid'] ) || ! $result['valid'] ) {
			$errors  = isset( $result['errors'] ) && is_array( $result['errors'] ) ? array_filter( $result['errors'] ) : array();
			$message = ! empty( $errors ) ? implode( ' ', array_map( 'wp_strip_all_tags', $errors ) ) : __( 'Connection failed.', 'etch-fusion-suite' );
			return new \WP_Error( 'connection_failed', $message );
		}

		return array(
			'message' => __( 'Connection successful.', 'etch-fusion-suite' ),
			'valid'   => true,
			'plugins' => isset( $result['plugins'] ) ? $result['plugins'] : array(),
		);
	}

	public function generate_migration_key( array $data ) {
		$url    = isset( $data['target_url'] ) ? $this->sanitize_url( $data['target_url'] ) : '';
		$key    = isset( $data['api_key'] ) ? $this->sanitize_text( $data['api_key'] ) : '';

		if ( empty( $url ) ) {
			$url = home_url();
		}

		// If the request targets the current site, generate the key locally without an HTTP round-trip.
		if ( home_url() === untrailingslashit( $url ) || untrailingslashit( home_url() ) === untrailingslashit( $url ) ) {
			if ( function_exists( 'etch_fusion_suite_container' ) ) {
				try {
					$container = etch_fusion_suite_container();
					if ( $container->has( 'token_manager' ) ) {
						$token_manager = $container->get( 'token_manager' );
						$token_data    = $token_manager->generate_migration_token();

						return array(
							'message' => __( 'Migration key generated.', 'etch-fusion-suite' ),
							'key'     => add_query_arg(
								array(
									'domain'  => home_url(),
									'token'   => $token_data['token'],
									'expires' => $token_data['expires'],
								),
								home_url()
							),
						);
					}
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- fallback to remote call below.
				}
			}
		}

		$result = $this->api_client->generate_migration_key( $url, $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'message' => __( 'Migration key generated.', 'etch-fusion-suite' ),
			'key'     => isset( $result['key'] ) ? $result['key'] : '',
		);
	}

	private function sanitize_settings( array $data ) {
		return array(
			'target_url'    => isset( $data['target_url'] ) ? $this->sanitize_url( $data['target_url'] ) : '',
			'api_key'       => isset( $data['api_key'] ) ? $this->sanitize_text( $data['api_key'] ) : '',
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
