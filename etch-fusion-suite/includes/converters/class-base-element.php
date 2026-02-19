<?php
/**
 * Base Element Converter
 *
 * Abstract base class for all element converters
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class EFS_Base_Element {

	/**
	 * Element type (e.g., 'container', 'section', 'heading')
	 */
	protected $element_type;

	/**
	 * Style map for CSS classes
	 */
	protected $style_map;

	/**
	 * Pending inline CSS assembled from ACSS utility classes for current element.
	 *
	 * @var string
	 */
	protected $pending_acss_inline_css = '';

	/**
	 * Current Bricks element being converted (used for class-preservation helpers).
	 *
	 * @var array
	 */
	protected $current_element = array();

	/**
	 * Constructor
	 */
	public function __construct( $style_map = array() ) {
		$this->style_map = $style_map;
	}

	/**
	 * Convert element to Etch format
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (converted block HTML)
	 * @param array $context Optional. Conversion context (e.g. element_map, convert_callback for slotChildren).
	 * @return string Gutenberg block HTML
	 */
	abstract public function convert( $element, $children = array(), $context = array() );

	/**
	 * Get style IDs for element
	 *
	 * @param array $element Bricks element
	 * @return array Style IDs
	 */
	protected function get_style_ids( $element ) {
		$style_ids                     = array();
		$this->current_element         = is_array( $element ) ? $element : array();
		$this->pending_acss_inline_css = $this->build_acss_inline_css( $element );

		// Get Global Classes from settings
		if ( isset( $element['settings']['_cssGlobalClasses'] ) && is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
				if ( isset( $this->style_map[ $class_id ]['id'] ) ) {
					$style_ids[] = $this->style_map[ $class_id ]['id'];
				}
			}
		}

		return $style_ids;
	}

	/**
	 * Get CSS classes from style IDs
	 *
	 * @param array $style_ids Style IDs
	 * @return string CSS classes (space-separated)
	 */
	protected function get_css_classes( $style_ids ) {
		$classes = array();

		foreach ( $style_ids as $style_id ) {
			// Skip Etch-internal styles
			if ( strpos( $style_id, 'etch-' ) === 0 ) {
				continue;
			}

			// Find selector in style map
			foreach ( $this->style_map as $bricks_id => $etch_data ) {
				if ( $etch_data['id'] === $style_id && isset( $etch_data['selector'] ) ) {
					// Remove leading dot from selector
					$class = ltrim( $etch_data['selector'], '.' );
					if ( $this->is_acss_selector_name( $class ) ) {
						break;
					}
					$classes[] = $class;
					break;
				}
			}
		}

		// Preserve ACSS background utility classes on elements (e.g. bg--ultra-light).
		$classes = array_merge( $classes, $this->get_preserved_acss_background_classes() );

		$classes = array_values( array_unique( array_filter( $classes ) ) );

		return implode( ' ', $classes );
	}

	/**
	 * Collect ACSS background utility class names attached to the current element.
	 *
	 * @return array<int,string>
	 */
	protected function get_preserved_acss_background_classes() {
		$classes = array();

		$element = is_array( $this->current_element ) ? $this->current_element : array();
		if ( empty( $element['settings']['_cssGlobalClasses'] ) || ! is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			return $classes;
		}

		static $class_name_by_id = null;
		if ( null === $class_name_by_id ) {
			$class_name_by_id = array();
			$global_classes   = get_option( 'bricks_global_classes', array() );
			if ( is_string( $global_classes ) ) {
				$decoded = json_decode( $global_classes, true );
				if ( is_array( $decoded ) ) {
					$global_classes = $decoded;
				}
			}

			if ( is_array( $global_classes ) ) {
				foreach ( $global_classes as $class_data ) {
					if ( ! is_array( $class_data ) || empty( $class_data['id'] ) ) {
						continue;
					}
					$id   = trim( (string) $class_data['id'] );
					$name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
					if ( '' === $id || '' === $name ) {
						continue;
					}
					$class_name_by_id[ $id ] = ltrim( preg_replace( '/^acss_import_/', '', $name ), '.' );
				}
			}
		}

		foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
			$id = is_scalar( $class_id ) ? trim( (string) $class_id ) : '';
			if ( '' === $id || empty( $class_name_by_id[ $id ] ) ) {
				continue;
			}

			$name = (string) $class_name_by_id[ $id ];
			if ( 0 === strpos( $name, 'bg--' ) ) {
				$classes[] = $name;
			}
		}

		return $classes;
	}

	/**
	 * Get element label
	 *
	 * @param array $element Bricks element
	 * @return string Label
	 */
	protected function get_label( $element ) {
		$raw_label = $element['label'] ?? ucfirst( $this->element_type );
		return $this->sanitize_label( $raw_label, ucfirst( $this->element_type ) );
	}

	/**
	 * Get element tag
	 *
	 * @param array $element Bricks element
	 * @param string $fallback_tag Default tag
	 * @return string HTML tag
	 */
	protected function get_tag( $element, $fallback_tag = 'div' ) {
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$tag      = $settings['tag'] ?? $fallback_tag;
		$custom_tag = isset( $settings['customTag'] ) && is_string( $settings['customTag'] ) ? trim( $settings['customTag'] ) : '';

		if ( ! is_string( $tag ) || '' === trim( $tag ) ) {
			return (string) $fallback_tag;
		}

		$tag = strtolower( trim( $tag ) );
		if ( 'custom' === $tag && '' !== $custom_tag ) {
			$tag = strtolower( $custom_tag );
		}

		if ( '' === $tag || ! preg_match( '/^[a-z][a-z0-9-]*$/', $tag ) ) {
			return (string) $fallback_tag;
		}

		return $tag;
	}

	/**
	 * Build Gutenberg block attributes
	 *
	 * @param string $label Element label
	 * @param array $style_ids Style IDs
	 * @param array $etch_attributes Etch attributes
	 * @param string $tag HTML tag
	 * @return array Block attributes
	 */
	protected function build_attributes( $label, $style_ids, $etch_attributes, $tag = 'div', $element = array() ) {
		$etch_attributes   = $this->merge_pending_acss_inline_style( $etch_attributes, $element );
		$label             = $this->sanitize_label( $label, ucfirst( $this->element_type ) );
		$custom_attributes = $this->extract_custom_attributes( $element );
		if ( ! empty( $custom_attributes ) ) {
			$etch_attributes = array_merge( $custom_attributes, (array) $etch_attributes );
		}

		return array(
			'metadata'   => array(
				'name' => (string) $label,
			),
			'tag'        => (string) $tag,
			'attributes' => $this->normalize_attributes( $etch_attributes ),
			'styles'     => $this->normalize_style_ids( $style_ids ),
		);
	}

	/**
	 * Extract custom attributes from Bricks settings._attributes.
	 *
	 * @param array $element Bricks element.
	 * @return array<string, string>
	 */
	protected function extract_custom_attributes( $element ) {
		$normalized = array();

		if ( ! is_array( $element ) || empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			return $normalized;
		}

		$raw_attributes = isset( $element['settings']['_attributes'] ) && is_array( $element['settings']['_attributes'] )
			? $element['settings']['_attributes']
			: array();

		foreach ( $raw_attributes as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name = isset( $entry['name'] ) ? strtolower( trim( (string) $entry['name'] ) ) : '';
			if ( '' === $name ) {
				continue;
			}

			// Allow standard HTML attribute tokens, including aria-*/data-* and namespaced variants.
			if ( ! preg_match( '/^[a-z_:][a-z0-9:._-]*$/', $name ) ) {
				continue;
			}

			$value = isset( $entry['value'] ) && is_scalar( $entry['value'] )
				? (string) $entry['value']
				: '';

			$normalized[ $name ] = $value;
		}

		return $normalized;
	}

	/**
	 * Merge pending ACSS inline declarations into element attributes.
	 *
	 * @param array $etch_attributes Raw attributes.
	 * @return array
	 */
	protected function merge_pending_acss_inline_style( $etch_attributes, $element = array() ) {
		if ( empty( $this->pending_acss_inline_css ) ) {
			return $etch_attributes;
		}

		if ( ! is_array( $etch_attributes ) ) {
			$etch_attributes = array();
		}

		$existing = isset( $etch_attributes['style'] ) && is_scalar( $etch_attributes['style'] )
			? trim( (string) $etch_attributes['style'] )
			: '';
		$pending  = trim( (string) $this->pending_acss_inline_css );

		if ( '' !== $existing && ';' !== substr( $existing, -1 ) ) {
			$existing .= ';';
		}
		if ( '' !== $pending && ';' !== substr( $pending, -1 ) ) {
			$pending .= ';';
		}

		$target_style_id = $this->resolve_single_neighbor_style_id( $element );
		if ( '' !== $target_style_id && $this->append_declarations_to_style_id( $target_style_id, $pending ) ) {
			$this->pending_acss_inline_css = '';
			return $etch_attributes;
		}

		$etch_attributes['style']      = trim( $existing . ' ' . $pending );
		$this->pending_acss_inline_css = '';

		return $etch_attributes;
	}

	/**
	 * Resolve exactly one non-utility neighbor style ID for declaration merge.
	 *
	 * @param array $element Bricks element data.
	 * @return string
	 */
	protected function resolve_single_neighbor_style_id( $element ) {
		if ( ! is_array( $element ) || empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			return '';
		}

		$candidate_style_ids = array();
		$settings            = $element['settings'];

		if ( ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ) {
			foreach ( $settings['_cssGlobalClasses'] as $class_id ) {
				$key = is_scalar( $class_id ) ? trim( (string) $class_id ) : '';
				if ( '' === $key || empty( $this->style_map[ $key ]['id'] ) ) {
					continue;
				}

				$selector = isset( $this->style_map[ $key ]['selector'] ) ? ltrim( (string) $this->style_map[ $key ]['selector'], '.' ) : '';
				if ( '' === $selector || $this->is_utility_like_selector( $selector ) || $this->is_acss_selector_name( $selector ) ) {
					continue;
				}

				$candidate_style_ids[] = (string) $this->style_map[ $key ]['id'];
			}
		}

		if ( isset( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] ) ) {
			$tokens = array_values( array_filter( preg_split( '/\s+/', trim( $settings['_cssClasses'] ) ) ) );
			foreach ( $tokens as $token ) {
				if ( $this->is_utility_like_selector( $token ) || $this->is_acss_selector_name( $token ) ) {
					continue;
				}

				foreach ( $this->style_map as $style_data ) {
					if ( ! is_array( $style_data ) ) {
						continue;
					}
					$selector = isset( $style_data['selector'] ) ? ltrim( (string) $style_data['selector'], '.' ) : '';
					if ( $selector !== $token || empty( $style_data['id'] ) ) {
						continue;
					}
					$candidate_style_ids[] = (string) $style_data['id'];
					break;
				}
			}
		}

		$candidate_style_ids = array_values( array_unique( array_filter( $candidate_style_ids ) ) );
		return 1 === count( $candidate_style_ids ) ? $candidate_style_ids[0] : '';
	}

	/**
	 * Identify utility classes that should not become merge targets.
	 *
	 * @param string $selector Class name without dot.
	 * @return bool
	 */
	protected function is_utility_like_selector( $selector ) {
		$name = trim( (string) $selector );
		if ( '' === $name ) {
			return true;
		}

		if ( 0 === strpos( $name, 'brxe-' ) || 0 === strpos( $name, 'brx-' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Ensure Bricks block elements preserve their flex-column display.
	 *
	 * Bricks automatically adds `display: flex; flex-direction: column;` to every
	 * `block`-type element via the built-in `brxe-block` class. Since that class is
	 * not a user-defined global class it is not migrated to Etch. This method detects
	 * when no display property is already defined for the element's associated styles
	 * and appends the equivalent declarations.
	 *
	 * @param array $style_ids       Etch style IDs resolved for the element.
	 * @param array $etch_attributes Current attribute array being built.
	 * @param array $element         Source Bricks element data.
	 * @return array Updated attributes.
	 */
	protected function apply_brxe_block_display( array $style_ids, array $etch_attributes, array $element ) {
		// Check if any resolved Etch style already defines a display property.
		$etch_styles = get_option( 'etch_styles', array() );
		$etch_styles = is_array( $etch_styles ) ? $etch_styles : array();

		foreach ( $style_ids as $style_id ) {
			if ( ! is_scalar( $style_id ) || '' === $style_id ) {
				continue;
			}
			$css = isset( $etch_styles[ $style_id ]['css'] ) && is_scalar( $etch_styles[ $style_id ]['css'] )
				? (string) $etch_styles[ $style_id ]['css'] : '';
			if ( preg_match( '/\bdisplay\s*:/i', $css ) ) {
				return $etch_attributes;
			}
		}

		// Check existing inline style.
		$current_style = isset( $etch_attributes['style'] ) && is_scalar( $etch_attributes['style'] )
			? trim( (string) $etch_attributes['style'] ) : '';
		if ( preg_match( '/\bdisplay\s*:/i', $current_style ) ) {
			return $etch_attributes;
		}

		// Respect explicit display set in Bricks element settings.
		$settings       = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$bricks_display = isset( $settings['_display'] ) ? strtolower( trim( (string) $settings['_display'] ) ) : '';
		if ( in_array( $bricks_display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true ) ) {
			return $etch_attributes;
		}

		$declarations = 'display: flex; flex-direction: column;';

		// Prefer patching the primary neighbor style over using an inline declaration.
		$target_style_id = $this->resolve_single_neighbor_style_id( $element );
		if ( '' !== $target_style_id ) {
			$this->append_declarations_to_style_id( $target_style_id, $declarations );
			return $etch_attributes;
		}

		// Fall back to an inline style attribute.
		if ( '' !== $current_style && ';' !== substr( rtrim( $current_style ), -1 ) ) {
			$current_style .= ';';
		}
		$etch_attributes['style'] = trim( $current_style . ' ' . $declarations );

		return $etch_attributes;
	}

	/**
	 * Append declarations to Etch style option; returns true when merged.
	 *
	 * @param string $style_id Etch style ID.
	 * @param string $declarations CSS declaration string.
	 * @return bool
	 */
	protected function append_declarations_to_style_id( $style_id, $declarations ) {
		$style_id     = trim( (string) $style_id );
		$declarations = trim( (string) $declarations );
		if ( '' === $style_id || '' === $declarations ) {
			return false;
		}

		static $writes = array();
		$key           = $style_id . '|' . md5( $declarations );
		if ( isset( $writes[ $key ] ) ) {
			return true;
		}

		$styles = get_option( 'etch_styles', array() );
		if ( ! is_array( $styles ) || empty( $styles[ $style_id ] ) || ! is_array( $styles[ $style_id ] ) ) {
			return false;
		}

		$current_css = isset( $styles[ $style_id ]['css'] ) && is_scalar( $styles[ $style_id ]['css'] )
			? trim( (string) $styles[ $style_id ]['css'] )
			: '';

		if ( false !== stripos( $current_css, trim( $declarations, ';' ) ) ) {
			$writes[ $key ] = true;
			return true;
		}

		if ( '' !== $current_css && ';' !== substr( $current_css, -1 ) ) {
			$current_css .= ';';
		}
		$styles[ $style_id ]['css'] = trim( $current_css . ' ' . $declarations );
		update_option( 'etch_styles', $styles );
		$writes[ $key ] = true;

		return true;
	}

	/**
	 * Build inline CSS declarations for ACSS classes attached to this element.
	 *
	 * @param array $element Bricks element data.
	 * @return string
	 */
	protected function build_acss_inline_css( $element ) {
		if ( ! isset( $element['settings']['_cssGlobalClasses'] ) || ! is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			return '';
		}

		static $acss_inline_map = null;
		if ( null === $acss_inline_map ) {
			$acss_inline_map = get_option( 'efs_acss_inline_style_map', array() );
			if ( ! is_array( $acss_inline_map ) ) {
				$acss_inline_map = array();
			}
		}

		if ( empty( $acss_inline_map ) ) {
			return '';
		}

		$declarations = array();
		foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
			if ( ! is_scalar( $class_id ) ) {
				continue;
			}
			$key = trim( (string) $class_id );
			if ( '' === $key || empty( $acss_inline_map[ $key ] ) ) {
				continue;
			}
			$declarations[] = trim( (string) $acss_inline_map[ $key ] );
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		return implode( ' ', array_unique( array_filter( $declarations ) ) );
	}

	/**
	 * Determine whether a selector name belongs to ACSS utility namespace.
	 *
	 * @param string $selector_name Selector without leading dot.
	 * @return bool
	 */
	protected function is_acss_selector_name( $selector_name ) {
		$selector_name = trim( (string) $selector_name );
		if ( '' === $selector_name ) {
			return false;
		}

		static $acss_names = null;
		if ( null === $acss_names ) {
			$acss_names     = array();
			$global_classes = get_option( 'bricks_global_classes', array() );
			if ( is_string( $global_classes ) ) {
				$decoded = json_decode( $global_classes, true );
				if ( is_array( $decoded ) ) {
					$global_classes = $decoded;
				}
			}

			if ( is_array( $global_classes ) ) {
				foreach ( $global_classes as $class_data ) {
					if ( ! is_array( $class_data ) ) {
						continue;
					}
					$category = isset( $class_data['category'] ) ? strtolower( trim( (string) $class_data['category'] ) ) : '';
					if ( 'acss' !== $category ) {
						continue;
					}
					$name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
					$name = ltrim( preg_replace( '/^acss_import_/', '', $name ), '.' );
					if ( '' !== $name ) {
						$acss_names[ $name ] = true;
					}
				}
			}
		}

		return isset( $acss_names[ $selector_name ] );
	}

	/**
	 * Generate an Etch element block with comment-only serialization.
	 *
	 * @param array        $attrs Block attributes.
	 * @param string|array $children_html Optional. Inner block HTML.
	 * @return string Gutenberg block HTML.
	 */
	protected function generate_etch_element_block( $attrs, $children_html = '' ) {
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$content    = is_array( $children_html ) ? implode( "\n", $children_html ) : (string) $children_html;
		$content    = trim( $content );

		if ( '' === $content ) {
			return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
				'<!-- /wp:etch/element -->';
		}

		return '<!-- wp:etch/element ' . $attrs_json . ' -->' . "\n" .
			$content . "\n" .
			'<!-- /wp:etch/element -->';
	}

	/**
	 * Generate an etch/text inner block.
	 *
	 * @param string $content Text/HTML content for the text block.
	 * @return string Gutenberg block HTML.
	 */
	protected function generate_etch_text_block( $content ) {
		$attrs = array(
			'content' => (string) $content,
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/text ' . $attrs_json . ' /-->';
	}

	/**
	 * Normalize style IDs to a unique array of strings.
	 *
	 * @param array $style_ids Raw style IDs.
	 * @return array Normalized style IDs.
	 */
	protected function normalize_style_ids( $style_ids ) {
		$normalized = array();

		if ( ! is_array( $style_ids ) ) {
			return $normalized;
		}

		foreach ( $style_ids as $style_id ) {
			if ( ! is_scalar( $style_id ) ) {
				continue;
			}

			$style_id = trim( (string) $style_id );
			if ( '' === $style_id ) {
				continue;
			}

			$normalized[] = $style_id;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize Etch attributes to string-valued key/value pairs.
	 *
	 * @param array $etch_attributes Raw attributes.
	 * @return array Normalized attributes.
	 */
	protected function normalize_attributes( $etch_attributes ) {
		$normalized = array();

		if ( ! is_array( $etch_attributes ) ) {
			return $normalized;
		}

		foreach ( $etch_attributes as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$normalized[ $key ] = (string) $value;
		}

		return $normalized;
	}

	/**
	 * Convert children to HTML
	 *
	 * @param array $children Child elements
	 * @param array $element_map All elements
	 * @return string Children HTML
	 */
	protected function convert_children( $children, $element_map ) {
		$children_html = '';

		foreach ( $children as $child ) {
			// This will be handled by the main converter
			// For now, just return empty string
			// The actual conversion happens in the main generator
		}

		return $children_html;
	}

	/**
	 * Normalize Bricks structure labels for Etch metadata names.
	 *
	 * Removes technical suffixes like "(CSS Tab)" and inline tag markers
	 * like "<ul>", "<li>", etc. before block JSON serialization.
	 *
	 * @param mixed  $label Label candidate.
	 * @param string $fallback Fallback label.
	 * @return string
	 */
	protected function sanitize_label( $label, $fallback = 'Element' ) {
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
}
