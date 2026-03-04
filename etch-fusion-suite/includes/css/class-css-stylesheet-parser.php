<?php
/**
 * CSS Stylesheet Parser
 *
 * Responsible for parsing raw custom CSS stylesheets (produced by Bricks Builder)
 * and extracting per-selector rule sets that can be stored in Etch's style manager.
 *
 * Handles two selector types:
 *   - Class selectors  (.my-class, .my-class > *, .my-class:hover, …)
 *   - ID selectors     (#brxe-abc, #my-anchor, …)
 *
 * Nested rules (e.g. ".my-class > *") are re-written using CSS nesting & syntax:
 *   ".my-class { color: red; } .my-class > * { color: blue; }"
 *   → "color: red;\n\n& > * {\n  color: blue;\n}"
 *
 * @media and @container queries are extracted separately and re-attached at the
 * end of the converted rule so they work correctly inside Etch's style manager.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

use Bricks2Etch\Core\EFS_Error_Handler;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses Bricks custom CSS stylesheets and converts them to Etch-compatible
 * nested CSS rule sets.
 */
class EFS_CSS_Stylesheet_Parser {

	/**
	 * CSS normalizer for transforming Bricks-specific CSS artefacts.
	 *
	 * @var EFS_CSS_Normalizer
	 */
	private $normalizer;

	/**
	 * Breakpoint resolver used to convert legacy px media conditions to
	 * Etch's to-rem() range syntax.
	 *
	 * @var EFS_Breakpoint_Resolver
	 */
	private $breakpoint_resolver;

	/**
	 * Error handler instance for optional debug-channel logging.
	 *
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	/**
	 * Initialise the parser with its required collaborators.
	 *
	 * @param EFS_CSS_Normalizer      $normalizer          CSS string transformer.
	 * @param EFS_Breakpoint_Resolver $breakpoint_resolver Media query normaliser.
	 * @param EFS_Error_Handler       $error_handler       Debug/error logging sink.
	 */
	public function __construct(
		EFS_CSS_Normalizer $normalizer,
		EFS_Breakpoint_Resolver $breakpoint_resolver,
		EFS_Error_Handler $error_handler
	) {
		$this->normalizer          = $normalizer;
		$this->breakpoint_resolver = $breakpoint_resolver;
		$this->error_handler       = $error_handler;
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Parse a custom CSS stylesheet and extract per-class rule sets.
	 *
	 * For each unique class name found in the stylesheet, all matching rules are
	 * collected, nested selectors are converted to & syntax, and the result is
	 * indexed by the Etch style ID from $style_map.  Classes that are not in the
	 * style map are silently skipped — they do not belong to any global class.
	 *
	 * @param string                   $stylesheet Raw CSS stylesheet.
	 * @param array<string,mixed>      $style_map  Map of bricks_id → style data produced
	 *                                              during class conversion.  Each value is
	 *                                              either a string (legacy) or an array with
	 *                                              'id' and 'selector' keys.
	 * @return array<string,array<string,mixed>>    Map of etch_id → style descriptor.
	 */
	public function parse_custom_css_stylesheet( string $stylesheet, array $style_map = array() ): array {
		$styles     = array();
		$stylesheet = $this->normalizer->normalize_bricks_id_selectors_in_css( $stylesheet );

		// Collect every unique class name referenced in the stylesheet.
		preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $stylesheet, $all_class_matches );
		if ( empty( $all_class_matches[1] ) ) {
			return $styles;
		}

		$class_names = array_unique( $all_class_matches[1] );

		foreach ( $class_names as $class_name ) {
			// Extract all CSS rules that target this class.
			$class_css = $this->extract_css_for_class( $stylesheet, $class_name );

			if ( '' === trim( $class_css ) ) {
				continue;
			}

			// Resolve the Etch style ID from the style map built during class conversion.
			$style_id = null;
			foreach ( $style_map as $bricks_id => $style_data ) {
				$selector = is_array( $style_data ) ? $style_data['selector'] : '';
				// Accept both ".class-name" and "class-name" as equivalent selectors.
				if ( '.' . $class_name === $selector || $class_name === $selector ) {
					$style_id = is_array( $style_data ) ? $style_data['id'] : $style_data;
					$this->log_debug_info(
						'CSS Stylesheet Parser: Found existing style mapping',
						array(
							'class_name' => $class_name,
							'bricks_id'  => $bricks_id,
							'etch_id'    => $style_id,
						)
					);
					break;
				}
			}

			// Classes with no style-map entry are not global Etch styles — skip them.
			if ( ! $style_id ) {
				$this->log_debug_info(
					'CSS Stylesheet Parser: Skipping custom CSS outside style map',
					array( 'class_name' => $class_name )
				);
				continue;
			}

			// Rewrite nested selectors (e.g. ".cls > *") to CSS nesting & syntax.
			$converted_css = $this->convert_nested_selectors_to_ampersand( $class_css, $class_name );

			$styles[ $style_id ] = array(
				'type'       => 'class',
				'selector'   => '.' . $class_name,
				'collection' => 'default',
				'css'        => trim( $converted_css ),
				'readonly'   => false,
			);
		}

		return $styles;
	}

	/**
	 * Parse ID-based CSS rules from a custom stylesheet.
	 *
	 * Handles selectors like #brxe-abc (rewritten to #etch-abc by the normalizer)
	 * and arbitrary IDs like #my-anchor.  Each unique ID selector gets its own
	 * style entry with an auto-generated hash ID.
	 *
	 * @param string $stylesheet Full custom stylesheet.
	 * @return array<string,array<string,mixed>>
	 */
	public function parse_custom_id_css_stylesheet( string $stylesheet ): array {
		$styles     = array();
		$stylesheet = $this->normalizer->normalize_bricks_id_selectors_in_css( $stylesheet );

		preg_match_all( '/#([a-zA-Z_][a-zA-Z0-9_-]*)/', $stylesheet, $all_id_matches );
		if ( empty( $all_id_matches[1] ) ) {
			return $styles;
		}

		$id_names = array_unique( $all_id_matches[1] );

		foreach ( $id_names as $id_name ) {
			$id_css = $this->extract_css_for_id( $stylesheet, $id_name );
			if ( '' === trim( $id_css ) ) {
				continue;
			}

			$converted_css = $this->convert_nested_id_selectors_to_ampersand( $id_css, $id_name );

			// Generate a deterministic but short ID for this rule set.
			$style_id            = 'id_' . substr( md5( '#' . $id_name ), 0, 8 );
			$styles[ $style_id ] = array(
				'type'       => 'class',
				'selector'   => '#' . $id_name,
				'collection' => 'default',
				'css'        => trim( $converted_css ),
				'readonly'   => false,
			);
		}

		return $styles;
	}

	// =========================================================================
	// Private helpers — class-based extraction
	// =========================================================================

	/**
	 * Extract all CSS rules that target a specific class from the stylesheet.
	 *
	 * Handles both top-level rules and rules nested inside @media / @container
	 * blocks.  Returns the extracted rules concatenated with blank-line separators.
	 *
	 * @param string $stylesheet Full stylesheet text.
	 * @param string $class_name Class name without the leading dot.
	 * @return string Extracted CSS rules (may be empty string).
	 */
	private function extract_css_for_class( string $stylesheet, string $class_name ): string {
		$escaped_class = preg_quote( $class_name, '/' );
		// Token pattern: matches ".class" that is not immediately followed by an
		// alphanumeric character (prevents partial-class false positives).
		$class_token = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])/';
		$rule_start  = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{/';
		$css_parts   = array();

		$lines         = explode( "\n", $stylesheet );
		$line_count    = count( $lines );
		$current_media = null;
		$media_content = '';
		$brace_count   = 0;
		$in_media      = false;

		for ( $i = 0; $i < $line_count; $i++ ) {
			$line = $lines[ $i ];

			// Detect @media block opening.
			if ( preg_match( '/@media\s+([^{]+)\{/', $line, $media_match ) ) {
				$current_media = '@media ' . trim( $media_match[1] );
				$media_content = '';
				$brace_count   = 1;
				$in_media      = true;
				continue;
			}

			if ( $in_media ) {
				// Track brace depth to know when the @media block closes.
				$brace_count   += substr_count( $line, '{' );
				$brace_count   -= substr_count( $line, '}' );
				$media_content .= $line . "\n";

				if ( 0 === $brace_count ) {
					// Collect the whole @media block only if it references our class.
					if ( preg_match( $class_token, $media_content ) ) {
						$css_parts[] = $current_media . " {\n" . trim( $media_content ) . "\n}";
					}
					$in_media      = false;
					$current_media = null;
					$media_content = '';
				}
				continue;
			}

			// Outside any @media block — collect top-level rules for our class.
			if ( preg_match( $rule_start, $line ) ) {
				$rule        = $line . "\n";
				$brace_count = substr_count( $line, '{' ) - substr_count( $line, '}' );

				// Read continuation lines until the rule's brace block closes.
				while ( $brace_count > 0 && $i < $line_count - 1 ) {
					++$i;
					$line         = $lines[ $i ];
					$rule        .= $line . "\n";
					$brace_count += substr_count( $line, '{' );
					$brace_count -= substr_count( $line, '}' );
				}

				$css_parts[] = trim( $rule );
			}
		}

		return implode( "\n\n", $css_parts );
	}

	/**
	 * Convert all nested class selectors inside a CSS block to & nesting syntax.
	 *
	 * Example input:
	 *   .my-class { padding: 1rem; }
	 *   .my-class > * { color: red; }
	 *
	 * Example output:
	 *   padding: 1rem;
	 *
	 *   & > * {
	 *     color: red;
	 *   }
	 *
	 * @media and @container blocks are extracted, converted separately, and
	 * appended at the end.
	 *
	 * @param string $css        CSS text that may contain multiple rules for $class_name.
	 * @param string $class_name Class name without leading dot.
	 * @return string Converted CSS ready for Etch's style manager.
	 */
	private function convert_nested_selectors_to_ampersand( string $css, string $class_name ): string {
		$escaped_class = preg_quote( $class_name, '/' );
		$rules         = array();
		$main_css      = '';

		// Extract @media / @container blocks first (they need brace-counting, not regex).
		$media_queries = $this->extract_media_queries( $css, $class_name );

		// Remove extracted at-rules from the main CSS string so they are not double-processed.
		foreach ( $media_queries as $mq ) {
			$css = str_replace( $mq['original'], '', $css );
		}

		// Match every remaining rule that targets our class: ".class<suffix> { <body> }".
		// The /s flag makes "." match newlines so multi-line declarations are captured.
		$pattern = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// The part captured after ".class" (e.g. " > *", ":hover", " .child").
				$selector_suffix = $match[1];
				$rule_content    = trim( $match[2] );

				$selector_suffix = $this->normalize_selector_suffix_with_ampersand( $selector_suffix, $class_name );

				if ( '' === trim( $selector_suffix ) ) {
					// Main selector (.my-class { … }) — inline into root declarations.
					$main_css .= $rule_content . "\n";
				} else {
					$rules[] = array(
						'selector' => $selector_suffix,
						'css'      => $rule_content,
					);
				}
			}
		}

		$result = trim( $main_css );

		// Append nested rules.
		foreach ( $rules as $rule ) {
			if ( '' !== $result ) {
				$result .= "\n\n";
			}
			$result .= $rule['selector'] . " {\n  " . trim( $rule['css'] ) . "\n}";
		}

		// Append converted @media / @container blocks.
		foreach ( $media_queries as $media ) {
			if ( '' !== $result ) {
				$result .= "\n\n";
			}
			$result .= $media['condition'] . " {\n  " . $media['css'] . "\n}";
		}

		// Fallback: if no structured rules were parsed, do an inline selector replace.
		if ( '' === $result ) {
			$result = preg_replace( '/\.' . $escaped_class . '(\s+[>+~]|\s+[.#\[]|::|:)/', '&$1', $css );
			$result = is_string( $result ) ? $result : '';
		}

		$this->log_debug_info(
			'CSS Stylesheet Parser: Converted nested class selectors',
			array( 'class_name' => $class_name )
		);

		return $result;
	}

	/**
	 * Extract @media and @container blocks that contain a specific class.
	 *
	 * Uses character-level brace counting rather than regex to correctly handle
	 * nested braces inside property values.
	 *
	 * @param string $css        CSS text.
	 * @param string $class_name Class name without leading dot.
	 * @return array<int,array<string,string>>  Each entry has:
	 *   - 'condition' string  Full at-rule including @media/@container and condition.
	 *   - 'css'       string  Converted inner content with & selectors.
	 *   - 'original'  string  Original at-rule string (for str_replace removal).
	 */
	private function extract_media_queries( string $css, string $class_name ): array {
		$media_queries = array();
		$pos           = 0;
		$length        = strlen( $css );
		$class_token   = '/\.' . preg_quote( $class_name, '/' ) . '(?![a-zA-Z0-9_-])/';

		while ( $pos < $length ) {
			// Find whichever at-rule keyword appears next.
			$pos_media     = strpos( $css, '@media', $pos );
			$pos_container = strpos( $css, '@container', $pos );

			if ( false === $pos_media && false === $pos_container ) {
				break;
			}

			if ( false === $pos_media ) {
				$media_pos  = $pos_container;
				$at_keyword = '@container';
			} elseif ( false === $pos_container || $pos_media <= $pos_container ) {
				$media_pos  = $pos_media;
				$at_keyword = '@media';
			} else {
				$media_pos  = $pos_container;
				$at_keyword = '@container';
			}

			$at_keyword_len = strlen( $at_keyword );

			$open_brace = strpos( $css, '{', $media_pos );
			if ( false === $open_brace ) {
				break;
			}

			// Grab the condition string between the keyword and the opening brace and
			// convert any legacy px values to Etch's to-rem() syntax.
			$media_condition = trim( substr( $css, $media_pos + $at_keyword_len, $open_brace - $media_pos - $at_keyword_len ) );
			$media_condition = $this->breakpoint_resolver->normalize_media_condition_to_etch( $media_condition );

			// Walk character by character to find the matching closing brace.
			$brace_count   = 1;
			$i             = $open_brace + 1;
			$content_start = $i;

			while ( $i < $length && $brace_count > 0 ) {
				if ( '{' === $css[ $i ] ) {
					++$brace_count;
				} elseif ( '}' === $css[ $i ] ) {
					--$brace_count;
				}
				++$i;
			}

			if ( 0 === $brace_count ) {
				$media_content = substr( $css, $content_start, $i - $content_start - 1 );

				// Only include this at-rule if it actually targets our class.
				if ( preg_match( $class_token, $media_content ) ) {
					$converted_content = $this->convert_selectors_in_media_query( $media_content, $class_name );
					$media_queries[]   = array(
						'condition' => $at_keyword . ' ' . $media_condition,
						'css'       => trim( $converted_content ),
						'original'  => substr( $css, $media_pos, $i - $media_pos ),
					);
				}
			}

			$pos = $i;
		}

		return $media_queries;
	}

	/**
	 * Convert class selectors inside an at-rule block to & syntax.
	 *
	 * @param string $media_content Inner content of the @media / @container block.
	 * @param string $class_name    Class name without leading dot.
	 * @return string Converted content with & selectors.
	 */
	private function convert_selectors_in_media_query( string $media_content, string $class_name ): string {
		$escaped_class = preg_quote( $class_name, '/' );
		$rules         = array();

		$this->log_debug_info(
			'CSS Stylesheet Parser: Converting class selectors in media query',
			array(
				'class_name'     => $class_name,
				'content_length' => strlen( $media_content ),
			)
		);

		$pattern = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $media_content, $matches, PREG_SET_ORDER ) ) {
			$this->log_debug_info(
				'CSS Stylesheet Parser: Found rules in media query',
				array( 'count' => count( $matches ) )
			);

			foreach ( $matches as $match ) {
				$selector_suffix = trim( $match[1] );
				$rule_content    = trim( $match[2] );

				$nested_selector = $this->normalize_selector_suffix_with_ampersand( $selector_suffix, $class_name );
				if ( '' === trim( $nested_selector ) ) {
					$nested_selector = '&';
				}

				$rules[] = $nested_selector . " {\n    " . $rule_content . "\n  }";
			}
		} else {
			$this->log_debug_info(
				'CSS Stylesheet Parser: No rules matched class in media query',
				array( 'class_name' => $class_name )
			);
		}

		$result = implode( "\n\n  ", $rules );

		$this->log_debug_info(
			'CSS Stylesheet Parser: Compiled media query result',
			array( 'length' => strlen( $result ) )
		);

		return $result;
	}

	// =========================================================================
	// Private helpers — ID-based extraction
	// =========================================================================

	/**
	 * Extract all CSS rules that target a specific ID selector from the stylesheet.
	 *
	 * Mirrors extract_css_for_class() but matches "#id" instead of ".class".
	 *
	 * @param string $stylesheet Full stylesheet text.
	 * @param string $id_name    ID name without the leading hash.
	 * @return string Extracted CSS rules (may be empty string).
	 */
	private function extract_css_for_id( string $stylesheet, string $id_name ): string {
		$escaped_id = preg_quote( $id_name, '/' );
		$id_token   = '/#' . $escaped_id . '(?![a-zA-Z0-9_-])/';
		$rule_start = '/#' . $escaped_id . '(?![a-zA-Z0-9_-])([^{]*?)\{/';
		$css_parts  = array();

		$lines         = explode( "\n", $stylesheet );
		$line_count    = count( $lines );
		$current_media = null;
		$media_content = '';
		$brace_count   = 0;
		$in_media      = false;

		for ( $i = 0; $i < $line_count; $i++ ) {
			$line = $lines[ $i ];

			if ( preg_match( '/@media\s+([^{]+)\{/', $line, $media_match ) ) {
				$current_media = '@media ' . trim( $media_match[1] );
				$media_content = '';
				$brace_count   = 1;
				$in_media      = true;
				continue;
			}

			if ( $in_media ) {
				$brace_count   += substr_count( $line, '{' );
				$brace_count   -= substr_count( $line, '}' );
				$media_content .= $line . "\n";

				if ( 0 === $brace_count ) {
					if ( preg_match( $id_token, $media_content ) ) {
						$css_parts[] = $current_media . " {\n" . trim( $media_content ) . "\n}";
					}
					$in_media      = false;
					$current_media = null;
					$media_content = '';
				}
				continue;
			}

			if ( preg_match( $rule_start, $line ) ) {
				$rule        = $line . "\n";
				$brace_count = substr_count( $line, '{' ) - substr_count( $line, '}' );

				while ( $brace_count > 0 && $i < $line_count - 1 ) {
					++$i;
					$line         = $lines[ $i ];
					$rule        .= $line . "\n";
					$brace_count += substr_count( $line, '{' );
					$brace_count -= substr_count( $line, '}' );
				}

				$css_parts[] = trim( $rule );
			}
		}

		return implode( "\n\n", $css_parts );
	}

	/**
	 * Convert nested ID selectors to & syntax.
	 *
	 * Mirrors convert_nested_selectors_to_ampersand() but matches "#id" selectors.
	 *
	 * @param string $css     CSS text containing rules for this ID.
	 * @param string $id_name ID name without leading hash.
	 * @return string Converted CSS with & nesting.
	 */
	private function convert_nested_id_selectors_to_ampersand( string $css, string $id_name ): string {
		$escaped_id    = preg_quote( $id_name, '/' );
		$rules         = array();
		$main_css      = '';
		$media_queries = $this->extract_media_queries_for_id( $css, $id_name );

		foreach ( $media_queries as $mq ) {
			$css = str_replace( $mq['original'], '', $css );
		}

		$pattern = '/#' . $escaped_id . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$selector_suffix = $this->normalize_selector_suffix_with_ampersand( $match[1], $id_name );
				$rule_content    = trim( $match[2] );

				if ( '' === trim( $selector_suffix ) ) {
					$main_css .= $rule_content . "\n";
				} else {
					$rules[] = array(
						'selector' => $selector_suffix,
						'css'      => $rule_content,
					);
				}
			}
		}

		$result = trim( $main_css );

		foreach ( $rules as $rule ) {
			if ( '' !== $result ) {
				$result .= "\n\n";
			}
			$result .= $rule['selector'] . " {\n  " . trim( $rule['css'] ) . "\n}";
		}

		foreach ( $media_queries as $media ) {
			if ( '' !== $result ) {
				$result .= "\n\n";
			}
			$result .= $media['condition'] . " {\n  " . $media['css'] . "\n}";
		}

		if ( '' === $result ) {
			$result = preg_replace( '/#' . $escaped_id . '(\s+[>+~]|\s+[.#\[]|::|:)/', '&$1', $css );
			$result = is_string( $result ) ? $result : '';
		}

		return $result;
	}

	/**
	 * Extract @media and @container blocks that contain a specific ID selector.
	 *
	 * Mirrors extract_media_queries() but matches "#id" instead of ".class".
	 *
	 * @param string $css     CSS text.
	 * @param string $id_name ID name without hash.
	 * @return array<int,array<string,string>>
	 */
	private function extract_media_queries_for_id( string $css, string $id_name ): array {
		$media_queries = array();
		$pos           = 0;
		$length        = strlen( $css );
		$id_token      = '/#' . preg_quote( $id_name, '/' ) . '(?![a-zA-Z0-9_-])/';

		while ( $pos < $length ) {
			$pos_media     = strpos( $css, '@media', $pos );
			$pos_container = strpos( $css, '@container', $pos );

			if ( false === $pos_media && false === $pos_container ) {
				break;
			}

			if ( false === $pos_media ) {
				$media_pos  = $pos_container;
				$at_keyword = '@container';
			} elseif ( false === $pos_container || $pos_media <= $pos_container ) {
				$media_pos  = $pos_media;
				$at_keyword = '@media';
			} else {
				$media_pos  = $pos_container;
				$at_keyword = '@container';
			}

			$at_keyword_len = strlen( $at_keyword );

			$open_brace = strpos( $css, '{', $media_pos );
			if ( false === $open_brace ) {
				break;
			}

			$media_condition = trim( substr( $css, $media_pos + $at_keyword_len, $open_brace - $media_pos - $at_keyword_len ) );
			$media_condition = $this->breakpoint_resolver->normalize_media_condition_to_etch( $media_condition );

			$brace_count   = 1;
			$i             = $open_brace + 1;
			$content_start = $i;

			while ( $i < $length && $brace_count > 0 ) {
				if ( '{' === $css[ $i ] ) {
					++$brace_count;
				} elseif ( '}' === $css[ $i ] ) {
					--$brace_count;
				}
				++$i;
			}

			if ( 0 === $brace_count ) {
				$media_content = substr( $css, $content_start, $i - $content_start - 1 );
				if ( preg_match( $id_token, $media_content ) ) {
					$converted_content = $this->convert_selectors_in_media_query_for_id( $media_content, $id_name );
					$media_queries[]   = array(
						'condition' => $at_keyword . ' ' . $media_condition,
						'css'       => trim( $converted_content ),
						'original'  => substr( $css, $media_pos, $i - $media_pos ),
					);
				}
			}

			$pos = $i;
		}

		return $media_queries;
	}

	/**
	 * Convert ID selectors inside an at-rule block to & syntax.
	 *
	 * @param string $media_content Inner content of the @media / @container block.
	 * @param string $id_name       ID name without leading hash.
	 * @return string Converted content with & selectors.
	 */
	private function convert_selectors_in_media_query_for_id( string $media_content, string $id_name ): string {
		$escaped_id = preg_quote( $id_name, '/' );
		$rules      = array();
		$pattern    = '/#' . $escaped_id . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $media_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$selector_suffix = trim( $match[1] );
				$rule_content    = trim( $match[2] );
				$nested_selector = $this->normalize_selector_suffix_with_ampersand( $selector_suffix, $id_name );
				if ( '' === trim( $nested_selector ) ) {
					$nested_selector = '&';
				}

				$rules[] = $nested_selector . " {\n    " . $rule_content . "\n  }";
			}
		}

		return implode( "\n\n  ", $rules );
	}

	// =========================================================================
	// Shared selector normalisation (private — duplicated from Settings Converter
	// to keep this class self-contained and avoid a circular dependency)
	// =========================================================================

	/**
	 * Normalise a selector suffix captured after a base class or ID selector and
	 * produce a properly prefixed & form for CSS nesting.
	 *
	 * Examples:
	 *   " > *"            → "& > *"    (combinator, space before)
	 *   ":hover"          → "&:hover"  (pseudo-class, attached)
	 *   " .child"         → "& .child" (descendant class)
	 *   "[data-foo]"      → "&[data-foo]" (attribute, always attached)
	 *   "::before, .cls::after" → "&::before, &::after"
	 *
	 * @param string $selector_suffix Part captured after the base selector token.
	 *                                Preserves leading whitespace to detect
	 *                                descendant vs. same-element context.
	 * @param string $class_name      Base class or ID name (without . or #).
	 * @return string                 Normalised selector, or empty string when
	 *                                the suffix is blank (main selector).
	 */
	private function normalize_selector_suffix_with_ampersand( string $selector_suffix, string $class_name ): string {
		$raw    = $selector_suffix;
		$suffix = trim( $raw );
		if ( '' === $suffix ) {
			return '';
		}

		// A leading whitespace in the raw suffix means the selector had a space
		// between the base token and the next token (descendant context), e.g.
		// ".cls [attr]" — no leading space means same-element, e.g. ".cls[attr]".
		$is_descendant_context = ( $raw !== $suffix && (bool) preg_match( '/^\s/', $raw ) );

		// Replace any remaining occurrence of the base class inside the suffix
		// (e.g. from comma-list selectors like "::before, .my-class::after").
		$class_pattern = '/\.' . preg_quote( $class_name, '/' ) . '\b/i';
		$suffix        = preg_replace( $class_pattern, '&', $suffix );
		$suffix        = is_string( $suffix ) ? $suffix : trim( $raw );

		$parts = array_filter(
			array_map( 'trim', explode( ',', $suffix ) ),
			static function ( string $part ): bool {
				return '' !== $part;
			}
		);

		if ( empty( $parts ) ) {
			return '';
		}

		$normalized_parts = array();
		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '&' ) ) {
				// Already normalised (e.g. after class_pattern replacement above).
				$normalized_parts[] = $part;
				continue;
			}

			if ( preg_match( '/^[>+~]/', $part ) ) {
				// CSS combinators always require a space: "& > *".
				$normalized_parts[] = '& ' . $part;
			} elseif ( preg_match( '/^\[/', $part ) ) {
				// Attribute selectors are always same-element — never add a space.
				$normalized_parts[] = '&' . $part;
			} elseif ( preg_match( '/^[.#]/', $part ) ) {
				// Class/ID selectors: honour descendant context from source whitespace.
				$normalized_parts[] = ( $is_descendant_context ? '& ' : '&' ) . $part;
			} else {
				// Pseudo-classes, pseudo-elements, and anything else.
				$normalized_parts[] = '&' . $part;
			}
		}

		return implode( ', ', $normalized_parts );
	}

	// =========================================================================
	// Debug logging helpers
	// =========================================================================

	/**
	 * Determine whether verbose debug logging should be emitted.
	 *
	 * Requires both WP_DEBUG and WP_DEBUG_LOG to be enabled.
	 *
	 * @return bool
	 */
	private function is_debug_logging_enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Emit a debug message through the error handler when debug logging is active.
	 *
	 * @param string           $message Human-readable message.
	 * @param array<mixed>|null $context Optional structured context.
	 */
	private function log_debug_info( string $message, ?array $context = null ): void {
		if ( ! $this->is_debug_logging_enabled() ) {
			return;
		}

		if ( method_exists( $this->error_handler, 'debug_log' ) ) {
			$this->error_handler->debug_log( $message, $context, 'EFS_CSS_STYLESHEET_PARSER' );
		}
	}
}
