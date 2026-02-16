<?php
/**
 * Unit tests for Code Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Code;
use WP_UnitTestCase;

class CodeConverterTest extends WP_UnitTestCase {

	/** @var array Style map (unused for code block but required by base) */
	private $style_map = array();

	public function test_convert_code_with_execute_true(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code'        => '<div>Hello</div>',
				'executeCode' => true,
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"unsafe":true', $result );
	}

	public function test_convert_code_with_execute_false(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code'        => '<span>Safe</span>',
				'executeCode' => false,
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"unsafe":false', $result );
	}

	public function test_code_with_php_tags(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<?php echo "hi"; ?>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( 'content', $result );
	}

	public function test_code_content_extraction(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<p>Extracted content</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'Extracted content', $result );
	}

	public function test_code_with_css_and_js(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code'           => '<div class="box">HTML</div>',
				'cssCode'        => '.box { color: red; }',
				'javascriptCode' => 'console.log("ok");',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '<style>', $result );
		$this->assertStringContainsString( '.box { color: red; }', $result );
		$this->assertStringContainsString( '<script>', $result );
		$this->assertStringContainsString( 'console.log(', $result );
		$this->assertStringContainsString( 'HTML', $result );
	}

	public function test_raw_html_block_structure(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array( 'code' => 'x' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '<!-- wp:etch/raw-html ', $result );
		$this->assertStringContainsString( '<!-- /wp:etch/raw-html -->', $result );
		$this->assertMatchesRegularExpression( '/"content":"x"/', $result );
	}
}
