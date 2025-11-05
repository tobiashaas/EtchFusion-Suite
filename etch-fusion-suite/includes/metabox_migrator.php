<?php
/**
 * MetaBox Migrator for Bricks to Etch Migration Plugin
 *
 * Handles migration of MetaBox field groups and configurations
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Exception;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_MetaBox_Migrator extends Abstract_Migrator {

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		parent::__construct( $error_handler, $api_client );

		$this->name     = 'MetaBox';
		$this->type     = 'metabox';
		$this->priority = 30;
	}

	/** @inheritDoc */
	public function supports() {
		return $this->check_plugin_active( 'rwmb_meta' );
	}

	/** @inheritDoc */
	public function validate() {
		$errors = array();

		if ( ! $this->supports() ) {
			$errors[] = 'MetaBox plugin is not active.';
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/** @inheritDoc */
	public function export() {
		return $this->export_metabox_configs();
	}

	/** @inheritDoc */
	public function import( $data ) {
		return $this->import_metabox_configs( $data );
	}

	/** @inheritDoc */
	public function migrate( $target_url, $jwt_token ) {
		return $this->migrate_metabox_configs( $target_url, $jwt_token );
	}

	/** @inheritDoc */
	public function get_stats() {
		return $this->get_metabox_stats();
	}

	/**
	 * Export MetaBox configurations
	 */
	public function export_metabox_configs() {
		if ( ! function_exists( 'rwmb_meta' ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'plugin' => 'MetaBox',
					'action' => 'MetaBox plugin not active, skipping configurations export',
				)
			);
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

		$exported_configs = array();

		foreach ( $configs as $config ) {
			$exported_config = $this->export_metabox_config( $config );
			if ( $exported_config ) {
				$exported_configs[] = $exported_config;
			}
		}

		return $exported_configs;
	}

	/**
	 * Export single MetaBox configuration
	 */
	private function export_metabox_config( $config ) {
		if ( empty( $config->ID ) ) {
			return null;
		}

		// Get configuration data
		$config_data = array(
			'ID'            => $config->ID,
			'title'         => $config->post_title,
			'post_name'     => $config->post_name,
			'post_content'  => $config->post_content,
			'post_excerpt'  => $config->post_excerpt,
			'post_status'   => $config->post_status,
			'post_date'     => $config->post_date,
			'post_modified' => $config->post_modified,
			'meta'          => array(),
		);

		// Get meta data
		$meta_data = get_post_meta( $config->ID );

		foreach ( $meta_data as $meta_key => $meta_values ) {
			// Skip WordPress core meta
			if ( in_array( $meta_key, array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}

			$config_data['meta'][ $meta_key ] = maybe_unserialize( $meta_values[0] );
		}

		return $config_data;
	}

	/**
	 * Import MetaBox configurations
	 */
	public function import_metabox_configs( $configs_data ) {
		if ( ! function_exists( 'rwmb_meta' ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'plugin' => 'MetaBox',
					'action' => 'MetaBox plugin not active, skipping configurations import',
				)
			);
			return new \WP_Error( 'metabox_not_active', 'MetaBox plugin is not active' );
		}

		if ( empty( $configs_data ) || ! is_array( $configs_data ) ) {
			return new \WP_Error( 'invalid_data', 'Invalid MetaBox configurations data' );
		}

		$imported_count = 0;
		$errors         = array();

		foreach ( $configs_data as $config_data ) {
			try {
				$result = $this->import_metabox_config( $config_data );
				if ( $result ) {
					++$imported_count;
				} else {
					$errors[] = 'Failed to import MetaBox configuration: ' . ( $config_data['title'] ?? 'Unknown' );
				}
			} catch ( Exception $e ) {
				$errors[] = 'Error importing MetaBox configuration: ' . $e->getMessage();
			}
		}

		if ( ! empty( $errors ) ) {
			$this->error_handler->log_error(
				'E302',
				array(
					'errors'         => $errors,
					'imported_count' => $imported_count,
					'action'         => 'MetaBox configurations import completed with errors',
				)
			);
		}

		return array(
			'imported_count' => $imported_count,
			'errors'         => $errors,
		);
	}

	/**
	 * Import single MetaBox configuration
	 */
	private function import_metabox_config( $config_data ) {
		// Check if configuration already exists
		$existing_config = get_page_by_path( $config_data['post_name'], OBJECT, 'meta-box' );

		if ( $existing_config ) {
			// Update existing configuration
			$post_id = $existing_config->ID;

			$post_data = array(
				'ID'           => $post_id,
				'post_title'   => $config_data['title'],
				'post_content' => $config_data['post_content'],
				'post_excerpt' => $config_data['post_excerpt'],
				'post_status'  => $config_data['post_status'],
			);

			$result = wp_update_post( $post_data );

			if ( is_wp_error( $result ) ) {
				$this->error_handler->log_error(
					'E302',
					array(
						'config_title' => $config_data['title'],
						'error'        => $result->get_error_message(),
						'action'       => 'Failed to update MetaBox configuration',
					)
				);
				return false;
			}
		} else {
			// Create new configuration
			$post_data = array(
				'post_title'   => $config_data['title'],
				'post_name'    => $config_data['post_name'],
				'post_content' => $config_data['post_content'],
				'post_excerpt' => $config_data['post_excerpt'],
				'post_status'  => $config_data['post_status'],
				'post_type'    => 'meta-box',
				'post_date'    => $config_data['post_date'],
			);

			$post_id = wp_insert_post( $post_data );

			if ( is_wp_error( $post_id ) ) {
				$this->error_handler->log_error(
					'E302',
					array(
						'config_title' => $config_data['title'],
						'error'        => $post_id->get_error_message(),
						'action'       => 'Failed to create MetaBox configuration',
					)
				);
				return false;
			}
		}

		// Import meta data
		if ( ! empty( $config_data['meta'] ) ) {
			foreach ( $config_data['meta'] as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

		return true;
	}

	/**
	 * Migrate MetaBox configurations
	 */
	public function migrate_metabox_configs( $target_url, $jwt_token ) {
		$configs = $this->export_metabox_configs();

		if ( empty( $configs ) ) {
			return true; // No configurations to migrate
		}

		$api_client = $this->api_client;
		if ( null === $api_client ) {
			$api_client = new EFS_API_Client( $this->error_handler );
		}
		$result = $api_client->send_metabox_configs( $target_url, $jwt_token, $configs );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get MetaBox configuration statistics
	 */
	public function get_metabox_stats() {
		if ( ! function_exists( 'rwmb_meta' ) ) {
			return array(
				'total_configs' => 0,
				'total_fields'  => 0,
				'field_types'   => array(),
			);
		}

		$configs = get_posts(
			array(
				'post_type'   => 'meta-box',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		$total_configs = count( $configs );
		$total_fields  = 0;
		$field_types   = array();

		foreach ( $configs as $config ) {
			$meta_data = get_post_meta( $config->ID );

			foreach ( $meta_data as $meta_key => $meta_values ) {
				if ( 0 === strpos( $meta_key, '_field_' ) ) {
					++$total_fields;

					$field_data = maybe_unserialize( $meta_values[0] );
					if ( is_array( $field_data ) && isset( $field_data['type'] ) ) {
						$type                 = $field_data['type'];
						$field_types[ $type ] = ( $field_types[ $type ] ?? 0 ) + 1;
					}
				}
			}
		}

		return array(
			'total_configs' => $total_configs,
			'total_fields'  => $total_fields,
			'field_types'   => $field_types,
		);
	}

	/**
	 * Parse MetaBox field configuration
	 */
	public function parse_metabox_field( $field_data ) {
		if ( ! is_array( $field_data ) ) {
			return null;
		}

		$field = array(
			'name'                                      => $field_data['name'] ?? '',
			'label'                                     => $field_data['label'] ?? '',
			'type'                                      => $field_data['type'] ?? 'text',
			'description'                               => $field_data['description'] ?? '',
			'required'                                  => $field_data['required'] ?? false,
			'placeholder'                               => $field_data['placeholder'] ?? '',
			'default'                                   => $field_data['default'] ?? '',
			'options'                                   => $field_data['options'] ?? array(),
			'min'                                       => $field_data['min'] ?? '',
			'max'                                       => $field_data['max'] ?? '',
			'step'                                      => $field_data['step'] ?? '',
			'size'                                      => $field_data['size'] ?? '',
			'multiple'                                  => $field_data['multiple'] ?? false,
			'clone'                                     => $field_data['clone'] ?? false,
			'sort_clone'                                => $field_data['sort_clone'] ?? false,
			'max_clone'                                 => $field_data['max_clone'] ?? '',
			'min_clone'                                 => $field_data['min_clone'] ?? '',
			'add_button'                                => $field_data['add_button'] ?? '',
			'std'                                       => $field_data['std'] ?? '',
			'desc'                                      => $field_data['desc'] ?? '',
			'id'                                        => $field_data['id'] ?? '',
			'class'                                     => $field_data['class'] ?? '',
			'before'                                    => $field_data['before'] ?? '',
			'after'                                     => $field_data['after'] ?? '',
			'field_name'                                => $field_data['field_name'] ?? '',
			'index_name'                                => $field_data['index_name'] ?? '',
			'save_field'                                => $field_data['save_field'] ?? true,
			'admin_columns'                             => $field_data['admin_columns'] ?? false,
			'admin_columns_sort'                        => $field_data['admin_columns_sort'] ?? false,
			'admin_columns_filter'                      => $field_data['admin_columns_filter'] ?? false,
			'admin_columns_search'                      => $field_data['admin_columns_search'] ?? false,
			'admin_columns_before'                      => $field_data['admin_columns_before'] ?? '',
			'admin_columns_after'                       => $field_data['admin_columns_after'] ?? '',
			'admin_columns_link'                        => $field_data['admin_columns_link'] ?? '',
			'admin_columns_link_target'                 => $field_data['admin_columns_link_target'] ?? '',
			'admin_columns_link_rel'                    => $field_data['admin_columns_link_rel'] ?? '',
			'admin_columns_link_class'                  => $field_data['admin_columns_link_class'] ?? '',
			'admin_columns_link_title'                  => $field_data['admin_columns_link_title'] ?? '',
			'admin_columns_link_text'                   => $field_data['admin_columns_link_text'] ?? '',
			'admin_columns_link_callback'               => $field_data['admin_columns_link_callback'] ?? '',
			'admin_columns_link_callback_args'          => $field_data['admin_columns_link_callback_args'] ?? array(),
			'admin_columns_link_callback_return'        => $field_data['admin_columns_link_callback_return'] ?? '',
			'admin_columns_link_callback_return_format' => $field_data['admin_columns_link_callback_return_format'] ?? '',
			'admin_columns_link_callback_return_format_args' => $field_data['admin_columns_link_callback_return_format_args'] ?? array(),
		);

		return $field;
	}

	/**
	 * Convert MetaBox field to ACF format
	 */
	public function convert_metabox_to_acf( $metabox_field ) {
		$acf_field = array(
			'name'          => $metabox_field['name'],
			'label'         => $metabox_field['label'],
			'type'          => $this->convert_metabox_field_type_to_acf( $metabox_field['type'] ),
			'instructions'  => $metabox_field['description'] ?? '',
			'required'      => $metabox_field['required'] ?? false,
			'default_value' => $metabox_field['default'] ?? '',
			'placeholder'   => $metabox_field['placeholder'] ?? '',
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

			case 'number':
				$acf_field['min']  = $metabox_field['min'] ?? '';
				$acf_field['max']  = $metabox_field['max'] ?? '';
				$acf_field['step'] = $metabox_field['step'] ?? '';
				break;

			case 'select':
			case 'checkbox_list':
			case 'radio':
				$acf_field['choices'] = $metabox_field['options'] ?? array();
				break;

			case 'date':
				$acf_field['display_format'] = 'Y-m-d';
				$acf_field['return_format']  = 'Y-m-d';
				break;

			case 'time':
				$acf_field['display_format'] = 'H:i';
				$acf_field['return_format']  = 'H:i';
				break;

			case 'datetime':
				$acf_field['display_format'] = 'Y-m-d H:i';
				$acf_field['return_format']  = 'Y-m-d H:i';
				break;

			case 'color':
				$acf_field['default_value'] = $metabox_field['default'] ?? '';
				break;

			case 'slider':
				$acf_field['min']  = $metabox_field['min'] ?? '';
				$acf_field['max']  = $metabox_field['max'] ?? '';
				$acf_field['step'] = $metabox_field['step'] ?? '';
				break;

			case 'group':
				$acf_field['layout'] = 'block';
				break;
		}

		return $acf_field;
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
}
