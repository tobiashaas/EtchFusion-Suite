<?php
/**
 * Base Element Converter
 *
 * Abstract base class for all element converters
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class EFS_Base_Element {

	/**
	 * Element type (e.g., 'container', 'section', 'heading')
	 */
	protected $element_type;

	/**
	 * Style map for CSS classes
	 */
	protected $style_map;

	/**
	 * Constructor
	 */
	public function __construct( $style_map = array() ) {
		$this->style_map = $style_map;
	}

	/**
	 * Convert element to Etch format
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements
	 * @return string Gutenberg block HTML
	 */
	abstract public function convert( $element, $children = array() );

	/**
	 * Get style IDs for element
	 *
	 * @param array $element Bricks element
	 * @return array Style IDs
	 */
	protected function get_style_ids( $element ) {
		$style_ids = array();

		// Get Global Classes from settings
		if ( isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
				if ( isset( $this->style_map[ $class_id ]['id'] ) ) {
					$style_ids[] = $this->style_map[ $class_id ]['id'];
				}
			}
		}

		return $style_ids;
	}

	/**
	 * Get CSS classes from style IDs
	 *
	 * @param array $style_ids Style IDs
	 * @return string CSS classes (space-separated)
	 */
	protected function get_css_classes( $style_ids ) {
		$classes = array();

		foreach ( $style_ids as $style_id ) {
			// Skip Etch-internal styles
			if ( strpos( $style_id, 'etch-' ) === 0 ) {
				continue;
			}

			// Find selector in style map
			foreach ( $this->style_map as $bricks_id => $etch_data ) {
				if ( $etch_data['id'] === $style_id && isset( $etch_data['selector'] ) ) {
					// Remove leading dot from selector
					$class     = ltrim( $etch_data['selector'], '.' );
					$classes[] = $class;
					break;
				}
			}
		}

		return implode( ' ', $classes );
	}

	/**
	 * Get element label
	 *
	 * @param array $element Bricks element
	 * @return string Label
	 */
	protected function get_label( $element ) {
		return $element['label'] ?? ucfirst( $this->element_type );
	}

	/**
	 * Get element tag
	 *
	 * @param array $element Bricks element
	 * @param string $fallback_tag Default tag
	 * @return string HTML tag
	 */
	protected function get_tag( $element, $fallback_tag = 'div' ) {
		return $element['settings']['tag'] ?? $fallback_tag;
	}

	/**
	 * Build Gutenberg block attributes
	 *
	 * @param string $label Element label
	 * @param array $style_ids Style IDs
	 * @param array $etch_attributes Etch attributes
	 * @param string $tag HTML tag
	 * @return array Block attributes
	 */
	protected function build_attributes( $label, $style_ids, $etch_attributes, $tag = 'div' ) {
		$attrs = array(
			'metadata' => array(
				'name'     => $label,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $label,
					'styles'     => $style_ids,
					'attributes' => $etch_attributes,
					'block'      => array(
						'type' => 'html',
						'tag'  => $tag,
					),
				),
			),
		);

		// Add tagName for Gutenberg if not div
		if ( 'div' !== $tag ) {
			$attrs['tagName'] = $tag;
		}

		return $attrs;
	}

	/**
	 * Convert children to HTML
	 *
	 * @param array $children Child elements
	 * @param array $element_map All elements
	 * @return string Children HTML
	 */
	protected function convert_children( $children, $element_map ) {
		$children_html = '';

		foreach ( $children as $child ) {
			// This will be handled by the main converter
			// For now, just return empty string
			// The actual conversion happens in the main generator
		}

		return $children_html;
	}
}
