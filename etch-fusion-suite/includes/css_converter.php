<?php
/**
 * CSS Converter for Bricks to Etch Migration Plugin
 *
 * Converts Bricks global classes to Etch-compatible CSS format
 */

namespace Bricks2Etch\Parsers;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_CSS_Converter {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * Style repository instance
	 */
	private $style_repository;

	/**
	 * ACSS class style declarations keyed by Bricks class ID and class name.
	 *
	 * @var array<string,string>
	 */
	private $acss_inline_style_map = array();

	/**
	 * Tracks whether Bricks content was scanned while collecting class references.
	 *
	 * @var bool
	 */
	private $has_scanned_bricks_content = false;

	/**
	 * Constructor
	 *
	 * @param EFS_Error_Handler $error_handler
	 * @param Style_Repository_Interface $style_repository
	 */
	public function __construct( EFS_Error_Handler $error_handler, Style_Repository_Interface $style_repository ) {
		$this->error_handler    = $error_handler;
		$this->style_repository = $style_repository;
	}

	/**
	 * Determine if debug logging is enabled via WordPress flags.
	 *
	 * @return bool True when WP_DEBUG and WP_DEBUG_LOG are enabled.
	 */
	private function is_debug_logging_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Route verbose diagnostics through the error handler debug channel when enabled.
	 *
	 * @param string     $message Debug message to log.
	 * @param array|null $context Optional structured context payload.
	 */
	private function log_debug_info( $message, $context = null ) {
		if ( ! $this->is_debug_logging_enabled() || ! method_exists( $this->error_handler, 'debug_log' ) ) {
			return;
		}

		$this->error_handler->debug_log( $message, $context, 'EFS_CSS_CONVERTER' );
	}

	/**
	 * Backwards-compatible wrapper to satisfy legacy integrations.
	 *
	 * @param array<string,mixed> $legacy_styles Optional payload.
	 * @return array<string,mixed>
	 */
	public function convert( array $legacy_styles = array() ) {
		$conversion = $this->convert_bricks_classes_to_etch();

		if ( ! empty( $legacy_styles ) ) {
			$conversion['legacy_styles'] = $legacy_styles;
		}

		return $conversion;
	}

	/**
	 * Convert Bricks breakpoint name to Etch media query
	 * Uses modern range syntax and to-rem() function.
	 * Bricks responsive breakpoints are desktop-first max-width breakpoints.
	 *
	 * @param string $breakpoint Bricks breakpoint name
	 * @return string|false Media query or false if unknown
	 */
	private function get_media_query_for_breakpoint( $breakpoint ) {
		// Keep Bricks desktop-first cascade semantics:
		// - tablet_landscape applies up to 1199px
		// - tablet_portrait applies up to 991px
		// - mobile_landscape applies up to 767px
		// - mobile_portrait applies up to 478px
		// Desktop remains explicit for 1200px+ overrides when present.
		$breakpoints = array(
			'desktop'          => '@media (width >= to-rem(1200px))',
			'tablet_landscape' => '@media (width <= to-rem(1199px))',
			'tablet_portrait'  => '@media (width <= to-rem(991px))',
			'mobile_landscape' => '@media (width <= to-rem(767px))',
			'mobile_portrait'  => '@media (width <= to-rem(478px))',
		);

		return isset( $breakpoints[ $breakpoint ] ) ? $breakpoints[ $breakpoint ] : false;
	}

	/**
	 * Check if class should be excluded from migration
	 *
	 * @param array $class_data Bricks class
	 * @return bool True if should be excluded
	 */
	private function should_exclude_class( $class_data ) {
		$class_name = ! empty( $class_data['name'] ) ? $class_data['name'] : '';

		// Skip if no name
		if ( empty( $class_name ) ) {
			return true;
		}

		// Blacklist prefixes
		$excluded_prefixes = array(
			'brxe-',        // Bricks Element classes
			'bricks-',      // Bricks System classes
			'brx-',         // Bricks Utility classes
			'wp-',          // WordPress default classes
			'wp-block-',    // Gutenberg block classes
			'has-',         // Gutenberg utility classes
			'is-',          // Gutenberg state classes
			'woocommerce-', // WooCommerce classes
			'wc-',          // WooCommerce short prefix
			'product-',     // WooCommerce product classes
			'cart-',        // WooCommerce cart classes
			'checkout-',    // WooCommerce checkout classes
		);

		foreach ( $excluded_prefixes as $prefix ) {
			if ( 0 === strpos( $class_name, $prefix ) ) {
				$this->error_handler->log_info( 'CSS Converter: Excluding class ' . $class_name . ' (prefix: ' . $prefix . ')' );
				return true;
			}
		}

		// Explicit class exclusions:
		// Keep classes on content elements, but do not migrate their style rules.
		$normalized_class_name = ltrim( preg_replace( '/^acss_import_/', '', $class_name ), '.' );
		$excluded_classes      = array(
			'bg--ultra-light',
			'bg--ultra-dark',
			'fr-lede',
			'fr-intro',
			'fr-note',
			'fr-notes',
			'text--l',
		);
		if ( in_array( $normalized_class_name, $excluded_classes, true ) ) {
			$this->error_handler->log_info( 'CSS Converter: Excluding class ' . $class_name . ' (explicit utility exclusion)' );
			return true;
		}

		return false;
	}

	/**
	 * Convert Bricks global classes to Etch format
	 *
	 * Generates Etch-compatible etch_styles array structure
	 */
	public function convert_bricks_classes_to_etch() {
		$this->log_debug_info( 'CSS Converter: Starting conversion' );
		$this->acss_inline_style_map = array();

		$bricks_classes = get_option( 'bricks_global_classes', array() );
		if ( is_string( $bricks_classes ) ) {
			$decoded = json_decode( $bricks_classes, true );
			if ( is_array( $decoded ) ) {
				$bricks_classes = $decoded;
			} else {
				$maybe          = maybe_unserialize( $bricks_classes );
				$bricks_classes = is_array( $maybe ) ? $maybe : array();
			}
		} elseif ( ! is_array( $bricks_classes ) ) {
			$bricks_classes = array();
		}

		$this->log_debug_info(
			'CSS Converter: Loaded Bricks classes',
			array(
				'count' => count( $bricks_classes ),
			)
		);

		$selected_post_types = $this->get_selected_post_types_from_active_migration();
		$referenced_class_ids = $this->collect_referenced_global_class_ids( $selected_post_types );
		$restrict_to_referenced_classes = (bool) apply_filters(
			'etch_fusion_suite_css_restrict_to_referenced_classes',
			true,
			$selected_post_types,
			$referenced_class_ids
		);
		$this->log_debug_info(
			'CSS Converter: Referenced global classes collected',
			array(
				'referenced_count'               => count( $referenced_class_ids ),
				'selected_post_types'            => $selected_post_types,
				'restrict_to_referenced_classes' => $restrict_to_referenced_classes,
			)
		);

		$etch_styles = array();
		$style_map   = array(); // Maps Bricks ID => Etch Style ID

		// Add Etch element styles (readonly)
		$etch_styles = array_merge( $etch_styles, $this->get_etch_element_styles() );

		// Add CSS variables (custom type) - DISABLED
		// Locally scoped variables should stay in their classes, not be moved to :root
		// $etch_styles = array_merge($etch_styles, $this->get_etch_css_variables());

		// Step 1: Collect all custom CSS into a temporary stylesheet
		$custom_css_stylesheet = '';
		$breakpoint_css_map    = array(); // Store breakpoint CSS per class

		// Collect custom CSS from global classes (skip excluded classes!)
		foreach ( $bricks_classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			if ( empty( $class['id'] ) && empty( $class['name'] ) ) {
				continue;
			}

			// Optional optimization: when enabled, migrate only referenced classes.
			if ( $restrict_to_referenced_classes && ! $this->is_referenced_global_class( $class, $referenced_class_ids ) ) {
				continue;
			}

			// ACSS utilities: keep styling via inline mapping, but do not migrate class selectors.
			if ( $this->is_acss_class( $class ) ) {
				$this->register_acss_inline_style( $class );
				continue;
			}

			// Skip excluded classes
			if ( $this->should_exclude_class( $class ) ) {
				continue;
			}

			$class_name = ! empty( $class['name'] ) ? $class['name'] : $class['id'];
			$class_name = preg_replace( '/^acss_import_/', '', $class_name );
			$bricks_id  = $class['id'];
			$settings   = isset( $class['settings'] ) && is_array( $class['settings'] ) ? $class['settings'] : array();

			// Collect main custom CSS
			if ( ! empty( $settings['_cssCustom'] ) ) {
				$custom_css             = str_replace( '%root%', '.' . $class_name, $settings['_cssCustom'] );
				$custom_css             = $this->normalize_deprecated_hsl_references( $custom_css );
				$custom_css_stylesheet .= "\n" . $custom_css . "\n";
			}

			// Collect breakpoint-specific custom CSS (store separately!)
			// Bricks stores these as _cssCustom:breakpoint_name
			foreach ( $settings as $key => $value ) {
				if ( 0 === strpos( $key, '_cssCustom:' ) && ! empty( $value ) ) {
					$breakpoint = str_replace( '_cssCustom:', '', $key );

					// Convert Bricks breakpoint names to media queries
					$media_query = $this->get_media_query_for_breakpoint( $breakpoint );

					if ( $media_query ) {
						// Extract just the CSS properties (remove the class wrapper)
						$custom_css = str_replace( '%root%', '.' . $class_name, $value );
						$custom_css = $this->normalize_deprecated_hsl_references( $custom_css );

						// Extract content from .class-name { content }
						if ( preg_match( '/\.' . preg_quote( $class_name, '/' ) . '\s*\{([^}]*)\}/s', $custom_css, $match ) ) {
							$css_content = trim( $match[1] );

							// Store by Bricks ID so we can add it later
							if ( ! isset( $breakpoint_css_map[ $bricks_id ] ) ) {
								$breakpoint_css_map[ $bricks_id ] = array();
							}
							$breakpoint_css_map[ $bricks_id ][] = $media_query . " {\n  " . $css_content . "\n}";

							$this->log_debug_info(
								'CSS Converter: Found breakpoint CSS',
								array(
									'class_name' => $class_name,
									'breakpoint' => $breakpoint,
								)
							);
						}
					}
				}
			}
		}

		// Collect inline CSS from code blocks (stored during content parsing)
		global $wpdb;
		$inline_css_options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'efs_inline_css_%'",
			ARRAY_A
		);

		foreach ( $inline_css_options as $option ) {
			$custom_css_stylesheet .= "\n" . $option['option_value'] . "\n";
			// Clean up after collecting
			delete_option( $option['option_name'] );
		}

		// Step 2: Convert user classes (without custom CSS for now)
		$converted_count = 0;
		$excluded_count  = 0;
		foreach ( $bricks_classes as $class ) {
			// Optional optimization: when enabled, migrate only referenced classes.
			if ( $restrict_to_referenced_classes && ! $this->is_referenced_global_class( $class, $referenced_class_ids ) ) {
				continue;
			}

			// ACSS utilities: keep styling via inline mapping, but do not migrate class selectors.
			if ( $this->is_acss_class( $class ) ) {
				$this->register_acss_inline_style( $class );
				++$excluded_count;
				continue;
			}

			// Skip excluded classes
			if ( $this->should_exclude_class( $class ) ) {
				++$excluded_count;
				continue;
			}

			$converted_class = $this->convert_bricks_class_to_etch( $class );
			if ( $converted_class ) {
				// Generate unique ID like Etch does (uniqid is fine!)
				$style_id = substr( uniqid(), -7 );

				// Add style WITH ID as key (Etch won't overwrite existing IDs!)
				$etch_styles[ $style_id ] = $converted_class;
				++$converted_count;

				// DEBUG: Log first 3 styles to verify selectors
				if ( $converted_count <= 3 ) {
					$this->log_debug_info(
						'CSS Converter: Converted style',
						array(
							'selector' => $converted_class['selector'] ?? '',
						)
					);
				}

				// Map Bricks ID to Etch Style ID + Selector
				// Store both ID and selector so we can use them on Bricks side
				if ( ! empty( $class['id'] ) ) {
					$style_map[ $class['id'] ] = array(
						'id'       => $style_id,
						'selector' => $converted_class['selector'] ?? '',
					);
				}
			}
		}
		$this->log_debug_info(
			'CSS Converter: Converted user classes',
			array(
				'converted' => $converted_count,
			)
		);

		// Step 3: Parse custom CSS stylesheet and merge with existing styles
		$this->log_debug_info(
			'CSS Converter: Custom CSS stylesheet length',
			array(
				'length' => strlen( $custom_css_stylesheet ),
			)
		);
		if ( ! empty( $custom_css_stylesheet ) ) {
			$this->log_debug_info( 'CSS Converter: Parsing custom CSS stylesheet' );
			$custom_styles = $this->parse_custom_css_stylesheet( $custom_css_stylesheet, $style_map );
			$this->log_debug_info(
				'CSS Converter: Parsed custom styles',
				array(
					'count' => count( $custom_styles ),
				)
			);

			// Merge custom CSS with existing styles (combine CSS for same selector)
			foreach ( $custom_styles as $style_id => $custom_style ) {
				if ( isset( $etch_styles[ $style_id ] ) ) {
					// Combine CSS for same selector
					$existing_css = trim( $etch_styles[ $style_id ]['css'] );
					$custom_css   = trim( $custom_style['css'] );

					// Convert custom CSS to logical properties
					$custom_css = $this->convert_to_logical_properties( $custom_css );

					if ( ! empty( $existing_css ) && ! empty( $custom_css ) ) {
						$etch_styles[ $style_id ]['css'] = $existing_css . "\n  " . $custom_css;
					} elseif ( ! empty( $custom_css ) ) {
						$etch_styles[ $style_id ]['css'] = $custom_css;
					}

					$this->log_debug_info(
						'CSS Converter: Merged custom CSS',
						array(
							'selector' => $custom_style['selector'],
						)
					);
				} else {
					// New style from custom CSS - convert to logical properties
					$custom_style['css']      = $this->convert_to_logical_properties( $custom_style['css'] );
					$etch_styles[ $style_id ] = $custom_style;
					$this->log_debug_info(
						'CSS Converter: Added custom CSS style',
						array(
							'selector' => $custom_style['selector'],
						)
					);
				}
			}
		}

		// Step 4: Add breakpoint-specific CSS to styles
		if ( ! empty( $breakpoint_css_map ) ) {
			$this->log_debug_info(
				'CSS Converter: Adding breakpoint CSS',
				array(
					'class_count' => count( $breakpoint_css_map ),
				)
			);

			foreach ( $breakpoint_css_map as $bricks_id => $media_queries ) {
				// Find the Etch style ID for this Bricks ID
				if ( isset( $style_map[ $bricks_id ] ) ) {
					$style_id = $style_map[ $bricks_id ]['id'];

					if ( isset( $etch_styles[ $style_id ] ) ) {
						// Append media queries to existing CSS
						foreach ( $media_queries as $media_query ) {
							$etch_styles[ $style_id ]['css'] .= "\n\n" . $media_query;
						}
						$this->log_debug_info(
							'CSS Converter: Added media queries to style',
							array(
								'count'    => count( $media_queries ),
								'style_id' => $style_id,
							)
						);
					}
				}
			}
		}

		// Save style map for use during content migration
		foreach ( $etch_styles as $style_key => $style_data ) {
			if ( ! is_array( $style_data ) || empty( $style_data['css'] ) || ! is_string( $style_data['css'] ) ) {
				continue;
			}
			$etch_styles[ $style_key ]['css'] = $this->normalize_deprecated_hsl_references( $style_data['css'] );
		}

		// Save style map for use during content migration
		$this->style_repository->save_style_map( $style_map );
		update_option( 'efs_acss_inline_style_map', $this->acss_inline_style_map );

		$total_styles = count( $etch_styles );
		$this->log_debug_info(
			'CSS Converter: Conversion summary',
			array(
				'converted'      => $converted_count,
				'excluded'       => $excluded_count,
				'total_styles'   => $total_styles,
				'style_map_size' => count( $style_map ),
			)
		);

		if ( 0 === $total_styles ) {
			$this->error_handler->log_info(
				'CSS Converter completed without generating styles',
				array(
					'bricks_classes' => count( $bricks_classes ),
				)
			);
		}

		// Return both styles AND style map
		return array(
			'styles'    => $etch_styles,
			'style_map' => $style_map,
		);
	}

	/**
	 * Detect whether class belongs to ACSS utilities.
	 *
	 * @param array $class_data Bricks global class data.
	 * @return bool
	 */
	private function is_acss_class( $class_data ) {
		if ( ! is_array( $class_data ) ) {
			return false;
		}

		$category = isset( $class_data['category'] ) ? strtolower( trim( (string) $class_data['category'] ) ) : '';
		if ( 'acss' === $category ) {
			return true;
		}

		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
		return 0 === strpos( $class_name, 'acss_import_' );
	}

	/**
	 * Cache ACSS class declarations from automatic.css for inline transfer.
	 *
	 * @param array $class_data Bricks class data.
	 * @return void
	 */
	private function register_acss_inline_style( $class_data ) {
		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
		$class_id   = isset( $class_data['id'] ) ? trim( (string) $class_data['id'] ) : '';

		if ( '' === $class_name ) {
			return;
		}

		$normalized_class_name = ltrim( preg_replace( '/^acss_import_/', '', $class_name ), '.' );
		if ( '' === $normalized_class_name ) {
			return;
		}

		$declarations = $this->get_acss_declarations_for_class( $normalized_class_name );
		if ( '' === $declarations ) {
			return;
		}

		if ( '' !== $class_id ) {
			$this->acss_inline_style_map[ $class_id ] = $declarations;
		}
		$this->acss_inline_style_map[ $normalized_class_name ] = $declarations;
		$this->acss_inline_style_map[ $class_name ]            = $declarations;
	}

	/**
	 * Fetch declaration block for an ACSS class from automatic.css.
	 *
	 * @param string $class_name Class selector name without leading dot.
	 * @return string
	 */
	private function get_acss_declarations_for_class( $class_name ) {
		static $stylesheet = null;
		static $cache      = array();

		if ( isset( $cache[ $class_name ] ) ) {
			return $cache[ $class_name ];
		}

		if ( null === $stylesheet ) {
			$upload_dir = wp_upload_dir();
			$css_path   = trailingslashit( $upload_dir['basedir'] ) . 'automatic-css/automatic.css';
			if ( ! file_exists( $css_path ) || ! is_readable( $css_path ) ) {
				$stylesheet = '';
			} else {
				$contents = file_get_contents( $css_path );
				$stylesheet = false !== $contents ? (string) $contents : '';
			}
		}

		if ( '' === $stylesheet ) {
			$cache[ $class_name ] = '';
			return '';
		}

		$pattern = '/\.' . preg_quote( $class_name, '/' ) . '\s*\{([^}]*)\}/';
		if ( ! preg_match( $pattern, $stylesheet, $match ) ) {
			$cache[ $class_name ] = '';
			return '';
		}

		$declarations = trim( (string) $match[1] );
		$declarations = $this->normalize_acss_deprecated_hsl_tokens( $declarations );
		$cache[ $class_name ] = $declarations;
		return $declarations;
	}

	/**
	 * Translate deprecated ACSS v3 color-token references to the v4 channel format.
	 *
	 * Example:
	 * var(--primary-hsl) -> var(--primary)
	 *
	 * @param string $declarations CSS declaration block.
	 * @return string
	 */
	private function normalize_acss_deprecated_hsl_tokens( $declarations ) {
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
	 * Normalize deprecated *-hsl variable usage to migration-safe alternatives.
	 *
	 * - hsl(var(--token-hsl) / a) => color-mix(in oklab, var(--token) X%, transparent)
	 * - hsl(var(--token-hsl))     => var(--token)
	 * - var(--token-hsl)          => var(--token)
	 *
	 * @param string $css CSS fragment.
	 * @return string
	 */
	private function normalize_deprecated_hsl_references( $css ) {
		if ( '' === $css || false === strpos( $css, '-hsl' ) ) {
			return $css;
		}

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

		$normalized_parts = preg_replace_callback(
			'/var\(\s*--([a-z0-9_-]+)-hsl\s*\)/i',
			static function ( $matches ) {
				$token = strtolower( (string) $matches[1] );
				return 'var(--' . $token . ')';
			},
			$normalized
		);

		return is_string( $normalized_parts ) ? $normalized_parts : $normalized;
	}

	/**
	 * Convert alpha values from hsl(... / a) to a percentage for color-mix().
	 *
	 * @param string $alpha Alpha token (e.g. ".08", "0.8", "80%", "var(--x)").
	 * @return string
	 */
	private function normalize_alpha_to_percentage( $alpha ) {
		$value = trim( (string) $alpha );
		if ( '' === $value ) {
			return '100%';
		}

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

		// Keep dynamic expressions usable in color-mix.
		return 'calc(' . $value . ' * 100%)';
	}

	/**
	 * Read selected post types from active migration option.
	 *
	 * @return array<int,string>
	 */
	private function get_selected_post_types_from_active_migration() {
		$active = get_option( 'efs_active_migration', array() );
		if ( ! is_array( $active ) || empty( $active['options'] ) || ! is_array( $active['options'] ) ) {
			return array();
		}

		$selected = isset( $active['options']['selected_post_types'] ) && is_array( $active['options']['selected_post_types'] )
			? $active['options']['selected_post_types']
			: array();

		$selected = array_values(
			array_filter(
				array_map( 'sanitize_key', $selected ),
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);

		return $selected;
	}

	/**
	 * Collect referenced Bricks global class identifiers from selected Bricks content.
	 *
	 * @param array<int,string> $selected_post_types Selected post types from wizard.
	 * @return array<string,bool> Map of class identifiers used in content.
	 */
	private function collect_referenced_global_class_ids( array $selected_post_types = array() ) {
		$this->has_scanned_bricks_content = false;

		$post_types = ! empty( $selected_post_types ) ? $selected_post_types : array( 'post', 'page', 'bricks_template' );
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				static function ( $value ) {
					return '' !== $value;
				}
			)
		);

		if ( empty( $post_types ) ) {
			return array();
		}

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

		$referenced = array();
		foreach ( $posts as $post ) {
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
			$this->has_scanned_bricks_content = true;

			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) {
					continue;
				}
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				if ( empty( $settings['_cssGlobalClasses'] ) || ! is_array( $settings['_cssGlobalClasses'] ) ) {
					continue;
				}
				foreach ( $settings['_cssGlobalClasses'] as $class_id ) {
					if ( ! is_scalar( $class_id ) ) {
						continue;
					}
					$key = trim( (string) $class_id );
					if ( '' !== $key ) {
						$referenced[ $key ] = true;
					}
				}

				// Bricks also stores direct class names in _cssClasses (space-separated).
				// Include these so class-restricted migration does not drop referenced globals.
				if ( ! empty( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] ) ) {
					$tokens = preg_split( '/\s+/', trim( $settings['_cssClasses'] ) );
					if ( is_array( $tokens ) ) {
						foreach ( $tokens as $token ) {
							$name = trim( (string) $token );
							if ( '' !== $name ) {
								$referenced[ $name ] = true;
							}
						}
					}
				}
			}
		}

		return $referenced;
	}

	/**
	 * Check whether a global class is referenced by migrated content.
	 *
	 * @param array             $class_data Bricks global class data.
	 * @param array<string,bool> $referenced_ids Referenced class identifiers.
	 * @return bool
	 */
	private function is_referenced_global_class( $class_data, array $referenced_ids ) {
		// If no Bricks content was available, keep previous behavior.
		if ( empty( $referenced_ids ) ) {
			return ! $this->has_scanned_bricks_content;
		}

		$class_id   = isset( $class_data['id'] ) ? trim( (string) $class_data['id'] ) : '';
		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';

		if ( '' !== $class_id && isset( $referenced_ids[ $class_id ] ) ) {
			return true;
		}
		if ( '' !== $class_name && isset( $referenced_ids[ $class_name ] ) ) {
			return true;
		}
		if ( '' !== $class_name ) {
			$prefixed = 'acss_import_' . $class_name;
			if ( isset( $referenced_ids[ $prefixed ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert single Bricks class to Etch format
	 */
	public function convert_bricks_class_to_etch( $bricks_class ) {
		// Use human-readable name instead of internal ID
		// Fallback to ID if name is not available
		$class_name = ! empty( $bricks_class['name'] ) ? $bricks_class['name'] : $bricks_class['id'];

		// Remove ACSS import prefix from class names
		$class_name = preg_replace( '/^acss_import_/', '', $class_name );

		// Convert base settings (if any)
		$css = '';
		if ( ! empty( $bricks_class['settings'] ) ) {
			$css = $this->convert_bricks_settings_to_css( $bricks_class['settings'], $class_name );

			// Convert responsive variants
			$responsive_css = $this->convert_responsive_variants( $bricks_class['settings'] );
			if ( ! empty( $responsive_css ) ) {
				$css .= ' ' . $responsive_css;
			}
		}

		$css = $this->normalize_css_variables( $css );
		$css = $this->move_image_fit_properties_to_nested_img( $class_name, $css );

		// Create entry even for empty classes (utility classes from CSS frameworks)
		return array(
			'type'       => 'class',
			'selector'   => '.' . $class_name,
			'collection' => 'default',
			'css'        => trim( $css ),
			'readonly'   => false,
		);
	}

	/**
	 * Move image fitting declarations from wrapper classes into nested img selector.
	 *
	 * Bricks often assigns image-class styles to a figure wrapper while the actual
	 * rendered image is an inner <img>. For these classes we migrate:
	 * - object-fit
	 * - aspect-ratio
	 * into:
	 * img { ... }
	 *
	 * @param string $class_name Class name without leading dot.
	 * @param string $css        CSS declarations generated for this class.
	 * @return string
	 */
	private function move_image_fit_properties_to_nested_img( $class_name, $css ) {
		$class_name = strtolower( trim( (string) $class_name ) );
		$css        = (string) $css;

		// Keep behavior scoped to image-like class selectors.
		if ( '' === $class_name || false === strpos( $class_name, 'image' ) ) {
			return $css;
		}

		$media_pos = strpos( $css, '@media' );
		$base_css  = false === $media_pos ? $css : substr( $css, 0, $media_pos );
		$media_css = false === $media_pos ? '' : substr( $css, $media_pos );

		if ( '' === trim( $base_css ) || preg_match( '/\bimg\s*\{/i', $base_css ) ) {
			return $css;
		}

		if ( ! preg_match_all( '/\b(object-fit|aspect-ratio)\s*:\s*([^;{}]+)\s*;/i', $base_css, $matches, PREG_SET_ORDER ) ) {
			return $css;
		}

		$img_declarations = array();
		foreach ( $matches as $match ) {
			$prop  = strtolower( trim( (string) $match[1] ) );
			$value = trim( (string) $match[2] );
			if ( '' === $value ) {
				continue;
			}
			$img_declarations[] = $prop . ': ' . $value . ';';
		}

		if ( empty( $img_declarations ) ) {
			return $css;
		}

		$base_css = preg_replace( '/\b(object-fit|aspect-ratio)\s*:\s*[^;{}]+\s*;/i', '', $base_css );
		$base_css = preg_replace( '/;{2,}/', ';', (string) $base_css );
		$base_css = trim( (string) $base_css );

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

	/**
	 * Convert responsive variants to media queries
	 */
	private function convert_responsive_variants( $settings ) {
		$responsive_css = '';

		// Bricks desktop-first breakpoint semantics.
		$breakpoints = array(
			'mobile_portrait'  => '(max-width: 478px)',
			'mobile_landscape' => '(max-width: 767px)',
			'tablet_portrait'  => '(max-width: 991px)',
			'tablet_landscape' => '(max-width: 1199px)',
			'desktop'          => '(min-width: 1200px)',
		);

		foreach ( $breakpoints as $breakpoint => $media_query ) {
			$breakpoint_settings = array();

			// Extract all properties for this breakpoint
			foreach ( $settings as $key => $value ) {
				if ( preg_match( '/:' . preg_quote( $breakpoint, '/' ) . '$/', (string) $key ) ) {
					// Remove breakpoint suffix to get base property name.
					$base_key                         = preg_replace( '/:' . preg_quote( $breakpoint, '/' ) . '$/', '', (string) $key );
					$breakpoint_settings[ $base_key ] = $value;
				}
			}

			if ( ! empty( $breakpoint_settings ) ) {
				$breakpoint_css = $this->convert_bricks_settings_to_css( $breakpoint_settings );
				if ( ! empty( $breakpoint_css ) ) {
					$responsive_css .= "\n@media " . $media_query . " {\n  " . str_replace( ';', ";\n  ", trim( $breakpoint_css ) ) . "\n}";
				}
			}
		}

		return $responsive_css;
	}

	/**
	 * Convert Bricks settings to CSS
	 */
	private function convert_bricks_settings_to_css( $settings, $class_name = '' ) {
		$css_properties = array();

		// Layout & Display
		$css_properties = array_merge( $css_properties, $this->convert_layout( $settings ) );

		// Flexbox Properties
		$css_properties = array_merge( $css_properties, $this->convert_flexbox( $settings ) );

		// Grid Properties
		$css_properties = array_merge( $css_properties, $this->convert_grid( $settings ) );

		// Sizing
		$css_properties = array_merge( $css_properties, $this->convert_sizing( $settings ) );

		// Background (Bricks stores this mostly in _background)
		if ( ! empty( $settings['_background'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_background( $settings['_background'] ) );
		} elseif ( ! empty( $settings['background'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_background( $settings['background'] ) );
		}

		// Gradient (Bricks uses _gradient setting)
		if ( ! empty( $settings['_gradient'] ) ) {
			$gradient_css = $this->convert_gradient( $settings['_gradient'] );
			if ( ! empty( $gradient_css ) ) {
				$css_properties[] = $gradient_css;
			}
		}

		// Border (Bricks uses _border)
		if ( ! empty( $settings['_border'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_border( $settings['_border'] ) );
		} elseif ( ! empty( $settings['border'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_border( $settings['border'] ) );
		}

		// Typography
		if ( ! empty( $settings['typography'] ) || ! empty( $settings['_typography'] ) ) {
			$typography     = ! empty( $settings['_typography'] ) ? $settings['_typography'] : $settings['typography'];
			$css_properties = array_merge( $css_properties, $this->convert_typography( $typography ) );
		}

		// Spacing
		if ( ! empty( $settings['spacing'] ) ) {
			$css_properties = array_merge( $css_properties, $this->convert_spacing( $settings['spacing'] ) );
		}

		// Margin & Padding (Bricks format)
		$css_properties = array_merge( $css_properties, $this->convert_margin_padding( $settings ) );

		// Position
		$css_properties = array_merge( $css_properties, $this->convert_position( $settings ) );

		// Transform & Effects (includes _transform, _boxShadow, _cssFilters, etc.)
		$css_properties = array_merge( $css_properties, $this->convert_effects( $settings ) );

		// Custom CSS is handled separately in parse_custom_css_stylesheet()
		// Don't include here to avoid duplication

		// Filter empty values
		$css_properties = array_filter( $css_properties );

		return implode( ' ', $css_properties );
	}

	/**
	 * Convert background settings
	 */
	private function convert_background( $background ) {
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
	 * Convert gradient settings
	 */
	private function convert_gradient( $gradient ) {
		if ( empty( $gradient['colors'] ) || ! is_array( $gradient['colors'] ) ) {
			return '';
		}

		// Build color stops
		$color_stops = array();
		foreach ( $gradient['colors'] as $color_data ) {
			$color_value = '';

			// Extract color value
			if ( is_array( $color_data['color'] ) ) {
				$color_value = $color_data['color']['raw'] ?? '';
			} else {
				$color_value = $color_data['color'] ?? '';
			}

			if ( empty( $color_value ) ) {
				continue;
			}

			// Add stop position if available
			if ( ! empty( $color_data['stop'] ) ) {
				$color_stops[] = $color_value . ' ' . $this->normalize_gradient_stop( $color_data['stop'] );
			} else {
				$color_stops[] = $color_value;
			}
		}

		if ( empty( $color_stops ) ) {
			return '';
		}

		// Build gradient CSS
		$gradient_type = $gradient['type'] ?? 'linear';
		$angle         = $gradient['angle'] ?? '180deg';

		if ( 'radial' === $gradient_type ) {
			return 'background-image: radial-gradient(' . implode( ', ', $color_stops ) . ');';
		} else {
			// Linear gradient (default)
			return 'background-image: linear-gradient(' . implode( ', ', $color_stops ) . ');';
		}
	}

	/**
	 * Normalize Bricks gradient stop values.
	 *
	 * Bricks can store plain numbers (e.g. "50") for stop positions.
	 * CSS color-stop positions require units, so we convert numeric values to percentages.
	 *
	 * @param mixed $stop Raw stop value from Bricks.
	 * @return string
	 */
	private function normalize_gradient_stop( $stop ) {
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
	 * Convert border settings
	 */
	private function convert_border( $border ) {
		$css = array();

		// Border width - can be string or array
		if ( ! empty( $border['width'] ) ) {
			if ( is_string( $border['width'] ) ) {
				$css[] = 'border-width: ' . $border['width'] . ';';
			} elseif ( is_array( $border['width'] ) ) {
				$top    = $border['width']['top'] ?? '0';
				$right  = $border['width']['right'] ?? '0';
				$bottom = $border['width']['bottom'] ?? '0';
				$left   = $border['width']['left'] ?? '0';
				$css[]  = 'border-width: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ';';
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

		// Border radius - can be string or array
		if ( ! empty( $border['radius'] ) ) {
			if ( is_string( $border['radius'] ) ) {
				$css[] = 'border-radius: ' . $border['radius'] . ';';
			} elseif ( is_array( $border['radius'] ) ) {
				$top    = $border['radius']['top'] ?? '0';
				$right  = $border['radius']['right'] ?? '0';
				$bottom = $border['radius']['bottom'] ?? '0';
				$left   = $border['radius']['left'] ?? '0';
				$css[]  = 'border-radius: ' . $top . ' ' . $right . ' ' . $bottom . ' ' . $left . ';';
			}
		}

		return $css;
	}

	/**
	 * Convert typography settings
	 */
	private function convert_typography( $typography ) {
		$css = array();

		// Font properties
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

		// Line properties
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

		// Text properties
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

		// Color
		if ( ! empty( $typography['color'] ) ) {
			$color_value = is_array( $typography['color'] ) ? ( $typography['color']['raw'] ?? '' ) : $typography['color'];
			if ( ! empty( $color_value ) ) {
				$css[] = 'color: ' . $color_value . ';';
			}
		}

		// Vertical align
		if ( ! empty( $typography['vertical-align'] ) ) {
			$css[] = 'vertical-align: ' . $typography['vertical-align'] . ';';
		} elseif ( ! empty( $typography['verticalAlign'] ) ) {
			$css[] = 'vertical-align: ' . $typography['verticalAlign'] . ';';
		}

		// White space
		if ( ! empty( $typography['white-space'] ) ) {
			$css[] = 'white-space: ' . $typography['white-space'] . ';';
		} elseif ( ! empty( $typography['whiteSpace'] ) ) {
			$css[] = 'white-space: ' . $typography['whiteSpace'] . ';';
		}

		return $css;
	}

	/**
	 * Convert spacing settings
	 */
	private function convert_spacing( $spacing ) {
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
	 * Convert layout properties
	 */
	private function convert_layout( $settings ) {
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

		if ( ! empty( $settings['_opacity'] ) ) {
			$css[] = 'opacity: ' . $settings['_opacity'] . ';';
		}

		if ( ! empty( $settings['_zIndex'] ) ) {
			$css[] = 'z-index: ' . $settings['_zIndex'] . ';';
		}

		return $css;
	}

	/**
	 * Convert flexbox properties
	 */
	private function convert_flexbox( $settings ) {
		$css = array();
		$has_flex_container_property = false;
		$has_display_defined         = ! empty( $settings['_display'] );
		$has_flex_direction_defined  = false;

		// Flex container properties
		// _direction is an alias for _flexDirection in Bricks
		$flex_direction = $settings['_flexDirection'] ?? $settings['_direction'] ?? '';
		if ( ! empty( $flex_direction ) ) {
			$css[] = 'flex-direction: ' . $flex_direction . ';';
			$has_flex_container_property = true;
			$has_flex_direction_defined  = true;
		}

		if ( ! empty( $settings['_flexWrap'] ) ) {
			$css[] = 'flex-wrap: ' . $settings['_flexWrap'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_justifyContent'] ) ) {
			$css[] = 'justify-content: ' . $settings['_justifyContent'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_alignItems'] ) ) {
			$css[] = 'align-items: ' . $settings['_alignItems'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_alignContent'] ) ) {
			$css[] = 'align-content: ' . $settings['_alignContent'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_rowGap'] ) ) {
			$css[] = 'row-gap: ' . $settings['_rowGap'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_columnGap'] ) ) {
			$css[] = 'column-gap: ' . $settings['_columnGap'] . ';';
			$has_flex_container_property = true;
		}

		if ( ! empty( $settings['_gap'] ) ) {
			$css[] = 'gap: ' . $settings['_gap'] . ';';
			$has_flex_container_property = true;
		}

		// Bricks "block" wrappers often carry flex-* props without an explicit display value.
		// Preserve expected behavior unless layout display is explicitly set elsewhere.
		if ( $has_flex_container_property && ! $has_display_defined ) {
			$injected = array( 'display: flex;' );

			// Bricks block defaults are column-oriented; preserve that when no explicit direction exists.
			if ( ! $has_flex_direction_defined ) {
				$injected[] = 'flex-direction: column;';
			}

			$css = array_merge( $injected, $css );
		}

		// Flex item properties
		if ( ! empty( $settings['_flexGrow'] ) ) {
			$css[] = 'flex-grow: ' . $settings['_flexGrow'] . ';';
		}

		if ( ! empty( $settings['_flexShrink'] ) ) {
			$css[] = 'flex-shrink: ' . $settings['_flexShrink'] . ';';
		}

		if ( ! empty( $settings['_flexBasis'] ) ) {
			$css[] = 'flex-basis: ' . $settings['_flexBasis'] . ';';
		}

		if ( ! empty( $settings['_alignSelf'] ) ) {
			$css[] = 'align-self: ' . $settings['_alignSelf'] . ';';
		}

		if ( ! empty( $settings['_order'] ) ) {
			$css[] = 'order: ' . $settings['_order'] . ';';
		}

		return $css;
	}

	/**
	 * Convert grid properties
	 */
	private function convert_grid( $settings ) {
		$css = array();

		if ( ! empty( $settings['_gridTemplateColumns'] ) ) {
			$css[] = 'grid-template-columns: ' . $settings['_gridTemplateColumns'] . ';';
		}

		if ( ! empty( $settings['_gridTemplateRows'] ) ) {
			$css[] = 'grid-template-rows: ' . $settings['_gridTemplateRows'] . ';';
		}

		// Grid gap (shorthand)
		if ( ! empty( $settings['_gridGap'] ) ) {
			$css[] = 'gap: ' . $settings['_gridGap'] . ';';
		}

		if ( ! empty( $settings['_gridColumnGap'] ) ) {
			$css[] = 'column-gap: ' . $settings['_gridColumnGap'] . ';';
		}

		if ( ! empty( $settings['_gridRowGap'] ) ) {
			$css[] = 'row-gap: ' . $settings['_gridRowGap'] . ';';
		}

		// Grid alignment
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

		// Grid item placement
		if ( ! empty( $settings['_gridItemColumnSpan'] ) ) {
			$css[] = 'grid-column: span ' . $settings['_gridItemColumnSpan'] . ';';
		}

		if ( ! empty( $settings['_gridItemRowSpan'] ) ) {
			$css[] = 'grid-row: span ' . $settings['_gridItemRowSpan'] . ';';
		}

		if ( ! empty( $settings['_gridItemColumnStart'] ) ) {
			$css[] = 'grid-column-start: ' . $settings['_gridItemColumnStart'] . ';';
		}

		if ( ! empty( $settings['_gridItemColumnEnd'] ) ) {
			$css[] = 'grid-column-end: ' . $settings['_gridItemColumnEnd'] . ';';
		}

		if ( ! empty( $settings['_gridItemRowStart'] ) ) {
			$css[] = 'grid-row-start: ' . $settings['_gridItemRowStart'] . ';';
		}

		if ( ! empty( $settings['_gridItemRowEnd'] ) ) {
			$css[] = 'grid-row-end: ' . $settings['_gridItemRowEnd'] . ';';
		}

		return $css;
	}

	/**
	 * Convert sizing properties to Logical Properties
	 */
	private function convert_sizing( $settings ) {
		$css = array();

		// Convert to logical properties
		if ( ! empty( $settings['_width'] ) ) {
			$css[] = 'inline-size: ' . $settings['_width'] . ';';
		}

		if ( ! empty( $settings['_height'] ) ) {
			$css[] = 'block-size: ' . $settings['_height'] . ';';
		}

		if ( ! empty( $settings['_minWidth'] ) ) {
			$css[] = 'min-inline-size: ' . $settings['_minWidth'] . ';';
		}

		if ( ! empty( $settings['_minHeight'] ) ) {
			$css[] = 'min-block-size: ' . $settings['_minHeight'] . ';';
		}

		if ( ! empty( $settings['_maxWidth'] ) ) {
			$css[] = 'max-inline-size: ' . $settings['_maxWidth'] . ';';
		}

		if ( ! empty( $settings['_maxHeight'] ) ) {
			$css[] = 'max-block-size: ' . $settings['_maxHeight'] . ';';
		}

		if ( ! empty( $settings['_aspectRatio'] ) ) {
			$css[] = 'aspect-ratio: ' . $settings['_aspectRatio'] . ';';
		}

		return $css;
	}

	/**
	 * Convert margin and padding (Bricks format) to Logical Properties
	 */
	private function convert_margin_padding( $settings ) {
		$css = array();

		// Margin - convert to logical properties
		$margin_top    = null;
		$margin_right  = null;
		$margin_bottom = null;
		$margin_left   = null;

		if ( ! empty( $settings['_margin'] ) ) {
			$margin = $settings['_margin'];
			if ( is_array( $margin ) ) {
				if ( array_key_exists( 'top', $margin ) ) {
					$margin_top = $margin['top'];
				}
				if ( array_key_exists( 'right', $margin ) ) {
					$margin_right = $margin['right'];
				}
				if ( array_key_exists( 'bottom', $margin ) ) {
					$margin_bottom = $margin['bottom'];
				}
				if ( array_key_exists( 'left', $margin ) ) {
					$margin_left = $margin['left'];
				}
			} else {
				$css[] = 'margin: ' . $margin . ';';
			}
		}

		// Individual margin properties (e.g., _marginTop, _marginBottom)
		if ( isset( $settings['_marginTop'] ) ) {
			$margin_top = $settings['_marginTop'];
		}
		if ( isset( $settings['_marginRight'] ) ) {
			$margin_right = $settings['_marginRight'];
		}
		if ( isset( $settings['_marginBottom'] ) ) {
			$margin_bottom = $settings['_marginBottom'];
		}
		if ( isset( $settings['_marginLeft'] ) ) {
			$margin_left = $settings['_marginLeft'];
		}

		$margin_block_value  = null;
		$margin_inline_value = null;

		if ( null !== $margin_top && null !== $margin_bottom && (string) $margin_top === (string) $margin_bottom ) {
			$margin_block_value = (string) $margin_top;
		}

		if ( null !== $margin_left && null !== $margin_right && (string) $margin_left === (string) $margin_right ) {
			$margin_inline_value = (string) $margin_left;
		}

		if ( null !== $margin_block_value && null !== $margin_inline_value && $margin_block_value === $margin_inline_value ) {
			$css[] = 'margin: ' . $margin_block_value . ';';
		} else {
			if ( null !== $margin_block_value ) {
				$css[] = 'margin-block: ' . $margin_block_value . ';';
			} else {
				if ( null !== $margin_top ) {
					$css[] = 'margin-block-start: ' . $margin_top . ';';
				}
				if ( null !== $margin_bottom ) {
					$css[] = 'margin-block-end: ' . $margin_bottom . ';';
				}
			}

			if ( null !== $margin_inline_value ) {
				$css[] = 'margin-inline: ' . $margin_inline_value . ';';
			} else {
				if ( null !== $margin_right ) {
					$css[] = 'margin-inline-end: ' . $margin_right . ';';
				}
				if ( null !== $margin_left ) {
					$css[] = 'margin-inline-start: ' . $margin_left . ';';
				}
			}
		}

		// Padding - convert to logical properties
		$padding_top    = null;
		$padding_right  = null;
		$padding_bottom = null;
		$padding_left   = null;

		if ( ! empty( $settings['_padding'] ) ) {
			$padding = $settings['_padding'];
			if ( is_array( $padding ) ) {
				if ( array_key_exists( 'top', $padding ) ) {
					$padding_top = $padding['top'];
				}
				if ( array_key_exists( 'right', $padding ) ) {
					$padding_right = $padding['right'];
				}
				if ( array_key_exists( 'bottom', $padding ) ) {
					$padding_bottom = $padding['bottom'];
				}
				if ( array_key_exists( 'left', $padding ) ) {
					$padding_left = $padding['left'];
				}
			} else {
				$css[] = 'padding: ' . $padding . ';';
			}
		}

		// Individual padding properties
		if ( isset( $settings['_paddingTop'] ) ) {
			$padding_top = $settings['_paddingTop'];
		}
		if ( isset( $settings['_paddingRight'] ) ) {
			$padding_right = $settings['_paddingRight'];
		}
		if ( isset( $settings['_paddingBottom'] ) ) {
			$padding_bottom = $settings['_paddingBottom'];
		}
		if ( isset( $settings['_paddingLeft'] ) ) {
			$padding_left = $settings['_paddingLeft'];
		}

		$padding_block_value  = null;
		$padding_inline_value = null;

		// Prefer shorthand when block-start and block-end are identical.
		if ( null !== $padding_top && null !== $padding_bottom && (string) $padding_top === (string) $padding_bottom ) {
			$padding_block_value = (string) $padding_top;
		}

		// Prefer shorthand when inline-start and inline-end are identical.
		if ( null !== $padding_left && null !== $padding_right && (string) $padding_left === (string) $padding_right ) {
			$padding_inline_value = (string) $padding_left;
		}

		// If all four values are equal, emit full shorthand.
		if ( null !== $padding_block_value && null !== $padding_inline_value && $padding_block_value === $padding_inline_value ) {
			$css[] = 'padding: ' . $padding_block_value . ';';
			return $css;
		}

		if ( null !== $padding_block_value ) {
			$css[] = 'padding-block: ' . $padding_block_value . ';';
		} else {
			if ( null !== $padding_top ) {
				$css[] = 'padding-block-start: ' . $padding_top . ';';
			}
			if ( null !== $padding_bottom ) {
				$css[] = 'padding-block-end: ' . $padding_bottom . ';';
			}
		}

		if ( null !== $padding_inline_value ) {
			$css[] = 'padding-inline: ' . $padding_inline_value . ';';
		} else {
			if ( null !== $padding_right ) {
				$css[] = 'padding-inline-end: ' . $padding_right . ';';
			}
			if ( null !== $padding_left ) {
				$css[] = 'padding-inline-start: ' . $padding_left . ';';
			}
		}

		return $css;
	}

	/**
	 * Convert position properties to Logical Properties
	 */
	private function convert_position( $settings ) {
		$css = array();

		if ( ! empty( $settings['_position'] ) ) {
			$css[] = 'position: ' . $settings['_position'] . ';';
		}

		// Convert to logical properties (inset-*)
		// Use isset() instead of !empty() to allow "0" values
		if ( isset( $settings['_top'] ) && '' !== $settings['_top'] ) {
			$css[] = 'inset-block-start: ' . $settings['_top'] . ';';
		}

		if ( isset( $settings['_right'] ) && '' !== $settings['_right'] ) {
			$css[] = 'inset-inline-end: ' . $settings['_right'] . ';';
		}

		if ( isset( $settings['_bottom'] ) && '' !== $settings['_bottom'] ) {
			$css[] = 'inset-block-end: ' . $settings['_bottom'] . ';';
		}

		if ( isset( $settings['_left'] ) && '' !== $settings['_left'] ) {
			$css[] = 'inset-inline-start: ' . $settings['_left'] . ';';
		}

		return $css;
	}

	/**
	 * Convert transform and effects
	 */
	private function convert_effects( $settings ) {
		$css = array();

		// Transform - can be string or array
		if ( ! empty( $settings['_transform'] ) ) {
			if ( is_string( $settings['_transform'] ) ) {
				$css[] = 'transform: ' . $settings['_transform'] . ';';
			} elseif ( is_array( $settings['_transform'] ) ) {
				$transform_parts = array();
				if ( ! empty( $settings['_transform']['translateX'] ) ) {
					$transform_parts[] = 'translateX(' . $settings['_transform']['translateX'] . 'px)';
				}
				if ( ! empty( $settings['_transform']['translateY'] ) ) {
					$transform_parts[] = 'translateY(' . $settings['_transform']['translateY'] . 'px)';
				}
				if ( ! empty( $settings['_transform']['scaleX'] ) ) {
					$transform_parts[] = 'scaleX(' . $settings['_transform']['scaleX'] . ')';
				}
				if ( ! empty( $settings['_transform']['scaleY'] ) ) {
					$transform_parts[] = 'scaleY(' . $settings['_transform']['scaleY'] . ')';
				}
				if ( ! empty( $settings['_transform']['rotateX'] ) ) {
					$transform_parts[] = 'rotateX(' . $settings['_transform']['rotateX'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['rotateY'] ) ) {
					$transform_parts[] = 'rotateY(' . $settings['_transform']['rotateY'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['rotateZ'] ) ) {
					$transform_parts[] = 'rotateZ(' . $settings['_transform']['rotateZ'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['skewX'] ) ) {
					$transform_parts[] = 'skewX(' . $settings['_transform']['skewX'] . 'deg)';
				}
				if ( ! empty( $settings['_transform']['skewY'] ) ) {
					$transform_parts[] = 'skewY(' . $settings['_transform']['skewY'] . 'deg)';
				}
				if ( ! empty( $transform_parts ) ) {
					$css[] = 'transform: ' . implode( ' ', $transform_parts ) . ';';
				}
			}
		}

		// Transform origin
		if ( ! empty( $settings['_transformOrigin'] ) ) {
			$css[] = 'transform-origin: ' . $settings['_transformOrigin'] . ';';
		}

		// Transition - usually string
		if ( ! empty( $settings['_transition'] ) && is_string( $settings['_transition'] ) ) {
			$css[] = 'transition: ' . $settings['_transition'] . ';';
		}

		if ( ! empty( $settings['_cssTransition'] ) && is_string( $settings['_cssTransition'] ) ) {
			$css[] = 'transition: ' . $settings['_cssTransition'] . ';';
		}

		// CSS Filters - array format
		if ( ! empty( $settings['_cssFilters'] ) && is_array( $settings['_cssFilters'] ) ) {
			$filter_parts = array();
			if ( isset( $settings['_cssFilters']['blur'] ) ) {
				$filter_parts[] = 'blur(' . $settings['_cssFilters']['blur'] . 'px)';
			}
			if ( isset( $settings['_cssFilters']['brightness'] ) ) {
				$filter_parts[] = 'brightness(' . $settings['_cssFilters']['brightness'] . '%)';
			}
			if ( isset( $settings['_cssFilters']['contrast'] ) ) {
				$filter_parts[] = 'contrast(' . $settings['_cssFilters']['contrast'] . '%)';
			}
			if ( isset( $settings['_cssFilters']['hue-rotate'] ) ) {
				$filter_parts[] = 'hue-rotate(' . $settings['_cssFilters']['hue-rotate'] . 'deg)';
			}
			if ( isset( $settings['_cssFilters']['invert'] ) ) {
				$filter_parts[] = 'invert(' . $settings['_cssFilters']['invert'] . '%)';
			}
			if ( isset( $settings['_cssFilters']['opacity'] ) ) {
				$filter_parts[] = 'opacity(' . $settings['_cssFilters']['opacity'] . '%)';
			}
			if ( isset( $settings['_cssFilters']['saturate'] ) ) {
				$filter_parts[] = 'saturate(' . $settings['_cssFilters']['saturate'] . '%)';
			}
			if ( isset( $settings['_cssFilters']['sepia'] ) ) {
				$filter_parts[] = 'sepia(' . $settings['_cssFilters']['sepia'] . '%)';
			}
			if ( ! empty( $filter_parts ) ) {
				$css[] = 'filter: ' . implode( ' ', $filter_parts ) . ';';
			}
		}

		// Filter - string format
		if ( ! empty( $settings['_filter'] ) && is_string( $settings['_filter'] ) ) {
			$css[] = 'filter: ' . $settings['_filter'] . ';';
		}

		// Backdrop filter - usually string
		if ( ! empty( $settings['_backdropFilter'] ) && is_string( $settings['_backdropFilter'] ) ) {
			$css[] = 'backdrop-filter: ' . $settings['_backdropFilter'] . ';';
		}

		// Box shadow - can be string or array
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
				$offset_x = isset( $shadow['offsetX'] ) ? $shadow['offsetX'] : '0';
				$offset_y = isset( $shadow['offsetY'] ) ? $shadow['offsetY'] : '0';
				$blur     = isset( $shadow['blur'] ) ? $shadow['blur'] : '0';
				$spread   = isset( $shadow['spread'] ) ? $shadow['spread'] : '0';
				$css[]    = 'box-shadow: ' . $inset . $offset_x . 'px ' . $offset_y . 'px ' . $blur . 'px ' . $spread . 'px ' . $color . ';';
			}
		}

		// Text shadow - can be string or array
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
	 * Convert physical properties to logical properties in CSS
	 * IMPORTANT: Does NOT convert media queries - only CSS properties!
	 */
	private function convert_to_logical_properties( $css ) {
		$css = $this->normalize_css_variables( $css );

		// Map of physical to logical properties
		$property_map = array(
			// Margin
			'margin-top'     => 'margin-block-start',
			'margin-right'   => 'margin-inline-end',
			'margin-bottom'  => 'margin-block-end',
			'margin-left'    => 'margin-inline-start',
			// Padding
			'padding-top'    => 'padding-block-start',
			'padding-right'  => 'padding-inline-end',
			'padding-bottom' => 'padding-block-end',
			'padding-left'   => 'padding-inline-start',
			// Border
			'border-top'     => 'border-block-start',
			'border-right'   => 'border-inline-end',
			'border-bottom'  => 'border-block-end',
			'border-left'    => 'border-inline-start',
			// Position
			'top'            => 'inset-block-start',
			'right'          => 'inset-inline-end',
			'bottom'         => 'inset-block-end',
			'left'           => 'inset-inline-start',
			// Size (but NOT in media queries!)
			'width'          => 'inline-size',
			'height'         => 'block-size',
			'min-width'      => 'min-inline-size',
			'min-height'     => 'min-block-size',
			'max-width'      => 'max-inline-size',
			'max-height'     => 'max-block-size',
		);

		// Extract and temporarily replace media queries to protect them
		$media_queries      = array();
		$placeholder_prefix = '___MEDIA_QUERY_';
		$placeholder_count  = 0;

		// Extract media queries
		$css = preg_replace_callback(
			'/@media[^{}]+\{[^}]*\}/s',
			function ( $match_data ) use ( &$media_queries, &$placeholder_count, $placeholder_prefix ) {
				$placeholder                   = $placeholder_prefix . $placeholder_count . '___';
				$media_queries[ $placeholder ] = $match_data[0];
				$placeholder_count++;
				return $placeholder;
			},
			$css
		);

		// Replace each physical property with logical equivalent (only outside media queries)
		foreach ( $property_map as $physical => $logical ) {
			// Match property with colon and optional whitespace
			$css = preg_replace( '/\b' . preg_quote( $physical, '/' ) . '\s*:/i', $logical . ':', $css );
		}

		// Restore media queries (unchanged)
		foreach ( $media_queries as $placeholder => $original ) {
			$css = str_replace( $placeholder, $original, $css );
		}

		return $css;
	}

	/**
	 * Normalize known Bricks/Framer variable aliases to Etch variables.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private function normalize_css_variables( $css ) {
		if ( '' === (string) $css ) {
			return $css;
		}

		$css = preg_replace_callback(
			'/\b(row-gap|column-gap|gap)\s*:\s*([a-z][a-z0-9_-]*)\s*;/i',
			static function ( $matches ) {
				$property = strtolower( (string) $matches[1] );
				$value    = strtolower( (string) $matches[2] );
				$keywords = array(
					'normal',
					'inherit',
					'initial',
					'unset',
					'revert',
					'revert-layer',
				);

				if ( in_array( $value, $keywords, true ) ) {
					return $property . ': ' . $value . ';';
				}

				// Bare identifiers are invalid for gap properties unless they are CSS-wide keywords.
				// Drop invalid declarations instead of guessing a CSS variable.
				return '';
			},
			$css
		);

		$css = preg_replace( '/row-gap:\s*([^;{}]+);\s*column-gap:\s*\1\s*;/i', 'gap: $1;', $css );
		$css = preg_replace( '/column-gap:\s*([^;{}]+);\s*row-gap:\s*\1\s*;/i', 'gap: $1;', $css );
		$css = $this->normalize_identical_shorthands( $css );
		$css = preg_replace( '/var\(\s*--fr-container-gap\s*\)/i', 'var(--container-gap)', $css );
		$css = preg_replace( '/var\(\s*--fr-card-gap\s*\)/i', 'var(--card-gap, var(--content-gap))', $css );
		$css = preg_replace_callback(
			'/var\(\s*--([a-z0-9_-]+?)-trans-([0-9]{1,3})\s*\)/i',
			static function ( $matches ) {
				$base_color = strtolower( (string) $matches[1] );
				$percent    = (int) $matches[2];
				$percent    = max( 0, min( 100, $percent ) );

				return 'color-mix(in oklch, var(--' . $base_color . ') ' . $percent . '%, transparent)';
			},
			$css
		);

		return $css;
	}

	/**
	 * Collapse logical longhands to shorthand only when all four side values are identical.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private function normalize_identical_shorthands( $css ) {
		if ( '' === (string) $css ) {
			return $css;
		}

		$css = preg_replace_callback(
			'/\{([^{}]*)\}/s',
			function ( $matches ) {
				return '{' . $this->collapse_known_quad_shorthands( $matches[1] ) . '}';
			},
			$css
		);

		// Also normalize declaration-only strings (no selector braces),
		// which are common in generated Etch style payloads.
		if ( false === strpos( $css, '{' ) && false === strpos( $css, '}' ) ) {
			$css = $this->collapse_known_quad_shorthands( $css );
		}

		return $css;
	}

	/**
	 * Apply known quad-shorthand collapses to a declaration list.
	 *
	 * @param string $decls Declaration block without braces.
	 * @return string
	 */
	private function collapse_known_quad_shorthands( $decls ) {
		$decls = $this->collapse_quad_shorthand(
			$decls,
			array(
				'inset-block-start',
				'inset-inline-end',
				'inset-block-end',
				'inset-inline-start',
			),
			'inset'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array(
				'padding-block-start',
				'padding-inline-end',
				'padding-block-end',
				'padding-inline-start',
			),
			'padding'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array(
				'margin-block-start',
				'margin-inline-end',
				'margin-block-end',
				'margin-inline-start',
			),
			'margin'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array(
				'border-start-start-radius',
				'border-start-end-radius',
				'border-end-end-radius',
				'border-end-start-radius',
			),
			'border-radius'
		);

		$decls = $this->collapse_quad_shorthand(
			$decls,
			array(
				'border-top-left-radius',
				'border-top-right-radius',
				'border-bottom-right-radius',
				'border-bottom-left-radius',
			),
			'border-radius'
		);

		return $decls;
	}

	/**
	 * Collapse four longhand declarations into one shorthand when all values match.
	 *
	 * @param string             $decls Declaration block without braces.
	 * @param array<int,string>  $properties Ordered list of four longhand properties.
	 * @param string             $shorthand Target shorthand property.
	 * @return string
	 */
	private function collapse_quad_shorthand( $decls, array $properties, $shorthand ) {
		$values = array();

		foreach ( $properties as $property ) {
			$pattern = '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:\s*([^;{}]+)\s*;/i';
			if ( ! preg_match( $pattern, $decls, $match ) ) {
				return $decls;
			}
			$values[] = trim( (string) $match[1] );
		}

		$top    = $values[0];
		$right  = $values[1];
		$bottom = $values[2];
		$left   = $values[3];

		if ( $top === $right && $top === $bottom && $top === $left ) {
			$shorthand_value = $top;
		} elseif ( $top === $bottom && $right === $left ) {
			$shorthand_value = $top . ' ' . $right;
		} elseif ( $right === $left ) {
			$shorthand_value = $top . ' ' . $right . ' ' . $bottom;
		} else {
			return $decls;
		}

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
	 * Get Etch element styles (readonly)
	 */
	private function get_etch_element_styles() {
		return array(
			'etch-section-style'   => array(
				'type'       => 'element',
				'selector'   => ':where([data-etch-element="section"])',
				'collection' => 'default',
				'css'        => 'inline-size: 100%; display: flex; flex-direction: column; align-items: center;',
				'readonly'   => true,
			),
			'etch-container-style' => array(
				'type'       => 'element',
				'selector'   => ':where([data-etch-element="container"])',
				'collection' => 'default',
				'css'        => 'inline-size: 100%; display: flex; flex-direction: column; max-width: var(--content-width, 1366px); align-self: center;',
				'readonly'   => true,
			),
			'etch-iframe-style'    => array(
				'type'       => 'element',
				'selector'   => ':where([data-etch-element="iframe"])',
				'collection' => 'default',
				'css'        => 'inline-size: 100%; height: auto; aspect-ratio: 16/9;',
				'readonly'   => true,
			),
		);
	}

	/**
	 * Get Etch CSS variables (custom type)
	 */
	private function get_etch_css_variables() {
		$css_variables = array();

		// Extract CSS variables from Bricks global classes
		$bricks_classes = get_option( 'bricks_global_classes', array() );

		foreach ( $bricks_classes as $class ) {
			if ( ! empty( $class['settings']['_cssCustom'] ) ) {
				$custom_css = $class['settings']['_cssCustom'];

				// Extract CSS variables (--variable-name: value;)
				if ( preg_match_all( '/--([a-zA-Z0-9_-]+):\s*([^;]+);/', $custom_css, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $match ) {
						$css_variables[ '--' . $match[1] ] = trim( $match[2] );
					}
				}
			}
		}

		if ( empty( $css_variables ) ) {
			return array();
		}

		$css_string = '';
		foreach ( $css_variables as $variable => $value ) {
			$css_string .= $variable . ': ' . $value . '; ';
		}

		return array(
			'etch-global-variable-style' => array(
				'type'       => 'custom',
				'selector'   => ':root',
				'collection' => 'default',
				'css'        => trim( $css_string ),
				'readonly'   => false,
			),
		);
	}

	/**
	 * Parse custom CSS stylesheet and extract individual rules per selector
	 * Now handles media queries and nested rules properly
	 */
	private function parse_custom_css_stylesheet( $stylesheet, $style_map = array() ) {
		$styles = array();

		// Find ALL unique class names in the stylesheet
		preg_match_all( '/\.([a-zA-Z0-9_-]+)/', $stylesheet, $all_class_matches );
		if ( empty( $all_class_matches[1] ) ) {
			return $styles;
		}

		// Get unique class names
		$class_names = array_unique( $all_class_matches[1] );

		foreach ( $class_names as $class_name ) {
			// Extract all CSS rules for this specific class
			$class_css = $this->extract_css_for_class( $stylesheet, $class_name );

			if ( empty( trim( $class_css ) ) ) {
				continue;
			}

			// Find the existing style ID for this class from style_map
			$style_id = null;
			foreach ( $style_map as $bricks_id => $style_data ) {
				$selector = is_array( $style_data ) ? $style_data['selector'] : '';
				// Match selector (with or without leading dot)
				if ( '.' . $class_name === $selector || $class_name === $selector ) {
					$style_id = is_array( $style_data ) ? $style_data['id'] : $style_data;
					$this->log_debug_info(
						'CSS Converter: Found existing style mapping',
						array(
							'class_name' => $class_name,
							'bricks_id'  => $bricks_id,
							'etch_id'    => $style_id,
						)
					);
					break;
				}
			}

			// If no existing style found, skip this class (it's not in our style map)
			if ( ! $style_id ) {
				$this->log_debug_info(
					'CSS Converter: Skipping custom CSS outside style map',
					array(
						'class_name' => $class_name,
					)
				);
				continue;
			}

			// Convert nested selectors to use & (ampersand) for proper CSS nesting
			$converted_css = $this->convert_nested_selectors_to_ampersand( $class_css, $class_name );

			// Store the custom CSS for this class
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
	 * Extract all CSS rules for a specific class from stylesheet
	 * Supports media queries and nested rules
	 */
	private function extract_css_for_class( $stylesheet, $class_name ) {
		$escaped_class = preg_quote( $class_name, '/' );
		$class_token   = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])/';
		$rule_start    = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{/';
		$css_parts     = array();

		// Split stylesheet into lines for easier processing
		$lines         = explode( "\n", $stylesheet );
		$current_media = null;
		$media_content = '';
		$brace_count   = 0;
		$in_media      = false;

		for ( $i = 0; $i < count( $lines ); $i++ ) {
			$line = $lines[ $i ];

			// Check for media query start
			if ( preg_match( '/@media\s+([^{]+)\{/', $line, $media_match ) ) {
				$current_media = '@media ' . trim( $media_match[1] );
				$media_content = '';
				$brace_count   = 1;
				$in_media      = true;
				continue;
			}

			// If we're in a media query, collect content
			if ( $in_media ) {
				// Count braces to know when media query ends
				$brace_count += substr_count( $line, '{' );
				$brace_count -= substr_count( $line, '}' );

				// Check if this line contains our class
				if ( preg_match( $class_token, $line ) ) {
					$media_content .= $line . "\n";
				} else {
					$media_content .= $line . "\n";
				}

				// Media query ended
				if ( 0 === $brace_count ) {
					// Check if media query contains our class
					if ( preg_match( $class_token, $media_content ) ) {
						$css_parts[] = $current_media . " {\n" . trim( $media_content ) . "\n}";
					}
					$in_media      = false;
					$current_media = null;
					$media_content = '';
				}
				continue;
			}

			// Not in media query - check for direct class rules
			if ( preg_match( $rule_start, $line ) ) {
				// Start of a rule for our class
				$rule        = $line . "\n";
				$brace_count = substr_count( $line, '{' ) - substr_count( $line, '}' );

				// Collect until rule ends
				while ( $brace_count > 0 && $i < count( $lines ) - 1 ) {
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
	 * Convert nested selectors to use & (ampersand) for CSS nesting
	 *
	 * Combines multiple rules for the same class into nested CSS:
	 *
	 * Input:
	 * .my-class { padding: 1rem; }
	 * .my-class > * { color: red; }
	 *
	 * Output:
	 * padding: 1rem;
	 *
	 * & > * {
	 *   color: red;
	 * }
	 *
	 * @param string $css Custom CSS
	 * @param string $class_name Base class name (without dot)
	 * @return string Converted CSS
	 */
	private function convert_nested_selectors_to_ampersand( $css, $class_name ) {
		$escaped_class = preg_quote( $class_name, '/' );

		// Parse all CSS rules for this class
		$rules         = array();
		$main_css      = '';
		$media_queries = array();

		// First, extract media queries with proper brace counting
		$media_queries = $this->extract_media_queries( $css, $class_name );

		// Remove media queries from main CSS
		foreach ( $media_queries as $mq ) {
			$css = str_replace( $mq['original'], '', $css );
		}

		// Pattern to match CSS rules: .selector { ... }
		// This handles multi-line rules properly
		// Capture everything between class name and opening brace
		$pattern = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$selector_suffix = $match[1]; // e.g., " > *", ":hover", " .child", ""
				$rule_content    = trim( $match[2] );

				$selector_suffix = $this->normalize_selector_suffix_with_ampersand( $selector_suffix, $class_name );

				// Check if this is the main selector (no suffix or only whitespace)
				if ( empty( trim( $selector_suffix ) ) ) {
					// This is the main selector (.my-class { ... })
					$main_css .= $rule_content . "\n";
				} else {
					$rules[] = array(
						'selector' => $selector_suffix,
						'css'      => $rule_content,
					);
				}
			}
		}

		// Build the final nested CSS
		$result = trim( $main_css );

		// Add nested rules
		foreach ( $rules as $rule ) {
			if ( ! empty( $result ) ) {
				$result .= "\n\n";
			}
			$result .= $rule['selector'] . " {\n  " . trim( $rule['css'] ) . "\n}";
		}

		// Add media queries
		foreach ( $media_queries as $media ) {
			if ( ! empty( $result ) ) {
				$result .= "\n\n";
			}
			$result .= $media['condition'] . " {\n  " . $media['css'] . "\n}";
		}

		// If no rules were found, just convert inline selectors
		if ( empty( $result ) ) {
			$result = preg_replace( '/\.' . $escaped_class . '(\s+[>+~]|\s+[.#\[]|::|:)/', '&$1', $css );
		}

		$this->log_debug_info(
			'CSS Converter: Converted nested selectors',
			array(
				'class_name' => $class_name,
			)
		);

		return $result;
	}

	/**
	 * Extract media queries from CSS with proper brace counting
	 *
	 * @param string $css CSS content
	 * @param string $class_name Class name to look for
	 * @return array Array of media queries with 'condition', 'css', and 'original'
	 */
	private function extract_media_queries( $css, $class_name ) {
		$media_queries = array();
		$pos           = 0;
		$length        = strlen( $css );
		$class_token   = '/\.' . preg_quote( $class_name, '/' ) . '(?![a-zA-Z0-9_-])/';

		while ( $pos < $length ) {
			// Find next @media
			$media_pos = strpos( $css, '@media', $pos );
			if ( false === $media_pos ) {
				break;
			}

			// Find opening brace
			$open_brace = strpos( $css, '{', $media_pos );
			if ( false === $open_brace ) {
				break;
			}

			// Extract media condition
			$media_condition = trim( substr( $css, $media_pos + 6, $open_brace - $media_pos - 6 ) );

			// Count braces to find matching closing brace
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
				// Extract content (without outer braces)
				$media_content = substr( $css, $content_start, $i - $content_start - 1 );

				// Check if this media query contains our class
				if ( preg_match( $class_token, $media_content ) ) {
					// Convert selectors to & syntax
					$converted_content = $this->convert_selectors_in_media_query( $media_content, $class_name );

					$media_queries[] = array(
						'condition' => '@media ' . $media_condition,
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
	 * Convert selectors inside media query to & syntax
	 */
	private function convert_selectors_in_media_query( $media_content, $class_name ) {
		$escaped_class = preg_quote( $class_name, '/' );
		$rules         = array();

		$this->log_debug_info(
			'CSS Converter: Converting media query content',
			array(
				'class_name'     => $class_name,
				'content_length' => strlen( $media_content ),
			)
		);

		// Pattern to match CSS rules inside media query
		$pattern = '/\.' . $escaped_class . '(?![a-zA-Z0-9_-])([^{]*?)\{([^}]*)\}/s';

		if ( preg_match_all( $pattern, $media_content, $matches, PREG_SET_ORDER ) ) {
			$this->log_debug_info(
				'CSS Converter: Found media query rules',
				array(
					'count' => count( $matches ),
				)
			);

			foreach ( $matches as $match ) {
				$selector_suffix = trim( $match[1] );
				$rule_content    = trim( $match[2] );
				$this->log_debug_info(
					'CSS Converter: Processing media query rule',
					array(
						'length' => strlen( $rule_content ),
					)
				);

				$nested_selector = $this->normalize_selector_suffix_with_ampersand( $selector_suffix, $class_name );
				if ( '' === trim( $nested_selector ) ) {
					$nested_selector = '&';
				}

				$rules[] = $nested_selector . " {\n    " . $rule_content . "\n  }";
			}
		} else {
			$this->log_debug_info(
				'CSS Converter: No media query rules matched class',
				array(
					'class_name' => $class_name,
				)
			);
		}

		$result = implode( "\n\n  ", $rules );
		$this->log_debug_info(
			'CSS Converter: Media query result compiled',
			array(
				'length' => strlen( $result ),
			)
		);

		return $result;
	}

	/**
	 * Normalize selector suffixes for nested output.
	 *
	 * Converts duplicate source-class selectors in comma lists, e.g.
	 * "::before, .my-class::after" => "&::before, &::after"
	 *
	 * @param string $selector_suffix Selector part captured after ".class".
	 * @param string $class_name Base class name.
	 * @return string
	 */
	private function normalize_selector_suffix_with_ampersand( $selector_suffix, $class_name ) {
		$suffix = trim( (string) $selector_suffix );
		if ( '' === $suffix ) {
			return '';
		}

		$class_pattern = '/\.' . preg_quote( $class_name, '/' ) . '\b/i';
		$suffix        = preg_replace( $class_pattern, '&', $suffix );
		$suffix        = is_string( $suffix ) ? $suffix : trim( (string) $selector_suffix );

		$parts = array_map( 'trim', explode( ',', $suffix ) );
		$parts = array_filter(
			$parts,
			static function ( $part ) {
				return '' !== $part;
			}
		);

		if ( empty( $parts ) ) {
			return '';
		}

		$normalized_parts = array();
		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '&' ) ) {
				$normalized_parts[] = $part;
				continue;
			}

			// Add space after & for combinators and descendant selectors.
			if ( preg_match( '/^[>+~]/', $part ) || preg_match( '/^[.#\[]/', $part ) ) {
				$normalized_parts[] = '& ' . $part;
			} else {
				$normalized_parts[] = '&' . $part;
			}
		}

		return implode( ', ', $normalized_parts );
	}

	/**
	 * Generate 7-character hash ID for styles
	 */
	private function generate_style_hash( $class_name ) {
		// Use the same ID generation as Etch: substr(uniqid(), -7)
		// Note: This generates random IDs, not deterministic ones
		// But it matches Etch's format exactly
		return substr( uniqid(), -7 );
	}

	/**
	 * Import Etch styles to target site
	 */
	public function import_etch_styles( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_styles', 'Invalid styles data provided' );
		}

		// Extract styles and style_map from data
		$etch_styles = $data['styles'] ?? $data; // Fallback to old format
		$style_map   = $data['style_map'] ?? array();

		$this->log_debug_info(
			'CSS Converter: import_etch_styles invoked',
			array(
				'count_styles'    => count( $etch_styles ),
				'count_style_map' => count( $style_map ),
			)
		);

		// Get existing etch_styles (for Etch Editor)
		$existing_styles = $this->style_repository->get_etch_styles();

		// Merge with new styles
		$merged_styles = array_merge( $existing_styles, $etch_styles );

		// NOTE: We only save to etch_styles, NOT etch_global_stylesheets
		// - etch_styles: Used by Etch's StylesRegister to render styles on pages that use them
		// - etch_global_stylesheets: Only for manually entered global CSS (not for classes)

		// TEST: Bypass Etch API to check if selectors are preserved
		$bypass_api = true; // Set to false to use Etch API again

		if ( $bypass_api ) {
			$this->log_debug_info( 'CSS Converter: Bypassing Etch API for direct option update' );

			// DEBUG: Log first 3 styles BEFORE saving
			$style_keys = array_keys( $merged_styles );
			for ( $i = 0; $i < min( 3, count( $style_keys ) ); $i++ ) {
				$key   = $style_keys[ $i ];
				$style = $merged_styles[ $key ];
				$this->log_debug_info(
					'CSS Converter: Previewing style before save',
					array(
						'key'      => $key,
						'selector' => $style['selector'] ?? '',
					)
				);
			}

			// Save directly to database
			$update_result = $this->style_repository->save_etch_styles( $merged_styles );

			if ( ! $update_result ) {
				$this->error_handler->log_info(
					'CSS Converter: Style option update returned false',
					array(
						'context' => 'Option may already exist with same value',
					)
				);
			} else {
				$this->log_debug_info( 'CSS Converter: Style option updated successfully' );
			}

			// DEBUG: Log first 3 styles AFTER saving (verify from DB)
			$saved_styles = $this->style_repository->get_etch_styles();
			$this->log_debug_info(
				'CSS Converter: Retrieved styles from database',
				array(
					'count' => count( $saved_styles ),
				)
			);

			for ( $i = 0; $i < min( 3, count( $style_keys ) ); $i++ ) {
				$key         = $style_keys[ $i ];
				$saved_style = $saved_styles[ $key ] ?? null;
				if ( $saved_style ) {
					$this->log_debug_info(
						'CSS Converter: Verified saved style',
						array(
							'key'      => $key,
							'selector' => $saved_style['selector'] ?? '',
						)
					);
				} else {
					$this->error_handler->log_info(
						'CSS Converter: Saved style missing after database read',
						array(
							'key' => $key,
						)
					);
				}
			}

			// Manually trigger cache invalidation
			$this->style_repository->invalidate_style_cache();

			// Trigger CSS rebuild
			$this->trigger_etch_css_rebuild();

			$this->log_debug_info(
				'CSS Converter: Direct save complete',
				array(
					'saved_styles' => count( $merged_styles ),
				)
			);

			// Style map was already created during conversion!
			// Just save it to WordPress options
			$this->style_repository->save_style_map( $style_map );
			$this->log_debug_info(
				'CSS Converter: Saved style map',
				array(
					'count' => count( $style_map ),
				)
			);

			// Log first few mappings for debugging
			$map_entries = array_slice( $style_map, 0, 3, true );
			foreach ( $map_entries as $bricks_id => $etch_id ) {
				$this->log_debug_info(
					'CSS Converter: Style map entry saved',
					array(
						'bricks_id' => $bricks_id,
						'etch_id'   => $etch_id,
					)
				);
			}

			return true;

		} else {
			// Fallback to direct DB access if Etch API not available
			$this->error_handler->log_info(
				'CSS Converter: Etch API unavailable, using fallback persistence path',
				array(
					'count_styles' => count( $merged_styles ),
				)
			);

			$result = $this->style_repository->save_etch_styles( $merged_styles );

			if ( ! $result && empty( $existing_styles ) ) {
				return new \WP_Error( 'save_failed', 'Failed to save styles to database' );
			}

			// Manual cache invalidation for fallback
			$this->style_repository->increment_svg_version();
			$this->style_repository->invalidate_style_cache();

			// Trigger Etch CSS rebuild
			$this->trigger_etch_css_rebuild();

			return true;
		}
	}

	/**
	 * Trigger Etch CSS rebuild
	 * Forces Etch to regenerate CSS files from styles
	 */
	private function trigger_etch_css_rebuild() {
		$this->log_debug_info( 'CSS Converter: Triggering Etch CSS rebuild' );

		// Method 1: Increment SVG version (forces cache invalidation)
		$current_version = $this->style_repository->get_svg_version();
		$new_version     = $this->style_repository->increment_svg_version();
		$this->log_debug_info(
			'CSS Converter: Updated SVG version',
			array(
				'previous' => $current_version,
				'current'  => $new_version,
			)
		);

		// Method 2: Clear all Etch caches
		$this->style_repository->invalidate_style_cache();
		$this->log_debug_info( 'CSS Converter: Cleared Etch caches' );

		// Method 3: Trigger WordPress actions that Etch might listen to
		do_action( 'etch_fusion_suite_styles_updated' );
		do_action( 'etch_fusion_suite_rebuild_css' );
		$this->log_debug_info( 'CSS Converter: Triggered Etch CSS action hooks' );

		// Note: We don't call Etch's internal classes directly because:
		// - StylesheetService has protected constructor (Singleton)
		// - StylesRegister handles rendering automatically when blocks are processed
		// - Cache invalidation via etch_svg_version is sufficient

		$this->log_debug_info( 'CSS Converter: CSS rebuild trigger complete' );
	}

	/**
	 * Save styles to etch_global_stylesheets for frontend rendering
	 *
	 * Etch uses etch_global_stylesheets to render CSS in the frontend
	 * This converts our etch_styles format to the global stylesheet format
	 */
	private function save_to_global_stylesheets( $etch_styles ) {
		$this->log_debug_info( 'CSS Converter: Saving styles to etch_global_stylesheets' );

		// Get existing global stylesheets
		$existing_global = $this->style_repository->get_global_stylesheets();

		// Convert etch_styles format to global stylesheet format
		// Global stylesheets format: array of {name, css} objects
		$new_stylesheets = array();

		foreach ( $etch_styles as $style_id => $style ) {
			// Skip element styles (they're built-in)
			if ( isset( $style['type'] ) && 'element' === $style['type'] ) {
				continue;
			}

			// Create stylesheet entry
			$stylesheet_name = isset( $style['selector'] ) ? $style['selector'] : $style_id;
			$stylesheet_css  = isset( $style['css'] ) ? $style['css'] : '';

			// Skip empty styles
			if ( empty( $stylesheet_css ) ) {
				continue;
			}

			// Wrap CSS with selector if not already wrapped
			if ( ! empty( $stylesheet_name ) && false === strpos( $stylesheet_css, $stylesheet_name ) ) {
				$wrapped_css = $stylesheet_name . ' { ' . $stylesheet_css . ' }';
			} else {
				$wrapped_css = $stylesheet_css;
			}

			$new_stylesheets[ $style_id ] = array(
				'name' => $stylesheet_name,
				'css'  => $wrapped_css,
			);
		}

		// Merge with existing global stylesheets
		$merged_global = array_merge( $existing_global, $new_stylesheets );

		// Save to database
		$result = $this->style_repository->save_global_stylesheets( $merged_global );

		if ( $result ) {
			$this->log_debug_info(
				'CSS Converter: Saved global stylesheets',
				array(
					'new_stylesheets'   => count( $new_stylesheets ),
					'total_stylesheets' => count( $merged_global ),
				)
			);
		} else {
			$this->error_handler->log_info(
				'CSS Converter: Failed to update etch_global_stylesheets',
				array(
					'new_stylesheets' => count( $new_stylesheets ),
				)
			);
		}

		return $result;
	}

	/**
	 * Validate CSS syntax
	 */
	public function validate_css_syntax( $css ) {
		// Basic CSS validation
		if ( empty( $css ) ) {
			return true;
		}

		// Check for basic CSS syntax errors
		$errors = array();

		// Check for unclosed brackets
		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			$errors[] = 'Unclosed CSS brackets';
		}

		// Check for unclosed quotes
		$single_quotes = substr_count( $css, "'" );
		$double_quotes = substr_count( $css, '"' );

		if ( 0 !== $single_quotes % 2 ) {
			$errors[] = 'Unclosed single quotes';
		}

		if ( 0 !== $double_quotes % 2 ) {
			$errors[] = 'Unclosed double quotes';
		}

		if ( ! empty( $errors ) ) {
			$this->error_handler->log_error(
				'E002',
				array(
					'css'    => $css,
					'errors' => $errors,
					'action' => 'CSS syntax validation failed',
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Fix common CSS issues
	 */
	public function fix_css_issues( $css ) {
		if ( empty( $css ) ) {
			return $css;
		}

		// Fix common issues
		$css = str_replace( '; ;', ';', $css ); // Remove double semicolons
		$css = preg_replace( '/\s+/', ' ', $css ); // Normalize whitespace
		$css = trim( $css );

		return $css;
	}

	/**
	 * Clean custom CSS - remove redundant class wrappers but keep media queries
	 */
	private function clean_custom_css( $custom_css, $class_name ) {
		if ( empty( $custom_css ) || empty( $class_name ) ) {
			return $custom_css;
		}

		// Replace Bricks %root% placeholder with actual class name
		$custom_css = str_replace( '%root%', '.' . $class_name, $custom_css );

		// Strategy: Remove ONLY the redundant .class-name { } wrappers
		// Keep everything else (media queries, child selectors, etc.)

		// Pattern to match: .class-name { content } where content doesn't start with another selector
		// This removes the redundant wrapper but keeps nested media queries
		$pattern = '/\.' . preg_quote( $class_name, '/' ) . '\s*\{\s*([^{}]*(?:\{[^}]*\}[^{}]*)*)\s*\}/s';

		$cleaned_parts = array();

		// Find all .class-name { ... } blocks
		if ( preg_match_all( $pattern, $custom_css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $clean_match ) {
				$content = trim( $clean_match[1] );

				// Check if content contains media queries or other nested rules
				if ( preg_match( '/@media|@supports|@container/', $content ) ) {
					// Keep media queries as-is
					$cleaned_parts[] = $content;
				} elseif ( preg_match( '/^\s*\.' . preg_quote( $class_name, '/' ) . '\s/', $content ) ) {
					// Skip if it's another nested .class-name (redundant)
					continue;
				} else {
					// Regular CSS properties - add them
					$cleaned_parts[] = $content;
				}
			}
		}

		// If we couldn't extract anything useful, return original
		if ( empty( $cleaned_parts ) ) {
			return $custom_css;
		}

		return implode( "\n", $cleaned_parts );
	}
}
