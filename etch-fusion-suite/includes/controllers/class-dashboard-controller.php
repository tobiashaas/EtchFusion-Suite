<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Services\EFS_Migration_Service;
use Bricks2Etch\Controllers\EFS_Template_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Dashboard_Controller {
	private $plugin_detector;
	private $error_handler;
	private $migration_service;
	private $settings_controller;
	private $template_controller;

	public function __construct(
		EFS_Plugin_Detector $plugin_detector,
		EFS_Error_Handler $error_handler,
		EFS_Migration_Service $migration_service,
		EFS_Settings_Controller $settings_controller,
		EFS_Template_Controller $template_controller
	) {
		$this->plugin_detector     = $plugin_detector;
		$this->error_handler       = $error_handler;
		$this->migration_service   = $migration_service;
		$this->settings_controller = $settings_controller;
		$this->template_controller = $template_controller;
	}

	public function render() {
		$data = $this->get_dashboard_context();
		$this->render_view( 'dashboard', $data );
	}

	public function detect_environment() {
		return array(
			'is_bricks_site' => $this->plugin_detector->is_bricks_active(),
			'is_etch_site'   => $this->plugin_detector->is_etch_active(),
			'site_url'       => home_url(),
			'is_https'       => is_ssl(),
		);
	}

	public function get_dashboard_context() {
		$env = $this->detect_environment();

		$progress_context = $this->get_progress();
		$template_extractor_enabled = \efs_feature_enabled( 'template_extractor' );
		$saved_templates             = array();

		if ( $env['is_etch_site'] && $template_extractor_enabled ) {
			$saved_templates = $this->get_saved_templates();
		}

		return array(
			'is_bricks_site'  => $env['is_bricks_site'],
			'is_etch_site'    => $env['is_etch_site'],
			'site_url'        => $env['site_url'],
			'is_https'        => $env['is_https'],
			'logs'            => $this->get_logs(),
			'progress_data'   => $progress_context['progress'],
			'progress_steps'  => $progress_context['steps'],
			'migration_id'    => isset( $progress_context['migrationId'] ) ? $progress_context['migrationId'] : '',
			'settings'        => $this->get_settings(),
			'nonce'           => wp_create_nonce( 'efs_nonce' ),
			'saved_templates' => $saved_templates,
			'template_extractor_enabled' => $template_extractor_enabled,
		);
	}

	private function get_logs() {
		return $this->error_handler->get_recent_logs();
	}

	private function get_progress() {
		$progress = $this->migration_service->get_progress();

		if ( ! is_array( $progress ) ) {
			return array(
				'progress'    => $this->get_default_progress(),
				'steps'       => array(),
				'migrationId' => '',
				'completed'   => false,
			);
		}

		$progress_data = isset( $progress['progress'] ) && is_array( $progress['progress'] ) ? $progress['progress'] : $this->get_default_progress();
		$steps_data    = isset( $progress['steps'] ) && is_array( $progress['steps'] ) ? $progress['steps'] : array();
		$migration_id  = isset( $progress['migrationId'] ) ? sanitize_text_field( $progress['migrationId'] ) : '';
		$completed     = isset( $progress['completed'] ) ? (bool) $progress['completed'] : false;

		return array(
			'progress'    => $progress_data,
			'steps'       => $steps_data,
			'migrationId' => $migration_id,
			'completed'   => $completed,
		);
	}

	private function get_default_progress() {
		return array(
			'percentage'   => 0,
			'status'       => '',
			'current_step' => '',
			'message'      => '',
			'started_at'   => null,
			'completed_at' => null,
		);
	}

	private function get_settings() {
		return $this->settings_controller->get_settings();
	}

	private function render_view( $template, array $data = array() ) {
		$path = plugin_dir_path( __FILE__ ) . '../views/' . $template . '.php';
		$path = realpath( $path );
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}

		switch ( $template ) {
			case 'dashboard':
				$view_args = $this->prepare_dashboard_view_args( $data );
				break;
			case 'logs':
				$view_args = $this->prepare_logs_view_args( $data );
				break;
			case 'migration-progress':
				$view_args = $this->prepare_migration_progress_view_args( $data );
				break;
			case 'template-extractor':
				$view_args = $this->prepare_template_extractor_view_args( $data );
				break;
			case 'bricks-setup':
				$view_args = $this->prepare_bricks_setup_view_args( $data );
				break;
			case 'etch-setup':
				$view_args = $this->prepare_etch_setup_view_args( $data );
				break;
			default:
				$view_args = $data;
		}

		$render = static function ( $template_path, array $template_args ) {
			foreach ( $template_args as $template_key => $template_value ) {
				${$template_key} = $template_value;
			}

			unset( $template_key, $template_value );
			include $template_path;
		};

		$render( $path, $view_args );
	}

	/**
	 * Prepare dashboard view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_dashboard_view_args( array $data ) {
		return array(
			'is_bricks_site'  => isset( $data['is_bricks_site'] ) ? (bool) $data['is_bricks_site'] : false,
			'is_etch_site'    => isset( $data['is_etch_site'] ) ? (bool) $data['is_etch_site'] : false,
			'site_url'        => isset( $data['site_url'] ) ? esc_url( $data['site_url'] ) : home_url(),
			'is_https'        => isset( $data['is_https'] ) ? (bool) $data['is_https'] : is_ssl(),
			'logs'            => isset( $data['logs'] ) && is_array( $data['logs'] ) ? $data['logs'] : array(),
			'progress_data'   => isset( $data['progress_data'] ) && is_array( $data['progress_data'] ) ? $data['progress_data'] : $this->get_default_progress(),
			'progress_steps'  => isset( $data['progress_steps'] ) && is_array( $data['progress_steps'] ) ? $data['progress_steps'] : array(),
			'migration_id'    => isset( $data['migration_id'] ) ? sanitize_text_field( $data['migration_id'] ) : '',
			'settings'        => isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			'nonce'           => isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : wp_create_nonce( 'efs_nonce' ),
			'saved_templates' => isset( $data['saved_templates'] ) && is_array( $data['saved_templates'] ) ? $data['saved_templates'] : array(),
			'template_extractor_enabled' => isset( $data['template_extractor_enabled'] ) ? (bool) $data['template_extractor_enabled'] : false,
			'feature_flags_section_id'   => 'efs-accordion-feature-flags',
		);
	}

	/**
	 * Prepare logs view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_logs_view_args( array $data ) {
		return array(
			'logs' => isset( $data['logs'] ) && is_array( $data['logs'] ) ? $data['logs'] : array(),
		);
	}

	/**
	 * Prepare migration progress view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_migration_progress_view_args( array $data ) {
		return array(
			'progress_data'  => isset( $data['progress_data'] ) && is_array( $data['progress_data'] ) ? $data['progress_data'] : $this->get_default_progress(),
			'progress_steps' => isset( $data['progress_steps'] ) && is_array( $data['progress_steps'] ) ? $data['progress_steps'] : array(),
			'migration_id'   => isset( $data['migration_id'] ) ? sanitize_text_field( $data['migration_id'] ) : '',
			'completed'      => isset( $data['completed'] ) ? (bool) $data['completed'] : false,
		);
	}

	/**
	 * Prepare template extractor view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_template_extractor_view_args( array $data ) {
		return array(
			'saved_templates' => isset( $data['saved_templates'] ) && is_array( $data['saved_templates'] ) ? $data['saved_templates'] : array(),
			'nonce'           => isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : wp_create_nonce( 'efs_nonce' ),
		);
	}

	/**
	 * Prepare Bricks setup view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_bricks_setup_view_args( array $data ) {
		$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();
		$migration_key_defaults = array(
			'context'  => 'bricks',
			'settings' => array(
				'target_url' => isset( $settings['target_url'] ) ? $settings['target_url'] : '',
				'api_key'    => isset( $settings['api_key'] ) ? $settings['api_key'] : '',
			),
		);

		return array(
			'settings'           => $settings,
			'nonce'              => isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : wp_create_nonce( 'efs_nonce' ),
			'key_context'        => 'bricks',
			'migration_key_args' => isset( $data['migration_key_args'] ) && is_array( $data['migration_key_args'] )
				? wp_parse_args( $data['migration_key_args'], $migration_key_defaults )
				: $migration_key_defaults,
		);
	}

	/**
	 * Prepare Etch setup view arguments.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	private function prepare_etch_setup_view_args( array $data ) {
		$site_url = isset( $data['site_url'] ) ? esc_url_raw( $data['site_url'] ) : home_url();
		return array(
			'nonce'              => isset( $data['nonce'] ) ? sanitize_text_field( $data['nonce'] ) : wp_create_nonce( 'efs_nonce' ),
			'is_https'           => isset( $data['is_https'] ) ? (bool) $data['is_https'] : is_ssl(),
			'site_url'           => esc_url( $site_url ),
			'key_context'        => 'etch',
			'migration_key_args' => array(
				'context'  => 'etch',
				'settings' => array(
					'target_url' => $site_url,
				),
			),
		);
	}

	private function get_saved_templates() {
		if ( ! $this->template_controller ) {
			return array();
		}

		return $this->template_controller->get_saved_templates();
	}
}
