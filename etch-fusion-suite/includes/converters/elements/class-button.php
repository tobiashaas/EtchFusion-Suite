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

class EFS_Element_Button extends EFS_Base_Element {

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
		$button_class = $this->resolve_button_class( $style, $css_classes );
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

		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, 'a', $element );

		return $this->generate_etch_element_block(
			$attrs,
			$this->generate_etch_text_block( wp_kses_post( $text ) )
		);
	}

	/**
	 * Resolve final button class with proper priority handling.
	 *
	 * Priority rules:
	 * 1. CSS classes (btn--*) have priority over style setting
	 * 2. Only ONE btn--* class can exist (primary, secondary, text, etc.)
	 * 3. EXCEPTION: btn--outline can exist alongside one primary class
	 * 4. If multiple btn--* classes exist in CSS, keep the first one
	 *
	 * @param string $style UI style setting (primary, secondary, outline, text)
	 * @param string $css_classes All CSS classes including mapped global classes
	 * @return string Final button classes for output
	 */
	private function resolve_button_class( $style, $css_classes ) {
		// Pattern: btn--primary, btn--secondary, btn--text, btn--outline, etc.
		$btn_pattern = '/btn--([a-z-]+)/i';

		// Extract all btn--* classes from combined CSS classes
		preg_match_all( $btn_pattern, $css_classes, $matches );
		$button_classes = $matches[0] ?? array(); // Full class names like 'btn--primary'
		$button_types   = $matches[1] ?? array(); // Type only like 'primary'

		// Separate outline from primary button types
		$outline_index = array_search( 'outline', $button_types, true );
		$has_outline   = false !== $outline_index;
		$primary_class = '';

		// Keep only the FIRST non-outline btn--* class
		foreach ( $button_types as $idx => $type ) {
			if ( 'outline' !== $type ) {
				$primary_class = $button_classes[ $idx ];
				break;
			}
		}

		// If no primary class found in CSS, derive from style setting
		if ( empty( $primary_class ) ) {
			// If style already contains btn--, use it directly
			if ( strpos( $style, 'btn--' ) === 0 ) {
				$primary_class = $style;
			} else {
				// Map Bricks styles to btn classes
				$style_map = array(
					'primary'   => 'btn--primary',
					'secondary' => 'btn--secondary',
					'outline'   => 'btn--outline',
					'text'      => 'btn--text',
				);

				$primary_class = $style_map[ $style ] ?? 'btn--primary';
			}

			// If derived class is outline, we still need a primary class
			if ( strpos( $primary_class, 'btn--outline' ) === 0 ) {
				$primary_class = 'btn--primary'; // Default to primary if style is outline
				$has_outline   = true;
			}
		}

		// Build final class string: primary class + optional outline
		$final_classes = array( $primary_class );
		if ( $has_outline && 'btn--outline' !== $primary_class ) {
			$final_classes[] = 'btn--outline';
		}

		// Add any other non-btn classes from css_classes
		$other_classes = preg_replace( $btn_pattern, '', $css_classes );
		$other_classes = trim( $other_classes );
		if ( ! empty( $other_classes ) ) {
			$final_classes[] = $other_classes;
		}

		return implode( ' ', array_filter( $final_classes ) );
	}
}
