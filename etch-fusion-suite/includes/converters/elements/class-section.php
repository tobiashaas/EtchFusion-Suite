<?php
/**
 * Section Element Converter
 *
 * Converts Bricks Section to Etch Section
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Section extends EFS_Base_Element {

	protected $element_type = 'section';

	/**
	 * Convert section element
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (already converted HTML)
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		// Get style IDs
		$style_ids = $this->get_style_ids( $element );

		// Add default section style
		array_unshift( $style_ids, 'etch-section-style' );

		// Get CSS classes
		$css_classes = $this->get_css_classes( $style_ids );

		// Get tag (sections can be section, header, footer, etc.)
		$tag = $this->get_tag( $element, 'section' );

		// Get label
		$label = $this->get_label( $element );

		// Build Etch attributes
		$etch_attributes = array(
			'data-etch-element' => 'section',
		);

		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		// Build block attributes
		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );

		// Build children HTML
		$children_html = is_array( $children ) ? implode( "\n", $children ) : $children;

		// Build block HTML (Etch v1.1+ comment-only block, no saved wrapper markup).
		return $this->generate_etch_element_block( $attrs, $children_html );
	}
}
