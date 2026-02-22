<?php
/**
 * Div Element Converter
 *
 * Converts Bricks Div/Block to standard Etch div/semantic elements.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Div extends EFS_Base_Element {

	protected $element_type = 'div';

	/**
	 * Convert div element
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (already converted HTML)
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		// Get style IDs
		$style_ids = $this->get_style_ids( $element );

		// Get CSS classes
		$css_classes = $this->get_css_classes( $style_ids );

		// Get tag (can be li, span, article, etc.)
		$tag = $this->get_tag( $element, 'div' );

		// Get label
		$label = $this->get_label( $element );

		// Build Etch attributes (no deprecated flex-div marker)
		$etch_attributes = array();

		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		// Bricks block-type elements have implicit `display: flex; flex-direction: column;`
		// via the built-in brxe-block class. Ensure equivalent display is present in Etch.
		if ( 'block' === ( $element['name'] ?? '' ) ) {
			$etch_attributes = $this->apply_brxe_block_display( $style_ids, $etch_attributes, $element, $css_classes );
		}

		// Build block attributes
		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );

		// Build children HTML
		$children_html = is_array( $children ) ? implode( "\n", $children ) : $children;

		// Build block HTML (Etch v1.1+ comment-only block, no saved wrapper markup).
		return $this->generate_etch_element_block( $attrs, $children_html );
	}
}
