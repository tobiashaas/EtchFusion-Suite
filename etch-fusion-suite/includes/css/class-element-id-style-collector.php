<?php
/**
 * Element ID Style Collector
 *
 * Collects element-scoped CSS from Bricks page content and converts it to
 * Etch-compatible ID-selector style entries.
 *
 * In Bricks, every element on a page has an element ID (e.g. brxe-a1b2c3d).
 * When the user sets inline styles on an element (rather than on a global class),
 * those settings are stored in the element's `settings` array inside the page-
 * content meta.  Etch uses the same mechanism but prefixes IDs with "etch-".
 *
 * This collector:
 * 1. Loads all Bricks-backed posts of the selected post types.
 * 2. Walks each element tree and converts element settings to CSS declarations.
 * 3. Accumulates the CSS under the corresponding Etch element ID selector.
 * 4. Also captures per-element custom CSS (`_cssCustom`, `_cssCustom:*`) and
 *    wraps them in the correct @media context.
 *
 * Additionally provides two utility methods called by the main orchestrator:
 * - map_bricks_element_id_to_etch_selector_id() — used both here and for global-class CSS.
 * - move_image_fit_properties_to_nested_img()   — post-processes image-class CSS.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and converts element-level inline styles from Bricks page content.
 */
class EFS_Element_ID_Style_Collector {

	/**
	 * Converts Bricks element settings objects to CSS declaration strings.
	 *
	 * @var EFS_Settings_CSS_Converter
	 */
	private $settings_converter;

	/**
	 * Performs pure CSS string transformations (logical properties, HSL normalisation, …).
	 *
	 * @var EFS_CSS_Normalizer
	 */
	private $normalizer;

	/**
	 * Resolves breakpoint keys to @media rule strings.
	 *
	 * @var EFS_Breakpoint_Resolver
	 */
	private $breakpoint_resolver;

	/**
	 * @param EFS_Settings_CSS_Converter $settings_converter Converts settings → CSS.
	 * @param EFS_CSS_Normalizer         $normalizer         CSS string transformer.
	 * @param EFS_Breakpoint_Resolver    $breakpoint_resolver Media query builder.
	 */
	public function __construct(
		EFS_Settings_CSS_Converter $settings_converter,
		EFS_CSS_Normalizer $normalizer,
		EFS_Breakpoint_Resolver $breakpoint_resolver
	) {
		$this->settings_converter  = $settings_converter;
		$this->normalizer          = $normalizer;
		$this->breakpoint_resolver = $breakpoint_resolver;
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Collect inline element styles from all selected Bricks posts.
	 *
	 * For each Bricks element that has non-empty settings, the element's inline
	 * CSS declarations are merged under the Etch element-ID selector (e.g.
	 * "#etch-a1b2c3d").  Custom CSS snippets from `_cssCustom` and its breakpoint
	 * variants (`_cssCustom:tablet_portrait`) are concatenated into a single
	 * $custom_css string for further processing by the stylesheet parser.
	 *
	 * @param array<int,string> $selected_post_types Post types to scan.
	 *                                               Defaults to post, page, bricks_template.
	 * @return array{styles: array<string,array<string,mixed>>, custom_css: string}
	 */
	public function collect_element_id_styles_from_posts( array $selected_post_types = array() ): array {
		$result = array(
			'styles'     => array(),
			'custom_css' => '',
		);

		// Safety guard: WordPress query functions must be available.
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return $result;
		}

		// Sanitise and default post-type list.
		$post_types = ! empty( $selected_post_types )
			? $selected_post_types
			: array( 'post', 'page', 'bricks_template' );

		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);

		if ( empty( $post_types ) ) {
			return $result;
		}

		// Query only posts with Bricks page content — avoids scanning entire post table.
		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => '_bricks_page_content_2',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bricks_page_content',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			// Prefer v2 content; fall back to legacy meta key.
			$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
			if ( empty( $elements ) ) {
				$elements = get_post_meta( $post->ID, '_bricks_page_content', true );
			}
			if ( is_string( $elements ) ) {
				$elements = maybe_unserialize( $elements );
			}
			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
					? $element['settings']
					: array();

				if ( empty( $settings ) ) {
					continue;
				}

				$this->process_element_settings( $element, $settings, $result );
			}
		}

		return $result;
	}

	/**
	 * Map a Bricks element's raw id attribute to the corresponding Etch selector ID.
	 *
	 * This handles ELEMENT IDs (type-1) — the HTML id attribute placed on each
	 * DOM node by Bricks/Etch — NOT Style Manager IDs (type-2, 7-char hex strings).
	 *
	 * Conversion rules:
	 *   brxe-abc1234 → etch-abc1234  (strip "brxe-", add "etch-")
	 *   etch-abc1234 → etch-abc1234  (already converted, pass through)
	 *   abc1234      → etch-abc1234  (bare ID, prefix with "etch-")
	 *
	 * Returns an empty string when the element has no id or the id is malformed.
	 *
	 * @param array<string,mixed> $element Bricks element array (must contain 'id' key).
	 * @return string Etch element id without leading #, or empty string.
	 */
	public function map_bricks_element_id_to_etch_selector_id( array $element ): string {
		$raw_id = isset( $element['id'] ) ? trim( (string) $element['id'] ) : '';
		if ( '' === $raw_id ) {
			return '';
		}

		// Strip any accidental leading hash.
		$raw_id = ltrim( $raw_id, '#' );
		if ( '' === $raw_id ) {
			return '';
		}

		// Already an Etch ID — return as-is.
		if ( 0 === strpos( $raw_id, 'etch-' ) ) {
			return $raw_id;
		}

		// Bricks element ID — swap the prefix.
		if ( 0 === strpos( $raw_id, 'brxe-' ) ) {
			return 'etch-' . substr( $raw_id, 5 );
		}

		// Bare ID (no recognised prefix) — sanitise and add "etch-".
		$normalized = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_id );
		$normalized = is_string( $normalized ) ? trim( $normalized ) : '';

		return '' !== $normalized ? 'etch-' . $normalized : '';
	}

	/**
	 * Resolve the Bricks root selector token for `%root%` substitution in custom CSS.
	 *
	 * Custom CSS in Bricks uses `%root%` as a placeholder for the current element's
	 * root selector.  This method returns the Bricks-side selector that should replace
	 * `%root%` before the CSS is stored.
	 *
	 * @param array<string,mixed> $element Bricks element array.
	 * @return string Selector string (e.g. "#brxe-a1b2c3d"), or empty string.
	 */
	public function get_bricks_element_root_selector( array $element ): string {
		$raw_id = isset( $element['id'] ) ? trim( (string) $element['id'] ) : '';
		$raw_id = ltrim( $raw_id, '#' );
		if ( '' === $raw_id ) {
			return '';
		}

		// Keep the original brxe- prefix for the root selector token.
		if ( 0 === strpos( $raw_id, 'brxe-' ) ) {
			return '#' . $raw_id;
		}

		// Etch ID — convert back to brxe- form for %root% compatibility.
		if ( 0 === strpos( $raw_id, 'etch-' ) ) {
			return '#brxe-' . substr( $raw_id, 5 );
		}

		return '#brxe-' . $raw_id;
	}

	/**
	 * Move image fitting declarations from a class's root CSS into a nested img selector.
	 *
	 * Bricks frequently applies `object-fit` and `aspect-ratio` to a figure wrapper
	 * element rather than to the inner <img>.  For image classes, these declarations
	 * must target the <img> child to take effect in Etch.
	 *
	 * Declarations moved:
	 * - object-fit
	 * - aspect-ratio
	 *
	 * When `object-fit: cover` is found, `inline-size: 100%` and `block-size: 100%`
	 * are also added to the nested img block to ensure the image fills its container.
	 *
	 * The method is a no-op unless the class name contains the substring "image".
	 *
	 * @param string $class_name Class name without leading dot.
	 * @param string $css        CSS declarations generated for this class.
	 * @return string Modified CSS with moved declarations.
	 */
	public function move_image_fit_properties_to_nested_img( string $class_name, string $css ): string {
		$class_name = strtolower( trim( $class_name ) );

		// Only run for image-related class names.
		if ( '' === $class_name || false === strpos( $class_name, 'image' ) ) {
			return $css;
		}

		// Separate base CSS from any @media blocks at the end.
		$media_pos = strpos( $css, '@media' );
		$base_css  = false === $media_pos ? $css : substr( $css, 0, $media_pos );
		$media_css = false === $media_pos ? '' : substr( $css, $media_pos );

		// Skip if there is no base CSS or if a nested img block already exists.
		if ( '' === trim( $base_css ) || preg_match( '/\bimg\s*\{/i', $base_css ) ) {
			return $css;
		}

		// Find object-fit and aspect-ratio declarations.
		if ( ! preg_match_all( '/\b(object-fit|aspect-ratio)\s*:\s*([^;{}]+)\s*;/i', $base_css, $matches, PREG_SET_ORDER ) ) {
			return $css;
		}

		$img_declarations     = array();
		$has_object_fit_cover = false;

		foreach ( $matches as $match ) {
			$prop  = strtolower( trim( (string) $match[1] ) );
			$value = trim( (string) $match[2] );
			if ( '' === $value ) {
				continue;
			}
			$img_declarations[] = $prop . ': ' . $value . ';';
			if ( 'object-fit' === $prop && 'cover' === strtolower( $value ) ) {
				$has_object_fit_cover = true;
			}
		}

		if ( empty( $img_declarations ) ) {
			return $css;
		}

		// Add sizing helpers when the image should cover its container.
		if ( $has_object_fit_cover ) {
			$existing = implode( ' ', $img_declarations );
			if ( false === strpos( $existing, 'inline-size' ) ) {
				$img_declarations[] = 'inline-size: 100%;';
			}
			if ( false === strpos( $existing, 'block-size' ) ) {
				$img_declarations[] = 'block-size: 100%;';
			}
		}

		// Remove moved declarations from the base CSS.
		$base_css = (string) preg_replace( '/\b(object-fit|aspect-ratio)\s*:\s*[^;{}]+\s*;/i', '', $base_css );
		$base_css = (string) preg_replace( '/;{2,}/', ';', $base_css );
		$base_css = trim( $base_css );

		$nested_img_css = "img {\n  " . implode( "\n  ", array_values( array_unique( $img_declarations ) ) ) . "\n}";

		if ( '' !== $base_css ) {
			$base_css = rtrim( $base_css );
			if ( ';' !== substr( $base_css, -1 ) && '}' !== substr( $base_css, -1 ) ) {
				$base_css .= ';';
			}
			$base_css .= "\n\n" . $nested_img_css;
		} else {
			$base_css = $nested_img_css;
		}

		return trim( $base_css . ( '' !== $media_css ? "\n" . ltrim( $media_css ) : '' ) );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Process a single element's settings and accumulate its CSS into $result.
	 *
	 * Handles both settings-derived CSS (via EFS_Settings_CSS_Converter) and raw
	 * custom CSS snippets stored in `_cssCustom` / `_cssCustom:<breakpoint>` keys.
	 *
	 * @param array<string,mixed>                                               $element  Bricks element.
	 * @param array<string,mixed>                                               $settings Element settings.
	 * @param array{styles: array<string,mixed>, custom_css: string}           $result   Accumulated result (by reference).
	 */
	private function process_element_settings( array $element, array $settings, array &$result ): void {
		$selector_id = $this->map_bricks_element_id_to_etch_selector_id( $element );

		if ( '' !== $selector_id ) {
			$this->accumulate_id_style( $selector_id, $settings, $result['styles'] );
		}

		$bricks_root_selector = $this->get_bricks_element_root_selector( $element );
		$this->accumulate_custom_css( $settings, $bricks_root_selector, $result['custom_css'] );
	}

	/**
	 * Convert element settings to CSS and merge into the ID-scoped styles map.
	 *
	 * Merges into an existing style entry when the same Etch ID has already been
	 * collected (can happen when the same element ID appears in multiple posts).
	 *
	 * @param string                                $selector_id Etch element ID without #.
	 * @param array<string,mixed>                   $settings    Element settings.
	 * @param array<string,array<string,mixed>>     $styles      Styles map (by reference).
	 */
	private function accumulate_id_style( string $selector_id, array $settings, array &$styles ): void {
		$base_css       = trim( $this->settings_converter->convert_bricks_settings_to_css( $settings ) );
		$responsive_css = trim( $this->settings_converter->convert_responsive_variants( $settings ) );
		$combined_css   = trim( $base_css . ( '' !== $responsive_css ? ' ' . $responsive_css : '' ) );

		if ( '' === $combined_css ) {
			return;
		}

		// Apply logical-property normalisation (physical → logical CSS).
		$combined_css = $this->normalizer->convert_to_logical_properties( $combined_css );
		$style_id     = 'id_' . substr( md5( '#' . $selector_id ), 0, 8 );

		if ( isset( $styles[ $style_id ] ) ) {
			// Merge with existing entry (idempotent append with whitespace separator).
			$existing                   = trim( (string) $styles[ $style_id ]['css'] );
			$styles[ $style_id ]['css'] = trim( $existing . "\n  " . $combined_css );
		} else {
			$styles[ $style_id ] = array(
				'type'       => 'class',
				'selector'   => '#' . $selector_id,
				'collection' => 'default',
				'css'        => $combined_css,
				'readonly'   => false,
			);
		}
	}

	/**
	 * Extract custom CSS snippets from element settings and append them to $custom_css.
	 *
	 * Handles `_cssCustom` (base/desktop custom CSS) and `_cssCustom:<breakpoint>`
	 * variants.  The `%root%` placeholder is replaced with the Bricks root selector
	 * before appending.  Breakpoint-scoped snippets are wrapped in the correct @media
	 * rule; snippets for unknown breakpoints are appended unwrapped.
	 *
	 * @param array<string,mixed> $settings             Element settings.
	 * @param string              $bricks_root_selector Bricks root selector for %root%.
	 * @param string              $custom_css           Accumulated custom CSS (by reference).
	 */
	private function accumulate_custom_css( array $settings, string $bricks_root_selector, string &$custom_css ): void {
		// Base custom CSS (no breakpoint).
		if ( ! empty( $settings['_cssCustom'] ) && is_string( $settings['_cssCustom'] ) ) {
			$snippet     = str_replace( '%root%', $bricks_root_selector, $settings['_cssCustom'] );
			$snippet     = $this->normalizer->normalize_deprecated_hsl_references( $snippet );
			$custom_css .= "\n" . $snippet . "\n";
		}

		// Breakpoint-scoped variants: "_cssCustom:tablet_portrait", etc.
		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, '_cssCustom:' ) ) {
				continue;
			}
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$breakpoint = str_replace( '_cssCustom:', '', $key );
			$snippet    = str_replace( '%root%', $bricks_root_selector, $value );
			$snippet    = $this->normalizer->normalize_deprecated_hsl_references( $snippet );
			$media      = $this->breakpoint_resolver->get_media_query_for_breakpoint( $breakpoint );

			if ( false === $media ) {
				// Unknown breakpoint — include snippet without a media wrapper.
				$custom_css .= "\n" . $snippet . "\n";
			} else {
				$custom_css .= "\n" . $media . " {\n" . $snippet . "\n}\n";
			}
		}
	}
}
