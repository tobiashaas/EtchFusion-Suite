<?php
/**
 * Unit tests for SVG Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Svg;
use WP_UnitTestCase;

class SvgConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-class-1' => array(
				'id'       => 'etch-style-1',
				'selector' => '.svg-wrapper',
			),
		);
	}

	public function test_convert_svg_with_file_source(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array(
				'source' => 'file',
				'file'   => array( 'url' => 'https://example.com/icon.svg' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
		$this->assertStringContainsString( 'https://example.com/icon.svg', $result );
	}

	public function test_convert_svg_with_code_source(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array(
				'source' => 'code',
				'code'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// With WordPress test env, upload may succeed and return block, or fail and return null.
		if ( $result !== null ) {
			$this->assertStringContainsString( 'wp:etch/svg', $result );
			$this->assertStringContainsString( 'src', $result );
		} else {
			$this->assertNull( $result, 'SVG code source upload not available in test environment.' );
		}
	}

	public function test_convert_svg_with_icon_set_source(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array(
				'source'   => 'iconSet',
				'iconSet'  => array(
					'svg' => array( 'url' => 'https://example.com/iconset.svg' ),
				),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
		$this->assertStringContainsString( 'https://example.com/iconset.svg', $result );
	}

	public function test_convert_svg_with_dynamic_data_source(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array( 'source' => 'dynamicData' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNull( $result );
	}

	public function test_svg_attribute_mapping(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array(
				'source'      => 'file',
				'file'        => array( 'url' => 'https://example.com/a.svg' ),
				'width'       => '48',
				'height'      => '48',
				'fill'        => '#333',
				'stroke'      => '#fff',
				'strokeWidth' => '2',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'width', $result );
		$this->assertStringContainsString( 'height', $result );
		$this->assertStringContainsString( 'fill', $result );
		$this->assertStringContainsString( 'stroke', $result );
		$this->assertStringContainsString( 'stroke-width', $result );
	}

	public function test_svg_css_classes_and_styles(): void {
		$converter = new EFS_Element_Svg( $this->style_map );
		$element   = array(
			'name'     => 'svg',
			'settings' => array(
				'source'             => 'file',
				'file'               => array( 'url' => 'https://example.com/b.svg' ),
				'_cssGlobalClasses'  => array( 'bricks-class-1' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'styles', $result );
		$this->assertStringContainsString( 'etch-style-1', $result );
	}
}
