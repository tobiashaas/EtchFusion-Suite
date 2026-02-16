<?php
/**
 * Unit tests for Shortcode Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Shortcode;
use WP_UnitTestCase;

class ShortcodeConverterTest extends WP_UnitTestCase {

	/** @var array Style map (unused for shortcode block but required by base) */
	private $style_map = array();

	public function test_convert_shortcode_basic(): void {
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array(
				'shortcode' => '[gallery]',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '[gallery]', $result );
		$this->assertStringNotContainsString( 'etchData', $result );
	}

	public function test_convert_shortcode_with_attributes(): void {
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array(
				'shortcode' => '[gallery ids="1,2,3"]',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( 'ids=', $result );
		$this->assertStringContainsString( '1,2,3', $result );
	}

	public function test_convert_shortcode_empty_content(): void {
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"content":""', $result );
	}

	public function test_shortcode_unsafe_attribute(): void {
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array( 'shortcode' => '[gallery]' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"unsafe":false', $result );
	}

	public function test_raw_html_block_structure(): void {
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array( 'shortcode' => '[contact-form-7 id="123"]' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '<!-- wp:etch/raw-html ', $result );
		$this->assertStringContainsString( '<!-- /wp:etch/raw-html -->', $result );
		$this->assertMatchesRegularExpression( '/"content":\s*"[^"]*contact-form-7[^"]*"/', $result );
	}

	public function test_shortcode_format_preserved(): void {
		$shortcode = '[gallery ids="1,2,3" orderby="rand"]';
		$converter = new EFS_Element_Shortcode( $this->style_map );
		$element   = array(
			'name'     => 'shortcode',
			'settings' => array( 'shortcode' => $shortcode ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'gallery', $result );
		$this->assertStringContainsString( '1,2,3', $result );
		$this->assertStringContainsString( 'rand', $result );
	}
}
