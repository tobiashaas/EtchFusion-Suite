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
					if ( $this->is_utility_like_selector( $class ) ) {
						break;
					}
					if ( $this->is_acss_selector_name( $class ) && ! $this->is_available_acss_selector_name( $class ) ) {
						break;
					}
					$classes[] = $class;
					break;
				}
			}
		}

		// Preserve resolved Bricks global class names even when no style-map entry exists.
		$classes = array_merge( $classes, $this->get_resolved_global_class_names() );
		// Preserve custom class tokens from Bricks `_cssClasses` as class names.
		$classes = array_merge( $classes, $this->get_custom_css_class_tokens() );

		$classes = array_values( array_unique( array_filter( $classes ) ) );

		// Sort: utility/ACSS classes to end so Etch Builder selects the semantic class first.
		$utility_prefixes = array( 'bg--', 'is-bg', 'is-bg-', 'hidden-accessible', 'smart-spacing' );
		usort(
			$classes,
			static function ( $a, $b ) use ( $utility_prefixes ) {
				$a_util = false;
				$b_util = false;
				foreach ( $utility_prefixes as $prefix ) {
					if ( 0 === strpos( $a, $prefix ) || $a === $prefix ) {
						$a_util = true;
					}
					if ( 0 === strpos( $b, $prefix ) || $b === $prefix ) {
						$b_util = true;
					}
				}
				if ( $a_util === $b_util ) {
					return 0;
				}
				return $a_util ? 1 : -1;
			}
		);

		return implode( ' ', $classes );
	}

	/**
	 * Collect plain class tokens from Bricks `_cssClasses`.
	 *
	 * @return array<int,string>
	 */
	protected function get_custom_css_class_tokens() {
		$classes  = array();
		$element  = is_array( $this->current_element ) ? $this->current_element : array();
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$raw      = isset( $settings['_cssClasses'] ) ? $settings['_cssClasses'] : '';

		if ( is_string( $raw ) || is_numeric( $raw ) ) {
			$tokens = preg_split( '/\s+/', trim( (string) $raw ) );
			if ( is_array( $tokens ) ) {
				foreach ( $tokens as $token ) {
					$token = trim( (string) $token );
					if ( '' === $token || $this->is_utility_like_selector( $token ) ) {
						continue;
					}
					$classes[] = preg_replace( '/^fr-/', '', $token );
				}
			}
		} elseif ( is_array( $raw ) ) {
			foreach ( $raw as $token ) {
				if ( ! is_scalar( $token ) ) {
					continue;
				}
				$token = trim( (string) $token );
				if ( '' === $token || $this->is_utility_like_selector( $token ) ) {
					continue;
				}
				$classes[] = preg_replace( '/^fr-/', '', $token );
			}
		}

		return $classes;
	}

	/**
	 * Collect resolved Bricks global class names attached to the current element.
	 *
	 * @return array<int,string>
	 */
	protected function get_resolved_global_class_names() {
		$classes = array();

		$element = is_array( $this->current_element ) ? $this->current_element : array();
		if ( empty( $element['settings']['_cssGlobalClasses'] ) || ! is_array( $element['settings']['_cssGlobalClasses'] ) ) {
			return $classes;
		}

		foreach ( $element['settings']['_cssGlobalClasses'] as $class_id ) {
			$id = is_scalar( $class_id ) ? trim( (string) $class_id ) : '';
			if ( '' === $id ) {
				continue;
			}

			// Skip IDs that are already handled by the style_map — their selector
			// class has been added via get_css_classes() and adding the raw ID
			// again would produce duplicates like "mapped-class bricks-global-1".
			if ( isset( $this->style_map[ $id ] ) ) {
				continue;
			}

			$name = $this->resolve_global_class_name_by_id( $id );
			// Some Bricks payloads store class names directly in _cssGlobalClasses.
			if ( '' === $name ) {
				$name = $this->normalize_class_name_token( $id );
			}
			// Strip site-specific fr- prefix (naming convention, not semantic).
			$name = preg_replace( '/^fr-/', '', $name );
			if ( '' !== $name && ! $this->is_utility_like_selector( $name ) ) {
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
		$default_label = $this->get_default_element_label();
		$raw_label     = $element['label'] ?? $default_label;
		return $this->sanitize_label( $raw_label, $default_label );
	}

	/**
	 * Get element tag
	 *
	 * @param array $element Bricks element
	 * @param string $fallback_tag Default tag
	 * @return string HTML tag
	 */
	protected function get_tag( $element, $fallback_tag = 'div' ) {
		$settings   = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$tag        = $settings['tag'] ?? $fallback_tag;
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
		$label             = $this->sanitize_label( $label, $this->get_default_element_label() );
		$custom_attributes = $this->extract_custom_attributes( $element );
		if ( ! empty( $custom_attributes ) ) {
			$etch_attributes = array_merge( $custom_attributes, (array) $etch_attributes );
		}
		$resolved_id = $this->resolve_element_id_attribute( $element, (array) $etch_attributes );
		if ( '' !== $resolved_id ) {
			$etch_attributes['id'] = $resolved_id;
		}
		$etch_attributes = $this->normalize_id_reference_attributes( (array) $etch_attributes );

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
	 * Build a null-safe default label from the converter element type.
	 *
	 * @return string
	 */
	protected function get_default_element_label() {
		$element_type = is_scalar( $this->element_type ) ? trim( (string) $this->element_type ) : '';
		if ( '' === $element_type ) {
			return 'Element';
		}

		return ucfirst( $element_type );
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

			// The HTML `datetime` attribute on <time> elements must be machine-readable
			// (ISO 8601). Replace Bricks date tags here — before the general dynamic-data
			// converter runs — so they use Y-m-d instead of the human-readable F j, Y format.
			if ( 'datetime' === $name ) {
				$value = str_replace( '{post_date}', "{this.date.dateFormat('Y-m-d')}", $value );
				$value = str_replace( '{post_modified}', "{this.modified.dateFormat('Y-m-d')}", $value );
			}

			$normalized[ $name ] = $value;
		}

		return $normalized;
	}

	/**
	 * Resolve HTML id attribute for the current element.
	 *
	 * Priority:
	 * 1) Existing `id` already present in attributes (e.g. custom attribute or converter override)
	 * 2) Bricks settings id fields (`_cssId`, `cssId`, `id`)
	 *
	 * @param array $element Source Bricks element.
	 * @param array $attributes Current Etch attributes.
	 * @return string
	 */
	protected function resolve_element_id_attribute( $element, array $attributes ) {
		$candidate = '';

		if ( isset( $attributes['id'] ) && is_scalar( $attributes['id'] ) ) {
			$candidate = (string) $attributes['id'];
		}

		if ( '' === trim( $candidate ) && is_array( $element ) ) {
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			foreach ( array( '_cssId', 'cssId', 'id' ) as $key ) {
				if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
					$candidate = (string) $settings[ $key ];
					if ( '' !== trim( $candidate ) ) {
						break;
					}
				}
			}
		}

		return $this->normalize_html_id_token( $candidate );
	}

	/**
	 * Normalize attribute values that reference element IDs.
	 *
	 * @param array $attributes Attribute map.
	 * @return array
	 */
	protected function normalize_id_reference_attributes( array $attributes ) {
		$id_ref_attributes = array(
			'for',
			'aria-labelledby',
			'aria-describedby',
			'aria-controls',
			'aria-owns',
			'aria-activedescendant',
		);

		foreach ( $id_ref_attributes as $name ) {
			if ( ! isset( $attributes[ $name ] ) || ! is_scalar( $attributes[ $name ] ) ) {
				continue;
			}
			$raw                 = trim( (string) $attributes[ $name ] );
			$parts               = preg_split( '/\s+/', $raw );
			$parts               = is_array( $parts ) ? $parts : array( $raw );
			$parts               = array_map( array( $this, 'normalize_html_id_reference_token' ), $parts );
			$parts               = array_values(
				array_filter(
					$parts,
					static function ( $part ) {
						return '' !== trim( (string) $part );
					}
				)
			);
			$attributes[ $name ] = implode( ' ', $parts );
		}

		foreach ( array( 'href', 'xlink:href' ) as $name ) {
			if ( ! isset( $attributes[ $name ] ) || ! is_scalar( $attributes[ $name ] ) ) {
				continue;
			}
			$value = trim( (string) $attributes[ $name ] );
			if ( '' === $value || 0 !== strpos( $value, '#' ) ) {
				continue;
			}
			$attributes[ $name ] = '#' . $this->normalize_html_id_reference_token( substr( $value, 1 ) );
		}

		return $attributes;
	}

	/**
	 * Normalize an ID token used as an element attribute.
	 *
	 * @param string $token Raw id token.
	 * @return string
	 */
	protected function normalize_html_id_token( $token ) {
		$token = trim( ltrim( (string) $token, '#' ) );
		if ( '' === $token ) {
			return '';
		}

		if ( preg_match( '/^brxe-(.+)$/i', $token, $matches ) ) {
			$token = 'etch-' . $matches[1];
		}

		if ( preg_match( '/\s/', $token ) ) {
			return '';
		}

		return $token;
	}

	/**
	 * Normalize a token that references another element by ID.
	 *
	 * @param string $token Raw reference token.
	 * @return string
	 */
	protected function normalize_html_id_reference_token( $token ) {
		return $this->normalize_html_id_token( $token );
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
				if ( '' === $key ) {
					continue;
				}

				$selector = '';
				$style_id = '';
				if ( isset( $this->style_map[ $key ] ) && is_array( $this->style_map[ $key ] ) ) {
					$selector = isset( $this->style_map[ $key ]['selector'] ) ? ltrim( (string) $this->style_map[ $key ]['selector'], '.' ) : '';
					$style_id = isset( $this->style_map[ $key ]['id'] ) ? trim( (string) $this->style_map[ $key ]['id'] ) : '';
				} elseif ( isset( $this->style_map[ $key ] ) && is_scalar( $this->style_map[ $key ] ) ) {
					// Legacy map format: Bricks ID => Etch style ID string.
					$style_id = trim( (string) $this->style_map[ $key ] );
				}

				if ( '' === $selector ) {
					$selector = $this->resolve_global_class_name_by_id( $key );
				}

				if ( '' === $selector || '' === $style_id || $this->is_utility_like_selector( $selector ) || $this->is_acss_selector_name( $selector ) || $this->is_modifier_like_selector( $selector ) ) {
					continue;
				}

				$candidate_style_ids[] = $style_id;
			}
		}

		if ( isset( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] ) ) {
			$tokens = array_values( array_filter( preg_split( '/\s+/', trim( $settings['_cssClasses'] ) ) ) );
			foreach ( $tokens as $token ) {
				if ( $this->is_utility_like_selector( $token ) || $this->is_acss_selector_name( $token ) || $this->is_modifier_like_selector( $token ) ) {
					continue;
				}

				$fallback_style_id = $this->find_style_id_by_class_name( $token );
				if ( '' !== $fallback_style_id ) {
					$candidate_style_ids[] = $fallback_style_id;
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
	 * Resolve an Etch style ID by class selector from current style map or etch_styles option.
	 *
	 * @param string $class_name Class selector token without leading dot.
	 * @return string
	 */
	protected function find_style_id_by_class_name( $class_name ) {
		$class_name = trim( (string) $class_name );
		if ( '' === $class_name ) {
			return '';
		}

		foreach ( $this->style_map as $style_data ) {
			if ( ! is_array( $style_data ) ) {
				continue;
			}
			$selector = isset( $style_data['selector'] ) ? ltrim( trim( (string) $style_data['selector'] ), '.' ) : '';
			if ( $selector !== $class_name ) {
				continue;
			}
			$style_id = isset( $style_data['id'] ) ? trim( (string) $style_data['id'] ) : '';
			if ( '' !== $style_id ) {
				return $style_id;
			}
		}

		$etch_styles = get_option( 'etch_styles', array() );
		if ( ! is_array( $etch_styles ) ) {
			return '';
		}

		foreach ( $etch_styles as $style_id => $style_data ) {
			if ( ! is_array( $style_data ) ) {
				continue;
			}
			$selector = isset( $style_data['selector'] ) ? ltrim( trim( (string) $style_data['selector'] ), '.' ) : '';
			if ( $selector === $class_name ) {
				$id = trim( (string) $style_id );
				if ( '' !== $id ) {
					return $id;
				}
			}
		}

		return '';
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
	 * Identify BEM-like modifier classes that should not be counted as merge targets.
	 *
	 * Examples:
	 * - card--featured
	 * - card__row--featured
	 *
	 * @param string $selector Class name without dot.
	 * @return bool
	 */
	protected function is_modifier_like_selector( $selector ) {
		$name = trim( (string) $selector );
		if ( '' === $name ) {
			return false;
		}

		return false !== strpos( $name, '--' );
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
	 * When `$classes` is provided the method additionally gates on whether the
	 * element name is `block` or the `brxe-block` class appears in the class list,
	 * and uses layout-profile analysis for more accurate flex/grid detection.
	 * Callers that already gate on element type (e.g. EFS_Element_Div) may omit
	 * `$classes` and the simple path is used instead.
	 *
	 * @param array  $style_ids       Etch style IDs resolved for the element.
	 * @param array  $etch_attributes Current attribute array being built.
	 * @param array  $element         Source Bricks element data.
	 * @param string $classes         Optional merged class string; enables class-list detection.
	 * @return array Updated attributes.
	 */
	public function apply_brxe_block_display( array $style_ids, array $etch_attributes, array $element, $classes = '' ) {
		if ( '' !== $classes ) {
			// Generator path: gate on element name OR brxe-block presence in class list.
			$class_list       = array_values( array_filter( preg_split( '/\s+/', trim( (string) $classes ) ) ) );
			$is_block_element = 'block' === ( $element['name'] ?? '' );
			if ( ! $is_block_element && ( empty( $class_list ) || ! in_array( 'brxe-block', $class_list, true ) ) ) {
				return $etch_attributes;
			}

			$current_style  = isset( $etch_attributes['style'] ) && is_scalar( $etch_attributes['style'] ) ? (string) $etch_attributes['style'] : '';
			$current_layout = $this->get_layout_profile_from_css( $current_style );
			if ( $current_layout['is_grid'] ) {
				return $etch_attributes;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$display  = isset( $settings['_display'] ) ? strtolower( trim( (string) $settings['_display'] ) ) : '';
			if ( in_array( $display, array( 'grid', 'inline-grid' ), true ) ) {
				return $etch_attributes;
			}

			// Try to patch a single identifiable neighbor class style before falling back to inline.
			$utility_prefixes = array( 'bg--', 'is-bg', 'is-bg-', 'hidden-accessible', 'smart-spacing' );
			$neighbor_classes = array();
			foreach ( $class_list as $name ) {
				$name = trim( (string) $name );
				if ( '' === $name || 'brxe-block' === $name ) {
					continue;
				}
				if ( $this->is_modifier_like_selector( $name ) ) {
					continue;
				}
				// Skip ACSS utility classes — they are not semantic targets for display injection.
				$is_utility = false;
				foreach ( $utility_prefixes as $prefix ) {
					if ( 0 === strpos( $name, $prefix ) || $name === $prefix ) {
						$is_utility = true;
						break;
					}
				}
				if ( $is_utility ) {
					continue;
				}
				$neighbor_classes[] = $name;
			}
			$neighbor_classes = array_values( array_unique( $neighbor_classes ) );

			if ( 1 === count( $neighbor_classes ) ) {
				$target_style_id = $this->find_style_id_by_class_name( $neighbor_classes[0] );
				if ( $target_style_id ) {
					// The CSS migration phase injects display:flex into the semantic class.
					// Skip the inline-style fallback — return without setting any style attribute.
					return $etch_attributes;
				}
			}

			$style_layout         = $this->get_layout_profile_for_style_ids( $style_ids );
			$display_is_derivable = $style_layout['is_flex']
				|| $style_layout['is_grid']
				|| $current_layout['is_flex']
				|| in_array( $display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true );

			if ( $display_is_derivable ) {
				return $etch_attributes;
			}

			if ( $current_layout['has_flex_direction'] || $style_layout['has_flex_direction'] ) {
				$inline_declarations = 'display: flex; inline-size: 100%;';
			} else {
				$inline_declarations = 'display: flex; flex-direction: column; inline-size: 100%;';
			}

			if ( '' !== trim( $current_style ) && ';' !== substr( rtrim( $current_style ), -1 ) ) {
				$current_style .= ';';
			}
			$etch_attributes['style'] = trim( $current_style . ' ' . $inline_declarations );
			return $etch_attributes;
		}

		// Simple path: caller has already gated on element type being block.
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

		$current_style = isset( $etch_attributes['style'] ) && is_scalar( $etch_attributes['style'] )
			? trim( (string) $etch_attributes['style'] ) : '';
		if ( preg_match( '/\bdisplay\s*:/i', $current_style ) ) {
			return $etch_attributes;
		}

		$settings       = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$bricks_display = isset( $settings['_display'] ) ? strtolower( trim( (string) $settings['_display'] ) ) : '';
		if ( in_array( $bricks_display, array( 'flex', 'inline-flex', 'grid', 'inline-grid' ), true ) ) {
			return $etch_attributes;
		}

		$declarations    = 'display: flex; flex-direction: column; inline-size: 100%;';
		$target_style_id = $this->resolve_single_neighbor_style_id( $element );
		if ( '' !== $target_style_id ) {
			$this->append_declarations_to_style_id( $target_style_id, $declarations );
			return $etch_attributes;
		}

		if ( '' !== $current_style && ';' !== substr( rtrim( $current_style ), -1 ) ) {
			$current_style .= ';';
		}
		$etch_attributes['style'] = trim( $current_style . ' ' . $declarations );

		return $etch_attributes;
	}

	/**
	 * Read flex/grid/flex-direction state from a CSS string.
	 *
	 * @param string $css Raw CSS declarations.
	 * @return array<string,bool>
	 */
	protected function get_layout_profile_from_css( $css ) {
		$css = (string) $css;
		return array(
			'is_grid'            => (bool) preg_match( '/\bdisplay\s*:\s*(?:inline-)?grid\b/i', $css ),
			'is_flex'            => (bool) preg_match( '/\bdisplay\s*:\s*(?:inline-)?flex\b/i', $css ),
			'has_flex_direction' => (bool) preg_match( '/\bflex-direction\s*:/i', $css ),
		);
	}

	/**
	 * Get layout profile for a single Etch style ID.
	 *
	 * @param string $style_id Etch style ID.
	 * @return array<string,bool>
	 */
	protected function get_layout_profile_for_style_id( $style_id ) {
		$style_id = trim( (string) $style_id );
		$empty    = array(
			'is_grid'            => false,
			'is_flex'            => false,
			'has_flex_direction' => false,
		);
		if ( '' === $style_id ) {
			return $empty;
		}

		$etch_styles = get_option( 'etch_styles', array() );
		if ( ! is_array( $etch_styles ) || empty( $etch_styles[ $style_id ] ) || ! is_array( $etch_styles[ $style_id ] ) ) {
			return $empty;
		}

		$css = isset( $etch_styles[ $style_id ]['css'] ) && is_scalar( $etch_styles[ $style_id ]['css'] )
			? (string) $etch_styles[ $style_id ]['css'] : '';

		return $this->get_layout_profile_from_css( $css );
	}

	/**
	 * Aggregate layout profile across multiple Etch style IDs.
	 *
	 * @param array $style_ids Etch style IDs.
	 * @return array<string,bool>
	 */
	protected function get_layout_profile_for_style_ids( array $style_ids ) {
		$profile = array(
			'is_grid'            => false,
			'is_flex'            => false,
			'has_flex_direction' => false,
		);
		if ( empty( $style_ids ) ) {
			return $profile;
		}

		$etch_styles = get_option( 'etch_styles', array() );
		if ( ! is_array( $etch_styles ) ) {
			return $profile;
		}

		foreach ( $style_ids as $style_id ) {
			if ( ! is_scalar( $style_id ) ) {
				continue;
			}
			$key = trim( (string) $style_id );
			if ( '' === $key || empty( $etch_styles[ $key ] ) || ! is_array( $etch_styles[ $key ] ) ) {
				continue;
			}
			$css                           = isset( $etch_styles[ $key ]['css'] ) && is_scalar( $etch_styles[ $key ]['css'] )
				? (string) $etch_styles[ $key ]['css'] : '';
			$item                          = $this->get_layout_profile_from_css( $css );
			$profile['is_grid']            = $profile['is_grid'] || $item['is_grid'];
			$profile['is_flex']            = $profile['is_flex'] || $item['is_flex'];
			$profile['has_flex_direction'] = $profile['has_flex_direction'] || $item['has_flex_direction'];
		}

		return $profile;
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
			$class_name = $this->resolve_global_class_name_by_id( $key );
			// ACSS utility classes that must stay as class attributes and never be inlined.
			// ACSS handles their styling on the target site.
			if (
				'' !== $class_name
				&& (
					0 === strpos( $class_name, 'bg--' )
					|| 'is-bg' === $class_name
					|| 0 === strpos( $class_name, 'is-bg-' )
					|| 'hidden-accessible' === $class_name
				)
			) {
				continue;
			}
			$declarations[] = trim( (string) $acss_inline_map[ $key ] );
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		$css = implode( ' ', array_unique( array_filter( $declarations ) ) );

		// Normalise to a single-line string safe for an HTML style="..." attribute:
		// 1. Remove CSS block comments (/* ... */).
		// 2. Collapse newlines + surrounding whitespace to a single space.
		// 3. Collapse runs of whitespace to one space.
		$css = preg_replace( '!/\*.*?\*/!s', '', $css );
		$css = preg_replace( '/\r?\n\s*/', ' ', $css );
		$css = preg_replace( '/\s{2,}/', ' ', $css );

		return trim( $css );
	}

	/**
	 * Resolve Bricks global class name from class ID.
	 *
	 * @param string $class_id Bricks global class id.
	 * @return string
	 */
	protected function resolve_global_class_name_by_id( $class_id ) {
		$class_id = trim( (string) $class_id );
		if ( '' === $class_id ) {
			return '';
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

		return isset( $class_name_by_id[ $class_id ] ) ? (string) $class_name_by_id[ $class_id ] : '';
	}

	/**
	 * Normalize a possible class token to a class name.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function normalize_class_name_token( $token ) {
		$name = ltrim( preg_replace( '/^acss_import_/', '', trim( (string) $token ) ), '.' );
		if ( '' === $name ) {
			return '';
		}

		return preg_match( '/^[a-zA-Z0-9_-]+$/', $name ) ? $name : '';
	}

	/**
	 * Check if ACSS selector currently exists in the imported ACSS stylesheet map.
	 *
	 * @param string $class_name Selector without leading dot.
	 * @return bool
	 */
	protected function is_available_acss_selector_name( $class_name ) {
		$class_name = trim( (string) $class_name );
		if ( '' === $class_name ) {
			return false;
		}

		static $available = null;
		if ( null === $available ) {
			$available = array();
			$map       = get_option( 'efs_acss_inline_style_map', array() );
			if ( is_array( $map ) ) {
				foreach ( array_keys( $map ) as $key ) {
					if ( ! is_scalar( $key ) ) {
						continue;
					}
					$name = $this->normalize_class_name_token( (string) $key );
					if ( '' !== $name ) {
						$available[ $name ] = true;
					}
				}
			}
		}

		return isset( $available[ $class_name ] );
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
	 * Normalize HTML entities so content is stored as plain text (avoids double encoding on output).
	 * Decodes repeatedly until stable so already double-encoded strings (e.g. &amp;amp;) become &.
	 *
	 * @param string $text Raw or entity-encoded text from Bricks/source.
	 * @return string Plain text safe for single encoding when output.
	 */
	protected function decode_html_entities_once( $text ) {
		return self::decode_html_entities_to_plain( $text );
	}

	/**
	 * Normalize HTML entities to plain text (avoids double encoding when output is escaped).
	 * Public static so other layers (e.g. Gutenberg generator) can use it.
	 *
	 * @param string $text Raw or entity-encoded text from Bricks/source.
	 * @return string Plain text.
	 */
	public static function decode_html_entities_to_plain( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		while ( $decoded !== $text ) {
			$text    = $decoded;
			$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $text;
	}

	/**
	 * Generate an etch/raw-html inner block for content that contains inline HTML markup.
	 *
	 * Use this instead of generate_etch_text_block() whenever the content may include
	 * tags like <em>, <b>, <i>, <strong>, <a>, <address>, etc. — etch/text treats its
	 * content as plain text and would display those tags literally.
	 *
	 * @param string $html    HTML content (e.g. "Hello <em>world</em>").
	 * @param string $label   Optional human-readable label for the block.
	 * @return string Gutenberg block markup.
	 */
	protected function generate_etch_raw_html_block( $html, $label = '' ) {
		$attrs = array(
			'content' => (string) $html,
			'unsafe'  => false,
		);
		if ( '' !== $label ) {
			$attrs['metadata'] = array( 'name' => $label );
		}
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" . '<!-- /wp:etch/raw-html -->';
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
