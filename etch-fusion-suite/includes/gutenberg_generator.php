<?php
/**
 * Gutenberg Generator for Bricks to Etch Migration Plugin
 *
 * Generates Gutenberg blocks from Bricks content
 */

namespace Bricks2Etch\Parsers;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Converters\EFS_Element_Factory;
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
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		// Build Etch-compatible attributes (flat schema)
		$etch_attributes = array(
			'data-etch-element' => 'section',
		);

		// Add CSS classes if available
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$section_name = ! empty( $label ) ? $label : 'Section';

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
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		// Get custom tag from element (e.g., ul, ol)
		$tag = $element['etch_data']['tag'] ?? ( $element['settings']['tag'] ?? 'div' );

		$this->error_handler->log_info( 'Gutenberg Generator: convert_etch_container element ID ' . $element['id'] . ' using tag ' . $tag );

		// Build Etch-compatible attributes (flat schema)
		$etch_attributes = array(
			'data-etch-element' => 'container',
		);

		// Add CSS classes if available
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$container_name = ! empty( $label ) ? $label : 'Container';

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
		$style_ids   = $this->get_element_style_ids( $element );
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );
		$tag         = $element['etch_data']['tag'] ?? ( $element['settings']['tag'] ?? 'div' );

		$attributes = array();
		if ( ! empty( $css_classes ) ) {
			$attributes['class'] = $css_classes;
		}

		// Build flat attrs for a simple div/semantic wrapper (without flex-div markers).
		$div_name = ! empty( $label ) ? $label : 'Div';

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
	 * Convert unsupported structural elements via simple Etch element wrapper.
	 */
	private function convert_etch_div( $element, $children, $element_map ) {
		return $this->convert_etch_simple_div( $element, $children, $element_map );
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

		$tag = strtolower( (string) ( $element['settings']['tag'] ?? 'h2' ) );
		if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
			$tag = 'h2';
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
		$label        = ! empty( $element['label'] ) ? (string) $element['label'] : 'Icon';
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

		$fallback_element              = $element;
		$fallback_element['etch_type'] = 'icon';
		$fallback_element['etch_data'] = array(
			'class' => $classes,
		);
		$attrs                         = $this->build_flat_fallback_attrs(
			$fallback_element,
			'i',
			array(
				'class' => (string) $icon_value,
			)
		);

		return $this->serialize_etch_element_block( $attrs );
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

		return $this->serialize_etch_element_block( $attrs, esc_html( wp_strip_all_tags( (string) $text ) ) );
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

		// Add timestamp comment to verify new content is generated
		$generation_timestamp = wp_date( 'Y-m-d H:i:s' );
		$timestamp            = '<!-- B2E Generated: ' . $generation_timestamp . ' | v0.5.0: Modular Element Converters -->';
		$this->error_handler->log_info( 'Gutenberg Generator: Generating blocks at ' . $generation_timestamp . ' with modular converters' );

		// Initialize element factory with style map (NEW - v0.5.0)
		$style_map             = get_option( 'efs_style_map', array() );
		$this->element_factory = new EFS_Element_Factory( $style_map, $this->dynamic_data_converter );
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
		$gutenberg_blocks   = array();
		$gutenberg_blocks[] = $timestamp; // Add timestamp at the beginning
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
			$this->error_handler->log_error(
				'I020',
				array(
					'post_id'      => $post->ID,
					'action'       => 'No Bricks content found for conversion',
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
			$this->error_handler->log_error(
				'I022',
				array(
					'post_id' => $post->ID,
					'action'  => 'Failed to generate Gutenberg blocks',
				)
			);
			return false;
		}

		// Append inline JavaScript from code blocks (if any)
		$inline_js = get_option( 'b2e_inline_js_' . $post->ID, '' );
		if ( ! empty( $inline_js ) ) {
			$gutenberg_html .= "\n\n<!-- wp:html -->\n<script>\n" . trim( $inline_js ) . "\n</script>\n<!-- /wp:html -->";
			delete_option( 'b2e_inline_js_' . $post->ID );
		}

		// Convert HTML to blocks array
		$gutenberg_blocks = parse_blocks( $gutenberg_html );

		// Get target site URL from settings
		$settings   = get_option( 'efs_settings', array() );
		$target_url = $settings['target_url'] ?? '';

		if ( empty( $target_url ) ) {
			$this->error_handler->log_error(
				'I024',
				array(
					'post_id' => $post->ID,
					'error'   => 'No target URL configured',
					'action'  => 'Failed to send blocks to Etch API',
				)
			);
			return false;
		}

		// Get API key from settings (stored after token validation)
		$api_key = $settings['api_key'] ?? '';

		if ( empty( $api_key ) ) {
			$this->error_handler->log_error(
				'I024',
				array(
					'post_id' => $post->ID,
					'error'   => 'No API key found in settings',
					'action'  => 'Failed to send blocks to Etch API',
				)
			);
			return false;
		}

		// Send blocks to Etch via HTTP (using new b2e/v1 endpoint with API key)
		$endpoint_url = rtrim( $target_url, '/' ) . '/wp-json/b2e/v1/import/post';

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
		// Skip hidden elements — produces no output in either path.
		if ( ! empty( $element['settings']['_hidden'] ) && true === $element['settings']['_hidden'] ) {
			return '';
		}

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
				return $result;
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
				$tag = strtolower( (string) ( $etch_data['level'] ?? 'h2' ) );
				if ( ! preg_match( '/^h[1-6]$/', $tag ) ) {
					$tag = 'h2';
				}
				$attrs = $this->build_flat_fallback_attrs( $element, $tag );
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

				$attrs = $this->build_flat_fallback_attrs( $element, 'a', $link_attributes );
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
				'name' => ! empty( $element['label'] ) ? (string) $element['label'] : 'HTML',
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

		$element_name = 'Element';
		if ( ! empty( $element['label'] ) ) {
			$element_name = (string) $element['label'];
		} elseif ( '' !== $resolved_etch_type ) {
			$element_name = ucfirst( $resolved_etch_type );
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

				$classes[] = $class_name;
			}
		}

		if ( empty( $classes ) ) {
			return '';
		}

		return implode( ' ', array_values( array_unique( $classes ) ) );
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
			'level' => $element['settings']['tag'] ?? 'h2',
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
