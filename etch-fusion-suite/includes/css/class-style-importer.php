<?php
/**
 * Style Importer
 *
 * Persists converted Etch style entries to the WordPress database and triggers
 * the Etch CSS rebuild pipeline.
 *
 * Responsibilities:
 * - Receive a converted styles payload (produced by EFS_CSS_Converter).
 * - Deduplicate incoming styles by selector (combine CSS when the same selector
 *   appears more than once in the payload).
 * - Merge with any pre-existing etch_styles so that re-running a migration
 *   always reflects the latest Bricks source data.
 * - Persist styles and the style_map to the database via Style_Repository_Interface.
 * - Trigger cache invalidation and the Etch CSS rebuild pipeline.
 * - Provide lightweight CSS validation and repair helpers used by the orchestrator.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists Etch styles and triggers the Etch CSS rebuild.
 */
class EFS_Style_Importer {

	/**
	 * Error/info/debug logging sink.
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

	/**
	 * @param EFS_Error_Handler          $error_handler    Logging sink.
	 * @param Style_Repository_Interface $style_repository Data access layer.
	 */
	public function __construct( EFS_Error_Handler $error_handler, Style_Repository_Interface $style_repository ) {
		$this->error_handler    = $error_handler;
		$this->style_repository = $style_repository;
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Persist a batch of converted Etch styles to the database.
	 *
	 * Expects either:
	 * - New format: `['styles' => [...], 'style_map' => [...]]`
	 * - Legacy format: a flat array of style entries (backward compat).
	 *
	 * The import pipeline:
	 * 1. Deduplicates incoming styles by selector (combines CSS for duplicate selectors).
	 * 2. Merges with existing etch_styles, allowing re-migration to overwrite stale data.
	 * 3. Saves merged styles and the style_map to the database.
	 * 4. Invalidates caches and triggers Etch's CSS rebuild.
	 *
	 * @param mixed $data Styles payload.
	 * @return bool|\WP_Error True on success, WP_Error on validation failure.
	 */
	public function import_etch_styles( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_styles', 'Invalid styles data provided' );
		}

		// Support both new format (`styles` + `style_map` keys) and legacy flat array.
		$etch_styles = $data['styles'] ?? $data;
		$style_map   = $data['style_map'] ?? array();

		$this->log_debug_info(
			'Style Importer: import_etch_styles invoked',
			array(
				'count_styles'    => count( $etch_styles ),
				'count_style_map' => count( $style_map ),
			)
		);

		// Fetch the styles that are already stored on this site.
		$existing_styles = $this->style_repository->get_etch_styles();

		// ------------------------------------------------------------------
		// Step A: Deduplicate incoming styles by selector.
		//
		// When convert_bricks_classes_to_etch() produces separate entries for
		// the settings-based CSS and the _cssCustom CSS of the same class, both
		// entries share the same selector.  Combine their CSS here.
		// ------------------------------------------------------------------
		$incoming = array();
		foreach ( $etch_styles as $style ) {
			$sel = $style['selector'] ?? '';
			if ( '' === $sel ) {
				continue;
			}
			if ( ! isset( $incoming[ $sel ] ) ) {
				$incoming[ $sel ] = $style;
			} else {
				// Same selector — merge CSS, ignoring identical content.
				$existing_css = trim( $incoming[ $sel ]['css'] ?? '' );
				$new_css      = trim( $style['css'] ?? '' );
				if ( '' !== $new_css && $new_css !== $existing_css ) {
					$incoming[ $sel ]['css'] = '' !== $existing_css
						? $existing_css . "\n  " . $new_css
						: $new_css;
				}
			}
		}

		// ------------------------------------------------------------------
		// Step B: Merge incoming styles with pre-existing styles.
		//
		// Pre-existing styles are the base; incoming styles overwrite any entry
		// with a matching selector.  This ensures re-running the migration
		// always reflects the latest Bricks source data without losing manually
		// added styles that have unique selectors.
		// ------------------------------------------------------------------
		$index = array();
		foreach ( $existing_styles as $style ) {
			$sel = $style['selector'] ?? '';
			if ( '' !== $sel ) {
				$index[ $sel ] = $style;
			}
		}
		foreach ( $incoming as $sel => $style ) {
			$index[ $sel ] = $style;
		}
		$merged_styles = array_values( $index );

		// NOTE: We persist to etch_styles (used by Etch's StylesRegister to render
		// styles on pages that reference them) rather than etch_global_stylesheets
		// (which is reserved for manually entered global CSS).

		$this->log_debug_info( 'Style Importer: Bypassing Etch API for direct option update' );

		// Preview first few styles before saving (debug only).
		$style_keys = array_keys( $merged_styles );
		for ( $i = 0; $i < min( 3, count( $style_keys ) ); $i++ ) {
			$key   = $style_keys[ $i ];
			$style = $merged_styles[ $key ];
			$this->log_debug_info(
				'Style Importer: Previewing style before save',
				array(
					'key'      => $key,
					'selector' => $style['selector'] ?? '',
				)
			);
		}

		// Persist styles to the database.
		$update_result = $this->style_repository->save_etch_styles( $merged_styles );

		if ( ! $update_result ) {
			$this->error_handler->log_info(
				'Style Importer: Style option update returned false',
				array( 'context' => 'Option may already exist with same value' )
			);
		} else {
			$this->log_debug_info( 'Style Importer: Style option updated successfully' );
		}

		// Verify persistence by re-reading from the database.
		$saved_styles = $this->style_repository->get_etch_styles();
		$this->log_debug_info(
			'Style Importer: Retrieved styles from database',
			array( 'count' => count( $saved_styles ) )
		);

		for ( $i = 0; $i < min( 3, count( $style_keys ) ); $i++ ) {
			$key         = $style_keys[ $i ];
			$saved_style = $saved_styles[ $key ] ?? null;
			if ( $saved_style ) {
				$this->log_debug_info(
					'Style Importer: Verified saved style',
					array(
						'key'      => $key,
						'selector' => $saved_style['selector'] ?? '',
					)
				);
			} else {
				$this->error_handler->log_info(
					'Style Importer: Saved style missing after database read',
					array( 'key' => $key )
				);
			}
		}

		// Invalidate caches and rebuild Etch CSS.
		$this->style_repository->invalidate_style_cache();
		$this->trigger_etch_css_rebuild();

		$this->log_debug_info(
			'Style Importer: Direct save complete',
			array( 'saved_styles' => count( $merged_styles ) )
		);

		// Persist the style_map (Bricks class ID → Etch style ID lookup table).
		$this->style_repository->save_style_map( $style_map );
		$this->log_debug_info(
			'Style Importer: Saved style map',
			array( 'count' => count( $style_map ) )
		);

		// Log a few style-map entries for traceability.
		foreach ( array_slice( $style_map, 0, 3, true ) as $bricks_id => $etch_id ) {
			$this->log_debug_info(
				'Style Importer: Style map entry saved',
				array(
					'bricks_id' => $bricks_id,
					'etch_id'   => $etch_id,
				)
			);
		}

		return true;
	}

	/**
	 * Validate a CSS string for common syntax errors.
	 *
	 * Checks:
	 * - Balanced curly braces.
	 * - Balanced single quotes.
	 * - Balanced double quotes.
	 *
	 * @param string $css CSS string to validate.
	 * @return bool True when valid (or when $css is empty), false otherwise.
	 */
	public function validate_css_syntax( string $css ): bool {
		if ( '' === $css ) {
			return true;
		}

		$errors = array();

		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			$errors[] = 'Unclosed CSS brackets';
		}

		if ( 0 !== substr_count( $css, "'" ) % 2 ) {
			$errors[] = 'Unclosed single quotes';
		}

		if ( 0 !== substr_count( $css, '"' ) % 2 ) {
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
	 * Fix common CSS formatting issues.
	 *
	 * Currently handles:
	 * - Removes double semicolons ("; ;").
	 * - Collapses runs of whitespace.
	 *
	 * @param string $css CSS string to repair.
	 * @return string Repaired CSS.
	 */
	public function fix_css_issues( string $css ): string {
		if ( '' === $css ) {
			return $css;
		}

		$css = str_replace( '; ;', ';', $css );
		$css = (string) preg_replace( '/\s+/', ' ', $css );

		return trim( $css );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Trigger Etch's CSS rebuild pipeline after saving styles.
	 *
	 * Uses three complementary mechanisms:
	 * 1. Increment the etch_svg_version counter — Etch treats this as a cache-bust signal.
	 * 2. Call invalidate_style_cache() directly via the repository.
	 * 3. Fire WordPress action hooks that Etch (or custom code) may listen to.
	 *
	 * Etch's internal StylesheetService and StylesRegister are intentionally NOT called
	 * directly because StylesheetService has a protected singleton constructor and
	 * StylesRegister handles rendering automatically when blocks are processed.
	 *
	 * @return void
	 */
	private function trigger_etch_css_rebuild(): void {
		$this->log_debug_info( 'Style Importer: Triggering Etch CSS rebuild' );

		// 1. Bump SVG/CSS version counter to force Etch cache invalidation.
		$current_version = $this->style_repository->get_svg_version();
		$new_version     = $this->style_repository->increment_svg_version();
		$this->log_debug_info(
			'Style Importer: Updated SVG version',
			array(
				'previous' => $current_version,
				'current'  => $new_version,
			)
		);

		// 2. Clear all remaining Etch-managed caches.
		$this->style_repository->invalidate_style_cache();
		$this->log_debug_info( 'Style Importer: Cleared Etch caches' );

		// 3. Fire action hooks — allows third-party code and Etch itself to react.
		do_action( 'etch_fusion_suite_styles_updated' );
		do_action( 'etch_fusion_suite_rebuild_css' );
		$this->log_debug_info( 'Style Importer: Triggered Etch CSS action hooks' );

		$this->log_debug_info( 'Style Importer: CSS rebuild trigger complete' );
	}

	/**
	 * Convert an etch_styles array into etch_global_stylesheets format and persist it.
	 *
	 * etch_global_stylesheets is Etch's format for frontend-rendered global CSS.
	 * Each entry has a `name` (the selector) and `css` (the wrapped CSS string).
	 * Element-type styles are excluded; they are rendered via the element tree.
	 *
	 * Merges new entries with any pre-existing global stylesheets so that existing
	 * manually authored global CSS is preserved.
	 *
	 * @param array<string,array<string,mixed>> $etch_styles Indexed etch_styles array.
	 * @return bool True when the database update succeeded.
	 */
	private function save_to_global_stylesheets( array $etch_styles ): bool {
		$this->log_debug_info( 'Style Importer: Saving styles to etch_global_stylesheets' );

		$existing_global = $this->style_repository->get_global_stylesheets();
		$new_stylesheets = array();

		foreach ( $etch_styles as $style_id => $style ) {
			// Element-type styles are rendered by the element pipeline, not globally.
			if ( isset( $style['type'] ) && 'element' === $style['type'] ) {
				continue;
			}

			$stylesheet_name = isset( $style['selector'] ) ? $style['selector'] : $style_id;
			$stylesheet_css  = isset( $style['css'] ) ? $style['css'] : '';

			if ( '' === $stylesheet_css ) {
				continue;
			}

			// Wrap the CSS with the selector if it is not already wrapped.
			if ( '' !== $stylesheet_name && false === strpos( $stylesheet_css, $stylesheet_name ) ) {
				$wrapped_css = $stylesheet_name . ' { ' . $stylesheet_css . ' }';
			} else {
				$wrapped_css = $stylesheet_css;
			}

			$new_stylesheets[ $style_id ] = array(
				'name' => $stylesheet_name,
				'css'  => $wrapped_css,
			);
		}

		$merged_global = array_merge( $existing_global, $new_stylesheets );
		$result        = $this->style_repository->save_global_stylesheets( $merged_global );

		if ( $result ) {
			$this->log_debug_info(
				'Style Importer: Saved global stylesheets',
				array(
					'new_stylesheets'   => count( $new_stylesheets ),
					'total_stylesheets' => count( $merged_global ),
				)
			);
		} else {
			$this->error_handler->log_info(
				'Style Importer: Failed to update etch_global_stylesheets',
				array( 'new_stylesheets' => count( $new_stylesheets ) )
			);
		}

		return $result;
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
		if ( ! $this->is_debug_logging_enabled() ) {
			return;
		}

		if ( method_exists( $this->error_handler, 'debug_log' ) ) {
			$this->error_handler->debug_log( $message, $context, 'EFS_STYLE_IMPORTER' );
		}
	}
}
