<?php
/**
 * Heading Element Converter
 *
 * Converts Bricks Heading to Etch heading element blocks.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Heading extends EFS_Base_Element {

	protected $element_type = 'heading';

	/**
	 * Convert heading element
	 *
	 * @param array $element Bricks element
	 * @param array $children Not used for headings
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$style_ids   = $this->get_style_ids( $element );
		$css_classes = $this->get_css_classes( $style_ids );
		$tag         = strtolower( $this->get_tag( $element, 'h2' ) );
		$label       = $this->get_label( $element );
		$text        = $element['settings']['text'] ?? 'Heading';
		$text        = is_string( $text ) ? $text : 'Heading';
		$text        = wp_kses_post( $text );
		$valid_tags  = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		$inner_block = '';

		if ( ! in_array( $tag, $valid_tags, true ) ) {
			$tag = 'h2';
		}

		$etch_attributes = array();
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs       = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag );
		$inner_block = $this->generate_etch_text_block( $text );

		return $this->generate_etch_element_block( $attrs, $inner_block );
	}
}
