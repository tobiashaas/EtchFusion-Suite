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
		$use_dynamic  = isset( $image_data['useDynamicData'] ) ? trim( (string) $image_data['useDynamicData'] ) : '';
		$alt_text     = $element['settings']['alt'] ?? ( $image_data['alt'] ?? '' );
		$caption      = $element['settings']['caption'] ?? '';
		$figure_attrs = array();
		$img_style    = $this->resolve_cover_img_style( $css_classes, $element );

		$alt_text = is_string( $alt_text ) ? $alt_text : '';
		$caption  = is_string( $caption ) ? $caption : '';
		// Bricks uses "none" as a CSS no-content sentinel — treat it as no caption.
		if ( 'none' === strtolower( trim( $caption ) ) ) {
			$caption = '';
		}

		// When the image source is dynamic data (e.g. {featured_image}), emit an Etch
		// dynamic-image block with a resolved data-source attribute instead of a static URL.
		if ( '' !== $use_dynamic ) {
			$dynamic_src = $this->resolve_dynamic_image_src( $use_dynamic );
			if ( '' !== $dynamic_src ) {
				$dynamic_media_id = (bool) preg_match( '/^\{(?:this|item)\.image\.id\}$/', $dynamic_src );
				if ( '' !== $css_classes ) {
					$figure_attrs['class'] = $css_classes;
				}
				$figure_block_attrs = $this->build_attributes( $label, $style_ids, $figure_attrs, 'figure', $element );

				$img_block = $this->generate_dynamic_image_block( 0, $dynamic_src, $alt_text, $dynamic_media_id, $img_style );
				return $this->generate_etch_element_block( $figure_block_attrs, array( $img_block ) );
			}
			// Unknown dynamic source — fall through to static handling (may return '' if no URL).
		}

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

		$img_block = $this->generate_dynamic_image_block( $image_id, $image_url, $alt_text, false, $img_style );

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
	 * Map a Bricks useDynamicData tag to an Etch dynamic-image src expression.
	 *
	 * Bricks stores dynamic image sources as tags like `{featured_image}` or
	 * `{acf_image_field}`. This method translates known tags to the Etch equivalent
	 * so that the loop context can resolve them at render time.
	 *
	 * @param string $bricks_dynamic_tag Raw useDynamicData value (e.g. "{featured_image}").
	 * @return string Etch dynamic src expression, or '' if the tag is unknown.
	 */
	private function resolve_dynamic_image_src( $bricks_dynamic_tag ) {
		$tag = trim( (string) $bricks_dynamic_tag );
		// Strip surrounding braces to normalise "{featured_image}" → "featured_image".
		$tag = trim( $tag, '{}' );

		$map = array(
			'featured_image'    => '{this.image.id}',
			'post_thumbnail'    => '{this.image.id}',
			'woo_product_image' => '{this.image.id}',
			'post_id'           => '{this.id}',
		);

		if ( isset( $map[ $tag ] ) ) {
			return $map[ $tag ];
		}

		// ACF image fields: {acf_field_name} or {acf:field_name}.
		if ( 0 === strpos( $tag, 'acf:' ) ) {
			$field_name = substr( $tag, 4 );
			return '{this.acf.' . $field_name . '}';
		}
		if ( 0 === strpos( $tag, 'acf_' ) ) {
			$field_name = substr( $tag, 4 );
			return '{this.acf.' . $field_name . '}';
		}

		return '';
	}

	/**
	 * Build Etch dynamic-image block for migrated images.
	 *
	 * Prefers mapped target media ID (from media migration) and falls back to src/alt.
	 *
	 * @param int    $source_image_id Source attachment ID from Bricks.
	 * @param string $image_url       Source image URL.
	 * @param string $alt_text        Image alt text.
	 * @param bool   $dynamic_media_id Whether image_url carries a dynamic mediaId expression.
	 * @return string
	 */
	private function generate_dynamic_image_block( $source_image_id, $image_url, $alt_text, $dynamic_media_id = false, $img_style = '' ) {
		$attributes = array();

		$mapped_media_id = $this->get_mapped_target_media_id( (int) $source_image_id );
		if ( $mapped_media_id > 0 ) {
			$attributes['mediaId'] = (string) $mapped_media_id;
		} elseif ( $dynamic_media_id ) {
			$attributes['mediaId'] = (string) $image_url;
			$attributes['alt']     = (string) $alt_text;
		} else {
			$attributes['src'] = (string) $image_url;
			$attributes['alt'] = (string) $alt_text;
		}
		if ( '' !== trim( (string) $img_style ) ) {
			$attributes['style'] = trim( (string) $img_style );
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
	 * Resolve image inline style for cover-like wrappers.
	 *
	 * Priority order:
	 *   1. Direct _objectFit setting on the element.
	 *   2. _objectFit in any attached Bricks global class.
	 *   3. Class-name heuristic (__image, bg-image tokens).
	 *
	 * @param string $css_classes Resolved CSS class string for the figure wrapper.
	 * @param array  $element     Bricks element data.
	 * @return string Inline style string for the img, or ''.
	 */
	private function resolve_cover_img_style( $css_classes, $element = array() ) {
		$cover_style = 'object-fit: cover; inline-size: 100%; block-size: 100%;';

		// 1. Direct element setting.
		$settings   = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$direct_fit = isset( $settings['_objectFit'] ) ? strtolower( trim( (string) $settings['_objectFit'] ) ) : '';
		if ( 'cover' === $direct_fit ) {
			return $cover_style;
		}

		// 2. Global class settings (loaded once per request).
		$class_ids = isset( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] )
			? $settings['_cssGlobalClasses']
			: array();

		if ( ! empty( $class_ids ) ) {
			static $global_class_settings = null;
			if ( null === $global_class_settings ) {
				$global_class_settings = array();
				$raw                   = get_option( 'bricks_global_classes', array() );
				if ( is_string( $raw ) ) {
					$decoded = json_decode( $raw, true );
					$raw     = is_array( $decoded ) ? $decoded : array();
				}
				if ( is_array( $raw ) ) {
					foreach ( $raw as $class_data ) {
						if ( ! is_array( $class_data ) || empty( $class_data['id'] ) ) {
							continue;
						}
						$id                           = trim( (string) $class_data['id'] );
						$global_class_settings[ $id ] = isset( $class_data['settings'] ) && is_array( $class_data['settings'] )
							? $class_data['settings']
							: array();
					}
				}
			}

			foreach ( $class_ids as $class_id ) {
				$class_id = trim( (string) $class_id );
				if ( '' === $class_id || ! isset( $global_class_settings[ $class_id ] ) ) {
					continue;
				}
				$fit = isset( $global_class_settings[ $class_id ]['_objectFit'] )
					? strtolower( trim( (string) $global_class_settings[ $class_id ]['_objectFit'] ) )
					: '';
				if ( 'cover' === $fit ) {
					return $cover_style;
				}
			}
		}

		// 3. Class-name heuristic for common patterns (__image, bg-image).
		$tokens = preg_split( '/\s+/', trim( (string) $css_classes ) );
		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $token ) {
				$token = trim( (string) $token );
				if ( false !== strpos( $token, '__image' ) || false !== strpos( $token, 'bg-image' ) ) {
					return $cover_style;
				}
			}
		}

		return '';
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
