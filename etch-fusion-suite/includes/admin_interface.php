<?php
/**
 * Admin Interface for Etch Fusion Suite
 *
 * Handles the WordPress admin menu and dashboard
 */

namespace Bricks2Etch\Admin;

use Bricks2Etch\Controllers\EFS_Dashboard_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;
use Bricks2Etch\Controllers\EFS_Migration_Controller;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Admin_Interface {
	private $dashboard_controller;
	private $settings_controller;
	private $migration_controller;

	public function __construct(
		EFS_Dashboard_Controller $dashboard_controller,
		EFS_Settings_Controller $settings_controller,
		EFS_Migration_Controller $migration_controller,
		$register_menu = true
	) {
		$this->dashboard_controller = $dashboard_controller;
		$this->settings_controller  = $settings_controller;
		$this->migration_controller = $migration_controller;

		if ( $register_menu ) {
			add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'script_loader_tag', array( $this, 'add_module_type_attribute' ), 10, 3 );
	}

	public function add_admin_menu() {
		add_menu_page(
			__( 'Etch Fusion', 'etch-fusion-suite' ),
			__( 'Etch Fusion', 'etch-fusion-suite' ),
			'manage_options',
			'etch-fusion-suite',
			array( $this, 'render_dashboard' ),
			'dashicons-migrate',
			30
		);
	}

	public function render_dashboard() {
		$this->dashboard_controller->render();
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'etch-fusion-suite' ) ) {
			return;
		}

		// Enqueue admin CSS
		$css_path = ETCH_FUSION_SUITE_DIR . 'assets/css/admin.css';
		if ( file_exists( $css_path ) ) {
			$css_version = ETCH_FUSION_SUITE_VERSION;
			$css_mtime   = filemtime( $css_path );
			if ( false !== $css_mtime ) {
				$css_version = $css_mtime;
			}
			wp_enqueue_style(
				'efs-admin-css',
				ETCH_FUSION_SUITE_URL . 'assets/css/admin.css',
				array(),
				$css_version
			);
		}

		// Enqueue admin JavaScript (ES6 module)
		$js_path = ETCH_FUSION_SUITE_DIR . 'assets/js/admin/main.js';
		if ( file_exists( $js_path ) ) {
			$js_version = ETCH_FUSION_SUITE_VERSION;
			$js_mtime   = filemtime( $js_path );
			if ( false !== $js_mtime ) {
				$js_version = $js_mtime;
			}
			wp_enqueue_script(
				'efs-admin-main',
				ETCH_FUSION_SUITE_URL . 'assets/js/admin/main.js',
				array(),
				$js_version,
				true
			);

			wp_script_add_data( 'efs-admin-main', 'type', 'module' );

			// Localize script data
			$context                      = $this->dashboard_controller->get_dashboard_context();
			$localized_context            = $context;
			$localized_context['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			// Nonce is generated here and consumed by verify_request() in all AJAX handlers (nonce action matches $nonce_action).
			$localized_context['nonce']          = wp_create_nonce( 'efs_nonce' );
			$localized_context['framer_enabled'] = isset( $localized_context['framer_enabled'] ) ? (bool) $localized_context['framer_enabled'] : false;

			if ( isset( $localized_context['template_extractor_enabled'] ) ) {
				unset( $localized_context['template_extractor_enabled'] );
			}

			// wp_localize_script() escapes all values, making it safe to pass controller context directly.
			wp_localize_script( 'efs-admin-main', 'efsData', $localized_context );
		} else {
			// Log missing asset for debugging
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs missing admin asset for debugging when WP_DEBUG is enabled
			error_log( sprintf( '[EFS] Admin script not found: %s', $js_path ) );
		}
	}

	/**
	 * Add type="module" attribute to admin script tag.
	 *
	 * @param string $tag    The script tag HTML.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL.
	 * @return string Modified script tag.
	 */
	public function add_module_type_attribute( $tag, $handle, $src ) {
		if ( 'efs-admin-main' !== $handle ) {
			return $tag;
		}

		// Replace the opening script tag to include type="module"
		$tag = str_replace( '<script ', '<script type="module" ', $tag );

		return $tag;
	}
}
