<?php
/**
 * Settings CSS Converter
 *
 * Converts Bricks element/class settings arrays to CSS declaration strings.
 *
 * Bricks stores visual styling inside the `settings` key of every global class
 * and element.  The settings are grouped by type (typography, flexbox, grid,
 * sizing, …).  This class contains one `convert_*` method per settings group
 * plus helpers for responsive and selector variants.
 *
 * All methods are pure relative to the settings input — they do not read from
 * the database or touch WordPress state.  The sole dependency on EFS_Breakpoint_Resolver
 * is needed for responsive-variant generation.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts individual Bricks settings categories to CSS declaration strings.
 *
 * Usage:
 *   $converter = new EFS_Settings_CSS_Converter($breakpoint_resolver, $normalizer);
 *   $css = $converter->convert_bricks_settings_to_css($settings, 'my-class');
 */
class EFS_Settings_CSS_Converter {

	/**
	 * Resolves breakpoints for responsive variant generation.
	 *
	 * @var EFS_Breakpoint_Resolver
	 */
	private $breakpoint_resolver;

	/**
	 * CSS normaliser for quad-shorthand helpers.
	 *
	 * @var EFS_CSS_Normalizer
	 */
	private $normalizer;

	/**
	 * @param EFS_Breakpoint_Resolver $breakpoint_resolver Breakpoint resolver instance.
	 * @param EFS_CSS_Normalizer      $normalizer          CSS normaliser instance.
	 */
	public function __construct( EFS_Breakpoint_Resolver $breakpoint_resolver, EFS_CSS_Normalizer $normalizer ) {
		$this->breakpoint_resolver = $breakpoint_resolver;
		$this->normalizer          = $normalizer;
	}

	// =========================================================================
	// Entry point
	// =========================================================================

	/**
	 * Convert a complete Bricks settings array to a CSS declaration string.
	 *
	 * Processes all supported settings groups in a fixed order and concatenates
	 * their outputs.  When $include_selector_variants is true, pseudo-class and
	 * pseudo-element variant rules (hover, ::before, etc.) are appended as nested
	 * blocks.
	 *
	 * Custom CSS (_cssCustom) is intentionally excluded here — it is handled
	 * separately by the stylesheet parser to avoid duplication.
	 *
	 * @param array<string,mixed> $settings                  Bricks settings.
	 * @param string              $class_name                Class name without leading dot (optional).
	 * @param bool                $include_selector_variants Whether to append variant rules.
	 * @return string
	 */
	public function convert_bricks_settings_to_css( array $settings, string $class_name = '', bool $include_selector_variants = true ): string {
		$css_properties = array();

		// Generated content property (pseudo-element UI settings: _content::before / _content::after).
		if ( isset( $settings['_content'] ) && '' !== trim( (string) $settings['_content'] ) ) {
			$content_value = $this->normalizer->normalize_content_property_value( $settings['_content'] );
			if ( '' !== $content_value ) {
				$css_properties[] = 'content: ' . $content_value . ';';
			}
		}

		$css_properties = array_merge( $css_properties, $this->convert_layout( $settings ) );
		$css_properties = array_merge( $css_properties, $this->convert_flexbox( $settings ) );
		$css_properties = array_merge( $css_properties, $this->convert_grid( $settings ) );
		$css_properties = array_merge( $css_properties, $this->convert_sizing( $settings ) );

		// Background (_background key is the primary one; 'background' is a legacy alias).
		if ( ! empty( $settings['_background'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_background( $settings['_background'] ) );
		} elseif ( ! empty( $settings['background'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_background( $settings['background'] ) );
		}

		// Gradient (_gradient key).
		if ( ! empty( $settings['_gradient'] ) ) {
			$gradient_css = $this->convert_gradient( $settings['_gradient'] );
			if ( '' !== $gradient_css ) {
				$css_properties[] = $gradient_css;
			}
		}

		// Border (_border key; 'border' is a legacy alias).
		if ( ! empty( $settings['_border'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_border_properties( $settings['_border'] ) );
		} elseif ( ! empty( $settings['border'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_border_properties( $settings['border'] ) );
		}

		// Typography (_typography primary, 'typography' legacy).
		if ( ! empty( $settings['_typography'] ) || ! empty( $settings['typography'] ) ) {
			$typography     = ! empty( $settings['_typography'] ) ? $settings['_typography'] : $settings['typography'];
			$css_properties = array_merge( $css_properties, $this->convert_typography( $typography ) );
		}

		// Spacing (legacy flat spacing object).
		if ( ! empty( $settings['spacing'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_spacing( $settings['spacing'] ) );
		}

		$css_properties = array_merge( $css_properties, $this->convert_margin_padding( $settings ) );
		$css_properties = array_merge( $css_properties, $this->convert_position( $settings ) );
		$css_properties = array_merge( $css_properties, $this->convert_effects( $settings ) );

		// Remove empty declarations.
		$css_properties = array_filter( $css_properties );

		// Append pseudo-class / pseudo-element variant blocks.
		if ( $include_selector_variants ) {
			$selector_variants_css = $this->convert_selector_variants( $settings, $class_name );
			if ( '' !== $selector_variants_css ) {
				$css_properties[] = $selector_variants_css;
			}
		}

		return implode( ' ', $css_properties );
	}

	// =========================================================================
	// Responsive and selector variants
	// =========================================================================

	/**
	 * Convert breakpoint-suffixed settings keys to @media query blocks.
	 *
	 * Bricks stores responsive overrides as "property:breakpoint_name" keys.
	 * This method groups them by breakpoint, converts each group to CSS, and
	 * wraps the result in the corresponding @media rule.
	 *
	 * @param array<string,mixed> $settings   Bricks settings.
	 * @param string              $class_name Class name without leading dot (optional).
	 * @return string
	 */
	public function convert_responsive_variants( array $settings, string $class_name = '' ): string {
		$responsive_css = '';

		$breakpoints = $this->breakpoint_resolver->get_breakpoint_media_query_map( true );
		if ( empty( $breakpoints ) ) {
			return $responsive_css;
		}

		// Build a normalised breakpoint key → media query map.
		$normalized_map = array();
		foreach ( $breakpoints as $breakpoint_key => $media_query ) {
			$normalized_map[ $this->breakpoint_resolver->sanitize_breakpoint_key( (string) $breakpoint_key ) ] = $media_query;
		}

		// Bucket settings by their breakpoint suffix.
		$bucketed_settings = array();
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( false === strpos( $key, ':' ) ) {
				continue;
			}

			$last_colon_pos = strrpos( $key, ':' );
			if ( false === $last_colon_pos ) {
				continue;
			}

			$suffix         = substr( $key, $last_colon_pos + 1 );
			$normalized_key = $this->breakpoint_resolver->sanitize_breakpoint_key( $suffix );
			if ( '' === $normalized_key || ! isset( $normalized_map[ $normalized_key ] ) ) {
				continue;
			}

			$base_key = substr( $key, 0, $last_colon_pos );
			if ( '' === $base_key ) {
				continue;
			}

			$bucketed_settings[ $normalized_key ][ $base_key ] = $value;
		}

		foreach ( $breakpoints as $breakpoint_key => $media_query ) {
			$normalized_key      = $this->breakpoint_resolver->sanitize_breakpoint_key( (string) $breakpoint_key );
			$breakpoint_settings = $bucketed_settings[ $normalized_key ] ?? array();

			if ( empty( $breakpoint_settings ) ) {
				continue;
			}

			$breakpoint_css = $this->convert_bricks_settings_to_css( $breakpoint_settings, $class_name );
			if ( '' !== $breakpoint_css ) {
				$responsive_css .= "\n@media " . $media_query . " {\n  " . str_replace( ';', ";\n  ", trim( $breakpoint_css ) ) . "\n}";
			}
		}

		return $responsive_css;
	}

	/**
	 * Convert Bricks selector/state variants stored as colon-suffixed setting keys.
	 *
	 * Bricks encodes pseudo-class and pseudo-element overrides as
	 * "property:state" (e.g. "_typography:hover", "_content::after").  This
	 * method groups them by suffix and emits nested selector blocks.
	 *
	 * Responsive variants (breakpoint suffixes) and _cssCustom:* keys are
	 * excluded from this pass — they are handled by other methods.
	 *
	 * @param array<string,mixed> $settings   Bricks settings.
	 * @param string              $class_name Class name without leading dot (optional).
	 * @return string
	 */
	public function convert_selector_variants( array $settings, string $class_name = '' ): string {
		$variant_settings       = array();
		$breakpoints            = $this->breakpoint_resolver->detect_breakpoint_keys( $settings );
		$normalized_breakpoints = array();
		foreach ( $breakpoints as $breakpoint_key ) {
			$normalized = $this->breakpoint_resolver->sanitize_breakpoint_key( (string) $breakpoint_key );
			if ( '' !== $normalized ) {
				$normalized_breakpoints[ $normalized ] = true;
			}
		}

		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || false === strpos( $key, ':' ) ) {
				continue;
			}

			// Custom CSS is handled by the dedicated stylesheet parser.
			if ( 0 === strpos( $key, '_cssCustom:' ) ) {
				continue;
			}

			$separator_pos = strpos( $key, ':' );
			if ( false === $separator_pos ) {
				continue;
			}

			$base_key = substr( $key, 0, $separator_pos );
			$suffix   = substr( $key, $separator_pos );

			if ( '' === $base_key || '' === $suffix ) {
				continue;
			}

			// Skip responsive-variant suffixes (already handled by convert_responsive_variants).
			$suffix_key = $this->breakpoint_resolver->sanitize_breakpoint_key( ltrim( $suffix, ':' ) );
			if ( '' !== $suffix_key && isset( $normalized_breakpoints[ $suffix_key ] ) ) {
				continue;
			}

			$variant_settings[ $suffix ][ $base_key ] = $value;
		}

		if ( empty( $variant_settings ) ) {
			return '';
		}

		$css_chunks = array();
		foreach ( $variant_settings as $suffix => $variant_values ) {
			$variant_css = trim( $this->convert_bricks_settings_to_css( $variant_values, $class_name, false ) );
			if ( '' === $variant_css ) {
				continue;
			}

			$selector = $this->normalize_variant_selector_suffix( $suffix, $class_name );
			if ( '' === $selector ) {
				continue;
			}

			$css_chunks[] = $selector . " {\n  " . trim( $variant_css ) . "\n}";
		}

		return implode( "\n\n", $css_chunks );
	}

	/**
	 * Normalise a raw selector suffix to the nested CSS ampersand form.
	 *
	 * @param string $suffix     Raw suffix (e.g. ":hover", "::after", " > .child").
	 * @param string $class_name Optional class name without leading dot.
	 * @return string
	 */
	public function normalize_variant_selector_suffix( string $suffix, string $class_name = '' ): string {
		$suffix = trim( $suffix );
		if ( '' === $suffix ) {
			return '';
		}

		if ( '' !== $class_name ) {
			return $this->normalize_selector_suffix_with_ampersand( $suffix, $class_name );
		}

		if ( 0 === strpos( $suffix, '&' ) ) {
			return $suffix;
		}

		if ( preg_match( '/^[>+~]/', $suffix ) || preg_match( '/^[.#]/', $suffix ) ) {
			return '& ' . $suffix;
		}

		// Attribute selectors and pseudo-classes/elements are same-element — no space.
		if ( preg_match( '/^\[/', $suffix ) ) {
			return '&' . $suffix;
		}

		return '&' . $suffix;
	}

	/**
	 * Normalise selector suffixes for nested CSS output.
	 *
	 * Converts duplicate source-class references in comma-separated lists and
	 * adds ampersand prefixes appropriate for each selector type.
	 *
	 * @param string $selector_suffix Selector part captured after ".class".
	 * @param string $class_name      Base class name without leading dot.
	 * @return string
	 */
	public function normalize_selector_suffix_with_ampersand( string $selector_suffix, string $class_name ): string {
		$raw    = $selector_suffix;
		$suffix = trim( $raw );
		if ( '' === $suffix ) {
			return '';
		}

		// Leading whitespace in the raw suffix indicates a descendant context.
		$is_descendant_context = ( $raw !== $suffix && preg_match( '/^\s/', $raw ) );

		// Replace any remaining occurrence of the source class with &.
		$class_pattern = '/\.' . preg_quote( $class_name, '/' ) . '\b/i';
		$suffix        = preg_replace( $class_pattern, '&', $suffix );
		$suffix        = is_string( $suffix ) ? $suffix : trim( $raw );

		$parts = array_filter( array_map( 'trim', explode( ',', $suffix ) ) );

		if ( empty( $parts ) ) {
			return '';
		}

		$normalized_parts = array();
		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '&' ) ) {
				$normalized_parts[] = $part;
				continue;
			}

			if ( preg_match( '/^[>+~]/', $part ) ) {
				// Combinators always need a space: "& > *".
				$normalized_parts[] = '& ' . $part;
			} elseif ( preg_match( '/^\[/', $part ) ) {
				// Attribute selectors are always same-element.
				$normalized_parts[] = '&' . $part;
			} elseif ( preg_match( '/^[.#]/', $part ) ) {
				// Class / ID selectors: respect descendant context from source whitespace.
				$normalized_parts[] = ( $is_descendant_context ? '& ' : '&' ) . $part;
			} else {
				$normalized_parts[] = '&' . $part;
			}
		}

		return implode( ', ', $normalized_parts );
	}

	// =========================================================================
	// Individual settings-group converters
	// =========================================================================

	/**
	 * Convert layout / display settings.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_layout( array $settings ): array {
		$css = array();

		if ( ! empty( $settings['_display'] ) ) {
			$css[] = 'display: ' . $settings['_display'] . ';';
		}
		if ( ! empty( $settings['_overflow'] ) ) {
			$css[] = 'overflow: ' . $settings['_overflow'] . ';';
		}
		if ( ! empty( $settings['_overflowX'] ) ) {
			$css[] = 'overflow-x: ' . $settings['_overflowX'] . ';';
		}
		if ( ! empty( $settings['_overflowY'] ) ) {
			$css[] = 'overflow-y: ' . $settings['_overflowY'] . ';';
		}
		if ( ! empty( $settings['_visibility'] ) ) {
			$css[] = 'visibility: ' . $settings['_visibility'] . ';';
		}
		if ( isset( $settings['_opacity'] ) && '' !== (string) $settings['_opacity'] ) {
			$css[] = 'opacity: ' . $settings['_opacity'] . ';';
		}
		if ( isset( $settings['_zIndex'] ) && '' !== (string) $settings['_zIndex'] ) {
			$css[] = 'z-index: ' . $settings['_zIndex'] . ';';
		}

		return $css;
	}

	/**
	 * Convert Flexbox container and item settings.
	 *
	 * When flex container properties are present but no explicit `display` is set,
	 * `display: flex` and (optionally) `flex-direction: column` are injected to
	 * replicate Bricks block default behaviour.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_flexbox( array $settings ): array {
		$css                         = array();
		$has_flex_container_property = false;
		$has_display_defined         = ! empty( $settings['_display'] );
		$has_flex_direction_defined  = false;

		// _direction is Bricks's alias for _flexDirection.
		$flex_direction = $settings['_flexDirection'] ?? $settings['_direction'] ?? '';
		if ( ! empty( $flex_direction ) ) {
			$css[]                       = 'flex-direction: ' . $flex_direction . ';';
			$has_flex_container_property = true;
			$has_flex_direction_defined  = true;
		}

		if ( ! empty( $settings['_flexWrap'] ) ) {
			$css[]                       = 'flex-wrap: ' . $settings['_flexWrap'] . ';';
			$has_flex_container_property = true;
		}
		if ( ! empty( $settings['_justifyContent'] ) ) {
			$css[]                       = 'justify-content: ' . $settings['_justifyContent'] . ';';
			$has_flex_container_property = true;
		}
		if ( ! empty( $settings['_alignItems'] ) ) {
			$css[]                       = 'align-items: ' . $settings['_alignItems'] . ';';
			$has_flex_container_property = true;
		}
		if ( ! empty( $settings['_alignContent'] ) ) {
			$css[]                       = 'align-content: ' . $settings['_alignContent'] . ';';
			$has_flex_container_property = true;
		}
		if ( isset( $settings['_rowGap'] ) && '' !== (string) $settings['_rowGap'] ) {
			$css[]                       = 'row-gap: ' . $settings['_rowGap'] . ';';
			$has_flex_container_property = true;
		}
		if ( isset( $settings['_columnGap'] ) && '' !== (string) $settings['_columnGap'] ) {
			$css[]                       = 'column-gap: ' . $settings['_columnGap'] . ';';
			$has_flex_container_property = true;
		}
		if ( isset( $settings['_gap'] ) && '' !== (string) $settings['_gap'] ) {
			$css[]                       = 'gap: ' . $settings['_gap'] . ';';
			$has_flex_container_property = true;
		}

		// Inject display:flex and flex-direction:column when a flex container
		// property is present but no explicit display value has been set.
		if ( $has_flex_container_property && ! $has_display_defined ) {
			$injected = array( 'display: flex;' );
			if ( ! $has_flex_direction_defined ) {
				$injected[] = 'flex-direction: column;';
			}
			$css = array_merge( $injected, $css );
		}

		// Flex item properties.
		if ( isset( $settings['_flexGrow'] ) && '' !== (string) $settings['_flexGrow'] ) {
			$css[] = 'flex-grow: ' . $settings['_flexGrow'] . ';';
		}
		if ( isset( $settings['_flexShrink'] ) && '' !== (string) $settings['_flexShrink'] ) {
			$css[] = 'flex-shrink: ' . $settings['_flexShrink'] . ';';
		}
		if ( ! empty( $settings['_flexBasis'] ) ) {
			$css[] = 'flex-basis: ' . $settings['_flexBasis'] . ';';
		}
		if ( ! empty( $settings['_alignSelf'] ) ) {
			$css[] = 'align-self: ' . $settings['_alignSelf'] . ';';
		}
		if ( isset( $settings['_order'] ) && '' !== (string) $settings['_order'] ) {
			$css[] = 'order: ' . $settings['_order'] . ';';
		}

		return $css;
	}

	/**
	 * Convert CSS Grid container and item properties.
	 *
	 * Grid item placement handles two competing Bricks storage conventions:
	 *
	 * a) Explicit line-based (preferred):
	 *      _gridItemColumnStart / _gridItemColumnEnd → grid-column-start / -end
	 *
	 * b) Span-based (_gridItemColumnSpan / _gridItemRowSpan):
	 *   - Non-numeric string (e.g. "full"): area shorthand (grid-column: full;)
	 *   - Numeric 1: treated as line 1, not span 1 (Bricks ambiguity)
	 *   - Numeric > 1: grid-column: span N;
	 *
	 * Line-based values always win when present.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_grid( array $settings ): array {
		$css = array();

		if ( ! empty( $settings['_gridTemplateColumns'] ) ) {
			$css[] = 'grid-template-columns: ' . $settings['_gridTemplateColumns'] . ';';
		}
		if ( ! empty( $settings['_gridTemplateRows'] ) ) {
			$css[] = 'grid-template-rows: ' . $settings['_gridTemplateRows'] . ';';
		}

		// Use isset() so gap:0 is preserved.
		if ( isset( $settings['_gridGap'] ) && '' !== (string) $settings['_gridGap'] ) {
			$css[] = 'gap: ' . $settings['_gridGap'] . ';';
		}
		if ( isset( $settings['_gridColumnGap'] ) && '' !== (string) $settings['_gridColumnGap'] ) {
			$css[] = 'column-gap: ' . $settings['_gridColumnGap'] . ';';
		}
		if ( isset( $settings['_gridRowGap'] ) && '' !== (string) $settings['_gridRowGap'] ) {
			$css[] = 'row-gap: ' . $settings['_gridRowGap'] . ';';
		}

		if ( ! empty( $settings['_justifyContentGrid'] ) ) {
			$css[] = 'justify-content: ' . $settings['_justifyContentGrid'] . ';';
		}
		if ( ! empty( $settings['_alignItemsGrid'] ) ) {
			$css[] = 'align-items: ' . $settings['_alignItemsGrid'] . ';';
		}
		if ( ! empty( $settings['_justifyItemsGrid'] ) ) {
			$css[] = 'justify-items: ' . $settings['_justifyItemsGrid'] . ';';
		}
		if ( ! empty( $settings['_alignContentGrid'] ) ) {
			$css[] = 'align-content: ' . $settings['_alignContentGrid'] . ';';
		}
		if ( ! empty( $settings['_gridAutoFlow'] ) ) {
			$css[] = 'grid-auto-flow: ' . $settings['_gridAutoFlow'] . ';';
		}

		$has_column_lines = ! empty( $settings['_gridItemColumnStart'] ) || ! empty( $settings['_gridItemColumnEnd'] );
		$has_row_lines    = ! empty( $settings['_gridItemRowStart'] ) || ! empty( $settings['_gridItemRowEnd'] );

		if ( $has_column_lines ) {
			if ( ! empty( $settings['_gridItemColumnStart'] ) ) {
				$css[] = 'grid-column-start: ' . $settings['_gridItemColumnStart'] . ';';
			}
			if ( ! empty( $settings['_gridItemColumnEnd'] ) ) {
				$css[] = 'grid-column-end: ' . $settings['_gridItemColumnEnd'] . ';';
			}
		} elseif ( ! empty( $settings['_gridItemColumnSpan'] ) ) {
			$n = $settings['_gridItemColumnSpan'];
			if ( ! is_numeric( $n ) ) {
				// Named grid area — use area shorthand, not "span name".
				$css[] = 'grid-column: ' . $n . ';';
			} elseif ( 1 === (int) $n ) {
				// Bricks uses span=1 ambiguously; treat as line 1.
				$css[] = 'grid-column: 1;';
			} else {
				$css[] = 'grid-column: span ' . $n . ';';
			}
		}

		if ( $has_row_lines ) {
			if ( ! empty( $settings['_gridItemRowStart'] ) ) {
				$css[] = 'grid-row-start: ' . $settings['_gridItemRowStart'] . ';';
			}
			if ( ! empty( $settings['_gridItemRowEnd'] ) ) {
				$css[] = 'grid-row-end: ' . $settings['_gridItemRowEnd'] . ';';
			}
		} elseif ( ! empty( $settings['_gridItemRowSpan'] ) ) {
			$n = $settings['_gridItemRowSpan'];
			if ( ! is_numeric( $n ) ) {
				$css[] = 'grid-row: ' . $n . ';';
			} elseif ( 1 === (int) $n ) {
				$css[] = 'grid-row: 1;';
			} else {
				$css[] = 'grid-row: span ' . $n . ';';
			}
		}

		return $css;
	}

	/**
	 * Convert sizing properties to logical equivalents.
	 *
	 * Bricks stores min/max dimensions under two naming conventions:
	 *   Older: _maxWidth, _minWidth, _maxHeight, _minHeight
	 *   Newer: _widthMax, _widthMin, _heightMax, _heightMin
	 * Both are handled — whichever is set wins.
	 *
	 * Container elements (display: flex / grid) with width: 100% are converted
	 * to max-inline-size: 100% to override Etch's container default max-width
	 * without locking the element to an exact width.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_sizing( array $settings ): array {
		$css = array();

		if ( ! empty( $settings['_width'] ) ) {
			$display      = isset( $settings['_display'] ) ? strtolower( (string) $settings['_display'] ) : '';
			$is_container = in_array( $display, array( 'flex', 'grid', 'inline-flex', 'inline-grid' ), true );
			$width_val    = (string) $settings['_width'];
			if ( $is_container && '100%' === $width_val ) {
				$css[] = 'max-inline-size: 100%;';
			} else {
				$css[] = 'inline-size: ' . $width_val . ';';
			}
		}

		if ( ! empty( $settings['_height'] ) ) {
			$css[] = 'block-size: ' . $settings['_height'] . ';';
		}

		if ( ! empty( $settings['_minWidth'] ) ) {
			$css[] = 'min-inline-size: ' . $settings['_minWidth'] . ';';
		} elseif ( ! empty( $settings['_widthMin'] ) ) {
			$css[] = 'min-inline-size: ' . $settings['_widthMin'] . ';';
		}

		if ( ! empty( $settings['_minHeight'] ) ) {
			$css[] = 'min-block-size: ' . $settings['_minHeight'] . ';';
		} elseif ( ! empty( $settings['_heightMin'] ) ) {
			$css[] = 'min-block-size: ' . $settings['_heightMin'] . ';';
		}

		if ( ! empty( $settings['_maxWidth'] ) ) {
			$css[] = 'max-inline-size: ' . $settings['_maxWidth'] . ';';
		} elseif ( ! empty( $settings['_widthMax'] ) ) {
			$css[] = 'max-inline-size: ' . $settings['_widthMax'] . ';';
		}

		if ( ! empty( $settings['_maxHeight'] ) ) {
			$css[] = 'max-block-size: ' . $settings['_maxHeight'] . ';';
		} elseif ( ! empty( $settings['_heightMax'] ) ) {
			$css[] = 'max-block-size: ' . $settings['_heightMax'] . ';';
		}

		if ( ! empty( $settings['_aspectRatio'] ) ) {
			$css[] = 'aspect-ratio: ' . $settings['_aspectRatio'] . ';';
		}

		return $css;
	}

	/**
	 * Convert typography settings to CSS declarations.
	 *
	 * Both kebab-case (font-size) and camelCase (fontSize) keys are handled to
	 * support different Bricks versions.
	 *
	 * @param array<string,mixed> $typography Bricks typography settings sub-array.
	 * @return array<int,string>
	 */
	public function convert_typography( array $typography ): array {
		$css = array();

		if ( ! empty( $typography['font-size'] ) ) {
			$css[] = 'font-size: ' . $typography['font-size'] . ';';
		} elseif ( ! empty( $typography['fontSize'] ) ) {
			$css[] = 'font-size: ' . $typography['fontSize'] . ';';
		}

		if ( ! empty( $typography['font-weight'] ) ) {
			$css[] = 'font-weight: ' . $typography['font-weight'] . ';';
		} elseif ( ! empty( $typography['fontWeight'] ) ) {
			$css[] = 'font-weight: ' . $typography['fontWeight'] . ';';
		}

		if ( ! empty( $typography['font-family'] ) ) {
			$css[] = 'font-family: ' . $typography['font-family'] . ';';
		} elseif ( ! empty( $typography['fontFamily'] ) ) {
			$css[] = 'font-family: ' . $typography['fontFamily'] . ';';
		}

		if ( ! empty( $typography['font-style'] ) ) {
			$css[] = 'font-style: ' . $typography['font-style'] . ';';
		} elseif ( ! empty( $typography['fontStyle'] ) ) {
			$css[] = 'font-style: ' . $typography['fontStyle'] . ';';
		}

		if ( ! empty( $typography['line-height'] ) ) {
			$css[] = 'line-height: ' . $typography['line-height'] . ';';
		} elseif ( ! empty( $typography['lineHeight'] ) ) {
			$css[] = 'line-height: ' . $typography['lineHeight'] . ';';
		}

		if ( ! empty( $typography['letter-spacing'] ) ) {
			$css[] = 'letter-spacing: ' . $typography['letter-spacing'] . ';';
		} elseif ( ! empty( $typography['letterSpacing'] ) ) {
			$css[] = 'letter-spacing: ' . $typography['letterSpacing'] . ';';
		}

		if ( ! empty( $typography['word-spacing'] ) ) {
			$css[] = 'word-spacing: ' . $typography['word-spacing'] . ';';
		} elseif ( ! empty( $typography['wordSpacing'] ) ) {
			$css[] = 'word-spacing: ' . $typography['wordSpacing'] . ';';
		}

		if ( ! empty( $typography['text-align'] ) ) {
			$css[] = 'text-align: ' . $typography['text-align'] . ';';
		} elseif ( ! empty( $typography['textAlign'] ) ) {
			$css[] = 'text-align: ' . $typography['textAlign'] . ';';
		}

		if ( ! empty( $typography['text-transform'] ) ) {
			$css[] = 'text-transform: ' . $typography['text-transform'] . ';';
		} elseif ( ! empty( $typography['textTransform'] ) ) {
			$css[] = 'text-transform: ' . $typography['textTransform'] . ';';
		}

		if ( ! empty( $typography['text-decoration'] ) ) {
			$css[] = 'text-decoration: ' . $typography['text-decoration'] . ';';
		} elseif ( ! empty( $typography['textDecoration'] ) ) {
			$css[] = 'text-decoration: ' . $typography['textDecoration'] . ';';
		}

		if ( ! empty( $typography['text-indent'] ) ) {
			$css[] = 'text-indent: ' . $typography['text-indent'] . ';';
		} elseif ( ! empty( $typography['textIndent'] ) ) {
			$css[] = 'text-indent: ' . $typography['textIndent'] . ';';
		}

		if ( ! empty( $typography['color'] ) ) {
			$color_value = is_array( $typography['color'] ) ? ( $typography['color']['raw'] ?? '' ) : $typography['color'];
			if ( ! empty( $color_value ) ) {
				$css[] = 'color: ' . $color_value . ';';
			}
		}

		if ( ! empty( $typography['vertical-align'] ) ) {
			$css[] = 'vertical-align: ' . $typography['vertical-align'] . ';';
		} elseif ( ! empty( $typography['verticalAlign'] ) ) {
			$css[] = 'vertical-align: ' . $typography['verticalAlign'] . ';';
		}

		if ( ! empty( $typography['white-space'] ) ) {
			$css[] = 'white-space: ' . $typography['white-space'] . ';';
		} elseif ( ! empty( $typography['whiteSpace'] ) ) {
			$css[] = 'white-space: ' . $typography['whiteSpace'] . ';';
		}

		return $css;
	}

	/**
	 * Convert legacy flat spacing settings (margin / padding shorthand).
	 *
	 * @param array<string,mixed> $spacing Bricks spacing sub-array.
	 * @return array<int,string>
	 */
	public function convert_spacing( array $spacing ): array {
		$css = array();

		if ( ! empty( $spacing['margin'] ) ) {
			$css[] = 'margin: ' . $spacing['margin'] . ';';
		}
		if ( ! empty( $spacing['padding'] ) ) {
			$css[] = 'padding: ' . $spacing['padding'] . ';';
		}

		return $css;
	}

	/**
	 * Convert Bricks _margin / _padding settings to logical CSS declarations.
	 *
	 * Supports both a compound key (_margin as array or shorthand string) and
	 * individual side keys (_marginTop, _marginRight, _marginBottom, _marginLeft).
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_margin_padding( array $settings ): array {
		$css = array();

		$margin_values = $this->extract_quad_values_from_settings(
			$settings,
			'_margin',
			array(
				'top'    => '_marginTop',
				'right'  => '_marginRight',
				'bottom' => '_marginBottom',
				'left'   => '_marginLeft',
			)
		);
		if ( null !== $margin_values['direct'] ) {
			$css[] = 'margin: ' . $margin_values['direct'] . ';';
		}
		$css = array_merge(
			$css,
			$this->build_logical_quad_declarations( 'margin', $margin_values['top'], $margin_values['right'], $margin_values['bottom'], $margin_values['left'] )
		);

		$padding_values = $this->extract_quad_values_from_settings(
			$settings,
			'_padding',
			array(
				'top'    => '_paddingTop',
				'right'  => '_paddingRight',
				'bottom' => '_paddingBottom',
				'left'   => '_paddingLeft',
			)
		);
		if ( null !== $padding_values['direct'] ) {
			$css[] = 'padding: ' . $padding_values['direct'] . ';';
		}
		$css = array_merge(
			$css,
			$this->build_logical_quad_declarations( 'padding', $padding_values['top'], $padding_values['right'], $padding_values['bottom'], $padding_values['left'] )
		);

		return $css;
	}

	/**
	 * Convert position properties to logical equivalents.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_position( array $settings ): array {
		$css = array();

		if ( ! empty( $settings['_position'] ) ) {
			$css[] = 'position: ' . $settings['_position'] . ';';
		}

		$inset_values = $this->extract_quad_values_from_settings(
			$settings,
			null,
			array(
				'top'    => '_top',
				'right'  => '_right',
				'bottom' => '_bottom',
				'left'   => '_left',
			)
		);
		$css          = array_merge(
			$css,
			$this->build_logical_quad_declarations( 'inset', $inset_values['top'], $inset_values['right'], $inset_values['bottom'], $inset_values['left'] )
		);

		return $css;
	}

	/**
	 * Convert transform, transition, filter, shadow, and miscellaneous effect settings.
	 *
	 * @param array<string,mixed> $settings Bricks settings.
	 * @return array<int,string>
	 */
	public function convert_effects( array $settings ): array {
		$css = array();

		// Transform — string or structured array.
		if ( ! empty( $settings['_transform'] ) ) {
			if ( is_string( $settings['_transform'] ) ) {
				$css[] = 'transform: ' . $settings['_transform'] . ';';
			} elseif ( is_array( $settings['_transform'] ) ) {
				$parts = array();
				if ( ! empty( $settings['_transform']['translateX'] ) ) {
					$parts[] = 'translateX(' . $settings['_transform']['translateX'] . 'px)';
				}
				if ( ! empty( $settings['_transform']['translateY'] ) ) {
					$parts[] = 'translateY(' . $settings['_transform']['translateY'] . 'px)';
				}
				if ( ! empty( $settings['_transform']['scaleX'] ) ) {
					$parts[] = 'scaleX(' . $settings['_transform']['scaleX'] . ')';
				}
				if ( ! empty( $settings['_transform']['scaleY'] ) ) {
					$parts[] = 'scaleY(' . $settings['_transform']['scaleY'] . ')';
				}
				if ( ! empty( $settings['_transform']['rotateX'] ) ) {
					$parts[] = 'rotateX(' . $settings['_transform']['rotateX'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['rotateY'] ) ) {
					$parts[] = 'rotateY(' . $settings['_transform']['rotateY'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['rotateZ'] ) ) {
					$parts[] = 'rotateZ(' . $settings['_transform']['rotateZ'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['skewX'] ) ) {
					$parts[] = 'skewX(' . $settings['_transform']['skewX'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['skewY'] ) ) {
					$parts[] = 'skewY(' . $settings['_transform']['skewY'] . 'deg)';
				}
				if ( ! empty( $parts ) ) {
					$css[] = 'transform: ' . implode( ' ', $parts ) . ';';
				}
			}
		}

		if ( ! empty( $settings['_transformOrigin'] ) ) {
			$css[] = 'transform-origin: ' . $settings['_transformOrigin'] . ';';
		}

		// Transition — _transition (legacy) or _cssTransition (newer).
		if ( ! empty( $settings['_transition'] ) && is_string( $settings['_transition'] ) ) {
			$css[] = 'transition: ' . $settings['_transition'] . ';';
		}
		if ( ! empty( $settings['_cssTransition'] ) && is_string( $settings['_cssTransition'] ) ) {
			$css[] = 'transition: ' . $settings['_cssTransition'] . ';';
		}

		// CSS filters — structured array.
		if ( ! empty( $settings['_cssFilters'] ) && is_array( $settings['_cssFilters'] ) ) {
			$filter_parts = array();
			$f            = $settings['_cssFilters'];
			if ( isset( $f['blur'] ) ) {
				$filter_parts[] = 'blur(' . $f['blur'] . 'px)';
			}
			if ( isset( $f['brightness'] ) ) {
				$filter_parts[] = 'brightness(' . $f['brightness'] . '%)';
			}
			if ( isset( $f['contrast'] ) ) {
				$filter_parts[] = 'contrast(' . $f['contrast'] . '%)';
			}
			if ( isset( $f['hue-rotate'] ) ) {
				$filter_parts[] = 'hue-rotate(' . $f['hue-rotate'] . 'deg)';
			}
			if ( isset( $f['invert'] ) ) {
				$filter_parts[] = 'invert(' . $f['invert'] . '%)';
			}
			if ( isset( $f['opacity'] ) ) {
				$filter_parts[] = 'opacity(' . $f['opacity'] . '%)';
			}
			if ( isset( $f['saturate'] ) ) {
				$filter_parts[] = 'saturate(' . $f['saturate'] . '%)';
			}
			if ( isset( $f['sepia'] ) ) {
				$filter_parts[] = 'sepia(' . $f['sepia'] . '%)';
			}
			if ( ! empty( $filter_parts ) ) {
				$css[] = 'filter: ' . implode( ' ', $filter_parts ) . ';';
			}
		}

		// Filter string (alternative to _cssFilters).
		if ( ! empty( $settings['_filter'] ) && is_string( $settings['_filter'] ) ) {
			$css[] = 'filter: ' . $settings['_filter'] . ';';
		}

		if ( ! empty( $settings['_backdropFilter'] ) && is_string( $settings['_backdropFilter'] ) ) {
			$css[] = 'backdrop-filter: ' . $settings['_backdropFilter'] . ';';
		}

		// Box shadow — string or structured array with values/color/inset.
		if ( ! empty( $settings['_boxShadow'] ) ) {
			if ( is_string( $settings['_boxShadow'] ) ) {
				$css[] = 'box-shadow: ' . $settings['_boxShadow'] . ';';
			} elseif ( is_array( $settings['_boxShadow'] ) && ! empty( $settings['_boxShadow']['values'] ) ) {
				$shadow = $settings['_boxShadow']['values'];
				$color  = '';
				if ( ! empty( $settings['_boxShadow']['color'] ) ) {
					$color = is_array( $settings['_boxShadow']['color'] ) ? ( $settings['_boxShadow']['color']['raw'] ?? '' ) : $settings['_boxShadow']['color'];
				}
				$inset    = ! empty( $settings['_boxShadow']['inset'] ) ? 'inset ' : '';
				$offset_x = $shadow['offsetX'] ?? '0';
				$offset_y = $shadow['offsetY'] ?? '0';
				$blur     = $shadow['blur'] ?? '0';
				$spread   = $shadow['spread'] ?? '0';
				$css[]    = 'box-shadow: ' . $inset . $offset_x . 'px ' . $offset_y . 'px ' . $blur . 'px ' . $spread . 'px ' . $color . ';';
			}
		}

		if ( ! empty( $settings['_textShadow'] ) ) {
			$css[] = 'text-shadow: ' . $settings['_textShadow'] . ';';
		}
		if ( ! empty( $settings['_objectFit'] ) ) {
			$css[] = 'object-fit: ' . $settings['_objectFit'] . ';';
		}
		if ( ! empty( $settings['_objectPosition'] ) ) {
			$css[] = 'object-position: ' . $settings['_objectPosition'] . ';';
		}
		if ( ! empty( $settings['_isolation'] ) ) {
			$css[] = 'isolation: ' . $settings['_isolation'] . ';';
		}
		if ( ! empty( $settings['_cursor'] ) ) {
			$css[] = 'cursor: ' . $settings['_cursor'] . ';';
		}
		if ( ! empty( $settings['_mixBlendMode'] ) ) {
			$css[] = 'mix-blend-mode: ' . $settings['_mixBlendMode'] . ';';
		}
		if ( ! empty( $settings['_pointerEvents'] ) ) {
			$css[] = 'pointer-events: ' . $settings['_pointerEvents'] . ';';
		}
		if ( ! empty( $settings['_scrollSnapType'] ) ) {
			$css[] = 'scroll-snap-type: ' . $settings['_scrollSnapType'] . ';';
		}
		if ( ! empty( $settings['_scrollSnapAlign'] ) ) {
			$css[] = 'scroll-snap-align: ' . $settings['_scrollSnapAlign'] . ';';
		}
		if ( ! empty( $settings['_scrollSnapStop'] ) ) {
			$css[] = 'scroll-snap-stop: ' . $settings['_scrollSnapStop'] . ';';
		}

		return $css;
	}

	/**
	 * Convert background settings.
	 *
	 * @param array<string,mixed> $background Bricks _background sub-array.
	 * @return array<int,string>
	 */
	public function convert_background( array $background ): array {
		$css = array();

		if ( ! empty( $background['color'] ) ) {
			$color_value = is_array( $background['color'] ) ? ( $background['color']['raw'] ?? '' ) : $background['color'];
			if ( ! empty( $color_value ) ) {
				$css[] = 'background-color: ' . $color_value . ';';
			}
		}

		if ( ! empty( $background['image']['url'] ) ) {
			$css[] = 'background-image: url(' . $background['image']['url'] . ');';
			if ( ! empty( $background['image']['size'] ) ) {
				$css[] = 'background-size: ' . $background['image']['size'] . ';';
			}
			if ( ! empty( $background['image']['position'] ) ) {
				$css[] = 'background-position: ' . $background['image']['position'] . ';';
			}
			if ( ! empty( $background['image']['repeat'] ) ) {
				$css[] = 'background-repeat: ' . $background['image']['repeat'] . ';';
			}
		}

		return $css;
	}

	/**
	 * Convert gradient settings to a CSS background-image declaration.
	 *
	 * @param array<string,mixed> $gradient Bricks _gradient sub-array.
	 * @return string Empty string when no gradient data is present.
	 */
	public function convert_gradient( array $gradient ): string {
		if ( empty( $gradient['colors'] ) || ! is_array( $gradient['colors'] ) ) {
			return '';
		}

		$color_stops = array();
		foreach ( $gradient['colors'] as $color_data ) {
			if ( is_array( $color_data['color'] ) ) {
				$color_value = $color_data['color']['raw'] ?? '';
			} else {
				$color_value = $color_data['color'] ?? '';
			}

			if ( empty( $color_value ) ) {
				continue;
			}

			if ( ! empty( $color_data['stop'] ) ) {
				$color_stops[] = $color_value . ' ' . $this->normalizer->normalize_gradient_stop( $color_data['stop'] );
			} else {
				$color_stops[] = $color_value;
			}
		}

		if ( empty( $color_stops ) ) {
			return '';
		}

		$gradient_type = $gradient['type'] ?? 'linear';
		if ( 'radial' === $gradient_type ) {
			return 'background-image: radial-gradient(' . implode( ', ', $color_stops ) . ');';
		}

		return 'background-image: linear-gradient(' . implode( ', ', $color_stops ) . ');';
	}

	/**
	 * Convert border settings.
	 *
	 * @param array<string,mixed> $border Bricks _border sub-array.
	 * @return array<int,string>
	 */
	public function convert_border_properties( array $border ): array {
		$css = array();

		if ( ! empty( $border['width'] ) ) {
			if ( is_string( $border['width'] ) ) {
				$css[] = 'border-width: ' . $this->normalizer->normalize_border_width_value( $border['width'] ) . ';';
			} elseif ( is_array( $border['width'] ) ) {
				$top   = $this->normalizer->normalize_border_width_component( $border['width']['top'] ?? '0' );
				$right = $this->normalizer->normalize_border_width_component( $border['width']['right'] ?? '0' );
				$bot   = $this->normalizer->normalize_border_width_component( $border['width']['bottom'] ?? '0' );
				$left  = $this->normalizer->normalize_border_width_component( $border['width']['left'] ?? '0' );
				$css[] = 'border-width: ' . $this->normalizer->build_quad_shorthand_value( $top, $right, $bot, $left ) . ';';
			}
		}

		if ( ! empty( $border['style'] ) ) {
			$css[] = 'border-style: ' . $border['style'] . ';';
		}

		if ( ! empty( $border['color'] ) ) {
			$color_value = is_array( $border['color'] ) ? ( $border['color']['raw'] ?? '' ) : $border['color'];
			if ( ! empty( $color_value ) ) {
				$css[] = 'border-color: ' . $color_value . ';';
			}
		}

		if ( ! empty( $border['radius'] ) ) {
			if ( is_string( $border['radius'] ) ) {
				$css[] = 'border-radius: ' . $border['radius'] . ';';
			} elseif ( is_array( $border['radius'] ) ) {
				$top   = $border['radius']['top'] ?? '0';
				$right = $border['radius']['right'] ?? '0';
				$bot   = $border['radius']['bottom'] ?? '0';
				$left  = $border['radius']['left'] ?? '0';
				$css[] = 'border-radius: ' . $this->normalizer->build_quad_shorthand_value( $top, $right, $bot, $left ) . ';';
			}
		}

		return $css;
	}

	// =========================================================================
	// Quad-value helpers
	// =========================================================================

	/**
	 * Extract quad values (top/right/bottom/left) from combined and individual settings.
	 *
	 * Checks the compound key first (as array with top/right/bottom/left sub-keys,
	 * or as a shorthand string), then individual side keys.  Individual side keys
	 * win over compound array sub-keys (more specific override less specific).
	 *
	 * @param array<string,mixed>  $settings     Source settings.
	 * @param string|null          $compound_key Key containing array or string quad value (nullable).
	 * @param array<string,string> $side_keys    Map: top/right/bottom/left → setting key.
	 * @return array{direct:string|null,top:string|null,right:string|null,bottom:string|null,left:string|null}
	 */
	public function extract_quad_values_from_settings( array $settings, $compound_key, array $side_keys ): array {
		$values = array(
			'direct' => null,
			'top'    => null,
			'right'  => null,
			'bottom' => null,
			'left'   => null,
		);

		if ( null !== $compound_key && array_key_exists( $compound_key, $settings ) ) {
			$compound_value = $settings[ $compound_key ];
			if ( is_array( $compound_value ) ) {
				foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
					if ( array_key_exists( $side, $compound_value ) && '' !== (string) $compound_value[ $side ] ) {
						$values[ $side ] = (string) $compound_value[ $side ];
					}
				}
			} elseif ( '' !== (string) $compound_value ) {
				$values['direct'] = (string) $compound_value;
			}
		}

		// Individual side keys always win.
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$key = $side_keys[ $side ] ?? null;
			if ( null !== $key && array_key_exists( $key, $settings ) && '' !== (string) $settings[ $key ] ) {
				$values[ $side ] = (string) $settings[ $key ];
			}
		}

		return $values;
	}

	/**
	 * Build logical block/inline declarations from quad values.
	 *
	 * When all four values are present a shorthand is emitted (margin: T R B L).
	 * When only pairs are present block/inline shorthands are used where possible.
	 * Otherwise individual longhand declarations are emitted.
	 *
	 * @param string      $property Base property name (margin, padding, inset).
	 * @param string|null $top      Top value.
	 * @param string|null $right    Right value.
	 * @param string|null $bottom   Bottom value.
	 * @param string|null $left     Left value.
	 * @return array<int,string>
	 */
	public function build_logical_quad_declarations( string $property, $top, $right, $bottom, $left ): array {
		$css = array();

		$has_all_sides = null !== $top && null !== $right && null !== $bottom && null !== $left;
		if ( $has_all_sides ) {
			$css[] = $property . ': ' . $this->normalizer->build_quad_shorthand_value( $top, $right, $bottom, $left ) . ';';
			return $css;
		}

		$block_equal  = null !== $top && null !== $bottom && (string) $top === (string) $bottom;
		$inline_equal = null !== $right && null !== $left && (string) $right === (string) $left;

		if ( $block_equal ) {
			$css[] = $property . '-block: ' . $top . ';';
		} else {
			if ( null !== $top ) {
				$css[] = $property . '-block-start: ' . $top . ';';
			}
			if ( null !== $bottom ) {
				$css[] = $property . '-block-end: ' . $bottom . ';';
			}
		}

		if ( $inline_equal ) {
			$css[] = $property . '-inline: ' . $right . ';';
		} else {
			if ( null !== $right ) {
				$css[] = $property . '-inline-end: ' . $right . ';';
			}
			if ( null !== $left ) {
				$css[] = $property . '-inline-start: ' . $left . ';';
			}
		}

		return $css;
	}
}
