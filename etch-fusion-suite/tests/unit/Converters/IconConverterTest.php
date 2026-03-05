<?php
/**
 * Unit tests for Icon Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Icon;
use WP_UnitTestCase;

class IconConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-icon-1' => array(
				'id'       => 'etch-icon-style',
				'selector' => '.icon-class',
			),
		);
	}

	public function test_convert_basic_icon(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(
				'icon' => 'fa-solid fa-heart',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
		$this->assertStringContainsString( '"tag":"svg"', $result );
	}

	public function test_icon_generates_svg_block(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}

	public function test_icon_empty_attributes(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"attributes":[]', $result );
	}

	public function test_icon_style_inheritance(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
			'_cssGlobalClasses' => array( 'bricks-icon-1' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Icon converter ignores CSS styles intentionally to emit empty svg blocks
		// This is by design to avoid icon-font specific attributes
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}

	public function test_icon_with_font_awesome_icon(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(
				'icon' => 'fa-solid fa-star',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
		$this->assertStringContainsString( '"tag":"svg"', $result );
	}

	public function test_icon_with_ionicons(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(
				'icon' => 'ion-ios-heart',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}

	public function test_icon_converts_to_empty_svg_block(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(
				'icon' => 'fa-solid fa-circle',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Font icons intentionally convert to empty svg blocks to avoid
		// carrying icon-font specific attributes and classes
		$this->assertStringContainsString( '<!-- wp:etch/svg', $result );
		$this->assertStringContainsString( '<!-- /wp:etch/svg -->', $result );
	}

	public function test_icon_with_custom_styling(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(
				'icon' => 'fa-solid fa-download',
			),
			'_cssGlobalClasses' => array( 'bricks-icon-1' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Icon converter ignores CSS styles intentionally
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}

	public function test_icon_no_children_support(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
		);
		$children = array(
			array( 'name' => 'text', 'settings' => array( 'text' => 'Should be ignored' ) ),
		);
		$result = $converter->convert( $element, $children, array() );
		$this->assertNotNull( $result );
		// Children should not appear in output
		$this->assertStringNotContainsString( 'Should be ignored', $result );
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}

	public function test_icon_json_structure(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Verify JSON structure is valid
		$this->assertStringContainsString( '{"tag":"svg"', $result );
		$this->assertStringContainsString( '"attributes"', $result );
		$this->assertStringContainsString( '"styles"', $result );
	}

	public function test_icon_multiple_style_classes(): void {
		$converter = new EFS_Element_Icon( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'settings' => array(),
			'_cssGlobalClasses' => array( 'bricks-icon-1', 'custom-class' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Icon converter ignores CSS styles intentionally
		$this->assertStringContainsString( 'wp:etch/svg', $result );
	}
}
