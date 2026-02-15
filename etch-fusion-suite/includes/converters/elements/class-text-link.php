<?php
/**
 * Text-Link Element Converter
 *
 * Converts Bricks text-link elements to etch/element blocks.
 * Generates anchor element attrs with etch/text inner blocks.
 *
 * @package Bricks_Etch_Migration
 * @subpackage Converters\Elements
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_TextLink extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'text-link';

	/**
	 * Convert Bricks text-link element to etch/element block.
	 *
	 * @param array $element  Bricks element.
	 * @param array $children Child elements (unused for text-link).
	 * @param array $context  Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings  = $element['settings'] ?? array();
		$text      = $settings['text'] ?? '';
		$link_data = $settings['link'] ?? null;
		$url       = '#';
		$new_tab   = false;

		// Extract link data: array or string format.
		if ( is_array( $link_data ) ) {
			$url     = $link_data['url'] ?? '#';
			$new_tab = ! empty( $link_data['newTab'] );
		} elseif ( is_string( $link_data ) ) {
			$url = '' !== $link_data ? $link_data : '#';
		}

		$style_ids   = $this->get_style_ids( $element );
		$css_classes = $this->get_css_classes( $style_ids );

		// Build attributes.
		$attributes = array(
			'href' => $url,
		);
		if ( '' !== $css_classes ) {
			$attributes['class'] = $css_classes;
		}
		if ( $new_tab ) {
			$attributes['target'] = '_blank';
			$attributes['rel']    = 'noopener noreferrer';
		}

		$label = $this->get_label( $element );
		if ( ucfirst( $this->element_type ) === $label ) {
			$label = 'Text Link';
		}

		$attrs = $this->build_attributes( $label, $style_ids, $attributes, 'a' );

		return $this->generate_etch_element_block(
			$attrs,
			$this->generate_etch_text_block( wp_kses_post( (string) $text ) )
		);
	}
}
