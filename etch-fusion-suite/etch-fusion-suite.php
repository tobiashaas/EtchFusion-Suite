<?php
/**
 * Plugin Name: Etch Fusion Suite
 * Plugin URI: https://github.com/tobiashaas/EtchFusion-Suite
 * Description: End-to-end migration and orchestration toolkit for transforming Bricks Builder sites into native Etch experiences.
 * Version: 0.10.2
 * Author: Tobias Haas
 * License: GPL v2 or later
 * Text Domain: etch-fusion-suite
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'ETCH_FUSION_SUITE_VERSION', '0.10.2' );
define( 'ETCH_FUSION_SUITE_FILE', __FILE__ );
define( 'ETCH_FUSION_SUITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETCH_FUSION_SUITE_URL', plugin_dir_url( __FILE__ ) );
define( 'ETCH_FUSION_SUITE_BASENAME', plugin_basename( __FILE__ ) );


// Load Composer autoloader when available
if ( file_exists( ETCH_FUSION_SUITE_DIR . 'vendor/autoload.php' ) ) {
	require_once ETCH_FUSION_SUITE_DIR . 'vendor/autoload.php';
}

// Always register manual WordPress-friendly autoloader for legacy class naming
require_once ETCH_FUSION_SUITE_DIR . 'includes/autoloader.php';

// Manually load container classes (ensure they're available before bootstrap)
require_once ETCH_FUSION_SUITE_DIR . 'includes/container/class-service-container.php';
require_once ETCH_FUSION_SUITE_DIR . 'includes/container/class-service-provider.php';

// Import namespaced classes
use Bricks2Etch\Api\EFS_API_Endpoints;
use Bricks2Etch\Container\EFS_Service_Container;
use Bricks2Etch\Container\EFS_Service_Provider;
use Bricks2Etch\Migrators\EFS_Migrator_Discovery;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;

// Bootstrap service container
global $etch_fusion_suite_container;

if ( ! isset( $etch_fusion_suite_container ) ) {
	$etch_fusion_suite_container        = new EFS_Service_Container();
	$etch_fusion_suite_service_provider = new EFS_Service_Provider();
	$etch_fusion_suite_service_provider->register( $etch_fusion_suite_container );
}

if ( ! function_exists( 'etch_fusion_suite_container' ) ) {
	function etch_fusion_suite_container() {
		global $etch_fusion_suite_container;

		return $etch_fusion_suite_container;
	}
}

// Main plugin class
class Etch_Fusion_Suite_Plugin {

	/**
	 * Single instance of the plugin
	 */
	private static $instance = null;

	/**
	 * Admin interface instance
	 */
	private $admin_interface = null;

	/**
	 * AJAX handler instance (NEW - v0.5.1)
	 */
	private $ajax_handler = null;

	/**
	 * Get single instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ) );

		// Initialize REST API endpoints immediately
		add_action( 'plugins_loaded', array( $this, 'init_rest_api' ) );
		add_action( 'plugins_loaded', array( $this, 'init_migrators' ), 20 );

		// Enable Application Passwords with environment-based HTTPS requirement
		add_filter( 'wp_is_application_passwords_available', array( $this, 'enable_application_passwords' ) );

		// Add security headers
		add_action( 'send_headers', array( $this, 'add_security_headers' ), 1 );

		// Activation/Deactivation hooks are registered at the end of the file
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Load text domain
		load_plugin_textdomain( 'etch-fusion-suite', false, dirname( ETCH_FUSION_SUITE_BASENAME ) . '/languages' );

		// Initialize components
		$this->init_components();
	}

	/**
	 * Initialize migrator discovery workflow
	 */
	public function init_migrators() {
		$container = etch_fusion_suite_container();

		if ( $container->has( 'migrator_registry' ) ) {
			$registry = $container->get( 'migrator_registry' );
			EFS_Migrator_Discovery::discover_migrators( $registry );
		}
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		$container = etch_fusion_suite_container();

		// Initialize admin interface with menu registration
		if ( is_admin() ) {
			$this->admin_interface = $container->get( 'admin_interface' );
		}

		// Always initialize admin interface for AJAX handlers
		if ( ! isset( $this->admin_interface ) ) {
			$this->admin_interface = $container->get( 'admin_interface' );
		}

		// Initialize AJAX handlers (NEW - v0.5.1)
		$this->ajax_handler = $container->get( 'ajax_handler' );
	}

	/**
	 * Initialize REST API endpoints
	 */
	public function init_rest_api() {
		EFS_API_Endpoints::set_container( etch_fusion_suite_container() );
		EFS_API_Endpoints::init();
	}

	/**
	 * Initialize CORS Manager with whitelist-based policy
	 */
	private function init_cors_manager() {
		$container = etch_fusion_suite_container();

		if ( $container->has( 'cors_manager' ) ) {
			$cors_manager = $container->get( 'cors_manager' );

			// Add CORS headers for REST API requests
			add_action(
				'rest_api_init',
				function () use ( $cors_manager ) {
					remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
					add_filter(
						'rest_pre_serve_request',
						function ( $value ) use ( $cors_manager ) {
							$cors_manager->add_cors_headers();
							return $value;
						}
					);
				}
			);

			// Handle preflight OPTIONS requests
			add_action(
				'rest_api_init',
				function () use ( $cors_manager ) {
					add_filter(
						'rest_pre_dispatch',
						function ( $result, $server, $request ) use ( $cors_manager ) {
							if ( $request->get_method() === 'OPTIONS' ) {
								$cors_manager->handle_preflight_request();
								return new WP_REST_Response( null, 200 );
							}
							return $result;
						},
						10,
						3
					);
				}
			);
		}
	}

	/**
	 * Add security headers to HTTP responses
	 */
	public function add_security_headers() {
		$container = etch_fusion_suite_container();

		if ( $container->has( 'security_headers' ) ) {
			$security_headers = $container->get( 'security_headers' );
			$security_headers->add_security_headers();
		}
	}

	/**
	 * Enable Application Passwords with environment-based HTTPS requirement
	 *
	 * Security: Only disable HTTPS requirement in local/development environments.
	 * Production environments should always use HTTPS for Application Passwords.
	 */
	public function enable_application_passwords( $available ) {
		$container = etch_fusion_suite_container();

		if ( $container->has( 'environment_detector' ) ) {
			$environment_detector = $container->get( 'environment_detector' );

			// Allow Application Passwords in local/development without HTTPS
			if ( $environment_detector->is_local_environment() || $environment_detector->is_development() ) {
				return true;
			}

			// In production, require HTTPS
			return is_ssl();
		}

		// Fallback: require HTTPS
		return is_ssl();
	}

	/**
	 * Add admin menu - REMOVED: This was causing duplicate menus
	 * Admin menu is now handled by EFS_Admin_Interface class only
	 */

	/**
	 * Admin page callback - REMOVED: This was causing duplicate dashboards
	 * Dashboard rendering is now handled by EFS_Admin_Interface class only
	 */

	/**
	 * Plugin activation
	 */
	public static function activate() {
		// Plugin activation tasks
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation with complete cleanup
	 */
	public static function deactivate() {
		// Clear ALL plugin data on deactivation
		global $wpdb;

		// Clear transients
		$wpdb->query(
			"DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_efs_%' 
             OR option_name LIKE '_transient_timeout_efs_%'"
		);

		// Clear all EFS options
		$efs_options = array(
			'efs_settings',
			'efs_migration_progress',
			'efs_migration_token',
			'efs_migration_token_value',
			'efs_private_key',
			'efs_error_log',
			'efs_api_key',
			'efs_import_api_key',
			'efs_export_api_key',
			'efs_migration_settings',
		);

		foreach ( $efs_options as $option ) {
			delete_option( $option );
		}

		// Clear user meta
		$wpdb->query(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%efs%'"
		);

		// Clear WordPress object cache
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin activation - DUPLICATE REMOVED
	 * Using static version instead
	 */

	/**
	 * Plugin deactivation - DUPLICATE REMOVED
	 * Using static version instead
	 */

	/**
	 * Create migration tables/options - REMOVED
	 * No longer needed with static activation
	 */

	/**
	 * Clean up transients - REMOVED
	 * No longer needed with static deactivation
	 */
}

/**
 * Global debug helper function for development
 *
 * @param string $message Debug message
 * @param mixed  $data    Optional data to log
 * @param string $context Context identifier (default: EFS_DEBUG)
 */
function etch_fusion_suite_debug_log( $message, $data = null, $context = 'ETCH_FUSION_SUITE_DEBUG' ) {
	if ( ! WP_DEBUG || ! WP_DEBUG_LOG ) {
		return;
	}

	$log_message = sprintf(
		'[%s] %s: %s',
		$context,
		current_time( 'Y-m-d H:i:s' ),
		$message
	);

	if ( null !== $data ) {
		$log_message .= ' | Data: ' . wp_json_encode( $data );
	}

	error_log( $log_message );
}

// Initialize the plugin (new name)
function etch_fusion_suite() {
	return Etch_Fusion_Suite_Plugin::get_instance();
}

// Start the plugin
etch_fusion_suite();

// Plugin activation/deactivation hooks
register_activation_hook( __FILE__, array( 'Etch_Fusion_Suite_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Etch_Fusion_Suite_Plugin', 'deactivate' ) );
