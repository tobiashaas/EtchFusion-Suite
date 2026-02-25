<?php
/**
 * CSS Converter — Orchestrator
 *
 * Public facade of the CSS conversion pipeline.  Coordinates eight focused
 * sub-modules to convert Bricks global classes, element-scoped styles, and
 * custom CSS snippets into Etch-compatible style entries.
 *
 * All heavy-lifting has been moved to dedicated classes in includes/css/:
 *   - EFS_CSS_Normalizer          — pure CSS string transformations
 *   - EFS_Breakpoint_Resolver     — Bricks breakpoint → @media query strings
 *   - EFS_ACSS_Handler            — Automatic.css utility-class inline mapping
 *   - EFS_Settings_CSS_Converter  — Bricks settings array → CSS declarations
 *   - EFS_CSS_Stylesheet_Parser   — raw CSS stylesheet → per-selector rule sets
 *   - EFS_Class_Reference_Scanner — which global classes are actually in use
 *   - EFS_Element_ID_Style_Collector — element-scoped inline styles from posts
 *   - EFS_Style_Importer          — persist styles + trigger Etch CSS rebuild
 *
 * This class keeps only:
 *   - The public API surface (unchanged for backward compatibility).
 *   - The top-level conversion loop (convert_bricks_classes_to_etch).
 *   - Two helpers that genuinely belong at this level:
 *       inject_brxe_block_display_css() — injects display:flex into block elements.
 *       get_etch_element_styles()       — static Etch framework style definitions.
 *
 * @package EtchFusionSuite
 */

namespace Bricks2Etch\Parsers;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;
use Bricks2Etch\CSS\EFS_CSS_Normalizer;
use Bricks2Etch\CSS\EFS_Breakpoint_Resolver;
use Bricks2Etch\CSS\EFS_ACSS_Handler;
use Bricks2Etch\CSS\EFS_Settings_CSS_Converter;
use Bricks2Etch\CSS\EFS_CSS_Stylesheet_Parser;
use Bricks2Etch\CSS\EFS_Class_Reference_Scanner;
use Bricks2Etch\CSS\EFS_Element_ID_Style_Collector;
use Bricks2Etch\CSS\EFS_Style_Importer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates the conversion of Bricks CSS data into Etch-compatible styles.
 *
 * All constructor parameters after $style_repository are optional and nullable.
 * When omitted the orchestrator self-constructs each sub-module, preserving
 * backward compatibility for call sites that use only the two original args
 * (e.g. the legacy instantiation in migration_manager.php).
 */
class EFS_CSS_Converter {

	/**
	 * Error / info / debug logging sink.
	 *
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	/**
	 * Persistence layer for Etch style data.
	 *
	 * @var Style_Repository_Interface
	 */
	private $style_repository;

	// =========================================================================
	// Sub-modules (injected or self-constructed)
	// =========================================================================

	/** @var EFS_CSS_Normalizer */
	private $normalizer;

	/** @var EFS_Breakpoint_Resolver */
	private $breakpoint_resolver;

	/** @var EFS_ACSS_Handler */
	private $acss_handler;

	/** @var EFS_Settings_CSS_Converter */
	private $settings_converter;

	/** @var EFS_CSS_Stylesheet_Parser */
	private $stylesheet_parser;

	/** @var EFS_Class_Reference_Scanner */
	private $class_scanner;

	/** @var EFS_Element_ID_Style_Collector */
	private $element_collector;

	/** @var EFS_Style_Importer */
	private $style_importer;

	// =========================================================================
	// Constructor
	// =========================================================================

	/**
	 * Initialise the orchestrator and all CSS sub-modules.
	 *
	 * Sub-modules that are not provided are self-constructed using the other
	 * resolved dependencies, so callers that pass only the two original
	 * arguments continue to work without modification.
	 *
	 * @param EFS_Error_Handler                   $error_handler       Logging sink.
	 * @param Style_Repository_Interface          $style_repository    Data access layer.
	 * @param EFS_CSS_Normalizer|null             $normalizer          Optional: CSS string transformer.
	 * @param EFS_Breakpoint_Resolver|null        $breakpoint_resolver Optional: breakpoint → @media resolver.
	 * @param EFS_ACSS_Handler|null               $acss_handler        Optional: ACSS inline-style manager.
	 * @param EFS_Settings_CSS_Converter|null     $settings_converter  Optional: settings → CSS converter.
	 * @param EFS_CSS_Stylesheet_Parser|null      $stylesheet_parser   Optional: raw stylesheet parser.
	 * @param EFS_Class_Reference_Scanner|null    $class_scanner       Optional: class-reference detector.
	 * @param EFS_Element_ID_Style_Collector|null $element_collector   Optional: element-scoped style collector.
	 * @param EFS_Style_Importer|null             $style_importer      Optional: style persistence handler.
	 */
	public function __construct(
		EFS_Error_Handler $error_handler,
		Style_Repository_Interface $style_repository,
		EFS_CSS_Normalizer $normalizer = null,
		EFS_Breakpoint_Resolver $breakpoint_resolver = null,
		EFS_ACSS_Handler $acss_handler = null,
		EFS_Settings_CSS_Converter $settings_converter = null,
		EFS_CSS_Stylesheet_Parser $stylesheet_parser = null,
		EFS_Class_Reference_Scanner $class_scanner = null,
		EFS_Element_ID_Style_Collector $element_collector = null,
		EFS_Style_Importer $style_importer = null
	) {
		$this->error_handler       = $error_handler;
		$this->style_repository    = $style_repository;
		$this->normalizer          = $normalizer ?? new EFS_CSS_Normalizer();
		$this->breakpoint_resolver = $breakpoint_resolver ?? new EFS_Breakpoint_Resolver();
		$this->acss_handler        = $acss_handler ?? new EFS_ACSS_Handler( $this->normalizer );
		$this->settings_converter  = $settings_converter ?? new EFS_Settings_CSS_Converter( $this->breakpoint_resolver, $this->normalizer );
		$this->stylesheet_parser   = $stylesheet_parser ?? new EFS_CSS_Stylesheet_Parser( $this->normalizer, $this->breakpoint_resolver, $error_handler );
		$this->class_scanner       = $class_scanner ?? new EFS_Class_Reference_Scanner( $error_handler );
		$this->element_collector   = $element_collector ?? new EFS_Element_ID_Style_Collector( $this->settings_converter, $this->normalizer, $this->breakpoint_resolver );
		$this->style_importer      = $style_importer ?? new EFS_Style_Importer( $error_handler, $style_repository );
	}

	// =========================================================================
	// Public API — backward-compatible surface
	// =========================================================================

	/**
	 * Backwards-compatible wrapper to satisfy legacy integrations.
	 *
	 * @param array<string,mixed> $legacy_styles Optional payload.
	 * @return array<string,mixed>
	 */
	public function convert( array $legacy_styles = array() ): array {
		$conversion = $this->convert_bricks_classes_to_etch();

		if ( ! empty( $legacy_styles ) ) {
			$conversion['legacy_styles'] = $legacy_styles;
		}

		return $conversion;
	}

	/**
	 * Convert all Bricks global classes and element styles to Etch format.
	 *
	 * Main entry point for the CSS conversion pipeline.  Orchestrates the eight
	 * sub-modules to produce a complete `etch_styles` payload and the `style_map`
	 * that links Bricks class IDs to Etch Style Manager IDs.
	 *
	 * ID SYSTEM OVERVIEW — two completely separate systems, never mix them:
	 *
	 * 1. ELEMENT IDs (HTML id attributes on individual DOM nodes)
	 *    Bricks: brxe-abc1234 → Etch: etch-abc1234
	 *    Renamed 1-to-1 by EFS_Element_ID_Style_Collector::map_bricks_element_id_to_etch_selector_id().
	 *    Used as CSS selectors: #brxe-abc1234 { … } → #etch-abc1234 { … }
	 *    Every element instance has its own unique id.
	 *
	 * 2. STYLE MANAGER IDs (internal keys in Etch's reusable-class registry)
	 *    Bricks global classes carry a random internal ID (e.g. "abc1234") plus a
	 *    human-readable name (e.g. "my-card").  Etch assigns a fresh 7-char hex ID
	 *    via substr(uniqid(), -7) to every migrated class.  The class name and
	 *    selector (.my-card) are preserved; only the internal tracking ID changes.
	 *    $style_map connects the two:
	 *      Bricks class ID → { id: Etch Style Manager ID, selector: '.my-card' }
	 *    These IDs never carry a "brxe-" or "etch-" prefix.
	 *
	 * 3. ETCH FRAMEWORK STYLES (etch-section-style, etch-container-style, …)
	 *    Built-in Etch CSS rules added under "etch-*" keys in $etch_styles so
	 *    Etch's Style Manager registers them as read-only.  NEVER in $style_map
	 *    because they have no Bricks counterpart and must not appear as class names
	 *    on converted elements.
	 *
	 * @return array{styles: array<string,array<string,mixed>>, style_map: array<string,mixed>}
	 */
	public function convert_bricks_classes_to_etch(): array {
		$this->log_debug_info( 'CSS Converter: Starting conversion' );

		// Reset ACSS handler state for this run.
		$this->acss_handler->reset();

		$bricks_classes = $this->class_scanner->get_bricks_global_classes_cached();
		$this->log_debug_info(
			'CSS Converter: Loaded Bricks classes',
			array( 'count' => count( $bricks_classes ) )
		);

		// Determine which classes are actually referenced in the selected post types.
		$selected_post_types  = $this->class_scanner->get_selected_post_types_from_active_migration();
		$referenced_class_ids = $this->class_scanner->collect_referenced_global_class_ids( $selected_post_types );
		$migration_data       = get_option( 'efs_active_migration', array() );

		$restrict_css_to_used           = isset( $migration_data['options']['restrict_css_to_used'] )
			? (bool) $migration_data['options']['restrict_css_to_used']
			: true;
		$restrict_to_referenced_classes = (bool) apply_filters(
			'etch_fusion_suite_css_restrict_to_referenced_classes',
			$restrict_css_to_used,
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

		$etch_styles     = array(); // Final Etch styles registry.
		$style_map       = array(); // Bricks class ID → { id: Etch Style Manager ID, selector }.
		// normalised_acss_name → stub_id.  Prevents duplicate stubs and lets
		// multiple Bricks IDs that share the same normalised name reuse the stub.
		$acss_stub_index = array();

		// Seed with built-in Etch framework element styles (type-3, read-only).
		$etch_styles = array_merge( $etch_styles, $this->get_etch_element_styles() );

		// ------------------------------------------------------------------
		// Step 1: Collect all custom CSS into a temporary stylesheet string.
		//
		// Class settings in Bricks can contain both structured CSS properties
		// (_cssCustom) and breakpoint-scoped raw CSS (_cssCustom:breakpoint_key).
		// The structured properties are converted in step 2.  Raw snippets are
		// collected here into a stylesheet that is parsed in step 3.
		// ------------------------------------------------------------------
		$custom_css_stylesheet = '';
		$breakpoint_css_map    = array(); // Per-class breakpoint CSS chunks, keyed by Bricks class ID.

		foreach ( $bricks_classes as $class ) {
			if ( ! is_array( $class ) ) {
				continue;
			}

			if ( empty( $class['id'] ) && empty( $class['name'] ) ) {
				continue;
			}

			// Optional optimisation: skip unreferenced classes.
			if ( $restrict_to_referenced_classes
				&& ! $this->class_scanner->is_referenced_global_class( $class, $referenced_class_ids )
			) {
				continue;
			}

			// ACSS utilities: build inline style map (mechanism 1) and register an
			// empty stub in etch_styles (mechanism 2) so the class appears in the
			// Etch Builder UI.  See EFS_ACSS_Handler class docblock for details.
			if ( $this->acss_handler->is_acss_class( $class ) ) {
				$this->acss_handler->register_acss_inline_style( $class );

				$acss_name = trim( (string) ( $class['name'] ?? '' ) );
				$acss_name = ltrim( (string) preg_replace( '/^acss_import_/', '', $acss_name ), '.' );
				$acss_name = (string) preg_replace( '/^fr-/', '', $acss_name );

				if ( '' !== $acss_name ) {
					// Create the stub only once per unique normalised name.  Two Bricks class
					// entries can share the same normalised name (e.g. an ACSS v3 import entry
					// "acss_import_bg--primary" and an ACSS v4 entry "bg--primary" with
					// category:acss both normalise to "bg--primary").  The stub ID is stored
					// in $acss_stub_index (not just a boolean) so later duplicates can reuse it.
					if ( ! isset( $acss_stub_index[ $acss_name ] ) ) {
						$stub_id                       = substr( md5( 'acss_' . $acss_name ), 0, 7 );
						$etch_styles[ $stub_id ]       = array(
							'type'       => 'class',
							'selector'   => '.' . $acss_name,
							'collection' => 'default',
							'css'        => '',
							'readonly'   => false,
						);
						$acss_stub_index[ $acss_name ] = $stub_id;
					}

					// Always register a style_map entry for this Bricks class ID so element
					// converters can find it regardless of which class entry they encountered.
					if ( ! empty( $class['id'] ) ) {
						$style_map[ $class['id'] ] = array(
							'id'       => $acss_stub_index[ $acss_name ],
							'selector' => '.' . $acss_name,
						);
					}
				}

				continue;
			}

			if ( $this->class_scanner->should_exclude_class( $class ) ) {
				continue;
			}

			$class_name = ! empty( $class['name'] ) ? $class['name'] : $class['id'];
			$class_name = (string) preg_replace( '/^acss_import_/', '', $class_name );
			$class_name = (string) preg_replace( '/^fr-/', '', $class_name );
			$bricks_id  = $class['id'];
			$settings   = isset( $class['settings'] ) && is_array( $class['settings'] ) ? $class['settings'] : array();

			// Collect base custom CSS snippet (_cssCustom key).
			if ( ! empty( $settings['_cssCustom'] ) ) {
				$snippet = str_replace( '%root%', '.' . $class_name, $settings['_cssCustom'] );
				$snippet = $this->normalizer->normalize_deprecated_hsl_references( $snippet );
				// Strip the fr- prefix from any class selectors inside the raw snippet.
				$snippet                = (string) preg_replace( '/(?<=\.)fr-([a-zA-Z0-9_-])/', '$1', $snippet );
				$custom_css_stylesheet .= "\n" . $snippet . "\n";
			}

			// Collect breakpoint-scoped custom CSS (_cssCustom:breakpoint_key keys).
			foreach ( $settings as $key => $value ) {
				if ( 0 !== strpos( $key, '_cssCustom:' ) || empty( $value ) ) {
					continue;
				}

				$breakpoint  = str_replace( '_cssCustom:', '', $key );
				$media_query = $this->breakpoint_resolver->get_media_query_for_breakpoint( $breakpoint );

				if ( $media_query ) {
					$snippet = str_replace( '%root%', '.' . $class_name, $value );
					$snippet = $this->normalizer->normalize_deprecated_hsl_references( $snippet );
					$snippet = (string) preg_replace( '/(?<=\.)fr-([a-zA-Z0-9_-])/', '$1', $snippet );

					// Extract the CSS body from inside ".class-name { … }" wrappers.
					if ( preg_match( '/\.' . preg_quote( $class_name, '/' ) . '\s*\{([^}]*)\}/s', $snippet, $match ) ) {
						$css_content = trim( $match[1] );

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

		// Collect inline CSS produced by code-block elements during content parsing.
		// Uses prepare() even though the pattern is a literal constant — PHPCS
		// WordPress standards require prepare() for every query with a LIKE clause.
		global $wpdb;
		$inline_css_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'efs_inline_css_%'
			),
			ARRAY_A
		);
		foreach ( $inline_css_options as $option ) {
			$custom_css_stylesheet .= "\n" . $option['option_value'] . "\n";
			delete_option( $option['option_name'] );
		}

		// ------------------------------------------------------------------
		// Step 2: Convert user classes (settings → CSS, without custom CSS).
		// ------------------------------------------------------------------
		$converted_count = 0;
		$excluded_count  = 0;

		foreach ( $bricks_classes as $class ) {
			if ( $restrict_to_referenced_classes
				&& ! $this->class_scanner->is_referenced_global_class( $class, $referenced_class_ids )
			) {
				continue;
			}

			if ( $this->acss_handler->is_acss_class( $class ) ) {
				// Inline style map and empty stubs were already registered in Step 1 — skip.
				++$excluded_count;
				continue;
			}

			if ( $this->class_scanner->should_exclude_class( $class ) ) {
				++$excluded_count;
				continue;
			}

			$converted_class = $this->convert_bricks_class_to_etch( $class );
			if ( $converted_class ) {
				// Fresh 7-char Style Manager ID (type-2) — unrelated to element HTML ids (type-1).
				// extra_entropy=true appends a random floating-point suffix, making collisions
				// negligible even when many classes are converted in rapid succession.
				$style_id                 = substr( uniqid( '', true ), -7 );
				$etch_styles[ $style_id ] = $converted_class;
				++$converted_count;

				if ( $converted_count <= 3 ) {
					$this->log_debug_info(
						'CSS Converter: Converted style',
						array( 'selector' => $converted_class['selector'] ?? '' )
					);
				}

				// Record the Bricks → Etch Style Manager ID mapping for element converters.
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
			array( 'converted' => $converted_count )
		);

		// ------------------------------------------------------------------
		// Step 2b: Collect element-scoped inline styles from post content.
		// ------------------------------------------------------------------
		$element_style_data = $this->element_collector->collect_element_id_styles_from_posts( $selected_post_types );

		if ( ! empty( $element_style_data['styles'] ) && is_array( $element_style_data['styles'] ) ) {
			foreach ( $element_style_data['styles'] as $style_id => $style_definition ) {
				if ( isset( $etch_styles[ $style_id ] ) ) {
					$existing_css = trim( (string) ( $etch_styles[ $style_id ]['css'] ?? '' ) );
					$new_css      = trim( (string) ( $style_definition['css'] ?? '' ) );
					if ( '' !== $existing_css && '' !== $new_css ) {
						$etch_styles[ $style_id ]['css'] = $existing_css . "\n  " . $new_css;
					} elseif ( '' !== $new_css ) {
						$etch_styles[ $style_id ]['css'] = $new_css;
					}
					continue;
				}
				$etch_styles[ $style_id ] = $style_definition;
			}
		}

		if ( ! empty( $element_style_data['custom_css'] ) && is_string( $element_style_data['custom_css'] ) ) {
			$custom_css_stylesheet .= "\n" . $element_style_data['custom_css'] . "\n";
		}

		// ------------------------------------------------------------------
		// Step 3: Parse the collected custom CSS stylesheet and merge rules.
		// ------------------------------------------------------------------
		$this->log_debug_info(
			'CSS Converter: Custom CSS stylesheet length',
			array( 'length' => strlen( $custom_css_stylesheet ) )
		);

		if ( ! empty( $custom_css_stylesheet ) ) {
			$this->log_debug_info( 'CSS Converter: Parsing custom CSS stylesheet' );

			$custom_styles    = $this->stylesheet_parser->parse_custom_css_stylesheet( $custom_css_stylesheet, $style_map );
			$id_custom_styles = $this->stylesheet_parser->parse_custom_id_css_stylesheet( $custom_css_stylesheet );

			if ( ! empty( $id_custom_styles ) ) {
				$custom_styles = array_merge( $custom_styles, $id_custom_styles );
			}

			$this->log_debug_info(
				'CSS Converter: Parsed custom styles',
				array( 'count' => count( $custom_styles ) )
			);

			foreach ( $custom_styles as $style_id => $custom_style ) {
				if ( isset( $etch_styles[ $style_id ] ) ) {
					// Combine CSS for the same selector.
					$existing_css = trim( $etch_styles[ $style_id ]['css'] );
					$new_css      = $this->normalizer->convert_to_logical_properties( trim( $custom_style['css'] ) );

					if ( '' !== $existing_css && '' !== $new_css ) {
						$etch_styles[ $style_id ]['css'] = $existing_css . "\n  " . $new_css;
					} elseif ( '' !== $new_css ) {
						$etch_styles[ $style_id ]['css'] = $new_css;
					}

					$this->log_debug_info(
						'CSS Converter: Merged custom CSS',
						array( 'selector' => $custom_style['selector'] )
					);
				} else {
					$custom_style['css']      = $this->normalizer->convert_to_logical_properties( $custom_style['css'] );
					$etch_styles[ $style_id ] = $custom_style;

					$this->log_debug_info(
						'CSS Converter: Added custom CSS style',
						array( 'selector' => $custom_style['selector'] )
					);
				}
			}
		}

		// ------------------------------------------------------------------
		// Step 4: Append breakpoint-scoped CSS to their respective styles.
		// ------------------------------------------------------------------
		if ( ! empty( $breakpoint_css_map ) ) {
			$this->log_debug_info(
				'CSS Converter: Adding breakpoint CSS',
				array( 'class_count' => count( $breakpoint_css_map ) )
			);

			foreach ( $breakpoint_css_map as $bricks_id => $media_queries ) {
				if ( ! isset( $style_map[ $bricks_id ] ) ) {
					continue;
				}

				$style_id = $style_map[ $bricks_id ]['id'];
				if ( ! isset( $etch_styles[ $style_id ] ) ) {
					continue;
				}

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

		// ------------------------------------------------------------------
		// Step 5: Post-migration CSS normalisation pass.
		//
		// Run once over all collected CSS to fix invalid patterns that may have
		// come through 1:1 from Bricks (invalid grid shorthands, deprecated HSL
		// references, etc.).
		// ------------------------------------------------------------------
		foreach ( $etch_styles as $style_key => $style_data ) {
			if ( ! is_array( $style_data ) || empty( $style_data['css'] ) || ! is_string( $style_data['css'] ) ) {
				continue;
			}
			$etch_styles[ $style_key ]['css'] = $this->normalizer->normalize_final_css( $style_data['css'] );
		}

		// Inject display:flex into block elements that carry no display CSS.
		$this->inject_brxe_block_display_css( $etch_styles, $style_map );

		// Persist style map and ACSS inline map for use during content migration.
		$this->style_repository->save_style_map( $style_map );
		update_option( 'efs_acss_inline_style_map', $this->acss_handler->get_inline_style_map() );

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
				array( 'bricks_classes' => count( $bricks_classes ) )
			);
		}

		return array(
			'styles'    => $etch_styles,
			'style_map' => $style_map,
		);
	}

	/**
	 * Convert a single Bricks global class to an Etch style entry.
	 *
	 * Delegates CSS generation to EFS_Settings_CSS_Converter for structured
	 * settings and to EFS_Element_ID_Style_Collector for image-fitting properties.
	 *
	 * @param array<string,mixed> $bricks_class Bricks global class data.
	 * @return array<string,mixed>|null Etch style entry, or null if empty.
	 */
	public function convert_bricks_class_to_etch( array $bricks_class ): ?array {
		// Resolve display name — prefer human-readable name over internal ID.
		$class_name = ! empty( $bricks_class['name'] ) ? $bricks_class['name'] : $bricks_class['id'];
		$class_name = (string) preg_replace( '/^acss_import_/', '', $class_name );
		$class_name = (string) preg_replace( '/^fr-/', '', $class_name );

		$css = '';
		if ( ! empty( $bricks_class['settings'] ) ) {
			$css = $this->settings_converter->convert_bricks_settings_to_css( $bricks_class['settings'], $class_name );

			$responsive_css = $this->settings_converter->convert_responsive_variants( $bricks_class['settings'], $class_name );
			if ( ! empty( $responsive_css ) ) {
				$css .= ' ' . $responsive_css;
			}
		}

		$css = $this->normalizer->normalize_css_variables( $css );
		$css = $this->element_collector->move_image_fit_properties_to_nested_img( $class_name, $css );

		// Create an entry even for empty classes (utility classes from CSS frameworks).
		return array(
			'type'       => 'class',
			'selector'   => '.' . $class_name,
			'collection' => 'default',
			'css'        => trim( $css ),
			'readonly'   => false,
		);
	}

	/**
	 * Return a count summary for the migration wizard preview.
	 *
	 * @param array<int,string> $selected_post_types Post types selected in the wizard.
	 * @param bool              $restrict_to_used    When true, count only referenced classes.
	 * @return array{total: int, to_migrate: int}
	 */
	public function get_css_class_counts( array $selected_post_types, bool $restrict_to_used ): array {
		return $this->class_scanner->get_css_class_counts( $selected_post_types, $restrict_to_used );
	}

	/**
	 * Persist a batch of converted Etch styles to the database.
	 *
	 * Delegates entirely to EFS_Style_Importer.
	 *
	 * @param mixed $data Styles payload (new or legacy format).
	 * @return bool|\WP_Error
	 */
	public function import_etch_styles( $data ) {
		return $this->style_importer->import_etch_styles( $data );
	}

	/**
	 * Validate a CSS string for basic syntax errors.
	 *
	 * @param string $css CSS to validate.
	 * @return bool
	 */
	public function validate_css_syntax( $css ): bool {
		return $this->style_importer->validate_css_syntax( (string) $css );
	}

	/**
	 * Fix common CSS formatting issues.
	 *
	 * @param string $css CSS to repair.
	 * @return string
	 */
	public function fix_css_issues( $css ): string {
		return $this->style_importer->fix_css_issues( (string) $css );
	}

	// =========================================================================
	// Private orchestration helpers
	// =========================================================================

	/**
	 * Inject display:flex/column into the semantic class of brxe-block elements.
	 *
	 * Bricks adds display:flex;flex-direction:column to every block via the built-in
	 * brxe-block class.  Since that class is not migrated, this scans all block elements,
	 * identifies the one non-utility class they carry, and ensures the display declarations
	 * are present in its Etch style entry.
	 *
	 * Only injects when exactly one non-utility semantic class is attached to the element
	 * (to avoid ambiguous injections on elements with multiple candidate classes).
	 *
	 * @param array<string,array<string,mixed>> $etch_styles In-memory styles map (by reference).
	 * @param array<string,mixed>               $style_map   Bricks class ID → style descriptor map.
	 */
	private function inject_brxe_block_display_css( array &$etch_styles, array $style_map ): void {
		$post_types = $this->class_scanner->get_selected_post_types_from_active_migration();
		if ( empty( $post_types ) ) {
			$post_types = array( 'page', 'post' );
		}

		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'meta_query'  => array(
					array(
						'key'     => '_bricks_page_content_2',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		// Utility-class prefix patterns — never the "semantic" class of a block element.
		$utility_prefixes = array( 'bg--', 'is-bg', 'is-bg-', 'hidden-', 'smart-' );
		$injected         = array();

		foreach ( $posts as $post ) {
			$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
			if ( is_string( $elements ) ) {
				$elements = maybe_unserialize( $elements );
			}
			if ( ! is_array( $elements ) ) {
				continue;
			}

			foreach ( $elements as $element ) {
				if ( 'block' !== ( $element['name'] ?? '' ) ) {
					continue;
				}

				$class_ids = isset( $element['settings']['_cssGlobalClasses'] )
					&& is_array( $element['settings']['_cssGlobalClasses'] )
						? $element['settings']['_cssGlobalClasses']
						: array();

				// Collect non-utility class entries from the style_map.
				$semantic = array();
				foreach ( $class_ids as $cid ) {
					$cid = trim( (string) $cid );
					if ( '' === $cid || ! isset( $style_map[ $cid ] ) || ! is_array( $style_map[ $cid ] ) ) {
						continue;
					}
					$class_name = ltrim( trim( (string) ( $style_map[ $cid ]['selector'] ?? '' ) ), '.' );
					$is_utility = false;
					foreach ( $utility_prefixes as $prefix ) {
						if ( 0 === strpos( $class_name, $prefix ) ) {
							$is_utility = true;
							break;
						}
					}
					if ( ! $is_utility ) {
						$semantic[] = $style_map[ $cid ];
					}
				}

				// Only inject when exactly one semantic class is present (unambiguous).
				if ( 1 !== count( $semantic ) ) {
					continue;
				}

				$style_id = trim( (string) ( $semantic[0]['id'] ?? '' ) );
				if ( '' === $style_id || isset( $injected[ $style_id ] ) ) {
					continue;
				}
				if ( ! isset( $etch_styles[ $style_id ] ) || ! is_array( $etch_styles[ $style_id ] ) ) {
					continue;
				}

				$css     = isset( $etch_styles[ $style_id ]['css'] ) ? (string) $etch_styles[ $style_id ]['css'] : '';
				$is_flex = (bool) preg_match( '/\bdisplay\s*:\s*(?:inline-)?flex\b/i', $css );
				$is_grid = (bool) preg_match( '/\bdisplay\s*:\s*(?:inline-)?grid\b/i', $css );
				$has_dir = (bool) preg_match( '/\bflex-direction\s*:/i', $css );

				// Skip when display is already defined.
				if ( $is_flex || $is_grid ) {
					$injected[ $style_id ] = true;
					continue;
				}

				// Also skip when the element settings specify an explicit grid display.
				$el_display = strtolower( trim( (string) ( $element['settings']['_display'] ?? '' ) ) );
				if ( in_array( $el_display, array( 'grid', 'inline-grid' ), true ) ) {
					$injected[ $style_id ] = true;
					continue;
				}

				$declarations = 'display: flex; inline-size: 100%;';
				if ( ! $has_dir ) {
					$declarations .= ' flex-direction: column;';
				}

				$existing_css = rtrim( $css );
				if ( '' !== $existing_css
					&& ';' !== substr( $existing_css, -1 )
					&& '}' !== substr( $existing_css, -1 )
				) {
					$existing_css .= ';';
				}

				$etch_styles[ $style_id ]['css'] = trim(
					$existing_css . ( '' !== $existing_css ? ' ' : '' ) . $declarations
				);
				$injected[ $style_id ]           = true;
			}
		}
	}

	/**
	 * Return the static Etch framework element style definitions.
	 *
	 * These are built-in Etch CSS rules for its own element types (section,
	 * container, iframe).  Added to etch_styles as read-only entries under
	 * "etch-*" keys.  They are NOT added to $style_map.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_etch_element_styles(): array {
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
	 * Parse custom CSS stylesheet — kept as a private delegating wrapper so that
	 * existing unit tests using ReflectionClass to call this method continue to work.
	 *
	 * @param string              $stylesheet Raw CSS stylesheet.
	 * @param array<string,mixed> $style_map  Bricks-ID → style descriptor map.
	 * @return array<string,array<string,mixed>>
	 */
	private function parse_custom_css_stylesheet( string $stylesheet, array $style_map = array() ): array {
		return $this->stylesheet_parser->parse_custom_css_stylesheet( $stylesheet, $style_map );
	}

	// =========================================================================
	// Debug logging helpers
	// =========================================================================

	/**
	 * Check whether verbose debug logging should be emitted.
	 *
	 * @return bool
	 */
	private function is_debug_logging_enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Emit a debug message through the error handler when debug logging is active.
	 *
	 * @param string            $message Human-readable message.
	 * @param array<mixed>|null $context Optional structured context.
	 */
	private function log_debug_info( string $message, ?array $context = null ): void {
		if ( ! $this->is_debug_logging_enabled() || ! method_exists( $this->error_handler, 'debug_log' ) ) {
			return;
		}

		$this->error_handler->debug_log( $message, $context, 'EFS_CSS_CONVERTER' );
	}
}
