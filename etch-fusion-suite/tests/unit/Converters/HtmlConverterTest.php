<?php
/**
 * Unit tests for HTML Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Html;
use WP_UnitTestCase;

class HtmlConverterTest extends WP_UnitTestCase {

	/** @var array Style map (unused for html block but required by base) */
	private $style_map = array();

	public function test_convert_html_basic(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array(
				'html' => '<p>Hello World</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( 'Hello World', $result );
		$this->assertStringNotContainsString( 'etchData', $result );
	}

	public function test_convert_html_with_special_characters(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array(
				'html' => '<div class="foo" data-x="a\"b">[bracket] &amp; "quotes"</div>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"content":', $result );
		$this->assertMatchesRegularExpression( '/"unsafe":\s*false/', $result );
	}

	public function test_convert_html_empty_content(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"content":""', $result );
	}

	public function test_html_unsafe_attribute(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array( 'html' => '<span>test</span>' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"unsafe":false', $result );
	}

	public function test_raw_html_block_structure(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array( 'html' => 'x' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '<!-- wp:etch/raw-html ', $result );
		$this->assertStringContainsString( '<!-- /wp:etch/raw-html -->', $result );
		$this->assertMatchesRegularExpression( '/"content":"x"/', $result );
	}

	public function test_html_content_extraction(): void {
		$converter = new EFS_Element_Html( $this->style_map );
		$element   = array(
			'name'     => 'html',
			'settings' => array(
				'html' => '<p>Extracted content</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'Extracted content', $result );
	}
}
