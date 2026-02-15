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
	 * @param array $children Child elements (converted block HTML)
	 * @param array $context Optional. Conversion context (e.g. element_map, convert_callback for slotChildren).
	 * @return string Gutenberg block HTML
	 */
	abstract public function convert( $element, $children = array(), $context = array() );

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
		$tag = $element['settings']['tag'] ?? $fallback_tag;

		if ( ! is_string( $tag ) || '' === $tag ) {
			return (string) $fallback_tag;
		}

		return $tag;
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
		return array(
			'metadata'   => array(
				'name' => (string) $label,
			),
			'tag'        => (string) $tag,
			'attributes' => $this->normalize_attributes( $etch_attributes ),
			'styles'     => $this->normalize_style_ids( $style_ids ),
		);
	}

	/**
	 * Generate an Etch element block with comment-only serialization.
	 *
	 * @param array        $attrs Block attributes.
	 * @param string|array $children_html Optional. Inner block HTML.
	 * @return string Gutenberg block HTML.
	 */
	protected function generate_etch_element_block( $attrs, $children_html = '' ) {
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$content    = is_array( $children_html ) ? implode( "\n", $children_html ) : (string) $children_html;
		$content    = trim( $content );

		if ( '' === $content ) {
			return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
				'<!-- /wp:etch/element -->';
		}

		return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
			$content . "\n" .
			'<!-- /wp:etch/element -->';
	}

	/**
	 * Generate an etch/text inner block.
	 *
	 * @param string $content Text/HTML content for the text block.
	 * @return string Gutenberg block HTML.
	 */
	protected function generate_etch_text_block( $content ) {
		$attrs = array(
			'content' => (string) $content,
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/text ' . $attrs_json . ' /-->';
	}

	/**
	 * Normalize style IDs to a unique array of strings.
	 *
	 * @param array $style_ids Raw style IDs.
	 * @return array Normalized style IDs.
	 */
	protected function normalize_style_ids( $style_ids ) {
		$normalized = array();

		if ( ! is_array( $style_ids ) ) {
			return $normalized;
		}

		foreach ( $style_ids as $style_id ) {
			if ( ! is_scalar( $style_id ) ) {
				continue;
			}

			$style_id = trim( (string) $style_id );
			if ( '' === $style_id ) {
				continue;
			}

			$normalized[] = $style_id;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize Etch attributes to string-valued key/value pairs.
	 *
	 * @param array $etch_attributes Raw attributes.
	 * @return array Normalized attributes.
	 */
	protected function normalize_attributes( $etch_attributes ) {
		$normalized = array();

		if ( ! is_array( $etch_attributes ) ) {
			return $normalized;
		}

		foreach ( $etch_attributes as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$normalized[ $key ] = (string) $value;
		}

		return $normalized;
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
