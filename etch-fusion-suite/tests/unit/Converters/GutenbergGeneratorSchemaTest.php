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

	/**
	 * Test that colon-parameter dynamic data tags are converted.
	 */
	public function test_dynamic_data_colon_param_tags_are_converted(): void {
		$error_handler = new EFS_Error_Handler();
		$dynamic_data  = new EFS_Dynamic_Data_Converter( $error_handler );

		// Basic colon param conversion.
		$result = $dynamic_data->convert_content( '{post_title:10}' );
		$this->assertSame( '{this.title(10)}', $result );

		// Known mapping with param.
		$result = $dynamic_data->convert_content( '{post_date:Y-m-d}' );
		$this->assertSame( '{this.date(Y-m-d)}', $result );

		// Deferred tags pass through unchanged.
		$result = $dynamic_data->convert_content( '{woo_product_price:123}' );
		$this->assertSame( '{woo_product_price:123}', $result );

		// Dedicated converter tags (acf, meta, query) pass through for later handling.
		$result = $dynamic_data->convert_content( '{acf:my_field}' );
		$this->assertSame( '{this.acf.my_field}', $result );
	}

	/**
	 * Test that hidden elements produce no output.
	 */
	public function test_hidden_elements_are_skipped(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'visible-heading',
				'name'     => 'heading',
				'parent'   => 0,
				'label'    => 'Visible',
				'children' => array(),
				'settings' => array(
					'text' => 'Hello',
					'tag'  => 'h2',
				),
			),
			array(
				'id'       => 'hidden-heading',
				'name'     => 'heading',
				'parent'   => 0,
				'label'    => 'Hidden',
				'children' => array(),
				'settings' => array(
					'text'    => 'Secret',
					'tag'     => 'h3',
					'_hidden' => true,
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( 'Hello', $result );
		$this->assertStringNotContainsString( 'Secret', $result );
	}

	/**
	 * Test that loop elements get wrapped in etch/loop block.
	 */
	public function test_loop_elements_are_wrapped(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'loop-container',
				'name'     => 'container',
				'parent'   => 0,
				'label'    => 'Posts Loop',
				'children' => array(),
				'settings' => array(
					'hasLoop' => true,
					'query'   => array(
						'objectType'     => 'post',
						'post_type'      => 'post',
						'posts_per_page' => 6,
						'orderby'        => 'date',
					),
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( 'wp:etch/loop', $result );
		$this->assertStringContainsString( '"objectType":"post"', $result );
		$this->assertStringContainsString( '"postsPerPage":6', $result );
		$this->assertStringContainsString( '/wp:etch/loop', $result );

		// The element block should be inside the loop.
		$this->assertStringContainsString( 'wp:etch/element', $result );
	}

	/**
	 * Test that condition elements get wrapped in etch/condition block.
	 */
	public function test_condition_elements_are_wrapped(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'cond-heading',
				'name'     => 'heading',
				'parent'   => 0,
				'label'    => 'Logged In Only',
				'children' => array(),
				'settings' => array(
					'text'        => 'Members Area',
					'tag'         => 'h2',
					'_conditions' => array(
						array(
							'type'    => 'user_logged_in',
							'compare' => '',
						),
					),
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		$this->assertStringContainsString( 'wp:etch/condition', $result );
		$this->assertStringContainsString( '"leftHand":"user.loggedIn"', $result );
		$this->assertStringContainsString( '"operator":"isTruthy"', $result );
		$this->assertStringContainsString( '/wp:etch/condition', $result );
	}

	/**
	 * Test that loop + condition wrapping order is correct (condition outside loop).
	 */
	public function test_condition_wraps_outside_loop(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'loop-cond',
				'name'     => 'container',
				'parent'   => 0,
				'label'    => 'Conditional Loop',
				'children' => array(),
				'settings' => array(
					'hasLoop'     => true,
					'query'       => array(
						'objectType' => 'post',
						'post_type'  => 'post',
					),
					'_conditions' => array(
						array(
							'type' => 'user_logged_in',
						),
					),
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		// Condition should be the outermost wrapper.
		$cond_open = strpos( $result, 'wp:etch/condition' );
		$loop_open = strpos( $result, 'wp:etch/loop' );
		$this->assertNotFalse( $cond_open );
		$this->assertNotFalse( $loop_open );
		$this->assertLessThan( $loop_open, $cond_open, 'Condition block should open before loop block' );
	}

	/**
	 * Test that WooCommerce conditions are skipped.
	 */
	public function test_woo_conditions_are_skipped(): void {
		$error_handler  = new EFS_Error_Handler();
		$dynamic_data   = new EFS_Dynamic_Data_Converter( $error_handler );
		$content_parser = new EFS_Content_Parser( $error_handler );
		$generator      = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$elements = array(
			array(
				'id'       => 'woo-cond',
				'name'     => 'heading',
				'parent'   => 0,
				'label'    => 'Woo Heading',
				'children' => array(),
				'settings' => array(
					'text'        => 'Shop',
					'tag'         => 'h2',
					'_conditions' => array(
						array(
							'type' => 'woo_is_cart',
						),
					),
				),
			),
		);

		$result = $generator->generate_gutenberg_blocks( $elements );

		// WooCommerce condition produces no AST, so no condition wrapper.
		$this->assertStringNotContainsString( 'wp:etch/condition', $result );
		// But the element itself should still render.
		$this->assertStringContainsString( 'Shop', $result );
	}

	/**
	 * Test that new mapping entries work.
	 */
	public function test_extended_mapping_entries(): void {
		$error_handler = new EFS_Error_Handler();
		$dynamic_data  = new EFS_Dynamic_Data_Converter( $error_handler );

		$this->assertSame( '{this.author.name}', $dynamic_data->convert_content( '{author_name}' ) );
		$this->assertSame( '{site.date}', $dynamic_data->convert_content( '{current_date}' ) );
		$this->assertSame( '{this.term.name}', $dynamic_data->convert_content( '{term_name}' ) );
		$this->assertSame( '{this.terms.category}', $dynamic_data->convert_content( '{post_terms_category}' ) );
	}
}
