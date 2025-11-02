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

		$html_parts = array();

		// Build element map for hierarchy
		$element_map = array();
		foreach ( $bricks_elements as $element ) {
			if ( isset( $element['id'] ) ) {
				$element_map[ $element['id'] ] = $element;
			}
		}

		// Find root elements (parent = 0 or '0')
		$root_elements = array();
		foreach ( $bricks_elements as $element ) {
			$parent = $element['parent'] ?? '0';
			if ( 0 === $parent || '0' === $parent || '' === $parent ) {
				$root_elements[] = $element;
			}
		}

		// Generate HTML for each top-level element
		$timestamp = wp_date( 'Y-m-d H:i:s' ); // Get current timestamp using site settings
		$html      = $timestamp . "\n"; // Add timestamp at the beginning
		foreach ( $root_elements as $element ) {
			$block_html = $this->convert_single_bricks_element( $element, $element_map );
			if ( ! empty( $block_html ) ) {
				$html_parts[] = $block_html;
			}
		}

		return implode( "\n\n", $html_parts );
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
				// brxe-block becomes flex-div
				return $this->convert_etch_flex_div( $element, $children, $element_map );

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
	 * Convert Bricks Section to Etch Section (wp:group with proper etchData)
	 */
	private function convert_etch_section( $element, $children, $element_map ) {
		$classes  = $this->get_element_classes( $element );
		$settings = $element['settings'] ?? array();
		$label    = $element['label'] ?? '';

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

		// Get CSS classes from style IDs for etchData.attributes.class
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		// Build Etch-compatible attributes
		$etch_attributes = array(
			'data-etch-element' => 'section',
		);

		// Add CSS classes if available
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		// Build attributes JSON with FULL Etch structure
		$section_name = ! empty( $label ) ? $label : 'Section';

		$attrs = array(
			'metadata' => array(
				'name'     => $section_name,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $section_name,
					'styles'     => $style_ids,
					'attributes' => $etch_attributes,
					'block'      => array(
						'type' => 'html',
						'tag'  => 'section',
					),
				),
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Build class attribute for HTML (Etch uses <div> in HTML, renders based on block.tag)
		$class_attr = ! empty( $classes ) ? ' class="wp-block-group ' . esc_attr( implode( ' ', $classes ) ) . '"' : ' class="wp-block-group"';

		// Etch Section = wp:group with <div> (rendered as <section> by Etch)
		return '<!-- wp:group ' . $attrs_json . ' -->' . "\n" .
				'<div' . $class_attr . '>' . "\n" .
				$children_html .
				'</div>' . "\n" .
				'<!-- /wp:group -->';
	}

	/**
	 * Convert Bricks Container to Etch Container (wp:group with proper etchData)
	 */
	private function convert_etch_container( $element, $children, $element_map ) {
		$classes = $this->get_element_classes( $element );
		$label   = $element['label'] ?? '';

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

		// Get CSS classes from style IDs for etchData.attributes.class
		$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

		// Get custom tag from element (e.g., ul, ol)
		$tag = $element['etch_data']['tag'] ?? 'div';

		$this->error_handler->log_info( 'Gutenberg Generator: convert_etch_container element ID ' . $element['id'] . ' using tag ' . $tag );

		// Build Etch-compatible attributes
		$etch_attributes = array(
			'data-etch-element' => 'container',
		);

		// Add CSS classes if available
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		// Build attributes JSON with FULL Etch structure
		$container_name = ! empty( $label ) ? $label : 'Container';

		$attrs = array(
			'metadata' => array(
				'name'     => $container_name,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $container_name,
					'styles'     => $style_ids,
					'attributes' => $etch_attributes,
					'block'      => array(
						'type' => 'html',
						'tag'  => $tag,  // Use custom tag from Bricks
					),
				),
			),
		);

		// Add tagName for Gutenberg if not div
		if ( 'div' !== $tag ) {
			$attrs['tagName'] = $tag;
		}

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Build class attribute for HTML
		$class_attr = ! empty( $classes ) ? ' class="wp-block-group ' . esc_attr( implode( ' ', $classes ) ) . '"' : ' class="wp-block-group"';

		// Etch Container = wp:group
		return '<!-- wp:group ' . $attrs_json . ' -->' . "\n" .
				'<div' . $class_attr . '>' . "\n" .
				$children_html .
				'</div>' . "\n" .
				'<!-- /wp:group -->';
	}

	/**
	 * Convert Bricks Block (brxe-block) to Etch Flex-Div (wp:group with proper etchData)
	 */
	private function convert_etch_flex_div( $element, $children, $element_map ) {
		$classes = $this->get_element_classes( $element );
		$label   = $element['label'] ?? '';

		// Convert children
		$children_html = '';
		foreach ( $children as $child ) {
			$child_html = $this->convert_single_bricks_element( $child, $element_map );
			if ( ! empty( $child_html ) ) {
				$children_html .= $child_html . "\n";
			}
		}

		// Build Etch-compatible attributes for flex-div
		$etch_attributes = array(
			'data-etch-element' => 'flex-div',
		);

		// NOTE: Do NOT add class names to etchData.attributes!
		// Only use etchData.styles with style IDs

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Add default flex-div style
		array_unshift( $style_ids, 'etch-flex-div-style' );

		// Build attributes JSON with FULL Etch structure
		$flex_name = ! empty( $label ) ? $label : 'Flex Div';

		$attrs = array(
			'metadata' => array(
				'name'     => $flex_name,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $flex_name,
					'styles'     => $style_ids,
					'attributes' => $etch_attributes,
					'block'      => array(
						'type' => 'html',
						'tag'  => 'div',
					),
				),
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Build class attribute for HTML
		$class_attr = ! empty( $classes ) ? ' class="wp-block-group ' . esc_attr( implode( ' ', $classes ) ) . '"' : ' class="wp-block-group"';

		// Etch Flex-Div = wp:group with flex-div etchData
		return '<!-- wp:group ' . $attrs_json . ' -->' . "\n" .
				'<div' . $class_attr . '>' . "\n" .
				$children_html .
				'</div>' . "\n" .
				'<!-- /wp:group -->';
	}

	/**
	 * Convert Bricks Div to simple HTML div (NOT flex-div)
	 */
	private function convert_etch_simple_div( $element, $children, $element_map ) {
		$classes = $this->get_element_classes( $element );
		$label   = $element['label'] ?? '';

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

		// Build attributes JSON - simple div WITHOUT flex-div
		$div_name = ! empty( $label ) ? $label : 'Div';

		$attrs = array(
			'metadata' => array(
				'name'     => $div_name,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $div_name,
					'styles'     => $style_ids,
					'attributes' => array(), // No special attributes for simple div
					'block'      => array(
						'type' => 'html',
						'tag'  => 'div',
					),
				),
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Build class attribute for HTML
		$class_attr = ! empty( $classes ) ? ' class="wp-block-group ' . esc_attr( implode( ' ', $classes ) ) . '"' : ' class="wp-block-group"';

		// Simple Div = wp:group WITHOUT flex-div
		return '<!-- wp:group ' . $attrs_json . ' -->' . "\n" .
				'<div' . $class_attr . '>' . "\n" .
				$children_html .
				'</div>' . "\n" .
				'<!-- /wp:group -->';
	}

	/**
	 * Convert Bricks Text to standard paragraph with Etch metadata
	 */
	private function convert_etch_text( $element ) {
		$text  = $element['settings']['text'] ?? '';
		$label = $element['label'] ?? '';

		if ( empty( $text ) ) {
			return '';
		}

		$classes    = $this->get_element_classes( $element );
		$class_attr = ! empty( $classes ) ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Build attributes with Etch metadata
		$attrs = array();

		// Add etchData with styles
		$text_name = ! empty( $label ) ? $label : 'Text';

		if ( ! empty( $style_ids ) || ! empty( $classes ) ) {
			$etch_data = array(
				'origin'     => 'etch',
				'name'       => $text_name,
				'styles'     => $style_ids,
				'attributes' => array(),
				'block'      => array(
					'type' => 'html',
					'tag'  => 'p',
				),
			);

			$attrs['metadata'] = array(
				'name'     => $text_name,
				'etchData' => $etch_data,
			);
		}

		$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';

		// Use standard wp:paragraph
		return '<!-- wp:paragraph' . $attrs_json . ' -->' . "\n" .
				'<p' . $class_attr . '>' . $text . '</p>' . "\n" .
				'<!-- /wp:paragraph -->';
	}

	/**
	 * Convert Bricks Heading to standard heading with Etch metadata
	 */
	private function convert_etch_heading( $element ) {
		$text  = $element['settings']['text'] ?? '';
		$tag   = $element['settings']['tag'] ?? 'h2';
		$label = $element['label'] ?? '';

		if ( empty( $text ) ) {
			return '';
		}

		$classes      = $this->get_element_classes( $element );
		$base_classes = array( 'wp-block-heading' );
		if ( ! empty( $classes ) ) {
			$base_classes = array_merge( $base_classes, $classes );
		}
		$class_attr = ' class="' . esc_attr( implode( ' ', $base_classes ) ) . '"';

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Build attributes with Etch metadata
		$attrs = array();

		// Add etchData with styles
		$heading_name = ! empty( $label ) ? $label : 'Heading';

		if ( ! empty( $style_ids ) || ! empty( $classes ) ) {
			$etch_data = array(
				'origin'     => 'etch',
				'name'       => $heading_name,
				'styles'     => $style_ids,
				'attributes' => array(),
				'block'      => array(
					'type' => 'html',
					'tag'  => $tag,
				),
			);

			$attrs['metadata'] = array(
				'name'     => $heading_name,
				'etchData' => $etch_data,
			);
		}

		// Add level attribute for heading
		$level = (int) str_replace( 'h', '', $tag );
		if ( $level >= 1 && $level <= 6 ) {
			$attrs['level'] = $level;
		}

		$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';

		// Use standard wp:heading
		return '<!-- wp:heading' . $attrs_json . ' -->' . "\n" .
				'<' . $tag . $class_attr . '>' . $text . '</' . $tag . '>' . "\n" .
				'<!-- /wp:heading -->';
	}

	/**
	 * Convert Bricks Image to standard image
	 */
	private function convert_etch_image( $element ) {
		$image_id  = $element['settings']['image']['id'] ?? '';
		$image_url = $element['settings']['image']['url'] ?? '';
		$label     = $element['label'] ?? '';

		if ( empty( $image_url ) ) {
			return '';
		}

		$classes = $this->get_element_classes( $element );

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		// Build attributes with Etch nestedData structure for img classes
		$image_name = ! empty( $label ) ? $label : 'Image';

		$attrs = array(
			'metadata' => array(
				'name'     => $image_name,
				'etchData' => array(
					'removeWrapper' => true,
					'block'         => array(
						'type' => 'html',
						'tag'  => 'figure',
					),
					'origin'        => 'etch',
					'name'          => $image_name,
					'nestedData'    => array(
						'img' => array(
							'origin'     => 'etch',
							'name'       => $image_name,
							'styles'     => $style_ids,
							'attributes' => array(
								'src'   => $image_url,
								'class' => ! empty( $classes ) ? implode( ' ', $classes ) : '',
							),
							'block'      => array(
								'type' => 'html',
								'tag'  => 'img',
							),
						),
					),
				),
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Use standard wp:image with Etch nestedData
		return '<!-- wp:image ' . $attrs_json . ' -->' . "\n" .
				'<figure class="wp-block-image"><img src="' . esc_url( $image_url ) . '" alt=""/></figure>' . "\n" .
				'<!-- /wp:image -->';
	}

	/**
	 * Convert Bricks Icon to Etch SVG or Icon element
	 */
	private function convert_etch_icon( $element ) {
		$icon_library = $element['settings']['icon']['library'] ?? '';
		$icon_value   = $element['settings']['icon']['icon'] ?? '';
		$svg_code     = $element['settings']['icon']['svg'] ?? '';
		$label        = $element['label'] ?? '';

		if ( empty( $icon_value ) && empty( $svg_code ) ) {
			return '';
		}

		$classes   = $this->get_element_classes( $element );
		$style_ids = $this->get_element_style_ids( $element );

		// Check if it's an SVG or FontAwesome icon
		if ( ! empty( $svg_code ) || strpos( $icon_library, 'svg' ) !== false ) {
			// SVG Icon - use Etch SVG element
			$svg_name = '';
			if ( ! empty( $label ) ) {
				$svg_name = $label;
			} else {
				$svg_name = 'SVG';
			}

			$attrs = array(
				'metadata' => array(
					'name'     => $svg_name,
					'etchData' => array(
						'origin'     => 'etch',
						'name'       => $svg_name,
						'styles'     => $style_ids,
						'attributes' => array(
							'src'         => ! empty( $svg_code ) ? $svg_code : '', // SVG code or URL
							'stripColors' => 'true',
						),
						'block'      => array(
							'type'        => 'html',
							'tag'         => 'svg',
							'specialized' => 'svg',
						),
					),
				),
			);

			// NOTE: Do NOT set className! Only use etchData.styles

			$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return '<!-- wp:group ' . $attrs_json . ' -->' . "\n" .
					'<div class="wp-block-group"></div>' . "\n" .
					'<!-- /wp:group -->';
		} else {
			// FontAwesome or other icon font - use HTML block with <i>
			$icon_name = '';
			if ( ! empty( $label ) ) {
				$icon_name = $label;
			} else {
				$icon_name = 'Icon';
			}

			$attrs = array(
				'metadata' => array(
					'name'     => $icon_name,
					'etchData' => array(
						'origin'     => 'etch',
						'name'       => $icon_name,
						'styles'     => $style_ids,
						'attributes' => array(
							'class' => $icon_value,
						),
						'block'      => array(
							'type' => 'html',
							'tag'  => 'i',
						),
					),
				),
			);

			// NOTE: Do NOT set className! Only use etchData.styles

			$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			$class_attr = ! empty( $classes ) ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '';

			return '<!-- wp:html ' . $attrs_json . ' -->' . "\n" .
					'<i' . $class_attr . ' class="' . esc_attr( $icon_value ) . '"></i>' . "\n" .
					'<!-- /wp:html -->';
		}
	}

	/**
	 * Convert Bricks Button to Etch Link (Anchor)
	 */
	private function convert_etch_button( $element ) {
		$text  = $element['settings']['text'] ?? 'Button';
		$link  = $element['settings']['link'] ?? '#';
		$label = $element['label'] ?? '';

		// Handle Bricks link format (can be array or string)
		if ( is_array( $link ) ) {
			$url    = $link['url'] ?? '#';
			$target = $link['newTab'] ?? false;
		} else {
			$url    = $link;
			$target = false;
		}

		$classes   = $this->get_element_classes( $element );
		$style_ids = $this->get_element_style_ids( $element );

		// Generate unique ref ID for the link
		$ref_id = substr( md5( $element['id'] ?? uniqid() ), 0, 7 );

		// Build Etch Link with nestedData (like Anchor element)
		$link_attrs = array(
			'href' => $url,
		);

		if ( $target ) {
			$link_attrs['target'] = '_blank';
			$link_attrs['rel']    = 'noopener';
		}

		if ( ! empty( $classes ) ) {
			$link_attrs['class'] = implode( ' ', $classes );
		}

		$anchor_name = '';
		if ( ! empty( $label ) ) {
			$anchor_name = $label;
		} else {
			$anchor_name = 'Anchor';
		}

		$attrs = array(
			'metadata' => array(
				'name'     => $anchor_name,
				'etchData' => array(
					'removeWrapper' => true,
					'block'         => array(
						'type' => 'html',
						'tag'  => 'p',
					),
					'origin'        => 'etch',
					'name'          => $anchor_name,
					'nestedData'    => array(
						$ref_id => array(
							'origin'     => 'etch',
							'name'       => $anchor_name,
							'styles'     => $style_ids,
							'attributes' => $link_attrs,
							'block'      => array(
								'type' => 'html',
								'tag'  => 'a',
							),
						),
					),
				),
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Use wp:paragraph with nested anchor (Etch Link structure)
		return '<!-- wp:paragraph ' . $attrs_json . ' -->' . "\n" .
				'<p><a data-etch-ref="' . $ref_id . '">' . esc_html( $text ) . '</a></p>' . "\n" .
				'<!-- /wp:paragraph -->';
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
			$style_map = get_option( 'b2e_style_map', array() );

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
			$style_map = get_option( 'b2e_style_map', array() );
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
	 * Convert style IDs to CSS class names for etchData.attributes.class
	 * This is the REAL fix! Etch renders classes from attributes.class, not from style IDs!
	 *
	 * Uses b2e_style_map which now contains both IDs and selectors
	 */
	private function get_css_classes_from_style_ids( $style_ids ) {
		if ( empty( $style_ids ) ) {
			$this->error_handler->log_info( 'Gutenberg Generator: No style IDs provided' );
			return '';
		}

		// Get style map which now contains selectors
		$style_map = get_option( 'b2e_style_map', array() );
		$this->error_handler->log_info( 'Gutenberg Generator: Style map has ' . count( $style_map ) . ' entries' );
		$this->error_handler->log_info( 'Gutenberg Generator: Looking for style IDs: ' . implode( ', ', $style_ids ) );

		$class_names = array();

		foreach ( $style_ids as $style_id ) {
			// Skip Etch internal styles (they don't have selectors in our map)
			if ( in_array( $style_id, array( 'etch-section-style', 'etch-container-style', 'etch-block-style' ), true ) ) {
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
		$style_map             = get_option( 'b2e_style_map', array() );
		$this->element_factory = new EFS_Element_Factory( $style_map );
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

			// Use factory to convert element
			$result = $this->element_factory->convert_element( $element, $children );

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
	 * Generate Etch group block (wp:group with etchData)
	 */
	private function generate_etch_group_block( $element, $element_map = array() ) {
		$etch_data = $element['etch_data'] ?? array();
		$content   = $element['content'] ?? '';

		// Convert dynamic data in content
		$content = $this->dynamic_data_converter->convert_content( $content );

		// Get style IDs using style map
		$style_ids = $this->get_element_style_ids( $element );

		// Use custom label if available, otherwise use element type
		$element_name = ! empty( $element['label'] ) ? $element['label'] : ucfirst( $element['etch_type'] );

		// IMPORTANT: Keep 'class' in etchData.attributes!
		// Etch renders CSS classes from attributes.class, not from style IDs
		$etch_data_attributes = $etch_data;

		// Build etchData
		$etch_data_array = array(
			'origin'     => 'etch',
			'name'       => $element_name,
			'styles'     => $style_ids,
			'attributes' => $etch_data_attributes,
			'block'      => array(
				'type' => 'html',
				'tag'  => $this->get_html_tag( $element['etch_type'] ),
			),
		);

		// Generate Gutenberg block
		$block_content = $this->generate_block_content( $element, $content, $element_map );

		// Build block attributes
		$block_attrs = array(
			'metadata' => array(
				'name'     => $etch_data_array['name'],
				'etchData' => $etch_data_array,
			),
		);

		// NOTE: Do NOT set className! Only use etchData.styles

		// Add tagName for non-div elements (section, article, etc.)
		$html_tag = $this->get_html_tag( $element['etch_type'] );
		if ( 'div' !== $html_tag ) {
			$block_attrs['tagName'] = $html_tag;
		}

		$gutenberg_html = sprintf(
			'<!-- wp:group %s -->',
			wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		// Add classes to the HTML element (including Etch style IDs)
		$classes = array( 'wp-block-group' );
		if ( ! empty( $style_ids ) ) {
			$classes = array_merge( $classes, $style_ids );
		}
		$class_attr = ' class="' . esc_attr( implode( ' ', $classes ) ) . '"';

		$gutenberg_html .= "\n<div" . $class_attr . '>';
		$gutenberg_html .= "\n" . $block_content;
		$gutenberg_html .= "\n</div>";
		$gutenberg_html .= "\n<!-- /wp:group -->";

		return $gutenberg_html;
	}

	/**
	 * Generate standard Gutenberg block
	 */
	private function generate_standard_block( $element ) {
		$etch_type = $element['etch_type'];
		$etch_data = $element['etch_data'] ?? array();

		// Get content from etch_data or element
		$content = $etch_data['content'] ?? $element['content'] ?? '';

		// Convert dynamic data in content
		$content = $this->dynamic_data_converter->convert_content( $content );

		// Get style IDs for this element
		$style_ids = $this->get_element_style_ids( $element );

		switch ( $etch_type ) {
			case 'heading':
				$level = $etch_data['level'] ?? 'h2';

				// Build class attribute for HTML (wp-block-heading only)
				$class_attr = ' class="wp-block-heading"';

				// Get element label for Etch metadata
				$element_label = ! empty( $element['label'] ) ? $element['label'] : 'Heading';

				// Get CSS classes from style IDs for etchData.attributes.class
				$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

				// Build block attributes with CSS classes in attributes.class
				$block_attrs = array(
					'level'    => intval( str_replace( 'h', '', $level ) ),
					'metadata' => array(
						'name'     => $element_label,
						'etchData' => array(
							'origin'     => 'etch',
							'name'       => $element_label,
							'styles'     => $style_ids,
							'attributes' => ! empty( $css_classes ) ? array( 'class' => $css_classes ) : array(),
							'block'      => array(
								'type' => 'html',
								'tag'  => $level,
							),
						),
					),
				);

				return sprintf(
					'<!-- wp:heading %s -->',
					wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				) . "\n" .
				'<' . $level . $class_attr . '>' . $content . '</' . $level . '>' . "\n" .
				'<!-- /wp:heading -->';

			case 'paragraph':
				// No class attribute for paragraphs
				$class_attr = '';

				// Get element label for Etch metadata
				$element_label = ! empty( $element['label'] ) ? $element['label'] : 'Paragraph';

				// Get CSS classes from style IDs for etchData.attributes.class
				$css_classes = $this->get_css_classes_from_style_ids( $style_ids );

				// Build block attributes with CSS classes in attributes.class
				$block_attrs = array(
					'metadata' => array(
						'name'     => $element_label,
						'etchData' => array(
							'origin'     => 'etch',
							'name'       => $element_label,
							'styles'     => $style_ids,
							'attributes' => ! empty( $css_classes ) ? array( 'class' => $css_classes ) : array(),
							'block'      => array(
								'type' => 'html',
								'tag'  => 'p',
							),
						),
					),
				);

				$attrs_json = ! empty( $block_attrs ) ? ' ' . wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';

				return '<!-- wp:paragraph' . $attrs_json . ' -->' . "\n" .
						'<p' . $class_attr . '>' . $content . '</p>' . "\n" .
						'<!-- /wp:paragraph -->';

			case 'image':
				$src = $etch_data['src'] ?? '';
				$alt = $etch_data['alt'] ?? '';

				if ( empty( $src ) ) {
					return ''; // Skip images without source
				}

				// Get style IDs for this element (not from etch_data!)
				$img_style_ids = $this->get_element_style_ids( $element );

				// Get CSS classes from style IDs
				$img_css_classes = $this->get_css_classes_from_style_ids( $img_style_ids );
				$img_class_attr  = ! empty( $img_css_classes ) ? ' class="wp-block-image ' . esc_attr( $img_css_classes ) . '"' : ' class="wp-block-image"';

				// Get element label for Etch metadata (not HTML caption!)
				$element_label = ! empty( $element['label'] ) ? $element['label'] : 'Image';

				// Build block attributes WITH class in etchData.attributes
				// Etch needs the class in attributes to render it on the figure
				$block_attrs = array(
					'metadata' => array(
						'name'     => $element_label,
						'etchData' => array(
							'origin'     => 'etch',
							'name'       => $element_label,
							'styles'     => $img_style_ids,
							'attributes' => ! empty( $img_css_classes ) ? array( 'class' => $img_css_classes ) : array(),
							'block'      => array(
								'type' => 'html',
								'tag'  => 'figure',
							),
						),
					),
				);

				// Gutenberg expects inline HTML for images (no line breaks)
				// Apply CSS classes to <figure>, not <img>
				return sprintf(
					'<!-- wp:image %s -->',
					wp_json_encode( $block_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				) . "\n" .
				'<figure' . $img_class_attr . '><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>' . "\n" .
				'<!-- /wp:image -->';

			case 'button':
				$href   = $etch_data['href'] ?? '#';
				$target = $etch_data['target'] ?? '_self';
				$class  = ! empty( $etch_data['class'] ) ? ' class="' . esc_attr( $etch_data['class'] ) . '"' : '';

				return '<!-- wp:buttons -->' . "\n" .
						'<div class="wp-block-buttons">' . "\n" .
						'<!-- wp:button -->' . "\n" .
						'<div class="wp-block-button' . $class . '">' . "\n" .
						'<a class="wp-block-button__link" href="' . esc_url( $href ) . '" target="' . esc_attr( $target ) . '">' . $content . '</a>' . "\n" .
						'</div>' . "\n" .
						'<!-- /wp:button -->' . "\n" .
						'</div>' . "\n" .
						'<!-- /wp:buttons -->';

			default:
				return $this->generate_generic_block( $element );
		}
	}

	/**
	 * Generate generic block (fallback)
	 */
	private function generate_generic_block( $element ) {
		$content   = $element['content'] ?? '';
		$etch_data = $element['etch_data'] ?? array();

		// Convert dynamic data in content
		$content = $this->dynamic_data_converter->convert_content( $content );

		$class = ! empty( $etch_data['class'] ) ? ' class="' . esc_attr( $etch_data['class'] ) . '"' : '';

		return '<!-- wp:html -->' . "\n" .
				'<div' . $class . '>' . $content . '</div>' . "\n" .
				'<!-- /wp:html -->';
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

		// Regular div handling
		$settings            = get_option( 'efs_settings', array() );
		$convert_div_to_flex = $settings['convert_div_to_flex'] ?? true;

		if ( $convert_div_to_flex ) {
			$element['etch_type'] = 'flex-div';
			$element['etch_data'] = array(
				'data-etch-element' => 'flex-div',
				'class'             => $this->extract_css_classes( $element['settings'] ),
			);
		} else {
			$element['etch_type'] = 'skip';
		}

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
