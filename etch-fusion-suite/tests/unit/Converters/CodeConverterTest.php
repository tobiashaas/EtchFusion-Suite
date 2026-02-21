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

	// ─── Pure HTML tests ─────────────────────────────────────────────

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
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"unsafe":true', $result );
		$this->assertStringContainsString( 'Hello', $result );
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
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '"unsafe":false', $result );
	}

	public function test_raw_html_block_structure(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array( 'code' => '<p>text</p>' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '<!-- wp:etch/raw-html ', $result );
		$this->assertStringContainsString( '<!-- /wp:etch/raw-html -->', $result );
		$this->assertStringContainsString( '<p>text</p>', $result );
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
		$this->assertStringContainsString( 'Extracted content', $result );
	}

	// ─── PHP detection ───────────────────────────────────────────────

	public function test_php_code_generates_warning_block(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'label'    => 'My PHP Block',
			'settings' => array(
				'code' => '<?php echo "hi"; ?>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '[EFS Migration] PHP code detected', $result );
		$this->assertStringContainsString( 'manual migration required', $result );
		$this->assertStringContainsString( '"unsafe":false', $result );
		$this->assertStringContainsString( 'PHP', $result );
	}

	public function test_php_short_open_tag_detected(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<? echo "hi"; ?>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '[EFS Migration] PHP code detected', $result );
	}

	public function test_php_code_is_html_escaped_in_warning(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<?php echo "<script>alert(1)</script>"; ?>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// The PHP code must be escaped — no raw <script> tag in output.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	// ─── JavaScript: native field ────────────────────────────────────

	public function test_javascript_field_produces_etch_element_with_base64(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'javascriptCode' => 'console.log("ok");',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test assertion.
		$expected_b64 = base64_encode( 'console.log("ok");' );
		$this->assertStringContainsString( $expected_b64, $result );
		$this->assertStringContainsString( '"script"', $result );
	}

	// ─── JavaScript: extracted from <script> in HTML field ───────────

	public function test_script_tag_in_html_field_extracted_to_etch_element(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<script>document.querySelector(".btn").addEventListener("click", fn);</script>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test assertion.
		$expected_b64 = base64_encode( 'document.querySelector(".btn").addEventListener("click", fn);' );
		$this->assertStringContainsString( $expected_b64, $result );
		// No raw <script> tag should remain.
		$this->assertStringNotContainsString( '<script>', $result );
	}

	public function test_script_type_module_extracted(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<script type="module">import { foo } from "./bar.js";</script>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test assertion.
		$expected_b64 = base64_encode( 'import { foo } from "./bar.js";' );
		$this->assertStringContainsString( $expected_b64, $result );
	}

	public function test_js_field_and_script_tag_combined(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'javascriptCode' => 'var a = 1;',
				'code'           => '<script>var b = 2;</script>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// Both JS fragments should be combined in base64.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test assertion.
		$expected_b64 = base64_encode( "var a = 1;\n\nvar b = 2;" );
		$this->assertStringContainsString( $expected_b64, $result );
	}

	// ─── CSS: native field ───────────────────────────────────────────

	public function test_css_field_produces_raw_html_with_style_tag(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'cssCode' => '.box { color: red; }',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'wp:etch/raw-html', $result );
		$this->assertStringContainsString( '<style>.box { color: red; }</style>', $result );
		$this->assertStringContainsString( '(CSS)', $result );
	}

	// ─── CSS: extracted from <style> in HTML field ───────────────────

	public function test_style_tag_in_html_field_extracted(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<style>.red { color: red; }</style><div class="red">Hi</div>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// CSS block.
		$this->assertStringContainsString( '<style>.red { color: red; }</style>', $result );
		$this->assertStringContainsString( '(CSS)', $result );
		// HTML block with remaining content (quotes are JSON-escaped in the block comment).
		$this->assertStringContainsString( '<div class=\"red\">Hi</div>', $result );
	}

	public function test_css_field_and_style_tag_combined(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'cssCode' => '.a { margin: 0; }',
				'code'    => '<style>.b { padding: 0; }</style>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '.a { margin: 0; }', $result );
		$this->assertStringContainsString( '.b { padding: 0; }', $result );
	}

	// ─── Mixed content: JS + CSS + HTML ──────────────────────────────

	public function test_code_with_all_three_types(): void {
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
		// JS → etch/element with script.code base64.
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"script"', $result );
		// CSS → etch/raw-html with <style>.
		$this->assertStringContainsString( '<style>.box { color: red; }</style>', $result );
		// HTML → etch/raw-html.
		$this->assertStringContainsString( 'HTML', $result );
	}

	// ─── Edge cases ──────────────────────────────────────────────────

	public function test_empty_settings_returns_empty_string(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertSame( '', $result );
	}

	public function test_html_comments_only_returns_empty(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<!-- marker comment -->',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertSame( '', $result );
	}

	public function test_whitespace_only_html_after_extraction_returns_empty(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<script>var x = 1;</script>   ',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// Only a JS block, no empty HTML block.
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringNotContainsString( '"content":"   "', $result );
	}

	public function test_multiple_script_tags_combined(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'code' => '<script>var a = 1;</script><script>var b = 2;</script>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test assertion.
		$expected_b64 = base64_encode( "var a = 1;\n\nvar b = 2;" );
		$this->assertStringContainsString( $expected_b64, $result );
	}

	public function test_root_css_variables_output_as_raw_html(): void {
		$converter = new EFS_Element_Code( $this->style_map );
		$element   = array(
			'name'     => 'code',
			'settings' => array(
				'cssCode' => ':root { --primary: #333; }',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		// "--" is replaced by "\u002d\u002d" to keep Gutenberg block comments parse-safe.
		$this->assertStringContainsString( ':root { \u002d\u002dprimary: #333; }', $result );
	}
}
