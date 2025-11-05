<?php
/**
 * Custom Fields Migrator for Bricks to Etch Migration Plugin
 *
 * Handles migration of custom fields and meta data
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use WP_Error;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Custom_Fields_Migrator extends Abstract_Migrator {

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		parent::__construct( $error_handler, $api_client );

		$this->name     = 'Custom Fields';
		$this->type     = 'custom_fields';
		$this->priority = 40;
	}

	/** @inheritDoc */
	public function supports() {
		return true;
	}

	/** @inheritDoc */
	public function validate() {
		// Custom fields migrate per-post; validation ensures WordPress environment available.
		return array(
			'valid'  => true,
			'errors' => array(),
		);
	}

	/** @inheritDoc */
	public function export() {
		// Global export not required for custom fields (handled per-post).
		return array();
	}

	/** @inheritDoc */
	public function import( $data ) {
		// Global import not applicable; return success to satisfy interface.
		return array(
			'imported' => 0,
			'skipped'  => 0,
		);
	}

	/** @inheritDoc */
	public function migrate( $target_url, $jwt_token ) {
		// Custom fields migrate alongside posts; nothing to do globally.
		return true;
	}

	/** @inheritDoc */
	public function get_stats() {
		return $this->get_custom_field_stats();
	}

	/**
	 * Migrate post meta from source to target
	 */
	public function migrate_post_meta( $source_post_id, $target_post_id, $target_url, $jwt_token ) {
		$meta_data = $this->get_post_meta( $source_post_id );

		if ( empty( $meta_data ) ) {
			return true; // No meta data to migrate
		}

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}
		$result = $api_client->send_post_meta( $target_url, $jwt_token, $target_post_id, $meta_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get post meta data
	 */
	private function get_post_meta( $post_id ) {
		global $wpdb;

		// Get all meta data for the post
		$meta_data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$post_id
			)
		);

		$filtered_meta = array();

		foreach ( $meta_data as $meta ) {
			// Skip Bricks-specific meta keys
			if ( 0 === strpos( $meta->meta_key, '_bricks_' ) ) {
				continue;
			}

			// Skip WordPress core meta keys
			if ( in_array(
				$meta->meta_key,
				array(
					'_edit_lock',
					'_edit_last',
					'_wp_old_slug',
					'_wp_old_date',
				),
				true
			) ) {
				continue;
			}

			// Unserialize if needed
			$meta_value = maybe_unserialize( $meta->meta_value );

			$filtered_meta[ $meta->meta_key ] = $meta_value;
		}

		return $filtered_meta;
	}

	/**
	 * Analyze custom fields in post meta
	 */
	public function analyze_custom_fields( $post_id ) {
		$meta_data     = $this->get_post_meta( $post_id );
		$custom_fields = array(
			'acf'       => array(),
			'metabox'   => array(),
			'jetengine' => array(),
			'other'     => array(),
		);

		foreach ( $meta_data as $meta_key => $meta_value ) {
			$field_group = 'other';

			if ( 0 === strpos( $meta_key, 'field_' ) ) {
				$field_group = 'acf';
			} elseif ( 0 === strpos( $meta_key, 'mb_' ) ) {
				$field_group = 'metabox';
			} elseif ( 0 === strpos( $meta_key, 'jet_' ) ) {
				$field_group = 'jetengine';
			}

			$custom_fields[ $field_group ][] = $meta_key;
		}

		return $custom_fields;
	}

	/**
	 * Get ACF field groups
	 */
	public function get_acf_field_groups() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return array();
		}

		return \acf_get_field_groups();
	}

	/**
	 * Get MetaBox configurations
	 */
	public function get_metabox_configurations() {
		if ( ! function_exists( 'rwmb_meta' ) ) {
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
		if ( ! class_exists( 'Jet_Engine' ) ) {
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
	 * Validate custom field data
	 */
	public function validate_custom_field_data( $field_data ) {
		$errors = array();

		if ( empty( $field_data ) ) {
			$errors[] = 'Field data is empty';
		}

		if ( ! is_array( $field_data ) ) {
			$errors[] = 'Field data must be an array';
		}

		// Check required fields
		$required_fields = array( 'name', 'type', 'label' );
		foreach ( $required_fields as $field ) {
			if ( empty( $field_data[ $field ] ) ) {
				$errors[] = "Required field '{$field}' is missing";
			}
		}

		return $errors;
	}

	/**
	 * Convert ACF field to MetaBox format
	 */
	public function convert_acf_to_metabox( $acf_field ) {
		$metabox_field = array(
			'name'        => $acf_field['name'],
			'label'       => $acf_field['label'],
			'type'        => $this->convert_acf_field_type_to_metabox( $acf_field['type'] ),
			'description' => $acf_field['instructions'] ?? '',
			'required'    => $acf_field['required'] ?? false,
		);

		// Add type-specific settings
		switch ( $acf_field['type'] ) {
			case 'text':
			case 'textarea':
			case 'email':
			case 'url':
			case 'password':
				$metabox_field['placeholder'] = $acf_field['placeholder'] ?? '';
				break;

			case 'select':
			case 'checkbox':
			case 'radio':
				$metabox_field['options'] = $acf_field['choices'] ?? array();
				break;

			case 'number':
				$metabox_field['min']  = $acf_field['min'] ?? '';
				$metabox_field['max']  = $acf_field['max'] ?? '';
				$metabox_field['step'] = $acf_field['step'] ?? '';
				break;

			case 'date_picker':
				$metabox_field['date_format'] = $acf_field['display_format'] ?? 'Y-m-d';
				break;

			case 'time_picker':
				$metabox_field['time_format'] = $acf_field['display_format'] ?? 'H:i';
				break;
		}

		return $metabox_field;
	}

	/**
	 * Convert MetaBox field to ACF format
	 */
	public function convert_metabox_to_acf( $metabox_field ) {
		$acf_field = array(
			'name'         => $metabox_field['name'],
			'label'        => $metabox_field['label'],
			'type'         => $this->convert_metabox_field_type_to_acf( $metabox_field['type'] ),
			'instructions' => $metabox_field['description'] ?? '',
			'required'     => $metabox_field['required'] ?? false,
		);

		// Add type-specific settings
		switch ( $metabox_field['type'] ) {
			case 'text':
			case 'textarea':
			case 'email':
			case 'url':
			case 'password':
				$acf_field['placeholder'] = $metabox_field['placeholder'] ?? '';
				break;

			case 'select':
			case 'checkbox_list':
			case 'radio':
				$acf_field['choices'] = $metabox_field['options'] ?? array();
				break;

			case 'number':
				$acf_field['min']  = $metabox_field['min'] ?? '';
				$acf_field['max']  = $metabox_field['max'] ?? '';
				$acf_field['step'] = $metabox_field['step'] ?? '';
				break;

			case 'date':
				$acf_field['display_format'] = $metabox_field['date_format'] ?? 'Y-m-d';
				break;

			case 'time':
				$acf_field['display_format'] = $metabox_field['time_format'] ?? 'H:i';
				break;
		}

		return $acf_field;
	}

	/**
	 * Convert ACF field type to MetaBox type
	 */
	private function convert_acf_field_type_to_metabox( $acf_type ) {
		$mapping = array(
			'text'             => 'text',
			'textarea'         => 'textarea',
			'number'           => 'number',
			'email'            => 'email',
			'url'              => 'url',
			'password'         => 'password',
			'wysiwyg'          => 'wysiwyg',
			'image'            => 'image',
			'file'             => 'file',
			'gallery'          => 'image_advanced',
			'select'           => 'select',
			'checkbox'         => 'checkbox_list',
			'radio'            => 'radio',
			'button_group'     => 'button_group',
			'true_false'       => 'switch',
			'date_picker'      => 'date',
			'time_picker'      => 'time',
			'date_time_picker' => 'datetime',
			'color_picker'     => 'color',
			'range'            => 'slider',
			'repeater'         => 'group',
			'flexible_content' => 'group',
			'clone'            => 'group',
			'group'            => 'group',
		);

		return $mapping[ $acf_type ] ?? 'text';
	}

	/**
	 * Convert MetaBox field type to ACF type
	 */
	private function convert_metabox_field_type_to_acf( $metabox_type ) {
		$mapping = array(
			'text'           => 'text',
			'textarea'       => 'textarea',
			'number'         => 'number',
			'email'          => 'email',
			'url'            => 'url',
			'password'       => 'password',
			'wysiwyg'        => 'wysiwyg',
			'image'          => 'image',
			'file'           => 'file',
			'image_advanced' => 'gallery',
			'select'         => 'select',
			'checkbox_list'  => 'checkbox',
			'radio'          => 'radio',
			'button_group'   => 'button_group',
			'switch'         => 'true_false',
			'date'           => 'date_picker',
			'time'           => 'time_picker',
			'datetime'       => 'date_time_picker',
			'color'          => 'color_picker',
			'slider'         => 'range',
			'group'          => 'group',
		);

		return $mapping[ $metabox_type ] ?? 'text';
	}

	/**
	 * Get custom field statistics
	 */
	public function get_custom_field_stats() {
		$stats = array(
			'acf_field_groups'     => 0,
			'metabox_configs'      => 0,
			'jetengine_meta_boxes' => 0,
			'total_custom_fields'  => 0,
		);

		// Count ACF field groups
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$acf_groups                = \acf_get_field_groups();
			$stats['acf_field_groups'] = count( $acf_groups );
		}

		// Count MetaBox configurations
		if ( function_exists( 'rwmb_meta' ) ) {
			$metabox_configs          = $this->get_metabox_configurations();
			$stats['metabox_configs'] = count( $metabox_configs );
		}

		// Count JetEngine meta boxes
		if ( class_exists( 'Jet_Engine' ) ) {
			$jetengine_meta_boxes          = $this->get_jetengine_meta_boxes();
			$stats['jetengine_meta_boxes'] = count( $jetengine_meta_boxes );
		}

		$stats['total_custom_fields'] = $stats['acf_field_groups'] + $stats['metabox_configs'] + $stats['jetengine_meta_boxes'];

		return $stats;
	}
}
