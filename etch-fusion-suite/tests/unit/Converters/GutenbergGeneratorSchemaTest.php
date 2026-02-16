<?php
/**
 * Unit tests for Gutenberg generator schema output.
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;
use WP_UnitTestCase;

class GutenbergGeneratorSchemaTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		update_option(
			'efs_style_map',
			array(
				'global-class-1' => array(
					'id'       => 'etch-style-1',
					'selector' => '.mapped-class',
				),
			)
		);
	}

	protected function tearDown(): void {
		delete_option( 'efs_style_map' );
		parent::tearDown();
	}

	public function test_generate_gutenberg_blocks_uses_flat_etch_element_schema_for_structural_elements(): void {
		$error_handler = new EFS_Error_Handler();
		$dynamic_data  = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator     = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'root-section',
				'name'     => 'section',
				'parent'   => 0,
				'label'    => 'Hero Section',
				'children' => array( 'inner-container' ),
				'settings' => array(
					'_cssGlobalClasses' => array( 'global-class-1' ),
				),
			),
			array(
				'id'       => 'inner-container',
				'name'     => 'container',
				'parent'   => 'root-section',
				'label'    => 'Hero Container',
				'children' => array( 'inner-div' ),
				'settings' => array(
					'tag' => 'div',
				),
			),
			array(
				'id'       => 'inner-div',
				'name'     => 'div',
				'parent'   => 'inner-container',
				'label'    => 'Hero Wrapper',
				'children' => array(),
				'settings' => array(),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"tag":"section"', $result );
		$this->assertStringContainsString( '"tag":"div"', $result );
		$this->assertStringContainsString( '"attributes":{"data-etch-element":"section"', $result );
		$this->assertStringContainsString( '"styles":["etch-section-style"', $result );

		$this->assertStringNotContainsString( '"etchData"', $result );
		$this->assertStringNotContainsString( 'wp:group', $result );
		$this->assertStringNotContainsString( 'wp-block-group', $result );
	}

	public function test_generate_gutenberg_blocks_fallback_iframe_uses_flat_etch_schema_without_wrappers(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'iframe-root',
				'name'     => 'iframe',
				'etch_type' => 'iframe',
				'parent'   => 0,
				'label'    => 'Embed Frame',
				'children' => array(),
				'settings' => array(),
				'etch_data' => array(
					'data-etch-element' => 'iframe',
					'src'               => 'https://example.com/embed',
					'class'             => 'iframe-custom',
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"tag":"iframe"', $result );
		$this->assertStringContainsString( '"attributes":{"data-etch-element":"iframe","src":"https://example.com/embed","class":"iframe-custom"}', $result );
		$this->assertStringContainsString( '"styles":["etch-iframe-style"]', $result );

		$this->assertStringNotContainsString( '"etchData"', $result );
		$this->assertStringNotContainsString( 'wp:group', $result );
		$this->assertStringNotContainsString( 'wp-block-group', $result );
	}
}
