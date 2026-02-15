<?php
/**
 * Image Element Converter
 *
 * Converts Bricks image elements to Etch figure/image blocks.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Image extends EFS_Base_Element {

	protected $element_type = 'image';

	/**
	 * Convert image element
	 *
	 * @param array $element Bricks element
	 * @param array $children Not used for images
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$style_ids    = $this->get_style_ids( $element );
		$css_classes  = $this->get_css_classes( $style_ids );
		$label        = $this->get_label( $element );
		$image_data   = isset( $element['settings']['image'] ) && is_array( $element['settings']['image'] ) ? $element['settings']['image'] : array();
		$image_id     = isset( $image_data['id'] ) ? (int) $image_data['id'] : 0;
		$image_url    = isset( $image_data['url'] ) ? (string) $image_data['url'] : '';
		$alt_text     = $element['settings']['alt'] ?? ( $image_data['alt'] ?? '' );
		$caption      = $element['settings']['caption'] ?? '';
		$figure_attrs = array();

		$alt_text = is_string( $alt_text ) ? $alt_text : '';
		$caption  = is_string( $caption ) ? $caption : '';

		if ( '' === $image_url && $image_id > 0 && function_exists( 'wp_get_attachment_url' ) ) {
			$resolved_url = wp_get_attachment_url( $image_id );
			if ( is_string( $resolved_url ) ) {
				$image_url = $resolved_url;
			}
		}

		if ( '' === trim( $image_url ) ) {
			return '';
		}

		if ( '' !== $css_classes ) {
			$figure_attrs['class'] = $css_classes;
		}

		$figure_block_attrs = $this->build_attributes( $label, $style_ids, $figure_attrs, 'figure' );

		$img_attrs = array(
			'src' => $image_url,
			'alt' => $alt_text,
		);

		if ( $image_id > 0 ) {
			$img_attrs['data-id'] = (string) $image_id;
		}

		$img_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Image', array(), $img_attrs, 'img' )
		);

		$inner_blocks = array( $img_block );

		if ( '' !== trim( $caption ) ) {
			$caption_block  = $this->generate_etch_element_block(
				$this->build_attributes( 'Caption', array(), array(), 'figcaption' ),
				$this->generate_etch_text_block( wp_kses_post( $caption ) )
			);
			$inner_blocks[] = $caption_block;
		}

		return $this->generate_etch_element_block( $figure_block_attrs, $inner_blocks );
	}
}
