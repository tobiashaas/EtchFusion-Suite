<?php
/**
 * Unit tests for EFS_CSS_Normalizer.
 *
 * All methods in EFS_CSS_Normalizer are pure string transforms with no
 * WordPress dependencies, so these tests run in the unit suite without
 * any database or WP bootstrap requirements.
 *
 * @package Bricks2Etch\Tests\Unit\CSS
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\CSS;

use Bricks2Etch\CSS\EFS_CSS_Normalizer;
use WP_UnitTestCase;

/**
 * Tests for EFS_CSS_Normalizer.
 *
 * Groups:
 *   - Grid placement normalisation
 *   - HSL color token normalisation
 *   - Alpha-to-percentage conversion
 *   - CSS variable alias normalisation
 *   - Logical property conversion
 *   - Bricks ID selector renaming
 *   - Quad shorthand collapse
 *   - Border-width unit normalisation
 *   - Gradient stop normalisation
 *   - Content property quoting
 */
class CssNormalizerTest extends WP_UnitTestCase {

	/** @var EFS_CSS_Normalizer */
	private $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new EFS_CSS_Normalizer();
	}

	// =========================================================================
	// Grid placement normalisation
	// =========================================================================

	/** Bricks bug: "span N / X" is invalid — the span keyword must be stripped. */
	public function test_normalize_grid_column_span_slash(): void {
		$input    = 'grid-column: span 2 / -1;';
		$expected = 'grid-column: 2 / -1;';
		$this->assertSame( $expected, $this->normalizer->normalize_invalid_grid_placement( $input ) );
	}

	/** Same bug on grid-row axis. */
	public function test_normalize_grid_row_span_slash(): void {
		$input    = 'grid-row: span 3 / 4;';
		$expected = 'grid-row: 3 / 4;';
		$this->assertSame( $expected, $this->normalizer->normalize_invalid_grid_placement( $input ) );
	}

	/** Valid "span N" (no slash) must not be changed. */
	public function test_normalize_grid_valid_span_only_unchanged(): void {
		$input = 'grid-column: span 2;';
		$this->assertSame( $input, $this->normalizer->normalize_invalid_grid_placement( $input ) );
	}

	/** Valid "A / B" line-based shorthand must not be changed. */
	public function test_normalize_grid_valid_line_based_unchanged(): void {
		$input = 'grid-column: 1 / -1;';
		$this->assertSame( $input, $this->normalizer->normalize_invalid_grid_placement( $input ) );
	}

	/** CSS without grid properties is returned verbatim. */
	public function test_normalize_grid_no_grid_props_unchanged(): void {
		$input = 'color: red; margin: 0;';
		$this->assertSame( $input, $this->normalizer->normalize_invalid_grid_placement( $input ) );
	}

	// =========================================================================
	// HSL color token normalisation
	// =========================================================================

	/** hsl(var(--token-hsl) / 0.5) → color-mix(..., 50%, transparent) */
	public function test_normalize_hsl_with_decimal_alpha(): void {
		$input    = 'background: hsl(var(--primary-hsl) / 0.5);';
		$result   = $this->normalizer->normalize_deprecated_hsl_references( $input );
		$this->assertStringContainsString( 'color-mix(in oklab, var(--primary) 50%, transparent)', $result );
	}

	/** hsl(var(--token-hsl)) without alpha → var(--token) */
	public function test_normalize_hsl_without_alpha(): void {
		$input    = 'color: hsl(var(--accent-hsl));';
		$result   = $this->normalizer->normalize_deprecated_hsl_references( $input );
		$this->assertStringContainsString( 'var(--accent)', $result );
		$this->assertStringNotContainsString( '-hsl', $result );
	}

	/** Bare var(--token-hsl) → var(--token) */
	public function test_normalize_bare_hsl_var(): void {
		$input    = 'color: var(--primary-hsl);';
		$result   = $this->normalizer->normalize_deprecated_hsl_references( $input );
		$this->assertStringContainsString( 'var(--primary)', $result );
		$this->assertStringNotContainsString( '-hsl', $result );
	}

	/** CSS without -hsl is returned unchanged (fast path). */
	public function test_normalize_hsl_no_hsl_unchanged(): void {
		$input = 'color: var(--primary); margin: 0;';
		$this->assertSame( $input, $this->normalizer->normalize_deprecated_hsl_references( $input ) );
	}

	// =========================================================================
	// Alpha-to-percentage conversion
	// =========================================================================

	/** Decimal ≤ 1 is multiplied by 100. */
	public function test_alpha_decimal_to_percentage(): void {
		$this->assertSame( '50%', $this->normalizer->normalize_alpha_to_percentage( '0.5' ) );
	}

	/** Decimal 1 = 100%. */
	public function test_alpha_one_to_hundred_percent(): void {
		$this->assertSame( '100%', $this->normalizer->normalize_alpha_to_percentage( '1' ) );
	}

	/** Empty string → 100% (fully opaque). */
	public function test_alpha_empty_is_fully_opaque(): void {
		$this->assertSame( '100%', $this->normalizer->normalize_alpha_to_percentage( '' ) );
	}

	/** Already a percentage — returned as-is. */
	public function test_alpha_already_percentage_unchanged(): void {
		$this->assertSame( '75%', $this->normalizer->normalize_alpha_to_percentage( '75%' ) );
	}

	/** CSS expression is wrapped in calc(). */
	public function test_alpha_expression_wrapped_in_calc(): void {
		$result = $this->normalizer->normalize_alpha_to_percentage( 'var(--alpha)' );
		$this->assertStringContainsString( 'calc(', $result );
		$this->assertStringContainsString( 'var(--alpha)', $result );
	}

	// =========================================================================
	// Logical property conversion
	// =========================================================================

	/** margin-top → margin-block-start */
	public function test_logical_margin_top(): void {
		$input  = '.el { margin-top: 10px; }';
		$result = $this->normalizer->convert_to_logical_properties( $input );
		$this->assertStringContainsString( 'margin-block-start: 10px', $result );
		$this->assertStringNotContainsString( 'margin-top:', $result );
	}

	/** width → inline-size */
	public function test_logical_width(): void {
		$input  = '.el { width: 100%; }';
		$result = $this->normalizer->convert_to_logical_properties( $input );
		$this->assertStringContainsString( 'inline-size: 100%', $result );
		$this->assertStringNotContainsString( ' width:', $result );
	}

	/** width inside @media condition must NOT be renamed. */
	public function test_logical_width_in_media_query_unchanged(): void {
		$input  = '@media (max-width: 767px) { .el { color: red; } }';
		$result = $this->normalizer->convert_to_logical_properties( $input );
		$this->assertStringContainsString( 'max-width: 767px', $result );
	}

	/** CSS custom properties (--width, --height) must not be renamed. */
	public function test_logical_custom_properties_unchanged(): void {
		$input  = '.el { --my-width: 100px; }';
		$result = $this->normalizer->convert_to_logical_properties( $input );
		// --my-width must not become --my-inline-size
		$this->assertStringContainsString( '--my-width', $result );
	}

	// =========================================================================
	// Bricks element ID selector renaming
	// =========================================================================

	/** #brxe-abc → #etch-abc */
	public function test_bricks_id_selector_renamed(): void {
		$input    = '#brxe-a1b2c3 { color: red; }';
		$result   = $this->normalizer->normalize_bricks_id_selectors_in_css( $input );
		$this->assertStringContainsString( '#etch-a1b2c3', $result );
		$this->assertStringNotContainsString( '#brxe-', $result );
	}

	/** Multiple ID selectors in one string are all renamed. */
	public function test_multiple_bricks_id_selectors_renamed(): void {
		$input  = '#brxe-aaa { } #brxe-bbb { }';
		$result = $this->normalizer->normalize_bricks_id_selectors_in_css( $input );
		$this->assertStringContainsString( '#etch-aaa', $result );
		$this->assertStringContainsString( '#etch-bbb', $result );
	}

	/** Empty string returns empty string. */
	public function test_bricks_id_selector_empty_string(): void {
		$this->assertSame( '', $this->normalizer->normalize_bricks_id_selectors_in_css( '' ) );
	}

	// =========================================================================
	// Quad shorthand collapse
	// =========================================================================

	/** All four equal → single value. */
	public function test_quad_shorthand_all_equal(): void {
		$result = $this->normalizer->build_quad_shorthand_value( '10px', '10px', '10px', '10px' );
		$this->assertSame( '10px', $result );
	}

	/** top==bottom AND right==left → two values. */
	public function test_quad_shorthand_two_values(): void {
		$result = $this->normalizer->build_quad_shorthand_value( '10px', '20px', '10px', '20px' );
		$this->assertSame( '10px 20px', $result );
	}

	/** right==left only → three values. */
	public function test_quad_shorthand_three_values(): void {
		$result = $this->normalizer->build_quad_shorthand_value( '10px', '20px', '30px', '20px' );
		$this->assertSame( '10px 20px 30px', $result );
	}

	/** All different → four values. */
	public function test_quad_shorthand_four_values(): void {
		$result = $this->normalizer->build_quad_shorthand_value( '10px', '20px', '30px', '40px' );
		$this->assertSame( '10px 20px 30px 40px', $result );
	}

	// =========================================================================
	// Border-width unit normalisation
	// =========================================================================

	/** Plain integer gets px appended. */
	public function test_border_width_integer_gets_px(): void {
		$this->assertSame( '2px', $this->normalizer->normalize_border_width_component( '2' ) );
	}

	/** Zero is returned as '0' (no unit). */
	public function test_border_width_zero_no_unit(): void {
		$this->assertSame( '0', $this->normalizer->normalize_border_width_component( '0' ) );
	}

	/** Already-unitized value unchanged. */
	public function test_border_width_with_unit_unchanged(): void {
		$this->assertSame( '2px', $this->normalizer->normalize_border_width_component( '2px' ) );
	}

	/** Keyword (thin, medium, thick) unchanged. */
	public function test_border_width_keyword_unchanged(): void {
		$this->assertSame( 'thin', $this->normalizer->normalize_border_width_component( 'thin' ) );
	}

	// =========================================================================
	// Gradient stop normalisation
	// =========================================================================

	/** Unitless number → percentage. */
	public function test_gradient_stop_unitless_gets_percent(): void {
		$this->assertSame( '50%', $this->normalizer->normalize_gradient_stop( '50' ) );
	}

	/** Already-percent value unchanged. */
	public function test_gradient_stop_with_percent_unchanged(): void {
		$this->assertSame( '50%', $this->normalizer->normalize_gradient_stop( '50%' ) );
	}

	/** Empty value returns empty. */
	public function test_gradient_stop_empty_returns_empty(): void {
		$this->assertSame( '', $this->normalizer->normalize_gradient_stop( '' ) );
	}

	// =========================================================================
	// Content property quoting
	// =========================================================================

	/** Plain string gets double-quoted. */
	public function test_content_property_plain_string_quoted(): void {
		$result = $this->normalizer->normalize_content_property_value( 'hello' );
		$this->assertSame( '"hello"', $result );
	}

	/** Already-quoted string is returned as-is. */
	public function test_content_property_already_quoted_unchanged(): void {
		$this->assertSame( '"hello"', $this->normalizer->normalize_content_property_value( '"hello"' ) );
	}

	/** CSS-wide keywords are lowercased and returned unchanged. */
	public function test_content_property_keyword_lowercased(): void {
		$this->assertSame( 'none', $this->normalizer->normalize_content_property_value( 'none' ) );
		$this->assertSame( 'normal', $this->normalizer->normalize_content_property_value( 'Normal' ) );
	}

	/** attr() function is returned as-is. */
	public function test_content_property_attr_function_unchanged(): void {
		$input = 'attr(data-label)';
		$this->assertSame( $input, $this->normalizer->normalize_content_property_value( $input ) );
	}
}
