<?php
/**
 * CSS Normalizer
 *
 * Pure CSS string-transformation utilities extracted from EFS_CSS_Converter.
 * All methods in this class are stateless — they receive a CSS string and
 * return a transformed string.  No WordPress functions are required at all,
 * making the class fully unit-testable without a WP bootstrap.
 *
 * Responsibilities:
 *   - Translate deprecated HSL color tokens to modern equivalents
 *   - Convert Bricks/Framer CSS custom-property aliases to Etch variables
 *   - Fix invalid grid shorthand patterns emitted by Bricks
 *   - Collapse logical quad longhands (margin-block-start etc.) to shortest
 *     valid shorthands
 *   - Convert physical CSS properties (width, height, top, …) to their
 *     logical counterparts (inline-size, block-size, inset-block-start, …)
 *   - Normalise border-width, gradient-stop, and content-property values
 *   - Rename Bricks element ID selectors (#brxe-…) to Etch equivalents
 *     (#etch-…) in raw CSS strings
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless CSS normalization helpers.
 *
 * All public methods accept a raw CSS string (or scalar value) and return
 * the normalised equivalent.  No state is maintained between calls.
 */
class EFS_CSS_Normalizer {

	/**
	 * Run the full post-migration CSS cleanup pipeline over a single style block.
	 *
	 * This is intended to be called once per style entry after all other
	 * conversion steps are complete.  The order matters:
	 *   1. Normalise deprecated HSL variable references (safe, no side effects).
	 *   2. Fix invalid grid shorthand patterns (Bricks bug: "span X / Y").
	 *
	 * @param string $css CSS declaration block or nested rule set.
	 * @return string
	 */
	public function normalize_final_css( string $css ): string {
		$css = $this->normalize_deprecated_hsl_references( $css );
		$css = $this->normalize_invalid_grid_placement( $css );
		return $css;
	}

	/**
	 * Fix invalid grid shorthand that mixes span with explicit line numbers.
	 *
	 * Bricks occasionally emits "grid-column: span 1 / -1" which is invalid CSS —
	 * a value must be either span-based ("span N") or line-based ("A / B"), never
	 * both at once.  We drop the "span" keyword so the result is line-based, which
	 * preserves the author's intent in almost all cases.
	 *
	 * Valid:   grid-column: span 2;     (occupy 2 columns)
	 * Valid:   grid-column: 1 / -1;     (from line 1 to last line)
	 * Invalid: grid-column: span 2 / -1 → fixed to: grid-column: 2 / -1
	 *
	 * @param string $css CSS fragment.
	 * @return string
	 */
	public function normalize_invalid_grid_placement( string $css ): string {
		if ( '' === $css || ( false === strpos( $css, 'grid-column' ) && false === strpos( $css, 'grid-row' ) ) ) {
			return $css;
		}

		$out = preg_replace( '/grid-column\s*:\s*span\s+(\d+)\s*\/\s*([^;]+?)(;|\s*})/i', 'grid-column: $1 / $2$3', $css );
		$out = preg_replace( '/grid-row\s*:\s*span\s+(\d+)\s*\/\s*([^;]+?)(;|\s*})/i', 'grid-row: $1 / $2$3', is_string( $out ) ? $out : $css );
		return is_string( $out ) ? $out : $css;
	}

	/**
	 * Convert an alpha value (from the legacy hsl(…/a) syntax) to a percentage
	 * suitable for use as the mix amount in color-mix().
	 *
	 * Mapping rules:
	 *   - Already a percentage:  returned as-is
	 *   - Numeric ≤ 1:           multiplied by 100 (e.g. 0.5 → "50%")
	 *   - Numeric > 1:           treated as a percentage already
	 *   - CSS expression:        wrapped in calc(… * 100%)
	 *   - Empty string:          returns "100%"
	 *
	 * @param string $alpha Raw alpha token.
	 * @return string
	 */
	public function normalize_alpha_to_percentage( string $alpha ): string {
		$value = trim( $alpha );
		if ( '' === $value ) {
			return '100%';
		}

		// Already formatted as a percentage.
		if ( preg_match( '/^\d+(\.\d+)?%$/', $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			$float_value = (float) $value;
			if ( $float_value <= 1 ) {
				$float_value *= 100;
			}
			$float_value = max( 0, min( 100, $float_value ) );
			$formatted   = rtrim( rtrim( sprintf( '%.4f', $float_value ), '0' ), '.' );
			return $formatted . '%';
		}

		// Keep dynamic expressions usable in color-mix().
		return 'calc(' . $value . ' * 100%)';
	}

	/**
	 * Normalise deprecated ACSS v3 hsl() color token references to migration-safe
	 * CSS equivalents.
	 *
	 * ACSS v3 used HSL channel tokens for opacity variants, e.g.:
	 *   hsl(var(--primary-hsl) / 0.08)
	 *   hsl(var(--primary-hsl))
	 *   var(--primary-hsl)
	 *
	 * ACSS v4 and Etch use color-mix() or bare custom properties:
	 *   color-mix(in oklab, var(--primary) 8%, transparent)
	 *   var(--primary)
	 *
	 * The three transformation rules applied in order:
	 *   1. hsl(var(--token-hsl) / alpha)  →  color-mix(in oklab, var(--token) α%, transparent)
	 *   2. hsl(var(--token-hsl))          →  var(--token)
	 *   3. var(--token-hsl)               →  var(--token)
	 *
	 * @param string $css CSS fragment (any length, may include selectors).
	 * @return string
	 */
	public function normalize_deprecated_hsl_references( string $css ): string {
		if ( '' === $css || false === strpos( $css, '-hsl' ) ) {
			return $css;
		}

		// Rule 1: hsl(var(--token-hsl) / alpha) → color-mix(…)
		$normalized = preg_replace_callback(
			'/hsl\(\s*var\(\s*--([a-z0-9_-]+)-hsl\s*\)\s*(?:\/\s*([^)]+))?\)/i',
			function ( $matches ) {
				$token = strtolower( (string) $matches[1] );
				$alpha = isset( $matches[2] ) ? trim( (string) $matches[2] ) : '';
				if ( '' !== $alpha ) {
					return 'color-mix(in oklab, var(--' . $token . ') ' . $this->normalize_alpha_to_percentage( $alpha ) . ', transparent)';
				}
				return 'var(--' . $token . ')';
			},
			$css
		);

		if ( ! is_string( $normalized ) ) {
			$normalized = $css;
		}

		// Rule 3: bare var(--token-hsl) → var(--token)
		$normalized = preg_replace_callback(
			'/var\(\s*--([a-z0-9_-]+)-hsl\s*\)/i',
			static function ( $matches ) {
				$token = strtolower( (string) $matches[1] );
				return 'var(--' . $token . ')';
			},
			$normalized
		);

		return is_string( $normalized ) ? $normalized : $css;
	}

	/**
	 * Translate deprecated ACSS v3 color-token references (--name-hsl) in a
	 * declaration block to the v4 channel format.
	 *
	 * This is the single-declaration version of normalize_deprecated_hsl_references();
	 * use it when processing a property-value string that does NOT include selectors
	 * or braces (e.g. the output of get_acss_declarations_for_class()).
	 *
	 * Example:
	 *   background-color: var(--primary-hsl);
	 *   → background-color: var(--primary);
	 *
	 * @param string $declarations CSS declaration block (no selectors/braces).
	 * @return string
	 */
	public function normalize_acss_deprecated_hsl_tokens( string $declarations ): string {
		if ( '' === $declarations || false === strpos( $declarations, '-hsl' ) ) {
			return $declarations;
		}

		$normalized = preg_replace_callback(
			'/var\(\s*--([a-z0-9_-]+)-hsl\s*\)/i',
			static function ( $matches ) {
				$token = strtolower( (string) $matches[1] );
				return 'var(--' . $token . ')';
			},
			$declarations
		);

		return is_string( $normalized ) ? $normalized : $declarations;
	}

	/**
	 * Normalise known Bricks/Framer CSS custom-property aliases to their Etch
	 * equivalents, and remove invalid gap declarations.
	 *
	 * Transformations applied:
	 *  - Bare identifier values for gap/row-gap/column-gap that are not CSS-wide
	 *    keywords are dropped (they are invalid CSS and were often Bricks artifacts).
	 *  - Duplicate row-gap + column-gap declarations with identical values are
	 *    collapsed to a single gap shorthand.
	 *  - --fr-container-gap  →  var(--container-gap)
	 *  - --fr-card-gap       →  var(--card-gap, var(--content-gap))
	 *  - --fr-card-padding   →  --card-padding  (declaration + usage)
	 *  - [class*=brxe-]      →  empty string   (specificity-boost selector, no Etch equivalent)
	 *  - .fr-*               →  .* (strip site-specific fr- prefix from selectors)
	 *  - --token-trans-N     →  color-mix(in oklch, var(--token) N%, transparent)
	 *
	 * This method is also called by convert_to_logical_properties() before
	 * the physical-to-logical property renaming pass.
	 *
	 * @param string $css Raw CSS string.
	 * @return string
	 */
	public function normalize_css_variables( string $css ): string {
		if ( '' === $css ) {
			return $css;
		}

		// Drop bare-identifier gap values that are not CSS-wide keywords.
		$css = preg_replace_callback(
			'/\b(row-gap|column-gap|gap)\s*:\s*([a-z][a-z0-9_-]*)\s*;/i',
			static function ( $matches ) {
				$property = strtolower( (string) $matches[1] );
				$value    = strtolower( (string) $matches[2] );
				$keywords = array( 'normal', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' );
				if ( in_array( $value, $keywords, true ) ) {
					return $property . ': ' . $value . ';';
				}
				// Bare identifiers are invalid for gap — drop the declaration.
				return '';
			},
			$css
		);

		// Collapse identical row-gap + column-gap pairs to gap shorthand.
		$css = preg_replace( '/row-gap:\s*([^;{}]+);\s*column-gap:\s*\1\s*;/i', 'gap: $1;', $css );
		$css = preg_replace( '/column-gap:\s*([^;{}]+);\s*row-gap:\s*\1\s*;/i', 'gap: $1;', $css );

		// Collapse logical longhands to shortest valid shorthands.
		$css = $this->normalize_identical_shorthands( $css );

		// Rename project-specific custom property usages.
		$css = preg_replace( '/var\(\s*--fr-container-gap\s*\)/i', 'var(--container-gap)', $css );
		$css = preg_replace( '/var\(\s*--fr-card-gap\s*\)/i', 'var(--card-gap, var(--content-gap))', $css );
		$css = preg_replace( '/--fr-card-padding\s*:/i', '--card-padding:', $css );
		$css = preg_replace( '/var\(\s*--fr-card-padding\s*\)/i', 'var(--card-padding)', $css );

		// Remove Bricks specificity-boost selector — it has no equivalent in Etch.
		$css = str_replace( '[class*=brxe-]', '', $css );

		// Strip site-specific fr- prefix from class selectors (e.g. .fr-card → .card).
		$css = preg_replace( '/(?<=\.)fr-([a-zA-Z0-9_-])/', '$1', $css );

		// --token-trans-N → color-mix(in oklch, var(--token) N%, transparent)
		$css = preg_replace_callback(
			'/var\(\s*--([a-z0-9_-]+?)-trans-([0-9]{1,3})\s*\)/i',
			static function ( $matches ) {
				$base_color = strtolower( (string) $matches[1] );
				$percent    = max( 0, min( 100, (int) $matches[2] ) );
				return 'color-mix(in oklch, var(--' . $base_color . ') ' . $percent . '%, transparent)';
			},
			$css
		);

		return $css;
	}

	/**
	 * Collapse logical longhands (e.g. margin-block-start, padding-inline-end, …)
	 * to the shortest valid shorthand (1 / 2 / 3 / 4 values).
	 *
	 * This processes both declaration blocks that appear inside selector braces and
	 * bare declaration strings (no selector/braces), which are common in the Etch
	 * style payload format.
	 *
	 * @param string $css Raw CSS string.
	 * @return string
	 */
	public function normalize_identical_shorthands( string $css ): string {
		if ( '' === $css ) {
			return $css;
		}

		// Handle rules wrapped in braces.
		$css = preg_replace_callback(
			'/\{([^{}]*)\}/s',
			function ( $matches ) {
				return '{' . $this->collapse_known_quad_shorthands( $matches[1] ) . '}';
			},
			$css
		);

		// Also normalize bare declaration strings (no selector braces).
		if ( false === strpos( $css, '{' ) && false === strpos( $css, '}' ) ) {
			$css = $this->collapse_known_quad_shorthands( $css );
		}

		return $css;
	}

	/**
	 * Apply all known quad-shorthand collapse rules to a declaration list.
	 *
	 * Handles the following property groups (top/right/bottom/left order):
	 *   - inset-block-start / inset-inline-end / inset-block-end / inset-inline-start → inset
	 *   - padding-block-start / … → padding
	 *   - margin-block-start / … → margin
	 *   - border-start-start-radius / … → border-radius  (logical radius notation)
	 *   - border-top-left-radius / … → border-radius    (physical radius notation)
	 *
	 * @param string $decls Declaration block without wrapping braces.
	 * @return string
	 */
	public function collapse_known_quad_shorthands( string $decls ): string {
		$decls = $this->collapse_quad_shorthand(
			$decls,
			array( 'inset-block-start', 'inset-inline-end', 'inset-block-end', 'inset-inline-start' ),
			'inset'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array( 'padding-block-start', 'padding-inline-end', 'padding-block-end', 'padding-inline-start' ),
			'padding'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array( 'margin-block-start', 'margin-inline-end', 'margin-block-end', 'margin-inline-start' ),
			'margin'
		);

		// Logical border-radius longhands.
		$decls = $this->collapse_quad_shorthand(
			$decls,
			array( 'border-start-start-radius', 'border-start-end-radius', 'border-end-end-radius', 'border-end-start-radius' ),
			'border-radius'
		);

		// Physical border-radius longhands (also common in Bricks output).
		$decls = $this->collapse_quad_shorthand(
			$decls,
			array( 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius' ),
			'border-radius'
		);

		return $decls;
	}

	/**
	 * Collapse four longhand declarations to one shorthand using the shortest valid
	 * 1/2/3/4 syntax.  If any of the four longhands is missing the declaration
	 * block is returned unchanged.
	 *
	 * The four properties are expected in top/right/bottom/left order.
	 *
	 * @param string            $decls      Declaration block without wrapping braces.
	 * @param array<int,string> $properties Ordered list of exactly four longhand properties.
	 * @param string            $shorthand  Target shorthand property name.
	 * @return string
	 */
	public function collapse_quad_shorthand( string $decls, array $properties, string $shorthand ): string {
		$values = array();

		foreach ( $properties as $property ) {
			$pattern = '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;{}]+)\s*;/i';
			if ( ! preg_match( $pattern, $decls, $match ) ) {
				// All four must be present — abort without change.
				return $decls;
			}
			$values[] = trim( (string) $match[1] );
		}

		$shorthand_value = $this->build_quad_shorthand_value( $values[0], $values[1], $values[2], $values[3] );

		// Remove the individual longhand declarations.
		foreach ( $properties as $property ) {
			$decls = preg_replace( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*[^;{}]+\s*;/i', ';', $decls );
		}

		$decls = rtrim( preg_replace( '/\s+/', ' ', $decls ) );
		$decls = preg_replace( '/;{2,}/', ';', $decls );
		$decls = trim( (string) $decls );

		if ( '' !== $decls && ';' !== substr( $decls, -1 ) ) {
			$decls .= ';';
		}

		return trim( $decls . ' ' . $shorthand . ': ' . $shorthand_value . ';' );
	}

	/**
	 * Build the shortest valid CSS quad shorthand value (top right bottom left).
	 *
	 * CSS shorthand reduction rules:
	 *   all equal             → "T"
	 *   top == bottom AND right == left  → "T R"
	 *   right == left (only)  → "T R B"
	 *   all different         → "T R B L"
	 *
	 * @param string $top    Top value.
	 * @param string $right  Right value.
	 * @param string $bottom Bottom value.
	 * @param string $left   Left value.
	 * @return string
	 */
	public function build_quad_shorthand_value( string $top, string $right, string $bottom, string $left ): string {
		if ( $top === $right && $top === $bottom && $top === $left ) {
			return $top;
		}

		if ( $top === $bottom && $right === $left ) {
			return $top . ' ' . $right;
		}

		if ( $right === $left ) {
			return $top . ' ' . $right . ' ' . $bottom;
		}

		return $top . ' ' . $right . ' ' . $bottom . ' ' . $left;
	}

	/**
	 * Normalise a border-width shorthand string, adding "px" units to any numeric
	 * token that lacks them.
	 *
	 * Bricks UI can store unitless numbers for border widths. CSS requires explicit
	 * units except for zero ("0").  Shorthand strings such as "1 2 1 2" are split on
	 * whitespace and each component normalised individually.
	 *
	 * @param string $value Raw border-width value.
	 * @return string
	 */
	public function normalize_border_width_value( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return $value;
		}

		// Only touch purely numeric shorthands (e.g. "1 1 1 1").
		if ( preg_match( '/^[0-9\.\s]+$/', $value ) ) {
			$parts = preg_split( '/\s+/', $value );
			$parts = is_array( $parts ) ? $parts : array( $value );
			$parts = array_map( array( $this, 'normalize_border_width_component' ), $parts );
			$parts = array_values(
				array_filter(
					$parts,
					static function ( $part ) {
						return '' !== trim( (string) $part );
					}
				)
			);
			return implode( ' ', $parts );
		}

		return $this->normalize_border_width_component( $value );
	}

	/**
	 * Normalise a single border-width component by appending "px" when the value is
	 * a plain integer or float without units.
	 *
	 * Zero (or "0.0") is kept as "0" per the CSS specification (unitless zero is
	 * valid for length properties).
	 *
	 * @param string $component Raw component value (e.g. "2", "0", "2px", "thin").
	 * @return string
	 */
	public function normalize_border_width_component( string $component ): string {
		$component = trim( $component );
		if ( '' === $component ) {
			return $component;
		}

		if ( preg_match( '/^-?(?:0|0+\.[0-9]+)$/', $component ) ) {
			return '0';
		}

		if ( preg_match( '/^-?[0-9]+(?:\.[0-9]+)?$/', $component ) ) {
			return $component . 'px';
		}

		return $component;
	}

	/**
	 * Normalise a gradient color-stop position.
	 *
	 * Bricks can store plain numbers for stop positions (e.g. "50").  CSS
	 * color-stop positions require units, so numeric values are converted to
	 * percentages.  Values that already carry units are returned as-is.
	 *
	 * @param mixed $stop Raw stop value from Bricks settings.
	 * @return string
	 */
	public function normalize_gradient_stop( $stop ): string {
		$stop = trim( (string) $stop );
		if ( '' === $stop ) {
			return $stop;
		}

		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $stop ) ) {
			return $stop . '%';
		}

		return $stop;
	}

	/**
	 * Normalise values for the CSS `content` property.
	 *
	 * Handles:
	 *  - CSS-wide keywords (normal, none, …): returned lowercased
	 *  - Functional values attr(), counter(), counters(): returned as-is
	 *  - Already-quoted strings: returned as-is
	 *  - Everything else: wrapped in double quotes with internal quotes escaped
	 *
	 * @param mixed $value Raw value from Bricks settings.
	 * @return string
	 */
	public function normalize_content_property_value( $value ): string {
		$content = trim( (string) $value );
		if ( '' === $content ) {
			return '';
		}

		$keywords = array(
			'normal',
			'none',
			'open-quote',
			'close-quote',
			'no-open-quote',
			'no-close-quote',
			'inherit',
			'initial',
			'unset',
			'revert',
			'revert-layer',
		);
		if ( in_array( strtolower( $content ), $keywords, true ) ) {
			return strtolower( $content );
		}

		if ( preg_match( '/^(attr|counter|counters)\s*\(/i', $content ) ) {
			return $content;
		}

		if ( preg_match( '/^([\'"]).*\\1$/s', $content ) ) {
			return $content;
		}

		return '"' . addcslashes( $content, '\\"' ) . '"';
	}

	/**
	 * Convert physical CSS properties to their logical equivalents in a CSS string.
	 *
	 * The following mapping is applied to all declarations that appear OUTSIDE of
	 * @media / @container at-rules (media-query conditions themselves use width /
	 * height deliberately — those must NOT be renamed):
	 *
	 *   Physical         → Logical
	 *   ─────────────────────────────────────
	 *   margin-top       → margin-block-start
	 *   margin-right     → margin-inline-end
	 *   margin-bottom    → margin-block-end
	 *   margin-left      → margin-inline-start
	 *   padding-top      → padding-block-start
	 *   padding-right    → padding-inline-end
	 *   padding-bottom   → padding-block-end
	 *   padding-left     → padding-inline-start
	 *   border-top       → border-block-start
	 *   border-right     → border-inline-end
	 *   border-bottom    → border-block-end
	 *   border-left      → border-inline-start
	 *   top              → inset-block-start
	 *   right            → inset-inline-end
	 *   bottom           → inset-block-end
	 *   left             → inset-inline-start
	 *   width            → inline-size
	 *   height           → block-size
	 *   min-width        → min-inline-size
	 *   min-height       → min-block-size
	 *   max-width        → max-inline-size
	 *   max-height       → max-block-size
	 *
	 * CSS custom properties (--my-width etc.) are protected by a negative
	 * lookbehind so they are never renamed.
	 *
	 * @param string $css CSS string (may include nested selectors, media queries, etc.).
	 * @return string
	 */
	public function convert_to_logical_properties( string $css ): string {
		// Normalise variable aliases first so downstream passes see clean names.
		$css = $this->normalize_css_variables( $css );

		$property_map = array(
			'margin-top'     => 'margin-block-start',
			'margin-right'   => 'margin-inline-end',
			'margin-bottom'  => 'margin-block-end',
			'margin-left'    => 'margin-inline-start',
			'padding-top'    => 'padding-block-start',
			'padding-right'  => 'padding-inline-end',
			'padding-bottom' => 'padding-block-end',
			'padding-left'   => 'padding-inline-start',
			'border-top'     => 'border-block-start',
			'border-right'   => 'border-inline-end',
			'border-bottom'  => 'border-block-end',
			'border-left'    => 'border-inline-start',
			'top'            => 'inset-block-start',
			'right'          => 'inset-inline-end',
			'bottom'         => 'inset-block-end',
			'left'           => 'inset-inline-start',
			'width'          => 'inline-size',
			'height'         => 'block-size',
			'min-width'      => 'min-inline-size',
			'min-height'     => 'min-block-size',
			'max-width'      => 'max-inline-size',
			'max-height'     => 'max-block-size',
		);

		// Extract media/container queries and replace them with placeholders so the
		// property renaming pass does not touch width/height inside @media conditions.
		$at_rules           = array();
		$placeholder_prefix = '___AT_RULE_';
		$placeholder_count  = 0;

		$css = preg_replace_callback(
			'/@media[^{}]+\{[^}]*\}/s',
			static function ( $match_data ) use ( &$at_rules, &$placeholder_count, $placeholder_prefix ) {
				$placeholder              = $placeholder_prefix . $placeholder_count . '___';
				$at_rules[ $placeholder ] = $match_data[0];
				++$placeholder_count;
				return $placeholder;
			},
			$css
		);

		// Rename physical → logical, but never touch CSS custom properties.
		foreach ( $property_map as $physical => $logical ) {
			$css = preg_replace( '/(?<![a-zA-Z0-9_-])' . preg_quote( $physical, '/' ) . '\s*:/i', $logical . ':', $css );
		}

		// Restore the original at-rule blocks.
		foreach ( $at_rules as $placeholder => $original ) {
			$css = str_replace( $placeholder, $original, $css );
		}

		return $css;
	}

	/**
	 * Replace Bricks element ID selectors in CSS with their Etch equivalents.
	 *
	 * Bricks generates HTML id attributes in the form "brxe-abc1234".  The CSS
	 * selectors that reference them use "#brxe-abc1234".  After migration the IDs
	 * are renamed to "etch-abc1234", so the corresponding CSS selectors must also
	 * be updated.
	 *
	 * @param string $css Raw CSS string.
	 * @return string
	 */
	public function normalize_bricks_id_selectors_in_css( string $css ): string {
		if ( '' === $css ) {
			return $css;
		}

		return (string) preg_replace_callback(
			'/#brxe-([a-zA-Z0-9_-]+)/i',
			static function ( $matches ) {
				return '#etch-' . $matches[1];
			},
			$css
		);
	}
}
