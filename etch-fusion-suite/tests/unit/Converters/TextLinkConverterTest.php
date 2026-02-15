<?php
/**
 * Unit tests for Text-Link Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_TextLink;
use WP_UnitTestCase;

class TextLinkConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-text-link-1' => array(
				'id'       => 'etch-text-link-style',
				'selector' => '.text-link-class',
			),
		);
	}

	public function test_convert_basic_text_link(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Click here',
				'link' => array( 'url' => 'https://example.com', 'newTab' => false ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"tag":"a"', $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertStringContainsString( 'Click here', $result );
	}

	public function test_link_url_extraction_from_array(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Link',
				'link' => array( 'url' => 'https://example.com', 'newTab' => false ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
	}

	public function test_link_url_extraction_from_string(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Link',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
	}

	public function test_new_tab_attributes(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Open',
				'link' => array( 'url' => 'https://example.com', 'newTab' => true ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"target":"_blank"', $result );
		$this->assertStringContainsString( '"rel":"noopener noreferrer"', $result );
	}

	public function test_no_new_tab_attributes(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Stay',
				'link' => array( 'url' => 'https://example.com', 'newTab' => false ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( '"target":', $result );
		$this->assertStringNotContainsString( '"rel":', $result );
	}

	public function test_css_class_inheritance(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text'              => 'Styled',
				'link'              => 'https://example.com',
				'_cssGlobalClasses' => array( 'bricks-text-link-1' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"class":"text-link-class"', $result );
	}

	public function test_flat_schema_structure(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'Link',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( 'etchData', $result );
		$this->assertStringContainsString( '"metadata":{"name":"Text Link"}', $result );
		$this->assertStringContainsString( '"tag":"a"', $result );
		$this->assertStringContainsString( '"attributes":{', $result );
		$this->assertStringContainsString( '"styles"', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
	}

	public function test_empty_text_content(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertStringContainsString( '"content":""', $result );
	}

	public function test_default_url_fallback(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => 'No URL',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"#"', $result );
	}

	public function test_text_content_sanitization(): void {
		$converter = new EFS_Element_TextLink( $this->style_map );
		$element   = array(
			'name'     => 'text-link',
			'settings' => array(
				'text' => '<script>alert(1)</script> & "quotes"',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( 'alert(1)', $result );
	}
}
