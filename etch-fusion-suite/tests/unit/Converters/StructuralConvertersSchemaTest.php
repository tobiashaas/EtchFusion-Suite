<?php
/**
 * Unit tests for structural converters against Etch v1.1+ flat schema.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Container;
use Bricks2Etch\Converters\Elements\EFS_Element_Div;
use Bricks2Etch\Converters\Elements\EFS_Element_Section;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;
use WP_UnitTestCase;

class StructuralConvertersSchemaTest extends WP_UnitTestCase {

	/**
	 * @var array<string, array<string, string>>
	 */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();

		$this->style_map = array(
			'bricks-global-1' => array(
				'id'       => 'mapped-style-1',
				'selector' => '.mapped-class',
			),
		);

		update_option( 'efs_style_map', $this->style_map );
	}

	protected function tearDown(): void {
		delete_option( 'efs_style_map' );
		parent::tearDown();
	}

	public function test_container_converter_uses_flat_attrs_and_comment_only_block(): void {
		$converter = new EFS_Element_Container( $this->style_map );
		$element   = array(
			'name'     => 'container',
			'label'    => 'Main Container',
			'settings' => array(
				'tag'               => 'nav',
				'_cssGlobalClasses' => array( 'bricks-global-1' ),
			),
		);

		$result = $converter->convert( $element, array( '<!-- wp:paragraph --><p>Child</p><!-- /wp:paragraph -->' ) );

		$attrs = $this->extract_etch_element_attrs( $result );

		$this->assertSame( 'nav', $attrs['tag'] );
		$this->assertSame( 'Main Container', $attrs['metadata']['name'] );
		$this->assertSame( 'container', $attrs['attributes']['data-etch-element'] );
		$this->assertSame( 'mapped-class', $attrs['attributes']['class'] );
		$this->assertContains( 'etch-container-style', $attrs['styles'] );
		$this->assertContains( 'mapped-style-1', $attrs['styles'] );

		$this->assertStringNotContainsString( 'etchData', $result );
		$this->assertStringNotContainsString( 'wp:group', $result );
		$this->assertStringNotContainsString( 'wp-block-group', $result );
	}

	public function test_section_converter_uses_flat_attrs_and_section_tag(): void {
		$converter = new EFS_Element_Section( $this->style_map );
		$element   = array(
			'name'     => 'section',
			'label'    => 'Hero Section',
			'settings' => array(
				'tag' => 'section',
			),
		);

		$result = $converter->convert( $element, array() );
		$attrs  = $this->extract_etch_element_attrs( $result );

		$this->assertSame( 'section', $attrs['tag'] );
		$this->assertSame( 'Hero Section', $attrs['metadata']['name'] );
		$this->assertSame( 'section', $attrs['attributes']['data-etch-element'] );
		$this->assertContains( 'etch-section-style', $attrs['styles'] );
		$this->assertArrayNotHasKey( 'etchData', $attrs['metadata'] );

		$this->assertStringNotContainsString( 'wp:group', $result );
		$this->assertStringNotContainsString( 'wp-block-group', $result );
	}

	public function test_div_converter_does_not_emit_deprecated_flex_div_marker(): void {
		$converter = new EFS_Element_Div( $this->style_map );
		$element   = array(
			'name'     => 'div',
			'label'    => 'Plain Div',
			'settings' => array(
				'tag'               => 'div',
				'_cssGlobalClasses' => array( 'bricks-global-1' ),
			),
		);

		$result = $converter->convert( $element, array() );
		$attrs  = $this->extract_etch_element_attrs( $result );

		$this->assertSame( 'div', $attrs['tag'] );
		$this->assertSame( 'Plain Div', $attrs['metadata']['name'] );
		$this->assertSame( 'mapped-class', $attrs['attributes']['class'] );
		$this->assertArrayNotHasKey( 'data-etch-element', $attrs['attributes'] );
		$this->assertNotContains( 'etch-flex-div-style', $attrs['styles'] );

		$this->assertStringNotContainsString( 'etchData', $result );
		$this->assertStringNotContainsString( 'wp:group', $result );
	}

	public function test_generator_outputs_flat_v11_etch_schema_for_minimal_structural_chain(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'section-1',
				'name'     => 'section',
				'parent'   => 0,
				'label'    => 'Schema Section',
				'children' => array( 'container-1' ),
				'settings' => array(
					'_cssGlobalClasses' => array( 'bricks-global-1' ),
				),
			),
			array(
				'id'       => 'container-1',
				'name'     => 'container',
				'parent'   => 'section-1',
				'label'    => 'Schema Container',
				'children' => array( 'div-1' ),
				'settings' => array(
					'tag' => 'div',
				),
			),
			array(
				'id'       => 'div-1',
				'name'     => 'div',
				'parent'   => 'container-1',
				'label'    => 'Schema Div',
				'children' => array(),
				'settings' => array(
					'_cssGlobalClasses' => array( 'bricks-global-1' ),
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( '<!-- wp:etch/element ', $result );
		$this->assertStringNotContainsString( 'wp:group', $result );
		$this->assertStringNotContainsString( 'etch-flex-div-style', $result );

		$all_attrs = $this->extract_all_etch_element_attrs( $result );
		$this->assertCount( 3, $all_attrs );

		$tags = array_column( $all_attrs, 'tag' );
		$this->assertSame( array( 'section', 'div', 'div' ), $tags );

		foreach ( $all_attrs as $attrs ) {
			$this->assertArrayHasKey( 'metadata', $attrs );
			$this->assertArrayHasKey( 'name', $attrs['metadata'] );
			$this->assertArrayHasKey( 'tag', $attrs );
			$this->assertArrayHasKey( 'attributes', $attrs );
			$this->assertIsArray( $attrs['attributes'] );
			$this->assertArrayHasKey( 'styles', $attrs );
			$this->assertIsArray( $attrs['styles'] );
		}
	}

	/**
	 * Extract attrs from the opening wp:etch/element comment.
	 *
	 * @param string $block_html Gutenberg block HTML.
	 * @return array<string, mixed>
	 */
	private function extract_etch_element_attrs( string $block_html ): array {
		$matched = preg_match( '/<!-- wp:etch\/element (\{.*?\}) -->/s', $block_html, $matches );
		$this->assertSame( 1, $matched, 'Expected an opening wp:etch/element block comment.' );

		$attrs = json_decode( $matches[1], true );
		$this->assertIsArray( $attrs );

		return $attrs;
	}

	/**
	 * Extract attrs from all opening wp:etch/element comments.
	 *
	 * @param string $block_html Gutenberg block HTML.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_all_etch_element_attrs( string $block_html ): array {
		$matched = preg_match_all( '/<!-- wp:etch\/element (\{.*?\}) -->/s', $block_html, $matches );
		$this->assertGreaterThan( 0, $matched, 'Expected at least one opening wp:etch/element block comment.' );

		$attrs_list = array();
		foreach ( $matches[1] as $attrs_json ) {
			$attrs = json_decode( $attrs_json, true );
			$this->assertIsArray( $attrs );
			$attrs_list[] = $attrs;
		}

		return $attrs_list;
	}
}
