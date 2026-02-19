<?php
/**
 * CSS converter shorthand and quad-helper tests.
 */

declare( strict_types=1 );

namespace {
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			if ( isset( $GLOBALS['__efs_test_options'] ) && is_array( $GLOBALS['__efs_test_options'] ) && array_key_exists( $name, $GLOBALS['__efs_test_options'] ) ) {
				return $GLOBALS['__efs_test_options'][ $name ];
			}
			return $default;
		}
	}
}

namespace EtchFusionSuite\Tests {

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CssConverterShorthandTest extends TestCase {

	private function makeConverter(): EFS_CSS_Converter {
		return new EFS_CSS_Converter( new EFS_Error_Handler(), new StubStyleRepository() );
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__efs_test_options'] = array();
	}

	public function test_build_quad_shorthand_value_emits_shortest_forms(): void {
		$converter = $this->makeConverter();
		$method    = new ReflectionMethod( $converter, 'build_quad_shorthand_value' );
		$method->setAccessible( true );

		$this->assertSame( '1rem', $method->invoke( $converter, '1rem', '1rem', '1rem', '1rem' ) );
		$this->assertSame( '1rem 2rem', $method->invoke( $converter, '1rem', '2rem', '1rem', '2rem' ) );
		$this->assertSame( '1rem 2rem 3rem', $method->invoke( $converter, '1rem', '2rem', '3rem', '2rem' ) );
		$this->assertSame( '1rem 2rem 3rem 4rem', $method->invoke( $converter, '1rem', '2rem', '3rem', '4rem' ) );
	}

	public function test_convert_bricks_class_to_etch_uses_shared_quad_modules_for_border_margin_padding_and_inset(): void {
		$converter = $this->makeConverter();
		$result    = $converter->convert_bricks_class_to_etch(
			array(
				'id'       => 'abc123',
				'name'     => 'quad-test',
				'settings' => array(
					'_border' => array(
						'width'  => array(
							'top'    => '1px',
							'right'  => '2px',
							'bottom' => '1px',
							'left'   => '2px',
						),
						'radius' => array(
							'top'    => 'var(--radius)',
							'right'  => 'var(--radius)',
							'bottom' => 'var(--radius)',
							'left'   => 'var(--radius)',
						),
					),
					'_margin' => array(
						'top'    => '1rem',
						'right'  => '2rem',
						'bottom' => '1rem',
						'left'   => '2rem',
					),
					'_padding' => array(
						'top'    => '1rem',
						'right'  => '2rem',
						'bottom' => '3rem',
						'left'   => '2rem',
					),
					'_top'    => '10px',
					'_right'  => '20px',
					'_bottom' => '10px',
					'_left'   => '20px',
				),
			)
		);

		$css = $result['css'];

		$this->assertStringContainsString( 'border-width: 1px 2px;', $css );
		$this->assertStringContainsString( 'border-radius: var(--radius);', $css );
		$this->assertStringContainsString( 'margin: 1rem 2rem;', $css );
		$this->assertStringContainsString( 'padding: 1rem 2rem 3rem;', $css );
		$this->assertStringContainsString( 'inset: 10px 20px;', $css );
	}

	public function test_convert_bricks_class_to_etch_normalizes_unitless_border_width_to_px(): void {
		$converter = $this->makeConverter();
		$result    = $converter->convert_bricks_class_to_etch(
			array(
				'id'       => 'hik09l',
				'name'     => 'fr-feature-card-yankee__row--featured',
				'settings' => array(
					'_border' => array(
						'width' => array(
							'top'    => '1',
							'right'  => '1',
							'bottom' => '1',
							'left'   => '1',
						),
						'style' => 'solid',
						'color' => array(
							'raw' => 'var(--featured-border-color)',
						),
					),
				),
			)
		);

		$css = $result['css'];
		$this->assertStringContainsString( 'border-width: 1px;', $css );
		$this->assertStringContainsString( 'border-style: solid;', $css );
		$this->assertStringContainsString( 'border-color: var(--featured-border-color);', $css );
	}

	public function test_margin_direct_value_can_be_extended_with_side_overrides(): void {
		$converter = $this->makeConverter();
		$result    = $converter->convert_bricks_class_to_etch(
			array(
				'id'       => 'override123',
				'name'     => 'override-test',
				'settings' => array(
					'_margin'    => '1rem',
					'_marginTop' => '2rem',
				),
			)
		);

		$css = $result['css'];

		$this->assertStringContainsString( 'margin: 1rem;', $css );
		$this->assertStringContainsString( 'margin-block-start: 2rem;', $css );
	}

	public function test_normalize_identical_shorthands_collapses_longhands_for_known_quad_properties(): void {
		$converter = $this->makeConverter();
		$method    = new ReflectionMethod( $converter, 'normalize_identical_shorthands' );
		$method->setAccessible( true );

		$css = '.x{padding-block-start: 1rem; padding-inline-end: 2rem; padding-block-end: 3rem; padding-inline-start: 2rem;'
			. 'margin-block-start: 10px; margin-inline-end: 20px; margin-block-end: 10px; margin-inline-start: 20px;'
			. 'inset-block-start: 5px; inset-inline-end: 5px; inset-block-end: 5px; inset-inline-start: 5px;'
			. 'border-top-left-radius: 4px; border-top-right-radius: 4px; border-bottom-right-radius: 4px; border-bottom-left-radius: 4px;}';

		$normalized = (string) $method->invoke( $converter, $css );

		$this->assertStringContainsString( 'padding: 1rem 2rem 3rem;', $normalized );
		$this->assertStringContainsString( 'margin: 10px 20px;', $normalized );
		$this->assertStringContainsString( 'inset: 5px;', $normalized );
		$this->assertStringContainsString( 'border-radius: 4px;', $normalized );
	}

	public function test_convert_bricks_class_to_etch_handles_bricks_ui_pseudo_and_state_keys(): void {
		$converter = $this->makeConverter();
		$result    = $converter->convert_bricks_class_to_etch(
			array(
				'id'       => 'pseudo123',
				'name'     => 'pseudo-test',
				'settings' => array(
					'_position'            => 'relative',
					'_typography'          => array(
						'font-size' => '100px',
					),
					'_typography:hover'    => array(
						'color' => array(
							'raw' => 'var(--info)',
						),
					),
					'_position::after'     => 'absolute',
					'_right::after'        => '0',
					'_bottom::after'       => '-10px',
					'_left::after'         => '0',
					'_width::after'        => '100%',
					'_height::after'       => '4px',
					'_content::after'      => '123',
					'_background::after'   => array(
						'color' => array(
							'raw' => 'var(--info)',
						),
					),
					'_content::before'     => '456',
					'_background::before'  => array(
						'color' => array(
							'raw' => 'var(--tertiary)',
						),
					),
					'_background:focus'    => array(
						'color' => array(
							'raw' => 'var(--accent)',
						),
					),
					'_typography:hover:tablet_portrait' => array(
						'color' => array(
							'raw' => 'var(--danger)',
						),
					),
				),
			)
		);

		$css = $result['css'];

		$this->assertStringContainsString( 'font-size: 100px;', $css );
		$this->assertStringContainsString( '&:hover {', $css );
		$this->assertStringContainsString( 'color: var(--info);', $css );
		$this->assertStringContainsString( '&::after {', $css );
		$this->assertStringContainsString( 'content: "123";', $css );
		$this->assertStringContainsString( '&::before {', $css );
		$this->assertStringContainsString( 'content: "456";', $css );
		$this->assertStringContainsString( '&:focus {', $css );
		$this->assertStringContainsString( '@media (max-width: 991px)', $css );
		$this->assertStringContainsString( 'color: var(--danger);', $css );
	}

	public function test_parse_custom_css_stylesheet_normalizes_brxe_id_selectors_inside_class_rules(): void {
		$converter = $this->makeConverter();
		$method    = new ReflectionMethod( $converter, 'parse_custom_css_stylesheet' );
		$method->setAccessible( true );

		$style_map = array(
			'abc123' => array(
				'id'       => 'style123',
				'selector' => '.alpha',
			),
		);

		$stylesheet = '.alpha #brxe-328483 { color: red; }';
		$result     = $method->invoke( $converter, $stylesheet, $style_map );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'style123', $result );
		$this->assertStringContainsString( '& #etch-328483 {', $result['style123']['css'] );
		$this->assertStringContainsString( 'color: red;', $result['style123']['css'] );
	}

	public function test_parse_custom_id_css_stylesheet_migrates_brxe_ids_with_pseudo_and_media(): void {
		$converter = $this->makeConverter();
		$method    = new ReflectionMethod( $converter, 'parse_custom_id_css_stylesheet' );
		$method->setAccessible( true );

		$stylesheet = implode(
			"\n",
			array(
				'#brxe-328483 { color: red; }',
				'#brxe-328483::before { content: "x"; }',
				'@media (max-width: 991px) {',
				'  #brxe-328483:hover { color: blue; }',
				'}',
			)
		);

		$result = $method->invoke( $converter, $stylesheet );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		$style = array_values( $result )[0];
		$this->assertSame( '#etch-328483', $style['selector'] );
		$this->assertStringContainsString( 'color: red;', $style['css'] );
		$this->assertStringContainsString( '&::before {', $style['css'] );
		$this->assertStringContainsString( 'content: "x";', $style['css'] );
		$this->assertStringContainsString( '@media (max-width: 991px)', $style['css'] );
		$this->assertStringContainsString( '&:hover {', $style['css'] );
		$this->assertStringContainsString( 'color: blue;', $style['css'] );
	}

	public function test_convert_bricks_class_to_etch_supports_custom_breakpoint_keys_from_bricks_options(): void {
		$GLOBALS['__efs_test_options']['bricks_breakpoints'] = array(
			array(
				'base'  => true,
				'key'   => 'desktop',
				'width' => 1279,
			),
			array(
				'key'   => 'tablet_portrait',
				'width' => 991,
			),
			array(
				'key'   => 'b1',
				'width' => 350,
			),
		);

		$converter = $this->makeConverter();
		$result    = $converter->convert_bricks_class_to_etch(
			array(
				'id'       => 'bp123',
				'name'     => 'bp-test',
				'settings' => array(
					'_background:b1' => array(
						'color' => array(
							'raw' => 'var(--base)',
						),
					),
				),
			)
		);

		$css = $result['css'];
		$this->assertStringContainsString( '@media (max-width: 350px)', $css );
		$this->assertStringContainsString( 'background-color: var(--base);', $css );
		$this->assertStringNotContainsString( '&:b1 {', $css );
	}
}

final class StubStyleRepository implements Style_Repository_Interface {
	public function get_etch_styles(): array {
		return array();
	}

	public function save_etch_styles( array $styles ): bool {
		return true;
	}

	public function get_style_map(): array {
		return array();
	}

	public function save_style_map( array $map ): bool {
		return true;
	}

	public function get_svg_version(): int {
		return 1;
	}

	public function increment_svg_version(): int {
		return 2;
	}

	public function get_global_stylesheets(): array {
		return array();
	}

	public function save_global_stylesheets( array $stylesheets ): bool {
		return true;
	}

	public function get_bricks_global_classes(): array {
		return array();
	}

	public function invalidate_style_cache(): bool {
		return true;
	}
}
}
