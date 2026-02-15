<?php
/**
 * Component Migrator for Bricks to Etch Migration Plugin
 *
 * Migrates Bricks components from bricks_components option to Etch wp_block posts
 * via REST API. Handles props mapping, slots conversion, and ID mapping for
 * content migration (cid → ref).
 *
 * @package Bricks2Etch\Migrators
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Converters\EFS_Element_Factory;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrator for Bricks components to Etch wp_block (reusable blocks).
 */
class EFS_Component_Migrator extends Abstract_Migrator {

	/**
	 * Maximum depth for nested component resolution.
	 */
	const MAX_REFERENCE_DEPTH = 10;

	/**
	 * Human-readable migrator name.
	 *
	 * @var string
	 */
	protected $name = 'Bricks Components';

	/**
	 * Migrator type identifier.
	 *
	 * @var string
	 */
	protected $type = 'component';

	/**
	 * Priority (15 = after CPTs 10, before content migration).
	 *
	 * @var int
	 */
	protected $priority = 15;

	/**
	 * Bricks component ID → Etch post ID mapping.
	 *
	 * @var array<string, int>
	 */
	protected $component_map = array();

	/**
	 * Element factory for converting elements to Gutenberg blocks.
	 *
	 * @var EFS_Element_Factory|null
	 */
	protected $element_factory;

	/**
	 * CSS class mapping from migration context (Bricks ID => Etch style data).
	 *
	 * @var array
	 */
	protected $style_map;

	/**
	 * Constructor.
	 *
	 * @param EFS_Error_Handler $error_handler Error handler instance.
	 * @param EFS_API_Client    $api_client    API client instance.
	 * @param array             $style_map    Optional. Style map for CSS class mapping.
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null, $style_map = array() ) {
		parent::__construct( $error_handler, $api_client );
		$this->style_map = is_array( $style_map ) ? $style_map : array();
	}

	/**
	 * Whether the migrator supports the current environment.
	 *
	 * @return bool
	 */
	public function supports() {
		$components = get_option( 'bricks_components', array() );
		return ! empty( $components ) && is_array( $components );
	}

	/**
	 * Validates component data and structure.
	 *
	 * @return array{valid: bool, errors: string[]}
	 */
	public function validate() {
		$components = get_option( 'bricks_components', array() );
		$errors     = array();

		if ( empty( $components ) || ! is_array( $components ) ) {
			return array(
				'valid'  => true,
				'errors' => array(),
			);
		}

		foreach ( $components as $component ) {
			if ( empty( $component['id'] ) ) {
				$errors[] = __( 'Component missing required field: id', 'etch-fusion-suite' );
			}
			if ( empty( $component['label'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: component id */
					__( 'Component %s missing required field: label', 'etch-fusion-suite' ),
					$component['id'] ?? 'unknown'
				);
			}
			if ( ! isset( $component['elements'] ) || ! is_array( $component['elements'] ) ) {
				$errors[] = sprintf(
					/* translators: %s: component id */
					__( 'Component %s missing or invalid elements', 'etch-fusion-suite' ),
					$component['id'] ?? 'unknown'
				);
			}
		}

		$by_id = array();
		foreach ( $components as $c ) {
			if ( ! empty( $c['id'] ) ) {
				$by_id[ $c['id'] ] = $c;
			}
		}
		$circular = $this->detect_circular_references( $components, $by_id );
		if ( ! empty( $circular ) ) {
			$errors = array_merge( $errors, $circular );
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Detects circular references in nested components.
	 *
	 * @param array $components List of all components.
	 * @param array $by_id      Component id => component (for lookup).
	 * @return string[] Error messages.
	 */
	protected function detect_circular_references( $components, $by_id = array() ) {
		if ( empty( $by_id ) ) {
			foreach ( (array) $components as $c ) {
				if ( ! empty( $c['id'] ) ) {
					$by_id[ $c['id'] ] = $c;
				}
			}
		}
		$errors = array();
		foreach ( $components as $component ) {
			$chain = array();
			$found = $this->check_circular_for_component( $component, $by_id, $chain );
			if ( $found ) {
				$errors[] = sprintf(
					/* translators: %s: component id */
					__( 'Circular reference detected in component: %s', 'etch-fusion-suite' ),
					$component['id'] ?? 'unknown'
				);
			}
		}
		return $errors;
	}

	/**
	 * Recursively check for circular reference from a component.
	 *
	 * @param array $component  Current component.
	 * @param array $by_id      Component id => component.
	 * @param array $chain     Current chain of component IDs.
	 * @return bool True if circular.
	 */
	protected function check_circular_for_component( $component, $by_id, &$chain ) {
		$id = $component['id'] ?? null;
		if ( ! $id ) {
			return false;
		}
		if ( in_array( $id, $chain, true ) ) {
			return true;
		}
		$chain[]  = $id;
		$elements = $component['elements'] ?? array();
		foreach ( $elements as $el ) {
			$cid = $el['cid'] ?? null;
			if ( $cid && isset( $by_id[ $cid ] ) ) {
				if ( $this->check_circular_for_component( $by_id[ $cid ], $by_id, $chain ) ) {
					return true;
				}
			}
		}
		array_pop( $chain );
		return false;
	}

	/**
	 * Export component data (for backup/preview).
	 *
	 * @return array
	 */
	public function export() {
		return get_option( 'bricks_components', array() );
	}

	/**
	 * Import not implemented; components are created on target via REST API.
	 *
	 * @param array $data Exported data.
	 * @return WP_Error
	 */
	public function import( $data ) {
		return new WP_Error(
			'component_import_unsupported',
			__( 'Component import is not supported; components are created on the target site via REST API.', 'etch-fusion-suite' )
		);
	}

	/**
	 * Run component migration: convert and push each component to target.
	 *
	 * @param string $target_url Target site URL.
	 * @param string $jwt_token  Migration JWT.
	 * @param bool   $dry_run    Optional. If true, skip API calls and only log.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function migrate( $target_url, $jwt_token, $dry_run = false ) {
		// Refresh style map at migration time so conversions use the latest Bricks→Etch class mapping.
		$this->style_map = get_option( 'efs_style_map', array() );
		if ( ! is_array( $this->style_map ) ) {
			$this->style_map = array();
		}

		$components = get_option( 'bricks_components', array() );
		if ( empty( $components ) || ! is_array( $components ) ) {
			$this->log_info( 'Component migration: no components to migrate', array() );
			return true;
		}

		$this->component_map   = $this->get_component_mapping();
		$this->element_factory = new EFS_Element_Factory( $this->style_map );

		$reference_chain = array();
		foreach ( $components as $component ) {
			$comp_id = $component['id'] ?? '';
			if ( ! $comp_id || ! isset( $component['elements'] ) ) {
				$this->log_warning(
					'W401',
					array(
						'component_id' => $comp_id,
						'reason'       => 'missing id or elements',
					)
				);
				continue;
			}
			if ( in_array( $comp_id, $reference_chain, true ) ) {
				$this->log_warning(
					'W401',
					array(
						'component_id' => $comp_id,
						'reason'       => 'circular reference skipped',
					)
				);
				continue;
			}

			$this->log_info(
				'Component migration start',
				array(
					'component_id' => $comp_id,
					'label'        => $component['label'] ?? '',
				)
			);

			$wp_block_data = $this->convert_component_to_wp_block( $component );
			if ( is_wp_error( $wp_block_data ) ) {
				$this->log_error(
					'E401',
					array(
						'component_id' => $comp_id,
						'error'        => $wp_block_data->get_error_message(),
					)
				);
				continue;
			}

			if ( $dry_run ) {
				$this->log_info(
					'Dry run: would migrate component',
					array(
						'component_id' => $comp_id,
						'post_title'   => $wp_block_data['post_title'],
					)
				);
				continue;
			}

			$etch_post_id = $this->create_component_on_target( $wp_block_data, $target_url, $jwt_token );
			if ( is_wp_error( $etch_post_id ) ) {
				$this->log_error(
					'E403',
					array(
						'component_id' => $comp_id,
						'error'        => $etch_post_id->get_error_message(),
					)
				);
				continue;
			}

			$this->save_component_mapping( $comp_id, $etch_post_id );
			$this->log_info(
				'I401',
				array(
					'component_id' => $comp_id,
					'etch_post_id' => $etch_post_id,
				)
			);
		}

		if ( ! $dry_run ) {
			update_option( 'b2e_component_map', $this->component_map );
		}

		return true;
	}

	/**
	 * Get statistics for components.
	 *
	 * @return array{total: int, with_props: int, with_slots: int}
	 */
	public function get_stats() {
		$components = get_option( 'bricks_components', array() );
		if ( ! is_array( $components ) ) {
			return array(
				'total'      => 0,
				'with_props' => 0,
				'with_slots' => 0,
			);
		}
		$total      = count( $components );
		$with_props = 0;
		$with_slots = 0;
		foreach ( $components as $c ) {
			if ( ! empty( $c['properties'] ) && is_array( $c['properties'] ) ) {
				++$with_props;
			}
			$elements = $c['elements'] ?? array();
			foreach ( $elements as $el ) {
				if ( $this->is_slot_element( $el ) ) {
					++$with_slots;
					break;
				}
			}
		}
		return array(
			'total'      => $total,
			'with_props' => $with_props,
			'with_slots' => $with_slots,
		);
	}

	/**
	 * Convert a single Bricks component to wp_block post data.
	 *
	 * @param array $component Bricks component (id, label, properties?, elements).
	 * @return array|WP_Error wp_block post data or error.
	 */
	public function convert_component_to_wp_block( $component ) {
		$comp_id    = $component['id'] ?? '';
		$label      = $component['label'] ?? '';
		$properties = $component['properties'] ?? array();
		$elements   = $component['elements'] ?? array();

		if ( ! $comp_id || ! $label ) {
			return new WP_Error( 'E402', __( 'Component missing id or label', 'etch-fusion-suite' ) );
		}

		$converted_properties = $this->convert_properties( $properties );
		$element_map          = array();
		$parent_to_children   = array();
		foreach ( $elements as $el ) {
			$eid = $el['id'] ?? null;
			if ( $eid ) {
				$element_map[ $eid ] = $el;
				$parent              = $el['parent'] ?? $comp_id;
				if ( ! isset( $parent_to_children[ $parent ] ) ) {
					$parent_to_children[ $parent ] = array();
				}
				$parent_to_children[ $parent ][] = $eid;
			}
		}

		// Resolve property connections to element attributes (element_id => [ setting_key => default value ]).
		$connection_map = $this->map_property_connections( $properties, $elements );
		$props_by_id    = array();
		foreach ( $properties as $p ) {
			$pid = $p['id'] ?? null;
			if ( null !== $pid && '' !== $pid ) {
				$props_by_id[ $pid ] = $p;
			}
		}
		$resolved_connections = array();
		foreach ( $connection_map as $conn ) {
			$eid = $conn['element_id'];
			$key = $conn['setting_key'];
			$val = isset( $props_by_id[ $conn['property_id'] ] ) ? ( $props_by_id[ $conn['property_id'] ]['default'] ?? null ) : null;
			if ( ! isset( $resolved_connections[ $eid ] ) ) {
				$resolved_connections[ $eid ] = array();
			}
			$resolved_connections[ $eid ][ $key ] = $val;
		}

		$root_ids = isset( $parent_to_children[ $comp_id ] ) ? $parent_to_children[ $comp_id ] : array();
		if ( empty( $root_ids ) ) {
			foreach ( $elements as $el ) {
				$pid = $el['parent'] ?? '';
				if ( $comp_id === $pid || '' === $pid || '0' === $pid ) {
					$root_ids[] = $el['id'];
				}
			}
		}
		if ( empty( $root_ids ) && ! empty( $element_map ) ) {
			$root_ids = array_keys( $element_map );
		}

		$blocks_html_parts = array();
		foreach ( $root_ids as $root_id ) {
			if ( ! isset( $element_map[ $root_id ] ) ) {
				continue;
			}
			$child_ids = isset( $parent_to_children[ $root_id ] ) ? $parent_to_children[ $root_id ] : array();
			$html      = $this->convert_element_tree( $element_map[ $root_id ], $element_map, $parent_to_children, $resolved_connections );
			if ( '' !== $html ) {
				$blocks_html_parts[] = $html;
			}
		}
		$gutenberg_blocks_html = implode( "\n", $blocks_html_parts );

		$post_data = array(
			'post_title'   => $label,
			'post_content' => $gutenberg_blocks_html,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'meta'         => array(
				'etch_component_html_key'   => sanitize_title( $label ),
				'etch_component_properties' => $converted_properties,
			),
		);
		return $post_data;
	}

	/**
	 * Map Bricks property types to Etch property schema.
	 *
	 * @param array $properties Bricks properties (id, type, default, connections?).
	 * @return array Etch-compatible properties keyed by property ID.
	 */
	public function convert_properties( $properties ) {
		$result = array();
		if ( ! is_array( $properties ) ) {
			return $result;
		}
		foreach ( $properties as $prop ) {
			$pid = $prop['id'] ?? null;
			if ( null === $pid || '' === $pid ) {
				continue;
			}
			$type    = $prop['type'] ?? 'text';
			$default = $prop['default'] ?? null;
			switch ( $type ) {
				case 'text':
					$result[ $pid ] = array(
						'type'    => 'string',
						'default' => (string) $default,
					);
					break;
				case 'toggle':
					$result[ $pid ] = array(
						'type'    => 'boolean',
						'default' => (bool) $default,
					);
					break;
				case 'class':
					$style_ids = array();
					if ( is_array( $default ) ) {
						foreach ( $default as $class_id ) {
							if ( isset( $this->style_map[ $class_id ]['id'] ) ) {
								$style_ids[] = $this->style_map[ $class_id ]['id'];
							}
						}
					}
					$result[ $pid ] = array(
						'type'    => 'array',
						'default' => $style_ids,
					);
					break;
				case 'number':
					$result[ $pid ] = array(
						'type'    => 'number',
						'default' => (float) $default,
					);
					break;
				default:
					$result[ $pid ] = array(
						'type'    => 'string',
						'default' => null !== $default ? (string) $default : '',
					);
			}
		}
		return $result;
	}

	/**
	 * Map property connections to block attributes (for use during element conversion).
	 *
	 * @param array $properties Bricks properties with connections.
	 * @param array $elements   Component elements.
	 * @return array Connection map.
	 */
	public function map_property_connections( $properties, $elements ) {
		$map = array();
		foreach ( (array) $properties as $prop ) {
			$connections = $prop['connections'] ?? array();
			if ( empty( $connections ) || ! is_array( $connections ) ) {
				continue;
			}
			foreach ( $connections as $conn ) {
				$target_id = $conn['element'] ?? $conn['id'] ?? null;
				$setting   = $conn['setting'] ?? $conn['key'] ?? null;
				if ( $target_id && $setting ) {
					$map[] = array(
						'property_id' => $prop['id'] ?? '',
						'element_id'  => $target_id,
						'setting_key' => $setting,
					);
				}
			}
		}
		return $map;
	}

	/**
	 * Convert a Bricks slot element to Etch slot block HTML.
	 *
	 * @param array  $slot_element Slot element (name === 'slot').
	 * @param string $component_id Parent component ID.
	 * @return string Gutenberg block HTML for etch/slot.
	 */
	public function convert_slot_element( $slot_element, $component_id = '' ) {
		$slot_id    = $slot_element['id'] ?? '';
		$attrs      = array(
			'name'   => 'Slot',
			'slotId' => $slot_id,
		);
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '<!-- wp:etch/slot ' . $attrs_json . ' /-->';
	}

	/**
	 * Handle slotChildren during component instance conversion (for content migration).
	 *
	 * @param array $element  Element with slotChildren.
	 * @param array $slot_map Slot ID => child element IDs.
	 * @return array Slot children map for innerBlocks.
	 */
	public function handle_slot_children( $element, $slot_map = array() ) {
		$slot_children = $element['slotChildren'] ?? array();
		if ( ! is_array( $slot_children ) ) {
			return array();
		}
		$result = array();
		foreach ( $slot_children as $slot_id => $child_ids ) {
			$result[ $slot_id ] = is_array( $child_ids ) ? $child_ids : array();
		}
		return $result;
	}

	/**
	 * Recursively convert element tree to Gutenberg block HTML.
	 *
	 * @param array $element               Current element.
	 * @param array $element_map          ID => element.
	 * @param array $children_map         Parent ID => list of child IDs.
	 * @param array $resolved_connections  Optional. element_id => [ setting_key => value ] from map_property_connections.
	 * @return string Block HTML.
	 */
	public function convert_element_tree( $element, $element_map, $children_map, $resolved_connections = array() ) {
		if ( $this->is_slot_element( $element ) ) {
			return $this->convert_slot_element( $element, '' );
		}

		$child_ids     = isset( $children_map[ $element['id'] ] ) ? $children_map[ $element['id'] ] : array();
		$children_html = array();
		foreach ( $child_ids as $cid ) {
			if ( isset( $element_map[ $cid ] ) ) {
				$ch_html = $this->convert_element_tree( $element_map[ $cid ], $element_map, $children_map, $resolved_connections );
				if ( '' !== $ch_html ) {
					$children_html[] = $ch_html;
				}
			}
		}
		$children = implode( "\n", $children_html );

		// Apply property connections to this element's settings so block attributes reflect connected props.
		$element_to_convert = $element;
		$eid                = $element['id'] ?? '';
		if ( '' !== $eid && isset( $resolved_connections[ $eid ] ) && is_array( $resolved_connections[ $eid ] ) ) {
			$element_to_convert['settings'] = array_merge(
				isset( $element_to_convert['settings'] ) && is_array( $element_to_convert['settings'] ) ? $element_to_convert['settings'] : array(),
				$resolved_connections[ $eid ]
			);
		}

		if ( ! $this->element_factory ) {
			$this->element_factory = new EFS_Element_Factory( $this->style_map );
		}
		$converted = $this->element_factory->convert_element( $element_to_convert, array( $children ) );
		return null !== $converted ? $converted : '';
	}

	/**
	 * Check if element is a Bricks slot.
	 *
	 * @param array $element Element data.
	 * @return bool
	 */
	public function is_slot_element( $element ) {
		return isset( $element['name'] ) && 'slot' === $element['name'];
	}

	/**
	 * Create component on target site via REST API.
	 *
	 * @param array  $wp_block_data wp_block post data from convert_component_to_wp_block.
	 * @param string $target_url    Target site URL.
	 * @param string $jwt_token    Migration JWT.
	 * @return int|WP_Error Etch post ID or error.
	 */
	public function create_component_on_target( $wp_block_data, $target_url, $jwt_token ) {
		$payload = array(
			'name'        => $wp_block_data['post_title'],
			'blocks'      => $wp_block_data['post_content'],
			'description' => '',
			'properties'  => isset( $wp_block_data['meta']['etch_component_properties'] ) ? $wp_block_data['meta']['etch_component_properties'] : array(),
			'key'         => isset( $wp_block_data['meta']['etch_component_html_key'] ) ? $wp_block_data['meta']['etch_component_html_key'] : '',
		);

		$max_attempts = 3;
		$backoff      = array( 1, 2, 4 );
		$last_error   = null;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$result = $this->api_client->send_authorized_request( $target_url, $jwt_token, '/components', 'POST', $payload, 'etch/v1' );
			if ( ! is_wp_error( $result ) ) {
				$post_id = isset( $result['id'] ) ? (int) $result['id'] : ( isset( $result['post_id'] ) ? (int) $result['post_id'] : 0 );
				if ( $post_id > 0 ) {
					return $post_id;
				}
				return new WP_Error( 'E403', __( 'Component API response missing post id', 'etch-fusion-suite' ) );
			}
			$last_error = $result;
			$this->log_warning(
				'E403',
				array(
					'attempt' => $attempt + 1,
					'error'   => $result->get_error_message(),
				)
			);
			if ( $attempt < $max_attempts - 1 && isset( $backoff[ $attempt ] ) ) {
				sleep( $backoff[ $attempt ] );
			}
		}

		return $last_error;
	}

	/**
	 * Store component ID mapping and persist to option.
	 *
	 * @param string $bricks_id   Bricks component ID.
	 * @param int    $etch_post_id Etch wp_block post ID.
	 */
	public function save_component_mapping( $bricks_id, $etch_post_id ) {
		$this->component_map[ $bricks_id ] = (int) $etch_post_id;
		update_option( 'b2e_component_map', $this->component_map );
	}

	/**
	 * Retrieve full component mapping from option.
	 *
	 * @return array<string, int>
	 */
	public function get_component_mapping() {
		return get_option( 'b2e_component_map', array() );
	}

	/**
	 * Get Etch component post ID for a Bricks component ID (for content migration).
	 *
	 * @param string $bricks_id Bricks component ID.
	 * @return int|null Etch post ID or null if not found.
	 */
	public static function get_etch_component_id( $bricks_id ) {
		$map = get_option( 'b2e_component_map', array() );
		return isset( $map[ $bricks_id ] ) ? (int) $map[ $bricks_id ] : null;
	}
}
