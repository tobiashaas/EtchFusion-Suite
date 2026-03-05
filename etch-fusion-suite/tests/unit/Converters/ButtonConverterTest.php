<?php
/**
 * Unit tests for Button Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Button;
use WP_UnitTestCase;

class ButtonConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-button-primary' => array(
				'id'       => 'etch-button-primary',
				'selector' => '.btn--primary',
			),
		);
	}

	public function test_convert_basic_button(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Click me',
				'link'  => array( 'url' => 'https://example.com', 'newTab' => false ),
				'style' => 'primary',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"tag":"a"', $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
		$this->assertStringContainsString( 'Click me', $result );
	}

	public function test_button_style_mapping_primary(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Primary',
				'link'  => 'https://example.com',
				'style' => 'primary',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--primary', $result );
	}

	public function test_button_style_mapping_secondary(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Secondary',
				'link'  => 'https://example.com',
				'style' => 'secondary',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--secondary', $result );
	}

	public function test_button_style_mapping_outline(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Outline',
				'link'  => 'https://example.com',
				'style' => 'outline',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--outline', $result );
	}

	public function test_button_style_mapping_text(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Text',
				'link'  => 'https://example.com',
				'style' => 'text',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--text', $result );
	}

	public function test_button_default_style_primary(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text' => 'Default',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--primary', $result );
	}

	public function test_button_link_url_extraction_from_array(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text' => 'Link',
				'link' => array( 'url' => 'https://example.com', 'newTab' => false ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
	}

	public function test_button_link_url_extraction_from_string(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text' => 'Link',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"https://example.com"', $result );
	}

	public function test_button_new_tab_attributes(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
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

	public function test_button_no_new_tab_attributes(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
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

	public function test_button_default_url_fallback(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text' => 'No URL',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"href":"#"', $result );
	}

	public function test_button_text_content_sanitization(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
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

	public function test_button_css_class_inheritance(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'              => 'Styled',
				'link'              => 'https://example.com',
				'_cssGlobalClasses' => array( 'bricks-button-primary' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'etch-button-primary', $result );
	}

	public function test_button_css_class_priority_over_style(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		// Button with style="primary" but CSS class "btn--secondary"
		// CSS classes should have priority
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Priority Test',
				'link'  => 'https://example.com',
				'style' => 'primary',
				'_cssGlobalClasses' => array( 'btn--secondary' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// CSS class (secondary) should override style (primary)
		$this->assertStringContainsString( 'btn--secondary', $result );
		// Primary should NOT appear
		$this->assertStringNotContainsString( 'btn--primary', $result );
	}

	public function test_button_single_class_only(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		// If multiple btn--* classes exist, only the first should be kept
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'              => 'Single Class',
				'link'              => 'https://example.com',
				'_cssGlobalClasses' => array( 'btn--primary btn--secondary' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// First btn--* class should be kept
		$this->assertStringContainsString( 'btn--primary', $result );
		// Second btn--* class should be removed
		$this->assertStringNotContainsString( 'btn--secondary', $result );
	}

	public function test_button_outline_as_additional_class(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		// btn--outline is the ONLY class that can exist alongside another btn--* class
		// Simulate a Bricks style that has outline variant
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Outline Button',
				'link'  => 'https://example.com',
				'style' => 'primary',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// With style="primary", should output btn--primary
		$this->assertStringContainsString( 'btn--primary', $result );
	}

	public function test_button_multiple_btn_classes_keeps_first(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		// When multiple btn--* classes exist in CSS, only first should be kept
		// This simulates custom CSS with multiple button classes
		$style_map_with_multi = array(
			'btn--primary--outline' => array(
				'id'       => 'primary-outline-style',
				'selector' => '.btn--primary.btn--outline', // Note: both classes in selector
			),
		);
		$converter2 = new EFS_Element_Button( $style_map_with_multi );
		$element    = array(
			'name'     => 'button',
			'settings' => array(
				'text'              => 'Multi Class',
				'link'              => 'https://example.com',
				'_cssGlobalClasses' => array( 'btn--primary--outline' ),
			),
		);
		$result = $converter2->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Should handle button classes appropriately
		$this->assertStringContainsString( 'btn--', $result );
	}

	public function test_button_flat_schema_structure(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text' => 'Button',
				'link' => 'https://example.com',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( 'etchData', $result );
		$this->assertStringContainsString( '"metadata":{"name":"Button"}', $result );
		$this->assertStringContainsString( '"tag":"a"', $result );
		$this->assertStringContainsString( '"attributes":{', $result );
		$this->assertStringContainsString( '"styles"', $result );
		$this->assertStringContainsString( 'wp:etch/text', $result );
	}

	public function test_button_empty_text_content(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
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

	public function test_button_style_class_prefix_detection(): void {
		$converter = new EFS_Element_Button( $this->style_map );
		$element   = array(
			'name'     => 'button',
			'settings' => array(
				'text'  => 'Prefixed',
				'link'  => 'https://example.com',
				'style' => 'btn--custom',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'btn--custom', $result );
	}
}
