<?php
/**
 * Paragraph Element Converter
 *
 * Converts Bricks text-basic elements to Etch element blocks.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Paragraph extends EFS_Base_Element {

	protected $element_type = 'paragraph';

	/**
	 * Convert paragraph element
	 *
	 * @param array $element Bricks element
	 * @param array $children Not used for paragraphs
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$style_ids       = $this->get_style_ids( $element );
		$css_classes     = $this->get_css_classes( $style_ids );
		$tag             = $this->get_tag( $element, 'p' );
		$label           = $this->get_label( $element );
		$text            = $element['settings']['text'] ?? '';
		$text            = is_string( $text ) ? $text : '';
		$text            = wp_kses_post( $text );
		$text            = preg_replace( '/(?:\x{00A0}|&nbsp;|&#160;)+$/u', '', $text );
		$etch_attributes = array();

		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );

		return $this->generate_etch_element_block(
			$attrs,
			$this->generate_etch_text_block( $text )
		);
	}
}
