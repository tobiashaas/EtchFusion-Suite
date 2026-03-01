<?php
/**
 * Admin Interface for Etch Fusion Suite
 *
 * Handles the WordPress admin menu and dashboard
 *
 * Located in includes/admin/ — resolved by the Admin namespace map in autoloader.php.
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

		// Google Font: Space Grotesk (used by admin UI)
		$font_url = 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap';
		wp_enqueue_style(
			'efs-google-font-space-grotesk',
			$font_url,
			array(),
			null
		);

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
				array( 'efs-google-font-space-grotesk' ),
				$css_version
			);
		}

		$etch_css_path = ETCH_FUSION_SUITE_DIR . 'assets/css/admin/etch-dashboard.css';
		if ( file_exists( $etch_css_path ) ) {
			$etch_css_version = ETCH_FUSION_SUITE_VERSION;
			$etch_css_mtime   = filemtime( $etch_css_path );
			if ( false !== $etch_css_mtime ) {
				$etch_css_version = $etch_css_mtime;
			}

			wp_enqueue_style(
				'efs-admin-etch-dashboard',
				ETCH_FUSION_SUITE_URL . 'assets/css/admin/etch-dashboard.css',
				array( 'efs-admin-css' ),
				$etch_css_version
			);
		}

		$bricks_wizard_css_path = ETCH_FUSION_SUITE_DIR . 'assets/css/admin/bricks-wizard.css';
		if ( file_exists( $bricks_wizard_css_path ) ) {
			$bricks_wizard_css_version = ETCH_FUSION_SUITE_VERSION;
			$bricks_wizard_css_mtime   = filemtime( $bricks_wizard_css_path );
			if ( false !== $bricks_wizard_css_mtime ) {
				$bricks_wizard_css_version = $bricks_wizard_css_mtime;
			}

			wp_enqueue_style(
				'efs-admin-bricks-wizard',
				ETCH_FUSION_SUITE_URL . 'assets/css/admin/bricks-wizard.css',
				array( 'efs-admin-css' ),
				$bricks_wizard_css_version
			);
		}

		// Enqueue bundled admin JavaScript (single IIFE bundle — no sub-module requests,
		// cache-busted automatically via ?ver= on every release).
		$js_path = ETCH_FUSION_SUITE_DIR . 'assets/js/dist/main.js';
		if ( file_exists( $js_path ) ) {
			// Always include the plugin version so the URL changes on every release,
			// even when file timestamps are identical between ZIP builds.
			$js_mtime   = filemtime( $js_path );
			$js_version = ETCH_FUSION_SUITE_VERSION . ( false !== $js_mtime ? '-' . $js_mtime : '' );
			wp_enqueue_script(
				'efs-admin-main',
				ETCH_FUSION_SUITE_URL . 'assets/js/dist/main.js',
				array(),
				$js_version,
				true
			);

			// Localize script data
			$context                      = $this->dashboard_controller->get_dashboard_context();
			$localized_context            = $context;
			$localized_context['ajaxUrl'] = admin_url( 'admin-ajax.php' );
			// Nonce is generated here and consumed by verify_request() in all AJAX handlers (nonce action matches $nonce_action).
			$localized_context['nonce']          = wp_create_nonce( 'efs_nonce' );
			$localized_context['rest_url']       = rest_url();
			$localized_context['rest_nonce']     = wp_create_nonce( 'wp_rest' );
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
}
