<?php
/**
 * Breakpoint Resolver
 *
 * Centralises all logic related to Bricks responsive breakpoints and their
 * conversion to Etch-compatible @media / @container query strings.
 *
 * Bricks uses a desktop-first, max-width breakpoint model.  Etch uses modern
 * CSS range-syntax with the to-rem() function to convert px values to rem at
 * runtime, e.g.:
 *
 *   Bricks: @media (max-width: 767px) { … }
 *   Etch:   @media (width <= to-rem(767px)) { … }
 *
 * Custom breakpoints registered by the user in Bricks Settings are picked up
 * via the `bricks_breakpoints` WordPress option and merged with the defaults.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves Bricks breakpoints to Etch-compatible media query strings.
 *
 * Instances cache the breakpoint map for the duration of the request so that
 * repeated calls to get_breakpoint_width_map() do not re-query the database.
 */
class EFS_Breakpoint_Resolver {

	/**
	 * Cached breakpoint width definitions, populated on first call to
	 * get_breakpoint_width_map() and reused for subsequent calls within the same
	 * request.
	 *
	 * @var array<string,array<string,int|string>>|null
	 */
	private $breakpoint_map_cache = null;

	/**
	 * Clear the in-memory breakpoint cache.
	 *
	 * Useful in tests that modify the `bricks_breakpoints` option mid-run.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->breakpoint_map_cache = null;
	}

	/**
	 * Resolve the full set of Bricks breakpoint width definitions.
	 *
	 * Starts with built-in defaults, then merges any custom breakpoints stored in
	 * the `bricks_breakpoints` WordPress option.  Custom entries override the
	 * corresponding built-in entry when the keys match.
	 *
	 * The returned array is sorted largest-width-first (mobile portrait last).
	 * The "desktop" entry (type=min) is always first regardless of width.
	 *
	 * Each entry has the shape:
	 *   [ 'type' => 'max'|'min', 'width' => int ]
	 *
	 * @return array<string,array<string,int|string>>
	 */
	public function get_breakpoint_width_map(): array {
		if ( null !== $this->breakpoint_map_cache ) {
			return $this->breakpoint_map_cache;
		}

		// Built-in Bricks breakpoints (px values match Bricks defaults).
		$definitions = array(
			'tablet_landscape' => array(
				'type'  => 'max',
				'width' => 1199,
			),
			'tablet_portrait'  => array(
				'type'  => 'max',
				'width' => 991,
			),
			'mobile_landscape' => array(
				'type'  => 'max',
				'width' => 767,
			),
			'mobile_portrait'  => array(
				'type'  => 'max',
				'width' => 478,
			),
			'desktop'          => array(
				'type'  => 'min',
				'width' => 1200,
			),
		);

		// Merge custom breakpoints from Bricks Settings (JSON or serialised array).
		$raw_breakpoints = function_exists( 'get_option' ) ? get_option( 'bricks_breakpoints', array() ) : array();
		if ( is_string( $raw_breakpoints ) ) {
			$decoded = json_decode( $raw_breakpoints, true );
			if ( is_array( $decoded ) ) {
				$raw_breakpoints = $decoded;
			}
		}

		if ( is_array( $raw_breakpoints ) ) {
			foreach ( $raw_breakpoints as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$key = isset( $item['key'] ) ? $this->sanitize_breakpoint_key( (string) $item['key'] ) : '';
				if ( '' === $key ) {
					continue;
				}

				$width = isset( $item['width'] ) ? (int) $item['width'] : 0;
				if ( $width <= 0 ) {
					continue;
				}

				// The entry marked as "base" is the desktop breakpoint.
				if ( 'desktop' === $key || ! empty( $item['base'] ) ) {
					$definitions['desktop'] = array(
						'type'  => 'min',
						'width' => $width + 1,
					);
					continue;
				}

				$definitions[ $key ] = array(
					'type'  => 'max',
					'width' => $width,
				);
			}
		}

		// Sort non-desktop entries largest-first, keeping desktop at the top.
		$desktop_def = $definitions['desktop'] ?? null;
		unset( $definitions['desktop'] );

		uasort(
			$definitions,
			static function ( $a, $b ) {
				return ( (int) ( $b['width'] ?? 0 ) ) <=> ( (int) ( $a['width'] ?? 0 ) );
			}
		);

		if ( is_array( $desktop_def ) ) {
			$definitions = array_merge( array( 'desktop' => $desktop_def ), $definitions );
		}

		$this->breakpoint_map_cache = $definitions;
		return $definitions;
	}

	/**
	 * Build complete media query condition strings for all registered breakpoints.
	 *
	 * @param bool $etch_syntax When true, produce Etch range syntax with to-rem().
	 *                          When false, produce plain CSS (min/max-width).
	 * @return array<string,string>  Map of breakpoint key → media condition string.
	 */
	public function get_breakpoint_media_query_map( bool $etch_syntax = false ): array {
		$definitions = $this->get_breakpoint_width_map();
		$queries     = array();

		foreach ( $definitions as $key => $definition ) {
			$width = (int) ( $definition['width'] ?? 0 );
			$type  = (string) ( $definition['type'] ?? 'max' );
			if ( $width <= 0 ) {
				continue;
			}

			if ( $etch_syntax ) {
				$queries[ $key ] = 'min' === $type
					? '(width >= to-rem(' . $width . 'px))'
					: '(width <= to-rem(' . $width . 'px))';
				continue;
			}

			$queries[ $key ] = 'min' === $type
				? '(min-width: ' . $width . 'px)'
				: '(max-width: ' . $width . 'px)';
		}

		return $queries;
	}

	/**
	 * Convert a Bricks breakpoint name to a complete @media rule string using Etch
	 * range syntax.
	 *
	 * Returns false when the breakpoint key is not registered in the map.
	 *
	 * @param string $breakpoint Bricks breakpoint key (e.g. "tablet_portrait").
	 * @return string|false
	 */
	public function get_media_query_for_breakpoint( string $breakpoint ) {
		$queries = $this->get_breakpoint_media_query_map( true );
		if ( ! isset( $queries[ $breakpoint ] ) ) {
			return false;
		}
		return '@media ' . $queries[ $breakpoint ];
	}

	/**
	 * Extract all breakpoint keys referenced in a Bricks settings array.
	 *
	 * Includes both the registered breakpoint keys and any custom breakpoint
	 * suffixes found on `_cssCustom:*` keys.
	 *
	 * @param array<string,mixed> $settings Bricks element or class settings.
	 * @return array<int,string>
	 */
	public function detect_breakpoint_keys( array $settings ): array {
		$keys = array_keys( $this->get_breakpoint_media_query_map() );

		foreach ( $settings as $setting_key => $value ) {
			$setting_key = (string) $setting_key;
			if ( 0 !== strpos( $setting_key, '_cssCustom:' ) ) {
				continue;
			}

			$breakpoint = $this->sanitize_breakpoint_key( substr( $setting_key, strlen( '_cssCustom:' ) ) );
			if ( '' !== $breakpoint ) {
				$keys[] = $breakpoint;
			}
		}

		return array_values( array_unique( array_filter( $keys ) ) );
	}

	/**
	 * Sanitise a breakpoint key with or without WordPress loaded.
	 *
	 * Produces a lowercase alphanumeric-plus-hyphen-plus-underscore string.
	 * When WordPress is available, wp's sanitize_key() is used; otherwise a
	 * manual regex is applied so the method works in non-WP unit tests.
	 *
	 * @param string $key Candidate breakpoint key.
	 * @return string
	 */
	public function sanitize_breakpoint_key( string $key ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return (string) sanitize_key( $key );
		}

		$normalized = strtolower( $key );
		$normalized = preg_replace( '/[^a-z0-9_-]/', '', $normalized );
		return is_string( $normalized ) ? $normalized : '';
	}

	/**
	 * Normalise a raw @media / @container condition from Bricks CSS to Etch's
	 * to-rem() range syntax.
	 *
	 * Three pixel-based patterns are handled:
	 *
	 * 1. Legacy media query syntax (Bricks default output):
	 *      (min-width: 1200px)     → (width >= to-rem(1200px))
	 *      (max-width: 767px)      → (width <= to-rem(767px))
	 *
	 * 2. Modern range media query syntax:
	 *      (width >= 1200px)       → (width >= to-rem(1200px))
	 *      (width <= 767px)        → (width <= to-rem(767px))
	 *
	 * 3. Container query range syntax (inline-size / block-size):
	 *      (inline-size >= 329px)  → (inline-size >= to-rem(329px))
	 *      (block-size >= 200px)   → (block-size >= to-rem(200px))
	 *
	 * Conditions that already contain to-rem() are left untouched.
	 * Non-px units and unrecognised query types are passed through unchanged.
	 *
	 * @param string $condition Raw condition string (without @media / @container keyword).
	 * @return string
	 */
	public function normalize_media_condition_to_etch( string $condition ): string {
		// Already converted — bail early.
		if ( false !== strpos( $condition, 'to-rem(' ) ) {
			return $condition;
		}

		// (min-width: Xpx) → (width >= to-rem(Xpx))
		$condition = preg_replace(
			'/\(\s*min-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)/',
			'(width >= to-rem($1px))',
			$condition
		);
		$condition = is_string( $condition ) ? $condition : '';

		// (max-width: Xpx) → (width <= to-rem(Xpx))
		$condition = preg_replace(
			'/\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)/',
			'(width <= to-rem($1px))',
			$condition
		);
		$condition = is_string( $condition ) ? $condition : '';

		// Range syntax for media and container queries (width|inline-size|block-size).
		$condition = preg_replace(
			'/\(\s*(width|inline-size|block-size)\s*(>=|<=|>|<)\s*(\d+(?:\.\d+)?)px\s*\)/',
			'($1 $2 to-rem($3px))',
			$condition
		);
		$condition = is_string( $condition ) ? $condition : '';

		return $condition;
	}
}
