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

		$figure_block_attrs = $this->build_attributes( $label, $style_ids, $figure_attrs, 'figure', $element );

		$img_block = $this->generate_dynamic_image_block( $image_id, $image_url, $alt_text );

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

	/**
	 * Build Etch dynamic-image block for migrated images.
	 *
	 * Prefers mapped target media ID (from media migration) and falls back to src/alt.
	 *
	 * @param int    $source_image_id Source attachment ID from Bricks.
	 * @param string $image_url       Source image URL.
	 * @param string $alt_text        Image alt text.
	 * @return string
	 */
	private function generate_dynamic_image_block( $source_image_id, $image_url, $alt_text ) {
		$attributes = array();

		$mapped_media_id = $this->get_mapped_target_media_id( (int) $source_image_id );
		if ( $mapped_media_id > 0 ) {
			$attributes['mediaId'] = (string) $mapped_media_id;
		} else {
			$attributes['src'] = (string) $image_url;
			$attributes['alt'] = (string) $alt_text;
		}

		$dynamic_attrs = array(
			'metadata'   => array(
				'name' => 'Image',
			),
			'tag'        => 'img',
			'attributes' => $attributes,
		);

		$json = wp_json_encode( $dynamic_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/dynamic-image ' . $json . " -->\n\n<!-- /wp:etch/dynamic-image -->";
	}

	/**
	 * Resolve mapped target media ID from migration mapping option.
	 *
	 * @param int $source_image_id Source attachment ID.
	 * @return int
	 */
	private function get_mapped_target_media_id( $source_image_id ) {
		if ( $source_image_id <= 0 ) {
			return 0;
		}

		$mappings = get_option( 'b2e_media_mappings', array() );
		if ( ! is_array( $mappings ) || ! isset( $mappings[ $source_image_id ] ) ) {
			return 0;
		}

		return (int) $mappings[ $source_image_id ];
	}
}
