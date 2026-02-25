<?php
/**
 * ACSS Handler
 *
 * Handles everything related to Automatic.css (ACSS) utility classes during
 * CSS migration.
 *
 * ACSS utility classes are treated differently from regular Bricks global
 * classes: instead of generating Etch style entries from Bricks settings data,
 * their CSS declarations are copied verbatim from the installed automatic.css
 * stylesheet.  This produces a compact "inline style map" that the content
 * converter (EFS_Gutenberg_Generator / EFS_Base_Element) can use to inline ACSS
 * class styles directly onto elements that reference them.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects ACSS utility classes and builds the inline style map.
 *
 * The inline style map is keyed by Bricks class ID and class name:
 *   [
 *     'bricks-class-id-xyz' => 'background-color: var(--primary);',
 *     'bg--primary'         => 'background-color: var(--primary);',
 *     'acss_import_bg--primary' => 'background-color: var(--primary);',
 *   ]
 */
class EFS_ACSS_Handler {

	/**
	 * CSS normalizer for HSL token normalisation.
	 *
	 * @var EFS_CSS_Normalizer
	 */
	private $normalizer;

	/**
	 * ACSS class declaration cache, populated incrementally by
	 * register_acss_inline_style() during the conversion loop.
	 *
	 * Keys are Bricks class IDs and normalised class names;
	 * values are the corresponding CSS declaration strings.
	 *
	 * @var array<string,string>
	 */
	private $inline_style_map = array();

	/**
	 * @param EFS_CSS_Normalizer $normalizer Normaliser instance for HSL token translations.
	 */
	public function __construct( EFS_CSS_Normalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Reset the inline style map (call at the start of each conversion run).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->inline_style_map = array();
	}

	/**
	 * Return the accumulated inline style map.
	 *
	 * @return array<string,string>
	 */
	public function get_inline_style_map(): array {
		return $this->inline_style_map;
	}

	/**
	 * Detect whether a Bricks global class belongs to the ACSS utility set.
	 *
	 * A class is considered an ACSS class when:
	 *   a) Its "category" field is "acss" (case-insensitive), OR
	 *   b) Its name starts with the "acss_import_" prefix (used by older ACSS
	 *      versions that imported classes into Bricks via a dedicated importer).
	 *
	 * @param array<string,mixed> $class_data Bricks global class data.
	 * @return bool
	 */
	public function is_acss_class( array $class_data ): bool {
		$category = isset( $class_data['category'] ) ? strtolower( trim( (string) $class_data['category'] ) ) : '';
		if ( 'acss' === $category ) {
			return true;
		}

		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
		return 0 === strpos( $class_name, 'acss_import_' );
	}

	/**
	 * Register an ACSS class in the inline style map.
	 *
	 * Looks up the class in the installed automatic.css stylesheet and caches
	 * its declaration block.  Entries are indexed by:
	 *   - Bricks class ID (for look-up by ID in element converters)
	 *   - Normalised class name  (e.g. "bg--primary")
	 *   - Original class name    (may include "acss_import_" prefix)
	 *
	 * If automatic.css is not installed or the class is not found, nothing is
	 * added to the map.
	 *
	 * @param array<string,mixed> $class_data Bricks class data.
	 * @return void
	 */
	public function register_acss_inline_style( array $class_data ): void {
		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';
		$class_id   = isset( $class_data['id'] ) ? trim( (string) $class_data['id'] ) : '';

		if ( '' === $class_name ) {
			return;
		}

		// Strip known prefixes to obtain the bare utility class name.
		$normalized_class_name = ltrim( preg_replace( '/^acss_import_/', '', $class_name ), '.' );
		$normalized_class_name = preg_replace( '/^fr-/', '', $normalized_class_name );
		if ( '' === $normalized_class_name ) {
			return;
		}

		$declarations = $this->get_acss_declarations_for_class( $normalized_class_name );
		if ( '' === $declarations ) {
			return;
		}

		// Store under all three lookup keys so element converters can find it
		// regardless of which key format they use.
		if ( '' !== $class_id ) {
			$this->inline_style_map[ $class_id ] = $declarations;
		}
		$this->inline_style_map[ $normalized_class_name ] = $declarations;
		$this->inline_style_map[ $class_name ]            = $declarations;
	}

	/**
	 * Fetch the CSS declaration block for a given ACSS utility class.
	 *
	 * Reads automatic.css from the uploads directory on first access and caches
	 * the stylesheet in a static variable for the duration of the request.
	 * Individual class lookups are also cached in a static array.
	 *
	 * @param string $class_name Utility class name without leading dot.
	 * @return string CSS declaration block, or empty string when not found.
	 */
	public function get_acss_declarations_for_class( string $class_name ): string {
		static $stylesheet = null;
		static $cache      = array();

		if ( isset( $cache[ $class_name ] ) ) {
			return $cache[ $class_name ];
		}

		if ( null === $stylesheet ) {
			$stylesheet = $this->load_automatic_css_stylesheet();
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

		$declarations         = trim( (string) $match[1] );
		$declarations         = $this->normalizer->normalize_acss_deprecated_hsl_tokens( $declarations );
		$cache[ $class_name ] = $declarations;
		return $declarations;
	}

	/**
	 * Load the automatic.css stylesheet from the uploads directory.
	 *
	 * Returns an empty string when:
	 *  - WordPress is not loaded (no wp_upload_dir())
	 *  - The file does not exist or is not readable
	 *
	 * @return string Full stylesheet content, or empty string on failure.
	 */
	private function load_automatic_css_stylesheet(): string {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$css_path   = trailingslashit( $upload_dir['basedir'] ) . 'automatic-css/automatic.css';

		if ( ! file_exists( $css_path ) || ! is_readable( $css_path ) ) {
			return '';
		}

		$contents = file_get_contents( $css_path );
		return false !== $contents ? (string) $contents : '';
	}
}
