<?php
/**
 * Unit tests for EFS_Breakpoint_Resolver.
 *
 * Tests cover the pure-computation methods that do not require a database call.
 * The one WP-dependent method — get_breakpoint_width_map() — is tested against
 * the built-in defaults (no `bricks_breakpoints` option set), which is the
 * state guaranteed by WP_UnitTestCase's clean database.
 *
 * @package Bricks2Etch\Tests\Unit\CSS
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\CSS;

use Bricks2Etch\CSS\EFS_Breakpoint_Resolver;
use WP_UnitTestCase;

/**
 * Tests for EFS_Breakpoint_Resolver.
 *
 * Groups:
 *   - Default breakpoint map structure
 *   - Media query string generation (Etch syntax)
 *   - Named breakpoint resolution
 *   - Legacy media-condition normalisation to Etch to-rem() syntax
 */
class BreakpointResolverTest extends WP_UnitTestCase {

	/** @var EFS_Breakpoint_Resolver */
	private $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new EFS_Breakpoint_Resolver();
		// Ensure the in-memory cache is clear between tests so each test
		// starts with a fresh read of the (clean) database.
		$this->resolver->clear_cache();
	}

	// =========================================================================
	// Default breakpoint map structure
	// =========================================================================

	/** The default map must include the five Bricks built-in breakpoints. */
	public function test_default_map_contains_builtin_keys(): void {
		$map = $this->resolver->get_breakpoint_width_map();
		$this->assertArrayHasKey( 'desktop', $map );
		$this->assertArrayHasKey( 'tablet_landscape', $map );
		$this->assertArrayHasKey( 'tablet_portrait', $map );
		$this->assertArrayHasKey( 'mobile_landscape', $map );
		$this->assertArrayHasKey( 'mobile_portrait', $map );
	}

	/** Desktop must appear first in the map (sorted largest-first, desktop always first). */
	public function test_default_map_desktop_is_first(): void {
		$map  = $this->resolver->get_breakpoint_width_map();
		$keys = array_keys( $map );
		$this->assertSame( 'desktop', $keys[0] );
	}

	/** Desktop uses the 'min' type (min-width breakpoint). */
	public function test_default_desktop_is_min_type(): void {
		$map = $this->resolver->get_breakpoint_width_map();
		$this->assertSame( 'min', $map['desktop']['type'] );
	}

	/** Each non-desktop breakpoint uses the 'max' type. */
	public function test_default_non_desktop_are_max_type(): void {
		$map = $this->resolver->get_breakpoint_width_map();
		foreach ( $map as $key => $definition ) {
			if ( 'desktop' === $key ) {
				continue;
			}
			$this->assertSame( 'max', $definition['type'], "Expected type=max for breakpoint '{$key}'" );
		}
	}

	/** The result of get_breakpoint_width_map() is cached after the first call. */
	public function test_breakpoint_map_is_cached(): void {
		$first  = $this->resolver->get_breakpoint_width_map();
		$second = $this->resolver->get_breakpoint_width_map();
		$this->assertSame( $first, $second );
	}

	/** clear_cache() forces a fresh read on the next call. */
	public function test_clear_cache_allows_fresh_read(): void {
		$first = $this->resolver->get_breakpoint_width_map();
		$this->resolver->clear_cache();
		$second = $this->resolver->get_breakpoint_width_map();
		// Values must be equal (same defaults), even if re-fetched.
		$this->assertEquals( $first, $second );
	}

	// =========================================================================
	// Media query string generation (Etch syntax)
	// =========================================================================

	/** Etch syntax uses to-rem() for max-width breakpoints. */
	public function test_etch_syntax_tablet_portrait_uses_to_rem(): void {
		$queries = $this->resolver->get_breakpoint_media_query_map( true );
		$this->assertArrayHasKey( 'tablet_portrait', $queries );
		$this->assertStringContainsString( 'to-rem(', $queries['tablet_portrait'] );
		$this->assertStringContainsString( '<=', $queries['tablet_portrait'] );
	}

	/** Plain CSS syntax (not Etch) uses max-width: Xpx. */
	public function test_plain_syntax_tablet_portrait_uses_max_width(): void {
		$queries = $this->resolver->get_breakpoint_media_query_map( false );
		$this->assertArrayHasKey( 'tablet_portrait', $queries );
		$this->assertStringContainsString( 'max-width:', $queries['tablet_portrait'] );
		$this->assertStringNotContainsString( 'to-rem(', $queries['tablet_portrait'] );
	}

	/** Desktop breakpoint uses >= (min-width) range syntax in Etch mode. */
	public function test_etch_syntax_desktop_uses_min_range(): void {
		$queries = $this->resolver->get_breakpoint_media_query_map( true );
		$this->assertArrayHasKey( 'desktop', $queries );
		$this->assertStringContainsString( '>=', $queries['desktop'] );
	}

	// =========================================================================
	// Named breakpoint resolution
	// =========================================================================

	/** Known breakpoint returns a complete @media string. */
	public function test_get_media_query_for_known_breakpoint(): void {
		$result = $this->resolver->get_media_query_for_breakpoint( 'mobile_portrait' );
		$this->assertIsString( $result );
		$this->assertStringStartsWith( '@media', $result );
		$this->assertStringContainsString( 'to-rem(', $result );
	}

	/** Unknown breakpoint returns false. */
	public function test_get_media_query_for_unknown_breakpoint_returns_false(): void {
		$result = $this->resolver->get_media_query_for_breakpoint( 'nonexistent_breakpoint' );
		$this->assertFalse( $result );
	}

	// =========================================================================
	// Legacy media-condition normalisation to Etch to-rem() syntax
	// =========================================================================

	/** (min-width: Xpx) → (width >= to-rem(Xpx)) */
	public function test_normalize_min_width_to_etch(): void {
		$result = $this->resolver->normalize_media_condition_to_etch( '(min-width: 1200px)' );
		$this->assertSame( '(width >= to-rem(1200px))', $result );
	}

	/** (max-width: Xpx) → (width <= to-rem(Xpx)) */
	public function test_normalize_max_width_to_etch(): void {
		$result = $this->resolver->normalize_media_condition_to_etch( '(max-width: 767px)' );
		$this->assertSame( '(width <= to-rem(767px))', $result );
	}

	/** Modern range syntax: (width >= Xpx) → (width >= to-rem(Xpx)) */
	public function test_normalize_range_syntax_to_etch(): void {
		$result = $this->resolver->normalize_media_condition_to_etch( '(width >= 1200px)' );
		$this->assertSame( '(width >= to-rem(1200px))', $result );
	}

	/** Container query: (inline-size >= Xpx) → (inline-size >= to-rem(Xpx)) */
	public function test_normalize_container_query_to_etch(): void {
		$result = $this->resolver->normalize_media_condition_to_etch( '(inline-size >= 329px)' );
		$this->assertSame( '(inline-size >= to-rem(329px))', $result );
	}

	/** Already-converted condition (contains to-rem) is returned unchanged. */
	public function test_normalize_already_converted_unchanged(): void {
		$input  = '(width <= to-rem(767px))';
		$result = $this->resolver->normalize_media_condition_to_etch( $input );
		$this->assertSame( $input, $result );
	}

	/** Non-px units (em, rem, vw) are returned unchanged. */
	public function test_normalize_non_px_units_unchanged(): void {
		$input  = '(min-width: 60em)';
		$result = $this->resolver->normalize_media_condition_to_etch( $input );
		$this->assertSame( $input, $result );
	}
}
