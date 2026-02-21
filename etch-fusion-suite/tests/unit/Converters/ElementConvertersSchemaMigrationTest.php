<?php
/**
 * Unit tests for converter schema migration coverage.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Button_Converter;
use Bricks2Etch\Converters\Elements\EFS_Element_Heading;
use Bricks2Etch\Converters\Elements\EFS_Element_Image;
use Bricks2Etch\Converters\Elements\EFS_Element_Paragraph;
use Bricks2Etch\Converters\Elements\EFS_Icon_Converter;
use WP_UnitTestCase;

class ElementConvertersSchemaMigrationTest extends WP_UnitTestCase {

	/** @var array<string,array<string,string>> */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'global-1' => array(
				'id'       => 'mapped-style-1',
				'selector' => '.mapped-class',
			),
		);
	}

	public function test_heading_converter_outputs_etch_element_with_etch_text(): void {
		$converter = new EFS_Element_Heading( $this->style_map );
		$element   = array(
			'name'     => 'heading',
			'label'    => 'Hero Heading',
			'settings' => array(
				'tag'  => 'h3',
				'text' => 'Heading Content',
			),
		);

		$result = $converter->convert( $element, array() );
		$this->assertStringContainsString( '<!-- wp:etch/element ', $result );
		$this->assertStringContainsString( '<!-- wp:etch/text ', $result );
		$this->assertStringContainsString( '"tag":"h3"', $result );
		$this->assertStringContainsString( 'Heading Content', $result );
		$this->assertStringNotContainsString( 'wp:heading', $result );
	}

	public function test_paragraph_converter_outputs_etch_element_with_etch_text(): void {
		$converter = new EFS_Element_Paragraph( $this->style_map );
		$element   = array(
			'name'     => 'text-basic',
			'label'    => 'Body Text',
			'settings' => array(
				'tag'  => 'p',
				'text' => 'Paragraph Content',
			),
		);

		$result = $converter->convert( $element, array() );
		$this->assertStringContainsString( '<!-- wp:etch/element ', $result );
		$this->assertStringContainsString( '<!-- wp:etch/text ', $result );
		$this->assertStringContainsString( '"tag":"p"', $result );
		$this->assertStringContainsString( 'Paragraph Content', $result );
		$this->assertStringNotContainsString( 'wp:paragraph', $result );
	}

	public function test_image_converter_outputs_figure_and_img_inner_blocks(): void {
		$converter = new EFS_Element_Image( $this->style_map );
		$element   = array(
			'name'     => 'image',
			'label'    => 'Hero Image',
			'settings' => array(
				'image' => array(
					'id'  => 42,
					'url' => 'https://example.com/image.jpg',
					'alt' => 'Alt text',
				),
			),
		);

		$result = $converter->convert( $element, array() );
		$this->assertStringContainsString( '"tag":"figure"', $result );
		$this->assertStringContainsString( '"tag":"img"', $result );
		$this->assertStringContainsString( 'https://example.com/image.jpg', $result );
		$this->assertStringNotContainsString( 'wp:image', $result );
		$this->assertStringNotContainsString( 'etchData', $result );
	}

	public function test_button_converter_outputs_anchor_element_and_text_block(): void {
		$converter = new EFS_Button_Converter( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'label'    => 'CTA',
			'settings' => array(
				'text' => 'Read more',
				'link' => array(
					'url'    => 'https://example.com',
					'newTab' => true,
				),
			),
		);

		$result = $converter->convert( $element, array() );
		$this->assertStringContainsString( '"tag":"a"', $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
		$this->assertStringContainsString( '"target":"_blank"', $result );
		$this->assertStringContainsString( '<!-- wp:etch/text ', $result );
		$this->assertStringContainsString( 'Read more', $result );
		$this->assertStringNotContainsString( 'nestedData', $result );
	}

	public function test_icon_converter_outputs_icon_element_without_placeholder_markup(): void {
		$converter = new EFS_Icon_Converter( $this->style_map );
		$element   = array(
			'name'     => 'icon',
			'label'    => 'Star Icon',
			'settings' => array(
				'icon' => array(
					'library' => 'fontawesome',
					'value'   => 'fa-solid fa-star',
				),
			),
		);

		$result = $converter->convert( $element, array() );
		// Font-icon based Bricks icons are intentionally converted to empty SVG
		// placeholder blocks (no icon-font specific attributes carried over).
		$this->assertStringContainsString( 'wp:etch/svg', $result );
		$this->assertStringContainsString( '"tag":"svg"', $result );
		$this->assertStringNotContainsString( '[Icon:', $result );
		$this->assertStringNotContainsString( 'etchData', $result );
	}
}
