<?php
/**
 * Custom Post Types Migrator for Bricks to Etch Migration Plugin
 *
 * Handles migration of custom post types and their configurations
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Exception;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_CPT_Migrator extends Abstract_Migrator {

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		parent::__construct( $error_handler, $api_client );

		$this->name     = 'Custom Post Types';
		$this->type     = 'cpt';
		$this->priority = 10;
	}

	/** @inheritDoc */
	public function supports() {
		return true;
	}

	/** @inheritDoc */
	public function validate() {
		$errors = array();

		$cpts = $this->export_custom_post_types();
		foreach ( $cpts as $cpt ) {
			$validation_errors = $this->validate_cpt_data( $cpt );
			if ( ! empty( $validation_errors ) ) {
				$errors = array_merge(
					$errors,
					array_map(
						function ( $error ) use ( $cpt ) {
							return sprintf( '%s (%s)', $error, $cpt['name'] );
						},
						$validation_errors
					)
				);
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/** @inheritDoc */
	public function export() {
		return $this->export_custom_post_types();
	}

	/** @inheritDoc */
	public function import( $data ) {
		return $this->import_custom_post_types( $data );
	}

	/** @inheritDoc */
	public function migrate( $target_url, $jwt_token ) {
		return $this->migrate_custom_post_types( $target_url, $jwt_token );
	}

	/** @inheritDoc */
	public function get_stats() {
		return $this->get_cpt_stats();
	}

	/**
	 * Export custom post types
	 */
	public function export_custom_post_types() {
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		$exported_cpts = array();

		foreach ( $post_types as $post_type ) {
			$exported_cpt = $this->export_custom_post_type( $post_type );
			if ( $exported_cpt ) {
				$exported_cpts[] = $exported_cpt;
			}
		}

		return $exported_cpts;
	}

	/**
	 * Export single custom post type
	 */
	private function export_custom_post_type( $post_type ) {
		if ( empty( $post_type->name ) ) {
			return null;
		}

		$cpt_data = array(
			'name'                  => $post_type->name,
			'label'                 => $post_type->label,
			'labels'                => $post_type->labels,
			'description'           => $post_type->description,
			'public'                => $post_type->public,
			'hierarchical'          => $post_type->hierarchical,
			'exclude_from_search'   => $post_type->exclude_from_search,
			'publicly_queryable'    => $post_type->publicly_queryable,
			'show_ui'               => $post_type->show_ui,
			'show_in_menu'          => $post_type->show_in_menu,
			'show_in_nav_menus'     => $post_type->show_in_nav_menus,
			'show_in_admin_bar'     => $post_type->show_in_admin_bar,
			'show_in_rest'          => $post_type->show_in_rest,
			'rest_base'             => $post_type->rest_base,
			'rest_controller_class' => $post_type->rest_controller_class,
			'menu_position'         => $post_type->menu_position,
			'menu_icon'             => $post_type->menu_icon,
			'capability_type'       => $post_type->capability_type,
			'capabilities'          => isset( $post_type->capabilities ) ? $post_type->capabilities : array(),
			'map_meta_cap'          => isset( $post_type->map_meta_cap ) ? $post_type->map_meta_cap : false,
			'supports'              => isset( $post_type->supports ) ? $post_type->supports : array(),
			'register_meta_box_cb'  => $post_type->register_meta_box_cb,
			'taxonomies'            => $post_type->taxonomies,
			'has_archive'           => $post_type->has_archive,
			'rewrite'               => $post_type->rewrite,
			'query_var'             => $post_type->query_var,
			'can_export'            => $post_type->can_export,
			'delete_with_user'      => $post_type->delete_with_user,
			'template'              => $post_type->template,
			'template_lock'         => $post_type->template_lock,
		);

		return $cpt_data;
	}

	/**
	 * Import custom post types
	 */
	public function import_custom_post_types( $cpts_data ) {
		if ( empty( $cpts_data ) || ! is_array( $cpts_data ) ) {
			return new \WP_Error( 'invalid_data', 'Invalid custom post types data' );
		}

		$imported_count = 0;
		$errors         = array();

		foreach ( $cpts_data as $cpt_data ) {
			try {
				$result = $this->import_custom_post_type( $cpt_data );
				if ( $result ) {
					++$imported_count;
				} else {
					$errors[] = 'Failed to import custom post type: ' . ( $cpt_data['name'] ?? 'Unknown' );
				}
			} catch ( Exception $e ) {
				$errors[] = 'Error importing custom post type: ' . $e->getMessage();
			}
		}

		if ( ! empty( $errors ) ) {
			$this->error_handler->log_error(
				'E302',
				array(
					'errors'         => $errors,
					'imported_count' => $imported_count,
					'action'         => 'Custom post types import completed with errors',
				)
			);
		}

		return array(
			'imported_count' => $imported_count,
			'errors'         => $errors,
		);
	}

	/**
	 * Import single custom post type
	 */
	private function import_custom_post_type( $cpt_data ) {
		if ( empty( $cpt_data['name'] ) ) {
			return false;
		}

		$post_type_name = $cpt_data['name'];

		// Check if post type already exists
		if ( post_type_exists( $post_type_name ) ) {
			// Update existing post type
			$this->error_handler->log_warning(
				'W003',
				array(
					'post_type' => $post_type_name,
					'action'    => 'Custom post type already exists, skipping import',
				)
			);
			return true;
		}

		// Sanitize CPT data before registration
		$sanitized_args = $this->sanitize_cpt_args( $cpt_data );

		// Register the custom post type
		$result = register_post_type( $post_type_name, $sanitized_args );

		if ( is_wp_error( $result ) ) {
			$this->error_handler->log_error(
				'E302',
				array(
					'post_type' => $post_type_name,
					'error'     => $result->get_error_message(),
					'action'    => 'Failed to register custom post type',
				)
			);
			return false;
		}

		// Save the registration for persistence
		$this->save_cpt_registration( $post_type_name, $cpt_data );

		return true;
	}

	/**
	 * Sanitize CPT arguments for register_post_type()
	 * Remove null values and ensure arrays are proper arrays
	 */
	private function sanitize_cpt_args( $cpt_data ) {
		$args = array();

		// Only include non-null values
		foreach ( $cpt_data as $key => $value ) {
			// Skip the 'name' key as it's not a valid argument
			if ( 'name' === $key ) {
				continue;
			}

			// Skip null values
			if ( null === $value ) {
				continue;
			}

			// Convert objects to arrays (for labels, capabilities, etc.)
			if ( is_object( $value ) ) {
				$value = (array) $value;
			}

			$args[ $key ] = $value;
		}

		// Ensure critical array fields are arrays
		$array_fields = array( 'labels', 'capabilities', 'supports', 'taxonomies' );
		foreach ( $array_fields as $field ) {
			if ( isset( $args[ $field ] ) && ! is_array( $args[ $field ] ) ) {
				unset( $args[ $field ] );
			}
		}

		return $args;
	}

	/**
	 * Save custom post type registration for persistence
	 */
	private function save_cpt_registration( $post_type_name, $cpt_data ) {
		$registered_cpts                    = get_option( 'b2e_registered_cpts', array() );
		$registered_cpts[ $post_type_name ] = $cpt_data;
		update_option( 'b2e_registered_cpts', $registered_cpts );
	}

	/**
	 * Register custom post types from saved registrations
	 */
	public function init_registered_cpts() {
		$registered_cpts = get_option( 'b2e_registered_cpts', array() );

		foreach ( $registered_cpts as $post_type_name => $cpt_data ) {
			if ( ! post_type_exists( $post_type_name ) ) {
				register_post_type( $post_type_name, $cpt_data );
			}
		}
	}

	/**
	 * Migrate custom post types
	 */
	public function migrate_custom_post_types( $target_url, $jwt_token ) {
		$cpts = $this->export_custom_post_types();

		if ( empty( $cpts ) ) {
			return true; // No CPTs to migrate
		}

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}
		$result = $api_client->send_custom_post_types( $target_url, $jwt_token, $cpts );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get custom post type statistics
	 */
	public function get_cpt_stats() {
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		$total_cpts  = count( $post_types );
		$total_posts = 0;
		$cpt_details = array();

		foreach ( $post_types as $post_type ) {
			$post_count   = wp_count_posts( $post_type->name );
			$total_posts += $post_count->publish;

			$cpt_details[] = array(
				'name'       => $post_type->name,
				'label'      => $post_type->label,
				'post_count' => $post_count->publish,
				'supports'   => $post_type->supports,
				'taxonomies' => $post_type->taxonomies,
			);
		}

		return array(
			'total_cpts'  => $total_cpts,
			'total_posts' => $total_posts,
			'cpt_details' => $cpt_details,
		);
	}

	/**
	 * Validate custom post type data
	 */
	public function validate_cpt_data( $cpt_data ) {
		$errors = array();

		if ( empty( $cpt_data ) ) {
			$errors[] = 'CPT data is empty';
		}

		if ( ! is_array( $cpt_data ) ) {
			$errors[] = 'CPT data must be an array';
		}

		// Check required fields
		$required_fields = array( 'name', 'label' );
		foreach ( $required_fields as $field ) {
			if ( empty( $cpt_data[ $field ] ) ) {
				$errors[] = "Required field '{$field}' is missing";
			}
		}

		// Validate post type name
		if ( ! empty( $cpt_data['name'] ) ) {
			$post_type_name = $cpt_data['name'];

			// Check for invalid characters
			if ( ! preg_match( '/^[a-z0-9_-]+$/', $post_type_name ) ) {
				$errors[] = 'Post type name contains invalid characters';
			}

			// Check length
			if ( strlen( $post_type_name ) > 20 ) {
				$errors[] = 'Post type name is too long (max 20 characters)';
			}

			// Check for reserved names
			$reserved_names = array(
				'post',
				'page',
				'attachment',
				'revision',
				'nav_menu_item',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'wp_block',
				'action',
				'author',
				'order',
				'theme',
				'fields',
				'field_group',
				'acf-field-group',
				'acf-field',
				'meta-box',
				'jet-engine-meta',
			);

			if ( in_array( $post_type_name, $reserved_names, true ) ) {
				$errors[] = 'Post type name is reserved';
			}
		}

		return $errors;
	}

	/**
	 * Get custom post type posts
	 */
	public function get_cpt_posts( $post_type_name ) {
		if ( ! post_type_exists( $post_type_name ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'   => $post_type_name,
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		return $posts;
	}

	/**
	 * Get custom post type posts count
	 */
	public function get_cpt_posts_count( $post_type_name ) {
		if ( ! post_type_exists( $post_type_name ) ) {
			return 0;
		}

		$post_count = wp_count_posts( $post_type_name );
		return $post_count->publish;
	}

	/**
	 * Check if custom post type has Bricks content
	 */
	public function cpt_has_bricks_content( $post_type_name ) {
		if ( ! post_type_exists( $post_type_name ) ) {
			return false;
		}

		$posts = get_posts(
			array(
				'post_type'   => $post_type_name,
				'post_status' => 'publish',
				'numberposts' => 1,
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

		return ! empty( $posts );
	}

	/**
	 * Get custom post types with Bricks content
	 */
	public function get_cpts_with_bricks_content() {
		$post_types = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);

		$cpts_with_bricks = array();

		foreach ( $post_types as $post_type ) {
			if ( $this->cpt_has_bricks_content( $post_type->name ) ) {
				$cpts_with_bricks[] = array(
					'name'       => $post_type->name,
					'label'      => $post_type->label,
					'post_count' => $this->get_cpt_posts_count( $post_type->name ),
				);
			}
		}

		return $cpts_with_bricks;
	}
}
