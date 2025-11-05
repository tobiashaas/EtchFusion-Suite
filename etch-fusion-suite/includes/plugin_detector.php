<?php
/**
 * Plugin Detector for Etch Fusion Suite
 *
 * Detects installed plugins and validates migration requirements
 */

namespace Bricks2Etch\Core;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Plugin_Detector {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;
	}

	/**
	 * Get installed plugins
	 */
	public function get_installed_plugins() {
		return array(
			'bricks'    => $this->is_bricks_active(),
			'etch'      => $this->is_etch_active(),
			'acf'       => $this->is_acf_active(),
			'metabox'   => $this->is_metabox_active(),
			'jetengine' => $this->is_jetengine_active(),
		);
	}

	/**
	 * Check if Bricks Builder is active
	 */
	public function is_bricks_active() {
		return class_exists( 'Bricks\Bricks' ) || function_exists( 'bricks_is_builder' );
	}

	/**
	 * Check if Etch PageBuilder is active
	 */
	public function is_etch_active() {
		return class_exists( 'Etch\Plugin' ) || function_exists( 'etch_run_plugin' ) || defined( 'ETCH_VERSION' );
	}

	/**
	 * Check if Advanced Custom Fields is active
	 */
	public function is_acf_active() {
		return class_exists( 'ACF' ) || function_exists( 'get_field' );
	}

	/**
	 * Check if MetaBox is active
	 */
	public function is_metabox_active() {
		return function_exists( 'rwmb_meta' ) || class_exists( 'RW_Meta_Box' );
	}

	/**
	 * Check if JetEngine is active
	 */
	public function is_jetengine_active() {
		return class_exists( 'Jet_Engine' ) || function_exists( 'jet_engine' );
	}

	/**
	 * Validate migration requirements (Source Site)
	 */
	public function validate_migration_requirements() {
		$validation_results = array(
			'valid'    => true,
			'errors'   => array(),
			'warnings' => array(),
			'plugins'  => $this->get_installed_plugins(),
		);

		// Check Bricks Builder (REQUIRED on Source Site)
		if ( ! $this->is_bricks_active() ) {
			$validation_results['valid']    = false;
			$validation_results['errors'][] = 'Bricks Builder is not active on source site';
		}

		// Note: We DON'T check for Etch on source site
		// Etch should be on the TARGET site, not source

		// Check for Bricks content
		$bricks_posts = $this->get_bricks_posts_count();
		if ( 0 === $bricks_posts ) {
			$validation_results['warnings'][] = 'No Bricks content found. Nothing to migrate.';
		} else {
			$validation_results['bricks_posts_count'] = $bricks_posts;
		}

		// Check for Bricks global classes
		$bricks_classes = get_option( 'bricks_global_classes', array() );
		// Ensure it's an array (sometimes it's a serialized string)
		if ( is_string( $bricks_classes ) ) {
			$bricks_classes = maybe_unserialize( $bricks_classes );
		}
		if ( ! is_array( $bricks_classes ) ) {
			$bricks_classes = array();
		}
		if ( empty( $bricks_classes ) ) {
			$validation_results['warnings'][] = 'No Bricks global classes found';
		} else {
			$validation_results['bricks_classes_count'] = count( $bricks_classes );
		}

		// Check custom field plugins (informational only)
		$custom_field_plugins = array(
			'acf'       => 'Advanced Custom Fields',
			'metabox'   => 'MetaBox',
			'jetengine' => 'JetEngine',
		);

		$active_custom_field_plugins = array();
		foreach ( $custom_field_plugins as $plugin => $name ) {
			if ( $this->is_plugin_active( $plugin ) ) {
				$active_custom_field_plugins[] = $name;
			}
		}

		if ( empty( $active_custom_field_plugins ) ) {
			$validation_results['warnings'][] = 'No custom field plugins detected. Custom fields will not be migrated.';
		} else {
			$validation_results['custom_field_plugins'] = $active_custom_field_plugins;
		}

		return $validation_results;
	}

	/**
	 * Get count of Bricks posts
	 */
	public function get_bricks_posts_count() {
		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_query'  => array(
					'relation' => 'AND',
					array(
						'key'     => '_bricks_template_type',
						'value'   => 'content',
						'compare' => '=',
					),
					array(
						'key'     => '_bricks_editor_mode',
						'value'   => 'bricks',
						'compare' => '=',
					),
				),
			)
		);

		return count( $posts );
	}

	/**
	 * Get Bricks global classes count
	 */
	public function get_bricks_global_classes_count() {
		$global_classes = get_option( 'bricks_global_classes', array() );
		return count( $global_classes );
	}

	/**
	 * Get custom post types
	 */
	public function get_custom_post_types() {
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		$cpts = array();
		foreach ( $post_types as $post_type ) {
			$cpts[] = array(
				'name'        => $post_type->name,
				'label'       => $post_type->label,
				'description' => $post_type->description,
				'supports'    => $post_type->supports,
				'taxonomies'  => $post_type->taxonomies,
			);
		}

		return $cpts;
	}

	/**
	 * Get ACF field groups
	 */
	public function get_acf_field_groups() {
		if ( ! $this->is_acf_active() ) {
			return array();
		}

		if ( function_exists( 'acf_get_field_groups' ) ) {
			return acf_get_field_groups();
		}

		return array();
	}

	/**
	 * Get MetaBox configurations
	 */
	public function get_metabox_configurations() {
		if ( ! $this->is_metabox_active() ) {
			return array();
		}

		// Get MetaBox configurations from database
		$configs = get_posts(
			array(
				'post_type'   => 'meta-box',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		return $configs;
	}

	/**
	 * Get JetEngine meta boxes
	 */
	public function get_jetengine_meta_boxes() {
		if ( ! $this->is_jetengine_active() ) {
			return array();
		}

		// Get JetEngine meta boxes from database
		$meta_boxes = get_posts(
			array(
				'post_type'   => 'jet-engine-meta',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		return $meta_boxes;
	}

	/**
	 * Check if plugin is active
	 */
	private function is_plugin_active( $plugin ) {
		switch ( $plugin ) {
			case 'bricks':
				return $this->is_bricks_active();
			case 'etch':
				return $this->is_etch_active();
			case 'acf':
				return $this->is_acf_active();
			case 'metabox':
				return $this->is_metabox_active();
			case 'jetengine':
				return $this->is_jetengine_active();
			default:
				return false;
		}
	}

	/**
	 * Get plugin versions
	 */
	public function get_plugin_versions() {
		$versions = array();

		// Bricks version
		if ( $this->is_bricks_active() ) {
			$versions['bricks'] = defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : 'Unknown';
		}

		// Etch version
		if ( $this->is_etch_active() ) {
			$versions['etch'] = defined( 'ETCH_VERSION' ) ? ETCH_VERSION : 'Unknown';
		}

		// ACF version
		if ( $this->is_acf_active() ) {
			$versions['acf'] = defined( 'ACF_VERSION' ) ? ACF_VERSION : 'Unknown';
		}

		// MetaBox version
		if ( $this->is_metabox_active() ) {
			$versions['metabox'] = defined( 'RWMB_VERSION' ) ? RWMB_VERSION : 'Unknown';
		}

		// JetEngine version
		if ( $this->is_jetengine_active() ) {
			$versions['jetengine'] = defined( 'JET_ENGINE_VERSION' ) ? JET_ENGINE_VERSION : 'Unknown';
		}

		return $versions;
	}

	/**
	 * Get system information
	 */
	public function get_system_info() {
		return array(
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'mysql_version'       => $this->get_mysql_version(),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
		);
	}

	/**
	 * Get MySQL version
	 */
	private function get_mysql_version() {
		global $wpdb;
		return $wpdb->get_var( 'SELECT VERSION()' );
	}

	/**
	 * Check if site is ready for migration
	 */
	public function is_ready_for_migration() {
		$validation = $this->validate_migration_requirements();
		return $validation['valid'];
	}

	/**
	 * Get migration readiness report
	 */
	public function get_migration_readiness_report() {
		$validation      = $this->validate_migration_requirements();
		$system_info     = $this->get_system_info();
		$plugin_versions = $this->get_plugin_versions();

		return array(
			'ready'                       => $validation['valid'],
			'validation'                  => $validation,
			'system_info'                 => $system_info,
			'plugin_versions'             => $plugin_versions,
			'bricks_posts_count'          => $this->get_bricks_posts_count(),
			'bricks_global_classes_count' => $this->get_bricks_global_classes_count(),
			'custom_post_types_count'     => count( $this->get_custom_post_types() ),
			'acf_field_groups_count'      => count( $this->get_acf_field_groups() ),
			'metabox_configs_count'       => count( $this->get_metabox_configurations() ),
			'jetengine_meta_boxes_count'  => count( $this->get_jetengine_meta_boxes() ),
		);
	}
}
