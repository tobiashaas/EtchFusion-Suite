<?php
/**
 * Button Element Converter
 *
 * Converts Bricks button elements to Etch link element blocks.
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

class EFS_Button_Converter extends EFS_Base_Element {

	protected $element_type = 'button';

	/**
	 * Convert Bricks button to Etch link.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings = $element['settings'] ?? array();
		$text     = $settings['text'] ?? '';
		$text     = is_string( $text ) ? $text : '';

		// Extract link URL - handle both array and string formats
		$link    = '#';
		$new_tab = false;
		if ( isset( $settings['link'] ) ) {
			if ( is_array( $settings['link'] ) ) {
				$link    = $settings['link']['url'] ?? '#';
				$new_tab = ! empty( $settings['link']['newTab'] );
			} else {
				$link = $settings['link'];
			}
		}

		$style        = isset( $settings['style'] ) && is_string( $settings['style'] ) ? $settings['style'] : 'primary';
		$style_ids    = $this->get_style_ids( $element );
		$css_classes  = $this->get_css_classes( $style_ids );
		$button_class = $this->map_button_class( $style, $css_classes );
		$label        = $this->get_label( $element );

		$etch_attributes = array(
			'href' => (string) $link,
		);

		if ( '' !== trim( $button_class ) ) {
			$etch_attributes['class'] = $button_class;
		}

		if ( $new_tab ) {
			$etch_attributes['target'] = '_blank';
			$etch_attributes['rel']    = 'noopener noreferrer';
		}

		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, 'a' );

		return $this->generate_etch_element_block(
			$attrs,
			$this->generate_etch_text_block( wp_kses_post( $text ) )
		);
	}

	/**
	 * Map Bricks button style to CSS class
	 */
	private function map_button_class( $style, $existing_classes ) {
		// If style already contains btn--, use it directly
		// (Bricks sometimes stores the full class name as style)
		if ( strpos( $style, 'btn--' ) === 0 ) {
			$button_class = $style;
		} else {
			// Map Bricks styles to btn classes
			$style_map = array(
				'primary'   => 'btn--primary',
				'secondary' => 'btn--secondary',
				'outline'   => 'btn--outline',
				'text'      => 'btn--text',
			);

			$button_class = $style_map[ $style ] ?? 'btn--primary';
		}

		// Combine with existing classes
		if ( ! empty( $existing_classes ) ) {
			return $existing_classes . ' ' . $button_class;
		}

		return $button_class;
	}
}
