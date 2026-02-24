<?php
/**
 * Container Element Converter
 *
 * Converts Bricks Container to Etch Container
 * Supports custom tags (ul, ol, etc.)
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Container extends EFS_Base_Element {

	protected $element_type = 'container';

	/**
	 * Convert container element
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (already converted HTML)
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		// Get style IDs
		$style_ids = $this->get_style_ids( $element );

		// Add default container style
		array_unshift( $style_ids, 'etch-container-style' );

		// Get CSS classes
		$css_classes = $this->get_css_classes( $style_ids );

		// Get custom tag (e.g., ul, ol). Header/footer templates can promote the root wrapper.
		$fallback_tag = $this->get_root_semantic_tag_from_context( $context, 'div' );
		$tag          = $this->get_tag( $element, $fallback_tag );

		// Get label
		$label = $this->get_label( $element );

		// Build Etch attributes
		$etch_attributes = array(
			'data-etch-element' => 'container',
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
