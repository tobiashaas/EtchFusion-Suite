<?php
/**
 * Gutenberg Generator for Bricks to Etch Migration Plugin
 *
 * Generates Gutenberg blocks from Bricks content
 */

namespace Bricks2Etch\Parsers;

use Bricks2Etch\Converters\EFS_Base_Element;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Converters\EFS_Element_Factory;
use Bricks2Etch\Converters\Elements\EFS_Element_Div;
use Bricks2Etch\Parsers\EFS_Content_Parser;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Gutenberg_Generator {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * Dynamic data converter instance
	 */
	private $dynamic_data_converter;

	/**
	 * Content parser instance
	 */
	private $content_parser;

	/**
	 * Element factory instance (NEW - v0.5.0)
	 */
	private $element_factory;

	/**
	 * Shared EFS_Element_Div instance used to delegate brxe-block display fallback
	 * to the canonical EFS_Base_Element::apply_brxe_block_display() helper.
	 */
	private $display_fallback_helper = null;

	/**
	 * Constructor
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_Dynamic_Data_Converter $dynamic_data_converter, EFS_Content_Parser $content_parser ) {
		$this->error_handler          = $error_handler;
		$this->dynamic_data_converter = $dynamic_data_converter;
		$this->content_parser         = $content_parser;

		// Load element factory (NEW - v0.5.0)
		require_once plugin_dir_path( __FILE__ ) . 'converters/class-element-factory.php';
	}

	/**
	 * Convert Bricks elements directly to Gutenberg HTML (SIMPLE & DIRECT)
	 */
	private function convert_bricks_to_gutenberg_html( $bricks_elements, $post ) {
		if ( empty( $bricks_elements ) || ! is_array( $bricks_elements ) ) {
			return '';
		}

		// Keep legacy API stable, but route serialization through the modern
		// element-factory path to avoid generating deprecated nested etchData.
		return $this->generate_gutenberg_blocks( $bricks_elements );
	}

	/**
	 * Convert a single Bricks element to Etch block
	 */
	private function convert_single_bricks_element( $element, $element_map ) {
		$element_type = $element['name'] ?? '';
		$element_id   = $element['id'] ?? '';
		$settings     = $element['settings'] ?? array();
		$children_ids = $element['children'] ?? array();

		// Get children elements
		$children = array();
		foreach ( $children_ids as $child_id ) {
			if ( isset( $element_map[ $child_id ] ) ) {
				$children[] = $element_map[ $child_id ];
			}
		}

		// Convert based on element type to ETCH blocks
		switch ( $element_type ) {
			case 'section':
				return $this->convert_etch_section( $element, $children, $element_map );

			case 'container':
				return $this->convert_etch_container( $element, $children, $element_map );

			case 'block':
				// brxe-block behaves like a regular div wrapper.
				return $this->convert_etch_simple_div( $element, $children, $element_map );

			case 'div':
				// Regular div stays as div
				return $this->convert_etch_simple_div( $element, $children, $element_map );

			case 'text':
			case 'text-basic':
				return $this->convert_etch_text( $element );

			case 'heading':
				return $this->convert_etch_heading( $element );

			case 'image':
				return $this->convert_etch_image( $element );

			case 'icon':
				return $this->convert_etch_icon( $element );

			case 'button':
				return $this->convert_etch_button( $element );

			case 'code':
			case 'filter-radio':
			case 'form':
			case 'map':
				// Convert unsupported elements to div
				return $this->convert_etch_div( $element, $children, $element_map );

			default:
				// Wrap unknown elements - render children
				$child_html = '';
				foreach ( $children as $child ) {
					$child_html .= $this->convert_single_bricks_element( $child, $element_map );
				}
				return $child_html;
		}
	}

	/**
	 * Convert Bricks Section to Etch Section (flat Etch v1.1+ attrs).
	 */
	private function convert_etch_section( $element, $children, $element_map ) {
		$label = $element['label'] ?? '';

		// Convert children
		$children_html = '';
		foreach ( $children as $child ) {
			$child_html = $this->convert_single_bricks_element( $child, $element_map );
			if ( ! empty( $child_html ) ) {
				$children_html .= $child_html . "\n";
			}
		}

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Add default section style
		array_unshift( $style_ids, 'etch-section-style' );

		// Get CSS classes from style IDs for attributes.class
		$css_classes    = $this->get_css_classes_from_style_ids( $style_ids );
		$source_classes = $this->merge_class_names( ...$this->get_element_classes( $element ) );

		// Build Etch-compatible attributes (flat schema)
		$etch_attributes = array(
			'data-etch-element' => 'section',
		);
		$etch_attributes = array_merge( $etch_attributes, $this->extract_custom_etch_attributes( $element['etch_data'] ?? array() ) );

		// Keep source classes even when style migration for a class is excluded.
		$merged_classes = $this->merge_class_names( $css_classes, $source_classes );
		if ( ! empty( $merged_classes ) ) {
			$etch_attributes['class'] = $merged_classes;
		}
		$etch_attributes = $this->apply_brxe_block_display_fallback( $etch_attributes, $element, $style_ids, $merged_classes );

		$section_name = $this->sanitize_element_label( $label, 'Section' );

		$attrs = array(
			'metadata'   => array(
				'name' => $section_name,
			),
			'tag'        => 'section',
			'attributes' => $etch_attributes,
			'styles'     => $style_ids,
		);

		return $this->serialize_etch_element_block( $attrs, $children_html );
	}

	/**
	 * Convert Bricks Container to Etch Container (flat Etch v1.1+ attrs).
	 */
	private function convert_etch_container( $element, $children, $element_map ) {
		$label = $element['label'] ?? '';

		// Convert children
		$children_html = '';
		foreach ( $children as $child ) {
			$child_html = $this->convert_single_bricks_element( $child, $element_map );
			if ( ! empty( $child_html ) ) {
				$children_html .= $child_html . "\n";
			}
		}

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Add default container style
		array_unshift( $style_ids, 'etch-container-style' );

		// Get CSS classes from style IDs for attributes.class
		$css_classes    = $this->get_css_classes_from_style_ids( $style_ids );
		$source_classes = $this->merge_class_names( ...$this->get_element_classes( $element ) );

		// Resolve tag from Bricks settings, including "custom" + customTag.
		$tag = $this->resolve_element_tag( $element, 'div' );

		$this->error_handler->log_info( 'Gutenberg Generator: convert_etch_container element ID ' . $element['id'] . ' using tag ' . $tag );

		// Build Etch-compatible attributes (flat schema)
		$etch_attributes = array(
			'data-etch-element' => 'container',
		);
		$etch_attributes = array_merge( $etch_attributes, $this->extract_custom_etch_attributes( $element['etch_data'] ?? array() ) );

		// Keep source classes even when style migration for a class is excluded.
		$merged_classes = $this->merge_class_names( $css_classes, $source_classes );
		if ( ! empty( $merged_classes ) ) {
			$etch_attributes['class'] = $merged_classes;
		}
		$etch_attributes = $this->apply_brxe_block_display_fallback( $etch_attributes, $element, $style_ids, $merged_classes );

		$container_name = $this->sanitize_element_label( $label, 'Container' );

		$attrs = array(
			'metadata'   => array(
				'name' => $container_name,
			),
			'tag'        => (string) $tag,
			'attributes' => $etch_attributes,
			'styles'     => $style_ids,
		);

		return $this->serialize_etch_element_block( $attrs, $children_html );
	}

	/**
	 * Legacy wrapper for older call sites.
	 * Bricks Block now maps to the same simple div conversion as regular div.
	 */
	private function convert_etch_flex_div( $element, $children, $element_map ) {
		return $this->convert_etch_simple_div( $element, $children, $element_map );
	}

	/**
	 * Convert Bricks Div to simple HTML div (NOT flex-div)
	 */
	private function convert_etch_simple_div( $element, $children, $element_map ) {
		$label = $element['label'] ?? '';

		// Convert children
		$children_html = '';
		foreach ( $children as $child ) {
			$child_html = $this->convert_single_bricks_element( $child, $element_map );
			if ( ! empty( $child_html ) ) {
				$children_html .= $child_html . "\n";
			}
		}

		// Get style IDs for this element
		$style_ids      = $this->get_element_style_ids( $element );
		$css_classes    = $this->get_css_classes_from_style_ids( $style_ids );
		$source_classes = $this->merge_class_names( ...$this->get_element_classes( $element ) );
		$tag            = $this->resolve_element_tag( $element, 'div' );

		$attributes     = array();
		$attributes     = array_merge( $attributes, $this->extract_custom_etch_attributes( $element['etch_data'] ?? array() ) );
		$merged_classes = $this->merge_class_names( $css_classes, $source_classes );
		if ( ! empty( $merged_classes ) ) {
			$attributes['class'] = $merged_classes;
		}
		$attributes = $this->apply_brxe_block_display_fallback( $attributes, $element, $style_ids, $merged_classes );

		// Build flat attrs for a simple div/semantic wrapper (without flex-div markers).
		$div_name = $this->sanitize_element_label( $label, 'Div' );

		$attrs = array(
			'metadata'   => array(
				'name' => $div_name,
			),
			'tag'        => (string) $tag,
			'attributes' => $attributes,
			'styles'     => $style_ids,
		);

		return $this->serialize_etch_element_block( $attrs, $children_html );
	}

	/**
	 * Extract transferable scalar attributes from etch_data.
	 *
	 * @param array $etch_data Etch data payload.
	 * @return array<string, string>
	 */
	private function extract_custom_etch_attributes( $etch_data ) {
		$attributes = array();
		if ( ! is_array( $etch_data ) ) {
			return $attributes;
		}

		foreach ( $etch_data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( in_array( $key, array( 'class', 'tag', 'content' ), true ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$attributes[ $key ] = $value;
		}

		return $attributes;
	}

	/**
	 * Delegate brxe-block display fallback to the shared helper in EFS_Base_Element.
	 *
	 * All logic lives in EFS_Base_Element::apply_brxe_block_display() so the two
	 * code paths (factory and legacy generator) share a single canonical implementation.
	 *
	 * @param array  $attributes Current Etch attributes.
	 * @param array  $element    Source Bricks element.
	 * @param array  $style_ids  Resolved Etch style IDs.
	 * @param string $classes    Merged class string.
	 * @return array
	 */
	private function apply_brxe_block_display_fallback( array $attributes, array $element, array $style_ids, $classes ) {
		if ( null === $this->display_fallback_helper ) {
			$style_map                     = get_option( 'efs_style_map', array() );
			$this->display_fallback_helper = new EFS_Element_Div( $style_map );
		}

		return $this->display_fallback_helper->apply_brxe_block_display( $style_ids, $attributes, $element, $classes );
	}

	/**
	 * Convert unsupported structural elements via simple Etch element wrapper.
	 */
	private function convert_etch_div( $element, $children, $element_map ) {
		return $this->convert_etch_simple_div( $element, $children, $element_map );
	}

	/**
	 * Resolve the output tag for structural elements.
	 *
	 * Supports Bricks "custom" tag mode via settings.customTag.
	 *
	 * @param array  $element Element payload.
	 * @param string $default_tag Fallback tag.
	 * @return string
	 */
	private function resolve_element_tag( $element, $default_tag = 'div' ) {
		$settings   = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$raw_tag    = $element['etch_data']['tag'] ?? ( $settings['tag'] ?? $default_tag );
		$custom_tag = isset( $settings['customTag'] ) ? trim( (string) $settings['customTag'] ) : '';

		if ( 'custom' === strtolower( trim( (string) $raw_tag ) ) && '' !== $custom_tag ) {
			$raw_tag = $custom_tag;
		}

		return $this->normalize_structural_tag( $raw_tag );
	}

	/**
	 * Normalize structural tag names from Bricks settings.
	 *
	 * Prevents invalid placeholders like "custom" from being emitted as real tags.
	 *
	 * @param mixed $tag Candidate tag value.
	 * @return string
	 */
	private function normalize_structural_tag( $tag ) {
		$tag = strtolower( trim( (string) $tag ) );
		if ( '' === $tag || 'custom' === $tag ) {
			return 'div';
		}

		// Accept standard/custom tag names when valid.
		if ( ! preg_match( '/^[a-z][a-z0-9-]*$/', $tag ) ) {
			return 'div';
		}

		return $tag;
	}

	/**
	 * Serialize an Etch element block without saved wrapper HTML.
	 *
	 * @param array  $attrs Block attrs.
	 * @param string $children_html Serialized inner blocks.
	 * @return string
	 */
	private function serialize_etch_element_block( $attrs, $children_html = '' ) {
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$content    = trim( (string) $children_html );

		if ( '' === $content ) {
			return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
				'<!-- /wp:etch/element -->';
		}

		return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
			$content . "\n" .
			'<!-- /wp:etch/element -->';
	}

	/**
	 * Convert Bricks Text to Etch paragraph with flat attrs.
	 */
	private function convert_etch_text( $element ) {
		$text = $element['settings']['text'] ?? '';
		if ( '' === trim( (string) $text ) ) {
			return '';
		}

		$fallback_element              = $element;
		$fallback_element['etch_type'] = 'paragraph';
		$fallback_element['etch_data'] = array(
			'class' => implode( ' ', $this->get_element_classes( $element ) ),
		);
		$attrs                         = $this->build_flat_fallback_attrs( $fallback_element, 'p' );

		return $this->serialize_etch_element_block( $attrs, wp_kses_post( $text ) );
	}

	/**
	 * Convert Bricks Heading to Etch heading with flat attrs.
	 */
	private function convert_etch_heading( $element ) {
		$text = $element['settings']['text'] ?? '';
		if ( '' === trim( (string) $text ) ) {
			return '';
		}

		$text = EFS_Base_Element::decode_html_entities_to_plain( $text );
		$tag  = strtolower( (string) ( $element['settings']['tag'] ?? 'h3' ) );
		if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
			$tag = 'h3';
		}

		$fallback_element              = $element;
		$fallback_element['etch_type'] = 'heading';
		$fallback_element['etch_data'] = array(
			'class' => implode( ' ', $this->get_element_classes( $element ) ),
		);
		$attrs                         = $this->build_flat_fallback_attrs( $fallback_element, $tag );

		return $this->serialize_etch_element_block( $attrs, esc_html( wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Convert Bricks Image to Etch image element with flat attrs.
	 */
	private function convert_etch_image( $element ) {
		$image_url = $element['settings']['image']['url'] ?? '';
		if ( '' === trim( (string) $image_url ) ) {
			return '';
		}

		$extra_attributes = array(
			'src' => esc_url_raw( (string) $image_url ),
		);
		if ( isset( $element['settings']['image']['alt'] ) && '' !== trim( (string) $element['settings']['image']['alt'] ) ) {
			$extra_attributes['alt'] = sanitize_text_field( (string) $element['settings']['image']['alt'] );
		}

		$fallback_element              = $element;
		$fallback_element['etch_type'] = 'image';
		$fallback_element['etch_data'] = array(
			'class' => implode( ' ', $this->get_element_classes( $element ) ),
		);
		$attrs                         = $this->build_flat_fallback_attrs( $fallback_element, 'img', $extra_attributes );

		return $this->serialize_etch_element_block( $attrs );
	}

	/**
	 * Convert Bricks Icon to Etch SVG or Etch element icon.
	 */
	private function convert_etch_icon( $element ) {
		$icon_library = $element['settings']['icon']['library'] ?? '';
		$icon_value   = $element['settings']['icon']['icon'] ?? '';
		$svg_code     = $element['settings']['icon']['svg'] ?? '';
		$label        = $this->sanitize_element_label( $element['label'] ?? '', 'Icon' );
		$classes      = implode( ' ', $this->get_element_classes( $element ) );
		$style_ids    = $this->get_element_style_ids( $element );

		if ( '' === trim( (string) $icon_value ) && '' === trim( (string) $svg_code ) ) {
			return '';
		}

		if ( '' !== trim( (string) $svg_code ) || false !== strpos( (string) $icon_library, 'svg' ) ) {
			$svg_attrs = array(
				'metadata'   => array(
					'name' => $label,
				),
				'attributes' => array(
					'src'         => (string) $svg_code,
					'stripColors' => 'true',
				),
				'styles'     => $style_ids,
			);

			if ( '' !== $classes ) {
				$svg_attrs['attributes']['class'] = $classes;
			}

			$attrs_json = wp_json_encode( $svg_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return '<!-- wp:etch/svg ' . $attrs_json . ' -->' . "\n" .
				'<!-- /wp:etch/svg -->';
		}

		// For font icon libraries, output an empty SVG block to avoid transferring
		// icon-font specific classes/attributes (e.g. data-icon-library).
		$fallback_svg_attrs = array(
			'tag'        => 'svg',
			'attributes' => array(),
			'styles'     => $style_ids,
		);
		$fallback_json      = wp_json_encode( $fallback_svg_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/svg ' . $fallback_json . ' -->' . "\n" .
			'<!-- /wp:etch/svg -->';
	}

	/**
	 * Convert Bricks Button to Etch anchor element with flat attrs.
	 */
	private function convert_etch_button( $element ) {
		$text  = $element['settings']['text'] ?? 'Button';
		$link  = $element['settings']['link'] ?? '#';
		$class = implode( ' ', $this->get_element_classes( $element ) );

		if ( is_array( $link ) ) {
			$url     = $link['url'] ?? '#';
			$new_tab = ! empty( $link['newTab'] );
		} else {
			$url     = (string) $link;
			$new_tab = false;
		}

		$link_attributes = array(
			'href' => esc_url_raw( (string) $url ),
		);

		if ( $new_tab ) {
			$link_attributes['target'] = '_blank';
			$link_attributes['rel']    = 'noopener';
		}

		$fallback_element              = $element;
		$fallback_element['etch_type'] = 'button';
		$fallback_element['etch_data'] = array(
			'class' => $class,
		);
		$attrs                         = $this->build_flat_fallback_attrs( $fallback_element, 'a', $link_attributes );

		$text = EFS_Base_Element::decode_html_entities_to_plain( (string) $text );
		return $this->serialize_etch_element_block( $attrs, esc_html( wp_strip_all_tags( $text ) ) );
	}

	/**
	 * Get element classes (resolve IDs to names)
	 */
	private function get_element_classes( $element ) {
		$classes = array();

		// Global classes - resolve IDs to names
		if ( isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			$global_classes = get_option( 'bricks_global_classes', array() );

			foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
				// Find the class by ID
				foreach ( $global_classes as $global_class ) {
					if ( isset( $global_class['id'] ) && $global_class['id'] === $class_id ) {
						if ( isset( $global_class['name'] ) ) {
							$classes[] = $global_class['name'];
						}
						break;
					}
				}
			}
		}

		// Custom classes (these are already names, not IDs)
		// Note: _cssClasses is a STRING in Bricks, not an array!
		if ( isset( $element['settings']['_cssClasses'] ) && ! empty( $element['settings']['_cssClasses'] ) ) {
			$css_classes = $element['settings']['_cssClasses'];
			// Split by space if it's a string
			if ( is_string( $css_classes ) ) {
				$css_classes = explode( ' ', trim( $css_classes ) );
			}
			// Merge with existing classes
			if ( is_array( $css_classes ) ) {
				$classes = array_merge( $classes, $css_classes );
			}
		}

		return $classes;
	}

	/**
	 * Get Etch style IDs for element (convert Bricks class IDs to Etch style IDs)
	 */
	private function get_element_style_ids( $element ) {
		$style_ids = array();

		$element_name = $element['name'] ?? 'unknown';
		$this->error_handler->log_info( 'Gutenberg Generator: get_element_style_ids called for element ' . $element_name );

		// Method 1: Get Bricks global class IDs (if available)
		if ( isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			// Get style map (Bricks ID => Etch ID)
			$style_map = get_option( 'efs_style_map', array() );

			$this->error_handler->log_info( 'Gutenberg Generator: Element ' . $element_name . ' has ' . count( $element['settings']['_cssGlobalClasses'] ) . ' global classes' );

			foreach ( $element['settings']['_cssGlobalClasses'] as $bricks_class_id ) {
				// Convert Bricks ID to Etch ID (handle both old and new format)
				if ( isset( $style_map[ $bricks_class_id ] ) ) {
					$etch_id     = is_array( $style_map[ $bricks_class_id ] ) ? $style_map[ $bricks_class_id ]['id'] : $style_map[ $bricks_class_id ];
					$style_ids[] = $etch_id;
					$this->error_handler->log_info( 'Gutenberg Generator: Mapped Bricks ID ' . $bricks_class_id . ' to Etch ID ' . $etch_id );
				} else {
					$this->error_handler->log_info( 'Gutenberg Generator: Bricks ID ' . $bricks_class_id . ' not found in style_map' );
				}
			}
		} else {
			$this->error_handler->log_info( 'Gutenberg Generator: Element ' . $element_name . ' has no _cssGlobalClasses in settings' );
		}

		// Method 2: Get regular CSS classes and map them to style IDs
		// This is needed because many Bricks sites use _cssClasses instead of _cssGlobalClasses
		$classes = $this->get_element_classes( $element );
		if ( ! empty( $classes ) ) {
			// Get Bricks Global Classes to check which classes actually have styles
			$bricks_classes     = get_option( 'bricks_global_classes', array() );
			$bricks_class_names = array();
			foreach ( $bricks_classes as $bricks_class ) {
				$class_name = ! empty( $bricks_class['name'] ) ? $bricks_class['name'] : $bricks_class['id'];
				// Remove ACSS prefix
				$class_name           = preg_replace( '/^acss_import_/', '', $class_name );
				$bricks_class_names[] = $class_name;
			}

			// Only generate IDs for classes that exist in Bricks Global Classes
			// We need to find the Bricks ID for this class name, then lookup in style_map
			$style_map = get_option( 'efs_style_map', array() );
			$this->error_handler->log_info( 'Gutenberg Generator: Style map has ' . count( $style_map ) . ' entries' );
			$this->error_handler->log_info( 'Gutenberg Generator: Processing ' . count( $classes ) . ' classes: ' . implode( ', ', $classes ) );

			foreach ( $classes as $class_name ) {
				if ( in_array( $class_name, $bricks_class_names, true ) ) {
					// Find the Bricks ID for this class name
					$bricks_id = null;
					foreach ( $bricks_classes as $bricks_class ) {
						$bricks_class_name = ! empty( $bricks_class['name'] ) ? $bricks_class['name'] : $bricks_class['id'];
						$bricks_class_name = preg_replace( '/^acss_import_/', '', $bricks_class_name );
						if ( $class_name === $bricks_class_name ) {
							$bricks_id = $bricks_class['id'];
							break;
						}
					}

					// Lookup Etch ID in style_map (handle both old and new format)
					if ( $bricks_id && isset( $style_map[ $bricks_id ] ) ) {
						$etch_id     = is_array( $style_map[ $bricks_id ] ) ? $style_map[ $bricks_id ]['id'] : $style_map[ $bricks_id ];
						$style_ids[] = $etch_id;
						$this->error_handler->log_info( 'Gutenberg Generator: Found global class ' . $class_name . ' (Bricks ID ' . $bricks_id . ') mapped to Etch ID ' . $etch_id );
					} else {
						$this->error_handler->log_info( 'Gutenberg Generator: Global class ' . $class_name . ' missing style_map entry' );
					}
				} else {
					$this->error_handler->log_info( 'Gutenberg Generator: Skipping non-global class ' . $class_name );
				}
			}
		}

		return array_unique( $style_ids );
	}

	/**
	 * Convert style IDs to CSS class names for block-level attributes.class.
	 *
	 * Uses efs_style_map which contains both IDs and selectors.
	 */
	private function get_css_classes_from_style_ids( $style_ids ) {
		if ( empty( $style_ids ) ) {
			$this->error_handler->log_info( 'Gutenberg Generator: No style IDs provided' );
			return '';
		}

		// Get style map which now contains selectors
		$style_map = get_option( 'efs_style_map', array() );
		$this->error_handler->log_info( 'Gutenberg Generator: Style map has ' . count( $style_map ) . ' entries' );
		$this->error_handler->log_info( 'Gutenberg Generator: Looking for style IDs: ' . implode( ', ', $style_ids ) );

		$class_names = array();

		foreach ( $style_ids as $style_id ) {
			// Skip Etch internal styles (they don't have selectors in our map)
			if ( in_array( $style_id, array( 'etch-section-style', 'etch-container-style', 'etch-iframe-style', 'etch-block-style' ), true ) ) {
				$this->error_handler->log_info( 'Gutenberg Generator: Skipping internal style ID ' . $style_id );
				continue;
			}

			// Find the Bricks ID for this Etch style ID
			foreach ( $style_map as $bricks_id => $style_data ) {
				// Handle both old format (string) and new format (array)
				$etch_id = is_array( $style_data ) ? $style_data['id'] : $style_data;

				if ( $style_id === $etch_id ) {
					// New format: get selector from style_data
					if ( is_array( $style_data ) && ! empty( $style_data['selector'] ) ) {
						$selector = $style_data['selector'];
						// Remove leading dot: ".my-class" => "my-class"
						$class_name = ltrim( $selector, '.' );
						// Remove pseudo-selectors and attribute selectors
						$class_name = preg_replace( '/[\[\]:].+$/', '', $class_name );

						if ( ! empty( $class_name ) ) {
							$class_names[] = $class_name;
							$this->error_handler->log_info( 'Gutenberg Generator: Resolved style ID ' . $style_id . ' to class ' . $class_name );
						}
					}

					break;
				}
			}
		}

		$result = ! empty( $class_names ) ? implode( ' ', array_unique( $class_names ) ) : '';
		$this->error_handler->log_info( 'Gutenberg Generator: Resolved CSS classes string: "' . $result . '"' );
		return $result;
	}

	/**
	 * Generate Gutenberg blocks from Bricks elements (REAL CONVERSION!)
	 */
	public function generate_gutenberg_blocks( $bricks_elements ) {
		if ( empty( $bricks_elements ) || ! is_array( $bricks_elements ) ) {
			return '';
		}

		$this->error_handler->log_info( 'Gutenberg Generator: Generating blocks with modular converters' );

		// Initialize element factory with style map (NEW - v0.5.0)
		$style_map                     = get_option( 'efs_style_map', array() );
		$_gg_container                 = etch_fusion_suite_container();
		$_gg_registry                  = $_gg_container->has( 'converter_registry' ) ? $_gg_container->get( 'converter_registry' ) : new \Bricks2Etch\Converters\EFS_Converter_Registry();
		$this->element_factory         = new EFS_Element_Factory( $_gg_registry, $style_map, $this->error_handler );
		$this->display_fallback_helper = new EFS_Element_Div( $style_map );
		$this->error_handler->log_info( 'Gutenberg Generator: Element factory initialized with ' . count( $style_map ) . ' style map entries' );

		// Build element lookup map (id => element)
		$element_map = array();
		foreach ( $bricks_elements as $element ) {
			$element_map[ $element['id'] ] = $element;
		}

		// Find top-level elements (parent = 0 or parent not in map)
		$top_level_elements = array();
		foreach ( $bricks_elements as $element ) {
			$parent_id = $element['parent'] ?? 0;
			if ( 0 === $parent_id || '0' === $parent_id || ! isset( $element_map[ $parent_id ] ) ) {
				$top_level_elements[] = $element;
			}
		}

		// Generate blocks for top-level elements (recursively includes children)
		$gutenberg_blocks = array();
		foreach ( $top_level_elements as $element ) {
			$block_html = $this->generate_block_html( $element, $element_map );
			if ( $block_html ) {
				$gutenberg_blocks[] = $block_html;
			}
		}

		return implode( "\n", $gutenberg_blocks );
	}

	/**
	 * Convert Bricks to Gutenberg and save to database (FOR ECH PROCESSING!)
	 */
	public function convert_bricks_to_gutenberg( $post ) {
		$this->error_handler->log_info( 'Gutenberg Generator: convert_bricks_to_gutenberg called for post ' . $post->ID );

		// Check for Bricks content first
		$bricks_content = get_post_meta( $post->ID, '_bricks_page_content_2', true );

		// If it's a JSON string, decode it
		if ( is_string( $bricks_content ) ) {
			$decoded = json_decode( $bricks_content, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$bricks_content = $decoded;
			}
		}

		if ( empty( $bricks_content ) || ! is_array( $bricks_content ) ) {
			$this->error_handler->log_info(
				'No Bricks content found for conversion',
				array(
					'post_id'      => $post->ID,
					'content_type' => gettype( $bricks_content ),
				)
			);
			return false;
		}

		$this->error_handler->log_info( 'Gutenberg Generator: Found ' . count( $bricks_content ) . ' Bricks elements' );

		// Convert Bricks elements directly to Gutenberg HTML (SIMPLE APPROACH)
		$gutenberg_html = $this->convert_bricks_to_gutenberg_html( $bricks_content, $post );

		$this->error_handler->log_info( 'Gutenberg Generator: Generated HTML length ' . strlen( $gutenberg_html ) );

		if ( empty( $gutenberg_html ) ) {
			$this->error_handler->log_info(
				'Failed to generate Gutenberg blocks',
				array(
					'post_id' => $post->ID,
				)
			);
			return false;
		}

		// Append inline JavaScript from code blocks (if any)
		$inline_js = get_option( 'efs_inline_js_' . $post->ID, get_option( 'b2e_inline_js_' . $post->ID, '' ) );
		if ( ! empty( $inline_js ) ) {
			$gutenberg_html .= "\n\n<!-- wp:html -->\n<script>\n" . trim( $inline_js ) . "\n</script>\n<!-- /wp:html -->";
			delete_option( 'efs_inline_js_' . $post->ID );
			delete_option( 'b2e_inline_js_' . $post->ID );
		}

		// Convert HTML to blocks array
		$gutenberg_blocks = parse_blocks( $gutenberg_html );

		// Get target site URL from settings
		$settings   = get_option( 'efs_settings', array() );
		$target_url = $settings['target_url'] ?? '';

		if ( empty( $target_url ) ) {
			$this->error_handler->log_info(
				'Failed to send blocks — missing target URL/API key',
				array(
					'post_id' => $post->ID,
					'reason'  => 'No target URL configured',
				)
			);
			return false;
		}

		// Get API key from settings (stored after token validation)
		$api_key = $settings['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			$this->error_handler->log_info(
				'Failed to send blocks — missing target URL/API key',
				array(
					'post_id' => $post->ID,
					'reason'  => 'No API key found in settings',
				)
			);
			return false;
		}

		// Send blocks to Etch via HTTP (using efs/v1 REST API endpoint)
		$endpoint_url = rtrim( $target_url, '/' ) . '/wp-json/efs/v1/import/post';

		// Convert bricks_template to page
		$target_post_type = $post->post_type;
		if ( 'bricks_template' === $target_post_type ) {
			$target_post_type = 'page';
			$this->error_handler->log_info( 'Gutenberg Generator: Converting bricks_template to page for post ' . $post->post_title );
		}

		// Prepare payload in format expected by /import/post endpoint
		$payload = array(
			'post'         => array(
				'post_title'   => $post->post_title,
				'post_name'    => $post->post_name,
				'post_type'    => $target_post_type, // Use converted type
				'post_status'  => $post->post_status,
				'post_excerpt' => $post->post_excerpt,
				'post_date'    => $post->post_date,
				'post_author'  => $post->post_author,
			),
			'etch_content' => $gutenberg_html, // Send HTML, not blocks array
		);

		// Debug log
		$this->error_handler->log_info( 'Gutenberg Generator: Sending blocks to endpoint ' . $endpoint_url );
		$this->error_handler->log_info( 'Gutenberg Generator: Post title ' . $post->post_title );
		$this->error_handler->log_info( 'Gutenberg Generator: Blocks count ' . count( $gutenberg_blocks ) );
		$this->error_handler->log_info( 'Gutenberg Generator: API key prefix ' . substr( $api_key, 0, 10 ) . '...' );

		$response = wp_remote_post(
			$endpoint_url,
			array(
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'timeout' => 30,
			)
		);

		// Debug log response
		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_info( 'Gutenberg Generator: API request error - ' . $response->get_error_message() );
		} else {
			$this->error_handler->log_info( 'Gutenberg Generator: Response code ' . wp_remote_retrieve_response_code( $response ) );
			$this->error_handler->log_info( 'Gutenberg Generator: Response body ' . wp_remote_retrieve_body( $response ) );
		}

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_error(
				'I024',
				array(
					'post_id' => $post->ID,
					'error'   => $response->get_error_message(),
					'action'  => 'Failed to send blocks to Etch API',
				)
			);
			return false;
		}

		// Check response
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$response_body = wp_remote_retrieve_body( $response );
			$this->error_handler->log_error(
				'I024',
				array(
					'post_id' => $post->ID,
					'error'   => "API returned {$response_code}: {$response_body}",
					'action'  => 'Failed to save blocks via Etch API',
				)
			);
			return false;
		}

		// Remove Bricks meta (cleanup)
		delete_post_meta( $post->ID, '_bricks_page_content' );
		delete_post_meta( $post->ID, '_bricks_page_settings' );

		// Log successful conversion and API save
		$this->error_handler->log_error(
			'I023',
			array(
				'post_id'             => $post->ID,
				'post_title'          => $post->post_title,
				'bricks_elements'     => count( $bricks_content ),
				'blocks_generated'    => count( $gutenberg_blocks ),
				'api_method'          => 'Custom Migration Endpoint via HTTP',
				'database_updated'    => true,
				'bricks_meta_cleaned' => true,
				'action'              => 'Bricks converted to Gutenberg blocks and saved via Etch API',
			)
		);

		return true;
	}

	/**
	 * Generate HTML for a single block
	 */
	private function generate_block_html( $element, $element_map = array() ) {
		// NEW v0.5.0: Use Element Factory for conversion
		if ( $this->element_factory ) {
			// Get children for this element
			$children = array();
			if ( isset( $element['children'] ) && is_array( $element['children'] ) ) {
				foreach ( $element['children'] as $child_id ) {
					if ( isset( $element_map[ $child_id ] ) ) {
						$child_html = $this->generate_block_html( $element_map[ $child_id ], $element_map );
						if ( $child_html ) {
							$children[] = $child_html;
						}
					}
				}
			}

			// Pass context for template (component) elements so slotChildren can be resolved to innerBlocks.
			$context = array();
			if ( ( $element['name'] ?? '' ) === 'template' && ! empty( $element_map ) ) {
				$context = array(
					'element_map'      => $element_map,
					'convert_callback' => array( $this, 'generate_block_html' ),
				);
			}

			// Use factory to convert element
			$result = $this->element_factory->convert_element( $element, $children, $context );

			if ( $result ) {
				$is_loop_element = ! empty( $element['settings']['hasLoop'] ) && ! empty( $element['settings']['query'] ) && is_array( $element['settings']['query'] );
				$dynamic_context = $is_loop_element ? array(
					'scope'      => 'loop',
					'loop_alias' => 'item',
					'loop_query' => $element['settings']['query'],
				) : array();
				$result          = $this->dynamic_data_converter->convert_content( $result, $dynamic_context );
				return $this->wrap_loop_block_if_needed( $element, $result );
			}

			// Fallback to old method if factory doesn't support this element
			$this->error_handler->log_info( 'Gutenberg Generator: Element factory returned null for element type ' . ( $element['name'] ?? 'unknown' ) );
		}

		// OLD METHOD (Fallback)
		$etch_type = $element['etch_type'] ?? 'generic';

		// Skip elements marked for skipping (e.g., code blocks)
		if ( 'skip' === $etch_type ) {
			return '';
		}

		// Check if this is a semantic tag that should be a group block
		$group_block_types = array(
			'section',
			'container',
			'div',
			'flex-div',
			'iframe',
			'ul',
			'ol',
			'li',
			'span',
			'figure',
			'figcaption',
			'article',
			'aside',
			'nav',
			'header',
			'footer',
			'main',
			'blockquote',
			'a',
		);

		if ( in_array( $etch_type, $group_block_types, true ) ) {
			return $this->generate_etch_group_block( $element, $element_map );
		}

		switch ( $etch_type ) {

			case 'heading':
			case 'paragraph':
			case 'image':
			case 'button':
				return $this->generate_standard_block( $element );

			case 'skip':
				return ''; // Skip this element

			default:
				return $this->generate_generic_block( $element );
		}
	}

	/**
	 * Wrap converted element markup in an Etch loop block when Bricks hasLoop/query is configured.
	 *
	 * @param array  $element Element payload.
	 * @param string $inner_markup Converted inner element block markup.
	 * @return string
	 */
	private function wrap_loop_block_if_needed( $element, $inner_markup ) {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		if ( empty( $settings['hasLoop'] ) || empty( $settings['query'] ) || ! is_array( $settings['query'] ) ) {
			return $inner_markup;
		}

		// Bricks standard loops use objectType/post_type and do NOT set a query.type field.
		// Only reject types that are explicitly unsupported (WooCommerce, custom PHP, etc.).
		// An absent query type is handled by normalize_loop_query_for_preset via objectType.
		$query_type  = isset( $settings['query']['type'] ) ? strtolower( trim( (string) $settings['query']['type'] ) ) : '';
		$is_rejected = 'woocommerce' === $query_type || 'custom' === $query_type || 'php' === $query_type
			|| ( '' !== $query_type && 0 === strpos( $query_type, 'wc-' ) );
		if ( $is_rejected ) {
			$placeholder = '<!-- [EFS] Loop not converted: ' . $query_type . ' -->';
			$this->error_handler->log_warning(
				'W007',
				array(
					'query_type' => $query_type,
					'element_id' => $element['id'] ?? '',
				)
			);
			return $placeholder . "\n" . (string) $inner_markup;
		}

		$loop_id = $this->ensure_etch_loop_preset( $settings['query'], $element );
		if ( '' === $loop_id ) {
			$placeholder = '<!-- [EFS] Loop not converted: ' . ( '' !== $query_type ? $query_type : 'unknown' ) . ' -->';
			$this->error_handler->log_warning(
				'W007',
				array(
					'query_type' => $query_type,
					'element_id' => $element['id'] ?? '',
				)
			);
			return $placeholder . "\n" . (string) $inner_markup;
		}

		$attrs_json = wp_json_encode(
			array(
				'loopId' => $loop_id,
				'itemId' => 'item',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return '<!-- wp:etch/loop ' . $attrs_json . ' -->' . "\n" .
			trim( (string) $inner_markup ) . "\n" .
			'<!-- /wp:etch/loop -->';
	}

	/**
	 * Ensure the standard Etch posts loop preset exists and return its ID.
	 *
	 * @param array $bricks_query Bricks query settings.
	 * @param array $element      Source element (for naming).
	 * @return string
	 */
	private function ensure_etch_loop_preset( $bricks_query, $element = array() ) {
		$loops = get_option( 'etch_loops', array() );
		$loops = is_array( $loops ) ? $loops : array();

		$normalized = $this->normalize_loop_query_for_preset( $bricks_query );
		$preset_key = isset( $normalized['preset_key'] ) ? (string) $normalized['preset_key'] : 'posts';
		$config     = isset( $normalized['config'] ) && is_array( $normalized['config'] ) ? $normalized['config'] : array();

		if ( empty( $config ) ) {
			return '';
		}

		$context_slug = $this->derive_loop_context_slug( $element );
		if ( '' === $context_slug ) {
			$context_slug = sanitize_key( $preset_key );
			if ( '' === $context_slug ) {
				$context_slug = 'posts';
			}
		}

		$config_json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$config_hash = is_string( $config_json ) ? substr( md5( $config_json ), 0, 8 ) : '';

		foreach ( $loops as $existing_id => $loop ) {
			if ( ! is_array( $loop ) || ! isset( $loop['config'] ) || ! is_array( $loop['config'] ) ) {
				continue;
			}
			$existing_json = wp_json_encode( $loop['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $existing_json ) || $existing_json !== $config_json ) {
				continue;
			}
			$existing_context = isset( $loop['context_slug'] ) ? (string) $loop['context_slug'] : '';
			if ( '' === $existing_context ) {
				$existing_context = 'posts';
				if ( 'posts' !== $preset_key ) {
					$existing_context = sanitize_key( $preset_key );
				}
			}
			if ( $existing_context === $context_slug ) {
				return (string) $existing_id;
			}
		}

		$loop_id = $context_slug . '_' . $config_hash;
		$name    = $context_slug;
		$key     = 'posts' === $preset_key ? 'posts' : sanitize_key( $preset_key );
		if ( '' === $key ) {
			$key = 'posts';
		}

		$loops[ $loop_id ] = array(
			'name'         => $name,
			'key'          => $key,
			'global'       => true,
			'config'       => $config,
			'context_slug' => $context_slug,
		);

		update_option( 'etch_loops', $loops );

		return (string) $loop_id;
	}

	/**
	 * Convert Bricks loop query settings into a stable Etch loop preset config.
	 *
	 * @param array $bricks_query Bricks query settings.
	 * @return array{preset_key:string,config:array<string,mixed>}
	 */
	private function normalize_loop_query_for_preset( $bricks_query ) {
		$query = is_array( $bricks_query ) ? $bricks_query : array();

		$object_type = isset( $query['objectType'] ) ? sanitize_key( (string) $query['objectType'] ) : 'post';
		if ( 'term' === $object_type ) {
			$taxonomy = 'category';
			if ( isset( $query['taxonomy'] ) && is_array( $query['taxonomy'] ) ) {
				$first_tax = reset( $query['taxonomy'] );
				if ( is_string( $first_tax ) && '' !== trim( $first_tax ) ) {
					$taxonomy = sanitize_key( $first_tax );
				}
			} elseif ( isset( $query['taxonomy'] ) && is_string( $query['taxonomy'] ) && '' !== trim( $query['taxonomy'] ) ) {
				$taxonomy = sanitize_key( (string) $query['taxonomy'] );
			}

			return array(
				'preset_key' => 'terms_' . $taxonomy,
				'config'     => array(
					'type' => 'wp-terms',
					'args' => array(
						'taxonomy' => $taxonomy,
					),
				),
			);
		}

		$post_type = 'post';
		if ( isset( $query['post_type'] ) && is_array( $query['post_type'] ) ) {
			$first_type = reset( $query['post_type'] );
			if ( is_string( $first_type ) && '' !== trim( $first_type ) ) {
				$post_type = sanitize_key( $first_type );
			}
		} elseif ( isset( $query['post_type'] ) && is_string( $query['post_type'] ) && '' !== trim( $query['post_type'] ) ) {
			$post_type = sanitize_key( (string) $query['post_type'] );
		}

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => '$limit ?? -1',
			'orderby'        => '$orderby ?? \'date\'',
			'order'          => '$order ?? \'DESC\'',
			'post_status'    => 'publish',
		);

		// Attachments use regular WP post status here.
		if ( 'attachment' === $post_type ) {
			$args['post_status'] = 'inherit';
		}

		if ( isset( $query['posts_per_page'] ) && is_scalar( $query['posts_per_page'] ) && '' !== trim( (string) $query['posts_per_page'] ) ) {
			$args['posts_per_page'] = (string) $query['posts_per_page'];
		}

		if ( ! empty( $query['tax_query_advanced'] ) && is_array( $query['tax_query_advanced'] ) ) {
			$tax_query = array();
			foreach ( $query['tax_query_advanced'] as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				$taxonomy = isset( $rule['taxonomy'] ) ? sanitize_key( (string) $rule['taxonomy'] ) : '';
				if ( '' === $taxonomy ) {
					continue;
				}
				$field = isset( $rule['field'] ) ? sanitize_key( (string) $rule['field'] ) : 'term_id';
				$op    = isset( $rule['operator'] ) ? strtoupper( sanitize_text_field( (string) $rule['operator'] ) ) : 'IN';
				$terms = isset( $rule['terms'] ) ? (string) $rule['terms'] : '';
				if ( '{term_id}' === trim( $terms ) ) {
					$terms = '$term_id ?? null';
				}

				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => $field,
					'terms'    => $terms,
					'operator' => $op,
				);
			}

			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query;
			}
		}

		$is_default_posts = 'post' === $post_type
			&& '$limit ?? -1' === $args['posts_per_page']
			&& empty( $args['tax_query'] );

		if ( $is_default_posts ) {
			return array(
				'preset_key' => 'posts',
				'config'     => array(
					'type' => 'wp-query',
					'args' => $args,
				),
			);
		}

		$preset_key = 'posts_' . $post_type;
		if ( ! empty( $args['tax_query'] ) ) {
			$preset_key .= '_tax';
		}

		return array(
			'preset_key' => $preset_key,
			'config'     => array(
				'type' => 'wp-query',
				'args' => $args,
			),
		);
	}

	/**
	 * Generate structural Etch element fallback using flat v1.1+ attrs.
	 */
	private function generate_etch_group_block( $element, $element_map = array() ) {
		$etch_type = $element['etch_type'] ?? 'div';
		$etch_data = isset( $element['etch_data'] ) && is_array( $element['etch_data'] ) ? $element['etch_data'] : array();
		$content   = $element['content'] ?? '';

		// Convert dynamic data in content.
		$content = $this->dynamic_data_converter->convert_content( $content );

		// Generate nested block content first.
		$block_content = $this->generate_block_content( $element, $content, $element_map );
		$tag           = (string) ( $etch_data['tag'] ?? $this->get_html_tag( $etch_type ) );

		$fallback_attributes = array();
		foreach ( $etch_data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( in_array( $key, array( 'class', 'tag', 'content' ), true ) ) {
				continue;
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$fallback_attributes[ $key ] = (string) $value;
			}
		}

		$attrs = $this->build_flat_fallback_attrs( $element, $tag, $fallback_attributes, $etch_type );

		return $this->serialize_etch_element_block( $attrs, $block_content );
	}

	/**
	 * Generate non-structural fallback blocks using flat Etch attrs.
	 */
	private function generate_standard_block( $element ) {
		$etch_type = $element['etch_type'] ?? 'generic';
		$etch_data = isset( $element['etch_data'] ) && is_array( $element['etch_data'] ) ? $element['etch_data'] : array();
		$content   = $etch_data['content'] ?? $element['content'] ?? '';

		$content = $this->dynamic_data_converter->convert_content( $content );

		switch ( $etch_type ) {
			case 'heading':
				$tag = strtolower( (string) ( $etch_data['level'] ?? 'h3' ) );
				if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
					$tag = 'h3';
				}
				$attrs   = $this->build_flat_fallback_attrs( $element, $tag );
				$content = EFS_Base_Element::decode_html_entities_to_plain( $content );
				return $this->serialize_etch_element_block( $attrs, esc_html( wp_strip_all_tags( $content ) ) );

			case 'paragraph':
				$attrs = $this->build_flat_fallback_attrs( $element, 'p' );
				return $this->serialize_etch_element_block( $attrs, wp_kses_post( $content ) );

			case 'image':
				$src = isset( $etch_data['src'] ) ? trim( (string) $etch_data['src'] ) : '';
				if ( '' === $src ) {
					return '';
				}

				$image_attributes = array(
					'src' => esc_url_raw( $src ),
				);
				if ( isset( $etch_data['alt'] ) && '' !== trim( (string) $etch_data['alt'] ) ) {
					$image_attributes['alt'] = sanitize_text_field( (string) $etch_data['alt'] );
				}

				$attrs = $this->build_flat_fallback_attrs( $element, 'img', $image_attributes );
				return $this->serialize_etch_element_block( $attrs );

			case 'button':
				$link_attributes = array(
					'href' => esc_url_raw( (string) ( $etch_data['href'] ?? '#' ) ),
				);

				if ( ! empty( $etch_data['target'] ) ) {
					$link_attributes['target'] = sanitize_key( (string) $etch_data['target'] );
				}

				$attrs   = $this->build_flat_fallback_attrs( $element, 'a', $link_attributes );
				$content = EFS_Base_Element::decode_html_entities_to_plain( $content );
				return $this->serialize_etch_element_block( $attrs, esc_html( wp_strip_all_tags( $content ) ) );

			default:
				return $this->generate_generic_block( $element );
		}
	}

	/**
	 * Generate generic fallback as an Etch raw HTML block.
	 */
	private function generate_generic_block( $element ) {
		$etch_data = isset( $element['etch_data'] ) && is_array( $element['etch_data'] ) ? $element['etch_data'] : array();
		$content   = $element['content'] ?? '';
		$content   = $this->dynamic_data_converter->convert_content( $content );

		if ( '' === trim( (string) $content ) && ! empty( $etch_data['content'] ) ) {
			$content = (string) $etch_data['content'];
		}

		$attrs = array(
			'metadata' => array(
				'name' => $this->sanitize_element_label( $element['label'] ?? '', 'HTML' ),
			),
			'content'  => (string) $content,
			'unsafe'   => false,
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}

	/**
	 * Generate block content
	 */
	private function generate_block_content( $element, $content, $element_map = array() ) {
		// Handle children elements
		if ( ! empty( $element['children'] ) && is_array( $element['children'] ) && ! empty( $element_map ) ) {
			$children_blocks = array();

			foreach ( $element['children'] as $child_id ) {
				// Find child element in map
				if ( isset( $element_map[ $child_id ] ) ) {
					$child_element = $element_map[ $child_id ];
					$child_html    = $this->generate_block_html( $child_element, $element_map );
					if ( $child_html ) {
						$children_blocks[] = $child_html;
					}
				}
			}

			return implode( "\n", $children_blocks );
		}

		return $content;
	}

	/**
	 * Build flat Etch v1.1+ attrs for fallback serialization paths.
	 *
	 * @param array  $element Bricks element.
	 * @param string $tag HTML tag for the Etch element block.
	 * @param array  $extra_attributes Optional attributes to merge.
	 * @param string $etch_type Optional explicit etch type.
	 * @return array<string, mixed>
	 */
	private function build_flat_fallback_attrs( $element, $tag, $extra_attributes = array(), $etch_type = '' ) {
		$resolved_etch_type = '';
		if ( '' !== $etch_type ) {
			$resolved_etch_type = (string) $etch_type;
		} else {
			$resolved_etch_type = isset( $element['etch_type'] ) ? (string) $element['etch_type'] : '';
		}

		$style_ids = $this->get_element_style_ids( $element );
		$style_ids = $this->prepend_default_structural_style( $resolved_etch_type, $style_ids );

		$etch_data  = isset( $element['etch_data'] ) && is_array( $element['etch_data'] ) ? $element['etch_data'] : array();
		$attributes = array();

		if ( isset( $etch_data['data-etch-element'] ) && '' !== trim( (string) $etch_data['data-etch-element'] ) ) {
			$attributes['data-etch-element'] = (string) $etch_data['data-etch-element'];
		}

		$attributes = array_merge( $attributes, $this->normalize_scalar_attributes( $extra_attributes ) );

		$mapped_class = $this->get_css_classes_from_style_ids( $style_ids );
		$class_value  = $this->merge_class_names(
			$mapped_class,
			isset( $etch_data['class'] ) ? (string) $etch_data['class'] : '',
			isset( $attributes['class'] ) ? (string) $attributes['class'] : ''
		);

		if ( '' !== $class_value ) {
			$attributes['class'] = $class_value;
		} elseif ( isset( $attributes['class'] ) ) {
			unset( $attributes['class'] );
		}

		$element_name = '';
		if ( ! empty( $element['label'] ) ) {
			$element_name = $this->sanitize_element_label( $element['label'], '' );
		}
		if ( '' === $element_name && '' !== $resolved_etch_type ) {
			$element_name = ucfirst( $resolved_etch_type );
		}
		if ( '' === $element_name ) {
			$element_name = 'Element';
		}

		return array(
			'metadata'   => array(
				'name' => $element_name,
			),
			'tag'        => (string) $tag,
			'attributes' => $attributes,
			'styles'     => $style_ids,
		);
	}

	/**
	 * Prepend default Etch styles for semantic structural types.
	 *
	 * @param string $etch_type Element type.
	 * @param array  $style_ids Existing style IDs.
	 * @return array<int, string>
	 */
	private function prepend_default_structural_style( $etch_type, $style_ids ) {
		$normalized = array();
		foreach ( (array) $style_ids as $style_id ) {
			if ( ! is_scalar( $style_id ) ) {
				continue;
			}

			$style_id = trim( (string) $style_id );
			if ( '' === $style_id ) {
				continue;
			}

			$normalized[] = $style_id;
		}

		$default_style = '';
		switch ( $etch_type ) {
			case 'section':
				$default_style = 'etch-section-style';
				break;
			case 'container':
				$default_style = 'etch-container-style';
				break;
			case 'iframe':
				$default_style = 'etch-iframe-style';
				break;
		}

		if ( '' !== $default_style ) {
			array_unshift( $normalized, $default_style );
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize Bricks structure-panel labels for Etch metadata names.
	 *
	 * Removes technical suffixes (e.g. "(CSS Tab)") and HTML-like tag markers
	 * such as "<ul>", "<li>", etc. before migration.
	 *
	 * @param mixed  $label Raw label value.
	 * @param string $fallback Fallback label when cleaned value is empty.
	 * @return string
	 */
	private function sanitize_element_label( $label, $fallback = 'Element' ) {
		if ( ! is_scalar( $label ) ) {
			return (string) $fallback;
		}

		$normalized = trim( (string) $label );
		if ( '' === $normalized ) {
			return (string) $fallback;
		}

		$normalized = html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$normalized = preg_replace( '/\(\s*css(?:\s+tab)?\s*\)/i', '', $normalized );
		$normalized = preg_replace( '/<[^>]*>/', '', $normalized );
		$normalized = str_replace( array( '<', '>' ), '', $normalized );
		$normalized = preg_replace( '/\s{2,}/', ' ', $normalized );
		$normalized = trim( $normalized, " \t\n\r\0\x0B-:|" );

		if ( '' === $normalized ) {
			return (string) $fallback;
		}

		return $normalized;
	}

	/**
	 * Derive a URL-safe slug from the element label for use as loop context (e.g. "Feature Card Alpha" → "feature-card-alpha").
	 *
	 * @param array $element Bricks element with optional 'label' key.
	 * @return string Slug or empty string if no usable label.
	 */
	private function derive_loop_context_slug( $element ) {
		$label = isset( $element['label'] ) && is_scalar( $element['label'] ) ? trim( (string) $element['label'] ) : '';
		if ( '' === $label ) {
			return '';
		}
		$cleaned = $this->sanitize_element_label( $label, '' );
		if ( '' === $cleaned ) {
			return '';
		}
		$slug = strtolower( $cleaned );
		$slug = preg_replace( '/[^a-z0-9\s-]/', '', $slug );
		$slug = preg_replace( '/\s+/', '-', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		$slug = trim( $slug, '-' );
		return $slug;
	}

	/**
	 * Normalize scalar attribute values.
	 *
	 * @param array $attributes Raw attributes.
	 * @return array<string, string>
	 */
	private function normalize_scalar_attributes( $attributes ) {
		$normalized = array();

		if ( ! is_array( $attributes ) ) {
			return $normalized;
		}

		foreach ( $attributes as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Merge class strings and remove duplicates.
	 *
	 * @param string ...$class_sets Class name sets.
	 * @return string
	 */
	private function merge_class_names( ...$class_sets ) {
		$classes = array();

		foreach ( $class_sets as $class_set ) {
			if ( ! is_scalar( $class_set ) ) {
				continue;
			}

			$parts = preg_split( '/\s+/', trim( (string) $class_set ) );
			if ( ! is_array( $parts ) ) {
				continue;
			}

			foreach ( $parts as $class_name ) {
				if ( '' === $class_name ) {
					continue;
				}

				if ( $this->should_exclude_transferred_class( $class_name ) ) {
					continue;
				}

				$classes[] = $class_name;
			}
		}

		if ( empty( $classes ) ) {
			return '';
		}

		return implode( ' ', array_values( array_unique( $classes ) ) );
	}

	/**
	 * Exclude specific utility classes from being transferred to target markup.
	 *
	 * @param string $class_name Raw class token.
	 * @return bool
	 */
	private function should_exclude_transferred_class( $class_name ) {
		$normalized = ltrim( preg_replace( '/^acss_import_/', '', trim( (string) $class_name ) ), '.' );
		if ( '' === $normalized ) {
			return true;
		}

		$excluded = array(
			'fr-lede',
			'fr-intro',
			'fr-note',
			'fr-notes',
			'text--l',
		);

		return in_array( $normalized, $excluded, true );
	}

	/**
	 * Extract style IDs from element settings
	 */
	private function extract_style_ids( $settings ) {
		$style_ids = array();

		// Add Etch element style
		$etch_type = $settings['etch_type'] ?? '';
		if ( $etch_type ) {
			$style_ids[] = 'etch-' . $etch_type . '-style';
		}

		// Extract CSS classes and convert to style IDs
		if ( ! empty( $settings['_cssClasses'] ) ) {
			$style_ids[] = $this->generate_style_hash( $settings['_cssClasses'] );
		}

		if ( ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ) {
			foreach ( $settings['_cssGlobalClasses'] as $global_class ) {
				$style_ids[] = $this->generate_style_hash( $global_class );
			}
		}

		return array_unique( $style_ids );
	}

	/**
	 * Generate 7-character hash ID for styles
	 */
	private function generate_style_hash( $class_name ) {
		return substr( md5( $class_name ), 0, 7 );
	}

	/**
	 * Get HTML tag for Etch element type
	 */
	private function get_html_tag( $etch_type ) {
		// Semantic HTML tags - return as-is
		$semantic_tags = array(
			'ul',
			'ol',
			'li',
			'span',
			'figure',
			'figcaption',
			'article',
			'aside',
			'nav',
			'header',
			'footer',
			'main',
			'p',
			'blockquote',
			'a',
		);

		if ( in_array( $etch_type, $semantic_tags, true ) ) {
			return $etch_type;
		}

		// Special Etch elements
		switch ( $etch_type ) {
			case 'section':
				return 'section';
			case 'container':
				return 'div';
			case 'div':
				return 'div';
			case 'flex-div':
				return 'div';
			case 'iframe':
				return 'iframe';
			default:
				return 'div';
		}
	}

	/**
	 * Process element by type (called from Content Parser)
	 */
	public function process_element_by_type( $element, $post_id ) {
		$element_name = $element['name'];

		switch ( $element_name ) {
			case 'section':
				return $this->process_section_element( $element, $post_id );

			case 'container':
				return $this->process_container_element( $element, $post_id );

			case 'block':
				return $this->process_div_element( $element, $post_id );

			case 'div':
				return $this->process_div_element( $element, $post_id );

			case 'heading':
				return $this->process_heading_element( $element, $post_id );

			case 'text':
				return $this->process_text_element( $element, $post_id );

			case 'image':
				return $this->process_image_element( $element, $post_id );

			case 'button':
				return $this->process_button_element( $element, $post_id );

			case 'video':
			case 'video-iframe':
				return $this->process_video_element( $element, $post_id );

			case 'iframe':
				return $this->process_iframe_element( $element, $post_id );

			default:
				return $this->process_generic_element( $element, $post_id );
		}
	}

	/**
	 * Process section element
	 */
	private function process_section_element( $element, $post_id ) {
		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Get CSS classes from style IDs
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		$element['etch_type'] = 'section';
		$element['etch_data'] = array(
			'data-etch-element' => 'section',
			'class'             => $css_classes,
		);

		return $element;
	}

	/**
	 * Process container element
	 */
	private function process_container_element( $element, $post_id ) {
		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Get CSS classes from style IDs
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		// Check if container has a custom tag (e.g., ul, ol)
		$tag = $element['settings']['tag'] ?? 'div';

		$this->error_handler->log_info( 'Gutenberg Generator: Container element ' . $element['id'] . ' using tag ' . $tag . ' with settings ' . wp_json_encode( $element['settings'] ) );

		$element['etch_type'] = 'container';
		$element['etch_data'] = array(
			'data-etch-element' => 'container',
			'class'             => $css_classes,
			'tag'               => $tag,  // Preserve custom tag
		);

		return $element;
	}

	/**
	 * Process div element
	 */
	private function process_div_element( $element, $post_id ) {
		// Check if this is actually a semantic HTML element
		$tag = $element['settings']['tag'] ?? 'div';

		// Preserve semantic HTML tags
		$semantic_tags = array(
			'ul',
			'ol',
			'li',           // Lists
			'span',                      // Inline
			'figure',
			'figcaption',     // Figure
			'article',
			'aside',
			'nav',  // Semantic sections
			'header',
			'footer',
			'main', // Page structure
			'p',
			'blockquote',          // Text
			'a',                         // Links
		);

		if ( in_array( $tag, $semantic_tags, true ) ) {
			// Keep semantic HTML element
			$element['etch_type'] = $tag;
			$element['etch_data'] = array(
				'tag'   => $tag,
				'class' => $this->extract_css_classes( $element['settings'] ),
			);
			return $element;
		}

		// Regular div handling (flex-div is deprecated in Etch).
		$element['etch_type'] = 'div';
		$element['etch_data'] = array(
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process heading element
	 */
	private function process_heading_element( $element, $post_id ) {
		$element['etch_type'] = 'heading';
		$element['etch_data'] = array(
			'level' => $element['settings']['tag'] ?? 'h3',
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process text element
	 */
	private function process_text_element( $element, $post_id ) {
		$element['etch_type'] = 'paragraph';
		$element['etch_data'] = array(
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process image element
	 */
	private function process_image_element( $element, $post_id ) {
		$element['etch_type'] = 'image';
		$element['etch_data'] = array(
			'src'   => $element['settings']['image']['url'] ?? '',
			'alt'   => $element['settings']['image']['alt'] ?? '',
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process button element
	 */
	private function process_button_element( $element, $post_id ) {
		$element['etch_type'] = 'button';
		$element['etch_data'] = array(
			'href'   => $element['settings']['link']['url'] ?? '#',
			'target' => $element['settings']['link']['target'] ?? '_self',
			'class'  => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process video element
	 */
	private function process_video_element( $element, $post_id ) {
		$element['etch_type'] = 'video-iframe';
		$element['etch_data'] = array(
			'src'   => $element['settings']['video']['url'] ?? '',
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process iframe element
	 */
	private function process_iframe_element( $element, $post_id ) {
		$element['etch_type'] = 'iframe';
		$element['etch_data'] = array(
			'data-etch-element' => 'iframe',
			'src'               => $element['settings']['iframe']['url'] ?? '',
			'class'             => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Process generic element
	 */
	private function process_generic_element( $element, $post_id ) {
		$element['etch_type'] = 'generic';
		$element['etch_data'] = array(
			'class' => $this->extract_css_classes( $element['settings'] ),
		);

		return $element;
	}

	/**
	 * Extract CSS classes from element settings
	 */
	private function extract_css_classes( $settings ) {
		$classes = array();

		if ( ! empty( $settings['_cssClasses'] ) ) {
			$classes[] = $settings['_cssClasses'];
		}

		if ( ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ) {
			$classes = array_merge( $classes, $settings['_cssGlobalClasses'] );
		}

		return implode( ' ', array_filter( $classes ) );
	}
}
