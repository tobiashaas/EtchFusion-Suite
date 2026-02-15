<?php
/**
 * Unit tests for Notes Element Converter.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Notes;
use WP_UnitTestCase;

class NotesConverterTest extends WP_UnitTestCase {

	/** @var array Style map (unused for notes but required by base) */
	private $style_map = array();

	public function test_convert_notes_with_html_content(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => '<p>Test note</p>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringNotContainsString( '</p>', $result );
		$this->assertStringContainsString( '<!-- MIGRATION NOTE: Test note -->', $result );
	}

	public function test_convert_notes_with_plain_text(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => 'Plain text note',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'Plain text note', $result );
		$this->assertStringContainsString( '<!-- MIGRATION NOTE:', $result );
		$this->assertStringContainsString( ' -->', $result );
	}

	public function test_convert_empty_notes(): void {
		$converter = new EFS_Element_Notes( $this->style_map );

		$element_empty = array(
			'name'     => 'fr-notes',
			'settings' => array( 'notesContent' => '' ),
		);
		$result_empty = $converter->convert( $element_empty, array(), array() );
		$this->assertSame( '<!-- MIGRATION NOTE: (empty) -->', $result_empty );

		$element_missing = array(
			'name'     => 'fr-notes',
			'settings' => array(),
		);
		$result_missing = $converter->convert( $element_missing, array(), array() );
		$this->assertSame( '<!-- MIGRATION NOTE: (empty) -->', $result_missing );
	}

	public function test_convert_notes_with_multiline_content(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => "<p>Line one</p>\n<p>Line two</p>",
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'MIGRATION NOTE:', $result );
		// wp_strip_all_tags leaves newlines; trim() keeps single-line for comment.
		$this->assertStringStartsWith( '<!--', $result );
		$this->assertStringEndsWith( '-->', $result );
	}

	public function test_html_stripping_validation(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => '<div class="x" data-id="1"><span><strong>Only</strong> <em>text</em> remains</span></div>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringNotContainsString( '<div', $result );
		$this->assertStringNotContainsString( '<span', $result );
		$this->assertStringNotContainsString( '<strong', $result );
		$this->assertStringNotContainsString( '<em', $result );
		$this->assertStringContainsString( 'Only text remains', $result );
	}

	public function test_comment_format_verification(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array( 'notesContent' => 'Format check' ),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringStartsWith( '<!--', $result );
		$this->assertStringEndsWith( '-->', $result );
		$this->assertStringContainsString( 'MIGRATION NOTE:', $result );
		$this->assertMatchesRegularExpression( '/^<!-- MIGRATION NOTE: .+ -->$/', $result );
	}

	public function test_special_characters_in_notes(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => 'Note with "quotes" and \'apostrophes\' and <b>tags</b>',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'quotes', $result );
		$this->assertStringContainsString( 'apostrophes', $result );
		$this->assertStringNotContainsString( '<b>', $result );
		$this->assertStringStartsWith( '<!--', $result );
		$this->assertStringEndsWith( '-->', $result );
	}

	public function test_comment_does_not_contain_double_hyphen_sequence(): void {
		$converter = new EFS_Element_Notes( $this->style_map );
		$element   = array(
			'name'     => 'fr-notes',
			'settings' => array(
				'notesContent' => 'Content with -- double hyphen -- inside',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Converter replaces "--" to avoid breaking HTML comment.
		$this->assertStringNotContainsString( '-- double hyphen --', $result );
		$this->assertStringStartsWith( '<!--', $result );
		$this->assertStringEndsWith( '-->', $result );
	}
}
