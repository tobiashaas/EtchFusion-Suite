<?php
/**
 * Unit tests for Rich Text (Text) Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Text;
use WP_UnitTestCase;

class TextConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-text-1' => array(
				'id'       => 'etch-text-style',
				'selector' => '.content-text',
			),
		);
	}

	public function test_single_paragraph_conversion(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>Simple paragraph</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertStringContainsString( 'Simple paragraph', $result );
		$this->assertMatchesRegularExpression( '/"tag":\s*"p"/', $result );
	}

	public function test_multiple_paragraphs(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>First</p><p>Second</p><p>Third</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'First', $result );
		$this->assertStringContainsString( 'Second', $result );
		$this->assertStringContainsString( 'Third', $result );
		$this->assertSame( 3, substr_count( $result, '<!-- wp:etch/element ' ) );
		$this->assertSame( 3, substr_count( $result, 'wp:etch/text' ) );
	}

	public function test_nested_list_structure(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<ul><li>Item 1</li><li>Item 2</li></ul>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertMatchesRegularExpression( '/"tag":\s*"ul"/', $result );
		$this->assertStringContainsString( '<li>Item 1</li>', $result );
		$this->assertStringContainsString( '<li>Item 2</li>', $result );
	}

	public function test_mixed_content(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>Intro</p><ul><li>Point 1</li></ul><p>Conclusion</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'Intro', $result );
		$this->assertStringContainsString( 'Point 1', $result );
		$this->assertStringContainsString( 'Conclusion', $result );
		$this->assertSame( 3, substr_count( $result, '<!-- wp:etch/element ' ) );
		$this->assertSame( 3, substr_count( $result, 'wp:etch/text' ) );
	}

	public function test_heading_conversion(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<h2>Heading Text</h2>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertMatchesRegularExpression( '/"tag":\s*"h2"/', $result );
		$this->assertStringContainsString( 'Heading Text', $result );
	}

	public function test_css_class_preservation(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'label'    => 'Rich Text',
			'settings' => array(
				'text'              => '<p class="custom-class">Text</p>',
				'_cssGlobalClasses' => array( 'bricks-text-1' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'custom-class', $result );
		$this->assertStringContainsString( 'etch-text-style', $result );
		$this->assertMatchesRegularExpression( '/"class":\s*"[^"]*custom-class[^"]*"/', $result );
	}

	public function test_malformed_html_handling(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>Unclosed paragraph<div>Nested</div>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertIsString( $result );
	}

	public function test_empty_content_handling(): void {
		$converter      = new EFS_Element_Text( $this->style_map );
		$element_empty  = array(
			'name'     => 'text',
			'settings' => array( 'text' => '' ),
		);
		$result_empty = $converter->convert( $element_empty, array(), array() );
		$this->assertSame( '', $result_empty );

		$element_null = array(
			'name'     => 'text',
			'settings' => array(),
		);
		$result_null = $converter->convert( $element_null, array(), array() );
		$this->assertSame( '', $result_null );
	}

	public function test_html_entity_preservation(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>&amp; &lt; &gt; &quot;</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	public function test_nested_html_in_list_items(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<ul><li><strong>Bold</strong> text</li></ul>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '<strong>Bold</strong>', $result );
		$this->assertStringContainsString( 'text', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
	}

	public function test_xss_protection(): void {
		$converter = new EFS_Element_Text( $this->style_map );
		$element   = array(
			'name'     => 'text',
			'settings' => array(
				'text' => '<p>Safe</p><script>alert(1)</script>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}
}
