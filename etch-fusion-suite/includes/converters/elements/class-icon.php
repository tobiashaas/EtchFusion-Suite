<?php
/**
 * Icon Element Converter
 *
 * Converts Bricks icon elements to Etch icon blocks.
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

class EFS_Icon_Converter extends EFS_Base_Element {

	protected $element_type = 'icon';

	/**
	 * Convert Bricks icon to Etch icon element.
	 *
	 * @param array $element Bricks element.
	 * @param array $children Child elements (unused for icon).
	 * @param array $context Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings     = $element['settings'] ?? array();
		$icon_data    = isset( $settings['icon'] ) && is_array( $settings['icon'] ) ? $settings['icon'] : array();
		$icon_library = isset( $icon_data['library'] ) ? (string) $icon_data['library'] : '';
		$icon_value   = '';
		$style_ids    = $this->get_style_ids( $element );
		$css_classes  = $this->get_css_classes( $style_ids );
		$label        = $this->get_label( $element );
		$etch_attrs   = array(
			'aria-hidden' => 'true',
		);
		$class_tokens = array();

		if ( isset( $icon_data['value'] ) && is_string( $icon_data['value'] ) ) {
			$icon_value = trim( $icon_data['value'] );
		} elseif ( isset( $icon_data['icon'] ) && is_string( $icon_data['icon'] ) ) {
			$icon_value = trim( $icon_data['icon'] );
		}

		if ( '' !== $icon_value ) {
			$class_tokens = array_merge( $class_tokens, (array) preg_split( '/\s+/', $icon_value ) );
		}

		if ( '' !== trim( $css_classes ) ) {
			$class_tokens = array_merge( $class_tokens, (array) preg_split( '/\s+/', trim( $css_classes ) ) );
		}

		$class_tokens = array_values( array_filter( array_unique( $class_tokens ) ) );
		if ( ! empty( $class_tokens ) ) {
			$etch_attrs['class'] = implode( ' ', $class_tokens );
		}

		if ( '' !== $icon_library ) {
			$etch_attrs['data-icon-library'] = $icon_library;
		}

		$attrs = $this->build_attributes( $label, $style_ids, $etch_attrs, 'i' );

		return $this->generate_etch_element_block( $attrs );
	}
}
