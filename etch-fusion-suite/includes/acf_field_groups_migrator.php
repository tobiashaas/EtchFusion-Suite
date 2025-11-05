<?php
/**
 * ACF Field Groups Migrator for Bricks to Etch Migration Plugin
 *
 * Handles migration of ACF field groups and configurations
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Exception;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_ACF_Field_Groups_Migrator extends Abstract_Migrator {

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		parent::__construct( $error_handler, $api_client );

		$this->name     = 'ACF Field Groups';
		$this->type     = 'acf';
		$this->priority = 20;
	}

	/** @inheritDoc */
	public function supports() {
		return $this->check_plugin_active( 'acf_get_field_groups' );
	}

	/** @inheritDoc */
	public function validate() {
		$errors = array();

		if ( ! $this->supports() ) {
			$errors[] = 'Advanced Custom Fields plugin is not active.';
		}

		if ( function_exists( 'acf_get_setting' ) ) {
			$version = acf_get_setting( 'version' );
			if ( ! empty( $version ) && version_compare( $version, '5.0', '<' ) ) {
				$errors[] = sprintf( 'ACF version %s is not supported. Please upgrade to 5.0 or higher.', $version );
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/** @inheritDoc */
	public function export() {
		return $this->export_field_groups();
	}

	/** @inheritDoc */
	public function import( $data ) {
		return $this->import_field_groups( $data );
	}

	/** @inheritDoc */
	public function migrate( $target_url, $jwt_token ) {
		return $this->migrate_acf_field_groups( $target_url, $jwt_token );
	}

	/** @inheritDoc */
	public function get_stats() {
		return $this->get_field_group_stats();
	}

	/**
	 * Export ACF field groups
	 */
	public function export_field_groups() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'plugin' => 'ACF',
					'action' => 'ACF plugin not active, skipping field groups export',
				)
			);
			return array();
		}

		$field_groups    = acf_get_field_groups();
		$exported_groups = array();

		foreach ( $field_groups as $group ) {
			$exported_group = $this->export_field_group( $group );
			if ( $exported_group ) {
				$exported_groups[] = $exported_group;
			}
		}

		return $exported_groups;
	}

	/**
	 * Export single field group
	 */
	private function export_field_group( $group ) {
		if ( empty( $group['ID'] ) ) {
			return null;
		}

		// Get field group data
		$group_data = array(
			'ID'                    => $group['ID'],
			'title'                 => $group['title'],
			'key'                   => $group['key'],
			'fields'                => array(),
			'location'              => $group['location'] ?? array(),
			'menu_order'            => $group['menu_order'] ?? 0,
			'position'              => $group['position'] ?? 'normal',
			'style'                 => $group['style'] ?? 'default',
			'label_placement'       => $group['label_placement'] ?? 'top',
			'instruction_placement' => $group['instruction_placement'] ?? 'label',
			'hide_on_screen'        => $group['hide_on_screen'] ?? array(),
			'active'                => $group['active'] ?? true,
		);

		// Get fields for this group
		$fields = acf_get_fields( $group['ID'] );

		if ( $fields ) {
			foreach ( $fields as $field ) {
				$exported_field = $this->export_field( $field );
				if ( $exported_field ) {
					$group_data['fields'][] = $exported_field;
				}
			}
		}

		return $group_data;
	}

	/**
	 * Export single field
	 */
	private function export_field( $field ) {
		if ( empty( $field['name'] ) ) {
			return null;
		}

		$field_data = array(
			'ID'                => $field['ID'] ?? 0,
			'key'               => $field['key'] ?? '',
			'label'             => $field['label'] ?? '',
			'name'              => $field['name'],
			'type'              => $field['type'] ?? 'text',
			'instructions'      => $field['instructions'] ?? '',
			'required'          => $field['required'] ?? false,
			'conditional_logic' => $field['conditional_logic'] ?? false,
			'wrapper'           => $field['wrapper'] ?? array(),
			'default_value'     => $field['default_value'] ?? '',
			'placeholder'       => $field['placeholder'] ?? '',
			'prepend'           => $field['prepend'] ?? '',
			'append'            => $field['append'] ?? '',
			'maxlength'         => $field['maxlength'] ?? '',
			'readonly'          => $field['readonly'] ?? false,
			'disabled'          => $field['disabled'] ?? false,
			'sub_fields'        => array(),
		);

		// Add type-specific settings
		$field_data = array_merge( $field_data, $this->get_field_type_settings( $field ) );

		// Handle sub fields (for repeater, flexible content, etc.)
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			foreach ( $field['sub_fields'] as $sub_field ) {
				$exported_sub_field = $this->export_field( $sub_field );
				if ( $exported_sub_field ) {
					$field_data['sub_fields'][] = $exported_sub_field;
				}
			}
		}

		return $field_data;
	}

	/**
	 * Get field type-specific settings
	 */
	private function get_field_type_settings( $field ) {
		$type     = $field['type'] ?? 'text';
		$settings = array();

		switch ( $type ) {
			case 'text':
			case 'textarea':
			case 'email':
			case 'url':
			case 'password':
				$settings['placeholder'] = $field['placeholder'] ?? '';
				$settings['maxlength']   = $field['maxlength'] ?? '';
				break;

			case 'number':
				$settings['min']         = $field['min'] ?? '';
				$settings['max']         = $field['max'] ?? '';
				$settings['step']        = $field['step'] ?? '';
				$settings['placeholder'] = $field['placeholder'] ?? '';
				break;

			case 'range':
				$settings['min']     = $field['min'] ?? '';
				$settings['max']     = $field['max'] ?? '';
				$settings['step']    = $field['step'] ?? '';
				$settings['prepend'] = $field['prepend'] ?? '';
				$settings['append']  = $field['append'] ?? '';
				break;

			case 'select':
			case 'checkbox':
			case 'radio':
				$settings['choices']       = $field['choices'] ?? array();
				$settings['default_value'] = $field['default_value'] ?? '';
				$settings['allow_null']    = $field['allow_null'] ?? false;
				$settings['multiple']      = $field['multiple'] ?? false;
				$settings['ui']            = $field['ui'] ?? false;
				$settings['ajax']          = $field['ajax'] ?? false;
				break;

			case 'button_group':
				$settings['choices']       = $field['choices'] ?? array();
				$settings['default_value'] = $field['default_value'] ?? '';
				$settings['allow_null']    = $field['allow_null'] ?? false;
				break;

			case 'true_false':
				$settings['message']       = $field['message'] ?? '';
				$settings['default_value'] = $field['default_value'] ?? 0;
				$settings['ui']            = $field['ui'] ?? false;
				$settings['ui_on_text']    = $field['ui_on_text'] ?? '';
				$settings['ui_off_text']   = $field['ui_off_text'] ?? '';
				break;

			case 'date_picker':
				$settings['display_format'] = $field['display_format'] ?? 'd/m/Y';
				$settings['return_format']  = $field['return_format'] ?? 'd/m/Y';
				$settings['first_day']      = $field['first_day'] ?? 1;
				break;

			case 'time_picker':
				$settings['display_format'] = $field['display_format'] ?? 'g:i a';
				$settings['return_format']  = $field['return_format'] ?? 'g:i a';
				break;

			case 'date_time_picker':
				$settings['display_format'] = $field['display_format'] ?? 'd/m/Y g:i a';
				$settings['return_format']  = $field['return_format'] ?? 'd/m/Y g:i a';
				$settings['first_day']      = $field['first_day'] ?? 1;
				break;

			case 'color_picker':
				$settings['default_value'] = $field['default_value'] ?? '';
				break;

			case 'image':
				$settings['return_format'] = $field['return_format'] ?? 'array';
				$settings['preview_size']  = $field['preview_size'] ?? 'medium';
				$settings['library']       = $field['library'] ?? 'all';
				$settings['min_width']     = $field['min_width'] ?? '';
				$settings['min_height']    = $field['min_height'] ?? '';
				$settings['min_size']      = $field['min_size'] ?? '';
				$settings['max_width']     = $field['max_width'] ?? '';
				$settings['max_height']    = $field['max_height'] ?? '';
				$settings['max_size']      = $field['max_size'] ?? '';
				$settings['mime_types']    = $field['mime_types'] ?? '';
				break;

			case 'file':
				$settings['return_format'] = $field['return_format'] ?? 'array';
				$settings['library']       = $field['library'] ?? 'all';
				$settings['min_size']      = $field['min_size'] ?? '';
				$settings['max_size']      = $field['max_size'] ?? '';
				$settings['mime_types']    = $field['mime_types'] ?? '';
				break;

			case 'gallery':
				$settings['return_format'] = $field['return_format'] ?? 'array';
				$settings['preview_size']  = $field['preview_size'] ?? 'medium';
				$settings['insert']        = $field['insert'] ?? 'append';
				$settings['library']       = $field['library'] ?? 'all';
				$settings['min']           = $field['min'] ?? '';
				$settings['max']           = $field['max'] ?? '';
				$settings['min_width']     = $field['min_width'] ?? '';
				$settings['min_height']    = $field['min_height'] ?? '';
				$settings['min_size']      = $field['min_size'] ?? '';
				$settings['max_width']     = $field['max_width'] ?? '';
				$settings['max_height']    = $field['max_height'] ?? '';
				$settings['max_size']      = $field['max_size'] ?? '';
				$settings['mime_types']    = $field['mime_types'] ?? '';
				break;

			case 'repeater':
				$settings['min']          = $field['min'] ?? '';
				$settings['max']          = $field['max'] ?? '';
				$settings['layout']       = $field['layout'] ?? 'table';
				$settings['button_label'] = $field['button_label'] ?? '';
				$settings['collapsed']    = $field['collapsed'] ?? '';
				break;

			case 'flexible_content':
				$settings['min']          = $field['min'] ?? '';
				$settings['max']          = $field['max'] ?? '';
				$settings['button_label'] = $field['button_label'] ?? '';
				break;

			case 'clone':
				$settings['clone']        = $field['clone'] ?? array();
				$settings['display']      = $field['display'] ?? 'seamless';
				$settings['layout']       = $field['layout'] ?? 'block';
				$settings['prefix_label'] = $field['prefix_label'] ?? 0;
				$settings['prefix_name']  = $field['prefix_name'] ?? 0;
				break;

			case 'group':
				$settings['layout'] = $field['layout'] ?? 'block';
				break;
		}

		return $settings;
	}

	/**
	 * Import field groups
	 */
	public function import_field_groups( $field_groups_data ) {
		if ( ! function_exists( 'acf_import_field_group' ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'plugin' => 'ACF',
					'action' => 'ACF plugin not active, skipping field groups import',
				)
			);
			return new \WP_Error( 'acf_not_active', 'ACF plugin is not active' );
		}

		if ( empty( $field_groups_data ) || ! is_array( $field_groups_data ) ) {
			return new \WP_Error( 'invalid_data', 'Invalid field groups data' );
		}

		$imported_count = 0;
		$errors         = array();

		foreach ( $field_groups_data as $group_data ) {
			try {
				$result = $this->import_field_group( $group_data );
				if ( $result ) {
					++$imported_count;
				} else {
					$errors[] = 'Failed to import field group: ' . ( $group_data['title'] ?? 'Unknown' );
				}
			} catch ( Exception $e ) {
				$errors[] = 'Error importing field group: ' . $e->getMessage();
			}
		}

		if ( ! empty( $errors ) ) {
			$this->error_handler->log_error(
				'E302',
				array(
					'errors'         => $errors,
					'imported_count' => $imported_count,
					'action'         => 'ACF field groups import completed with errors',
				)
			);
		}

		return array(
			'imported_count' => $imported_count,
			'errors'         => $errors,
		);
	}

	/**
	 * Import single field group
	 */
	private function import_field_group( $group_data ) {
		// Remove ID to avoid conflicts
		unset( $group_data['ID'] );

		// Import the field group
		$result = acf_import_field_group( $group_data );

		if ( is_wp_error( $result ) ) {
			$this->error_handler->log_error(
				'E302',
				array(
					'group_title' => $group_data['title'] ?? 'Unknown',
					'error'       => $result->get_error_message(),
					'action'      => 'Failed to import ACF field group',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Migrate ACF field groups
	 */
	public function migrate_acf_field_groups( $target_url, $jwt_token ) {
		$field_groups = $this->export_field_groups();

		if ( empty( $field_groups ) ) {
			return true; // No field groups to migrate
		}

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}
		$result = $api_client->send_acf_field_groups( $target_url, $jwt_token, $field_groups );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get ACF field group statistics
	 */
	public function get_field_group_stats() {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return array(
				'total_groups' => 0,
				'total_fields' => 0,
				'field_types'  => array(),
			);
		}

		$field_groups = \acf_get_field_groups();
		$total_groups = count( $field_groups );
		$total_fields = 0;
		$field_types  = array();

		foreach ( $field_groups as $group ) {
			$fields = acf_get_fields( $group['ID'] );
			if ( $fields ) {
				$total_fields += count( $fields );

				foreach ( $fields as $field ) {
					$type                 = $field['type'] ?? 'unknown';
					$field_types[ $type ] = ( $field_types[ $type ] ?? 0 ) + 1;
				}
			}
		}

		return array(
			'total_groups' => $total_groups,
			'total_fields' => $total_fields,
			'field_types'  => $field_types,
		);
	}
}
