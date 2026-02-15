<?php
/**
 * Component Element Converter
 *
 * Converts Bricks component instances (template element with cid) to Etch
 * etch/component blocks. Resolves Bricks component ID to Etch post ID via
 * component mapping (b2e_component_map).
 *
 * @package Bricks2Etch\Converters\Elements
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;
use Bricks2Etch\Migrators\EFS_Component_Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Bricks component instance to etch/component block.
 */
class EFS_Element_Component extends EFS_Base_Element {

	/**
	 * Element type (Bricks template = component instance).
	 *
	 * @var string
	 */
	protected $element_type = 'template';

	/**
	 * Convert Bricks component instance to etch/component block.
	 *
	 * @param array $element  Bricks element (must have cid for component ref).
	 * @param array $children Child block HTML (e.g. from recursive conversion).
	 * @param array $context Optional. Must contain element_map and convert_callback to resolve slotChildren to innerBlocks.
	 * @return string Gutenberg block HTML for etch/component.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$cid = $element['cid'] ?? null;
		if ( empty( $cid ) ) {
			return '';
		}

		$etch_post_id = EFS_Component_Migrator::get_etch_component_id( $cid );
		if ( null === $etch_post_id ) {
			return '';
		}

		$attributes  = $this->convert_instance_properties( $element );
		$slot_blocks = $this->convert_slot_children( $element, $context );

		$attrs      = array(
			'ref'        => $etch_post_id,
			'attributes' => $attributes,
		);
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$inner_parts = array();
		if ( ! empty( $slot_blocks ) && is_array( $slot_blocks ) ) {
			$inner_parts = array_merge( $inner_parts, $slot_blocks );
		}
		if ( is_array( $children ) && ! empty( $children ) ) {
			$inner_parts = array_merge( $inner_parts, $children );
		}
		$inner_html = implode( "\n", $inner_parts );

		return '<!-- wp:etch/component ' . $attrs_json . ' -->' . "\n" .
			$inner_html . "\n" .
			'<!-- /wp:etch/component -->';
	}

	/**
	 * Map instance properties to Etch component attributes.
	 * Normalizes values: class-type → map Bricks class IDs to Etch style IDs via style_map;
	 * booleans/toggles → bool; numeric → float/int; text → string. Uses the same mapping
	 * logic as EFS_Component_Migrator::convert_properties() so instance attributes match
	 * the Etch component property schema.
	 *
	 * @param array $element Bricks element with properties/settings.
	 * @return array<string, mixed> Attributes for etch/component.
	 */
	public function convert_instance_properties( $element ) {
		$properties = $element['properties'] ?? $element['settings']['properties'] ?? array();
		if ( ! is_array( $properties ) ) {
			return array();
		}
		$attrs = array();
		foreach ( $properties as $key => $value ) {
			$attrs[ $key ] = $this->normalize_instance_property_value( $value );
		}
		return $attrs;
	}

	/**
	 * Normalize a single instance property value for Etch schema.
	 * Class-type (array of Bricks class IDs) → Etch style IDs; toggle → bool; number → float/int; else string.
	 *
	 * @param mixed $value Raw value from Bricks instance.
	 * @return mixed Normalized value (array of style IDs, bool, float|int, or string).
	 */
	protected function normalize_instance_property_value( $value ) {
		// Class-type: array of Bricks class IDs → map to Etch style IDs (same as convert_properties).
		if ( is_array( $value ) ) {
			$style_ids = array();
			$style_map = is_array( $this->style_map ) ? $this->style_map : array();
			foreach ( $value as $class_id ) {
				if ( isset( $style_map[ $class_id ]['id'] ) ) {
					$style_ids[] = $style_map[ $class_id ]['id'];
				} else {
					$style_ids[] = $class_id;
				}
			}
			return $style_ids;
		}

		// Boolean / toggle.
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( 'true' === $value || '1' === $value ) {
			return true;
		}
		if ( 'false' === $value || '0' === $value ) {
			return false;
		}

		// Numeric.
		if ( is_numeric( $value ) ) {
			return strpos( (string) $value, '.' ) !== false ? (float) $value : (int) $value;
		}

		// Text / default.
		return (string) $value;
	}

	/**
	 * Build innerBlocks from slotChildren (slot ID => child element IDs).
	 * When context provides element_map and convert_callback, resolves child IDs to block HTML
	 * and wraps each slot's content in an etch/slot block so slot content migrates.
	 *
	 * @param array $element Bricks element with slotChildren.
	 * @param array $context Optional. Keys: element_map (id => element), convert_callback (element, element_map) => block HTML.
	 * @return array List of block HTML strings (etch/slot blocks with inner content when context given; otherwise empty).
	 */
	public function convert_slot_children( $element, $context = array() ) {
		$slot_children = $element['slotChildren'] ?? array();
		if ( ! is_array( $slot_children ) ) {
			return array();
		}

		$element_map      = isset( $context['element_map'] ) && is_array( $context['element_map'] ) ? $context['element_map'] : null;
		$convert_callback = isset( $context['convert_callback'] ) && is_callable( $context['convert_callback'] ) ? $context['convert_callback'] : null;

		if ( ! $element_map || ! $convert_callback ) {
			return array();
		}

		$result = array();
		foreach ( $slot_children as $slot_id => $child_ids ) {
			if ( ! is_array( $child_ids ) ) {
				continue;
			}
			$slot_inner_parts = array();
			foreach ( $child_ids as $child_id ) {
				if ( ! isset( $element_map[ $child_id ] ) ) {
					continue;
				}
				$child_html = call_user_func( $convert_callback, $element_map[ $child_id ], $element_map );
				if ( '' !== $child_html && null !== $child_html ) {
					$slot_inner_parts[] = $child_html;
				}
			}
			$result[] = $this->build_slot_block_with_inner( $slot_id, $slot_inner_parts );
		}
		return $result;
	}

	/**
	 * Build a single etch/slot block with inner content (innerBlocks).
	 *
	 * @param string $slot_id   Slot identifier.
	 * @param array  $inner_html Array of block HTML strings for the slot's inner content.
	 * @return string Gutenberg block HTML for etch/slot.
	 */
	protected function build_slot_block_with_inner( $slot_id, $inner_html = array() ) {
		$attrs      = array(
			'name'   => 'Slot',
			'slotId' => $slot_id,
		);
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$inner      = is_array( $inner_html ) && ! empty( $inner_html ) ? implode( "\n", $inner_html ) : '';
		return '<!-- wp:etch/slot ' . $attrs_json . ' -->' . "\n" . $inner . "\n" . '<!-- /wp:etch/slot -->';
	}
}
