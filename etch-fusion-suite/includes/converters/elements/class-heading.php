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
		$tag         = strtolower( $this->get_tag( $element, 'h3' ) );
		$label       = $this->get_label( $element );
		$text        = $element['settings']['text'] ?? 'Heading';
		$link_data   = isset( $element['settings']['link'] ) ? $element['settings']['link'] : array();
		$text        = is_string( $text ) ? $text : 'Heading';
		$text        = wp_kses_post( $text );
		$text        = preg_replace( '/(?:\x{00A0}|&nbsp;|&#160;)+$/u', '', $text );
		$valid_tags  = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );
		$inner_block = '';

		if ( ! in_array( $tag, $valid_tags, true ) ) {
			$tag = 'h3';
		}

		$etch_attributes = array();
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs       = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );
		$inner_block = $this->build_heading_inner_block( $text, $link_data );

		return $this->generate_etch_element_block( $attrs, $inner_block );
	}

	/**
	 * Build heading inner markup, preserving links when configured in Bricks.
	 *
	 * @param string       $text Heading text.
	 * @param array|string $link_data Bricks link setting.
	 * @return string
	 */
	private function build_heading_inner_block( $text, $link_data ) {
		$link_url = '';
		$new_tab  = false;

		if ( is_array( $link_data ) ) {
			$link_url = isset( $link_data['url'] ) && is_scalar( $link_data['url'] ) ? trim( (string) $link_data['url'] ) : '';
			$new_tab  = ! empty( $link_data['newTab'] );
		} elseif ( is_scalar( $link_data ) ) {
			$link_url = trim( (string) $link_data );
		}

		if ( '' === $link_url ) {
			return $this->generate_etch_text_block( $text );
		}

		$link_attributes = array( 'href' => $link_url );
		if ( $new_tab ) {
			$link_attributes['target'] = '_blank';
			$link_attributes['rel']    = 'noopener noreferrer';
		}

		$text_html = '<a href="' . esc_url( $link_url ) . '"';
		if ( $new_tab ) {
			$text_html .= ' target="_blank" rel="noopener noreferrer"';
		}
		$text_html .= '>' . $text . '</a>';

		return $this->generate_etch_text_block( $text_html );
	}
}
