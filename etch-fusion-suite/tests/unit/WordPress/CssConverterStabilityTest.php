<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Services\EFS_Content_Service;
use WP_UnitTestCase;

class CssConverterStabilityTest extends WP_UnitTestCase {
	/** @var EFS_CSS_Converter */
	private $css_converter;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var int */
	private $post_id = 0;

	protected function setUp(): void {
		parent::setUp();

		$container              = \etch_fusion_suite_container();
		$this->css_converter    = $container->get( 'css_converter' );
		$this->content_service  = $container->get( 'content_service' );

		$this->resetStyleState();

		update_option(
			'bricks_global_classes',
			array(
				array(
					'id'       => 'probe-class-id',
					'name'     => 'probe-card',
					'settings' => array(
						'_padding' => '16px',
					),
				),
			),
			false
		);

		update_option(
			'efs_active_migration',
			array(
				'mode'    => 'headless',
				'options' => array(
					'selected_post_types'    => array( 'page' ),
					'restrict_css_to_used'   => true,
				),
			),
			false
		);

		$this->post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'CSS Probe',
				'post_name'   => 'css-probe',
				'post_status' => 'publish',
			)
		);

		add_post_meta(
			$this->post_id,
			'_bricks_page_content_2',
			array(
				array(
					'id'       => 'probe-section',
					'name'     => 'section',
					'settings' => array(
						'tag'               => 'section',
						'_cssGlobalClasses' => array( 'probe-class-id' ),
					),
					'elements' => array(),
				),
			),
			true
		);
	}

	protected function tearDown(): void {
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}

		$this->resetStyleState();
		parent::tearDown();
	}

	public function test_repeated_css_conversions_keep_style_ids_stable_for_content_generation(): void {
		$first_conversion  = $this->css_converter->convert_bricks_classes_to_etch();
		$second_conversion = $this->css_converter->convert_bricks_classes_to_etch();

		$first_id  = $first_conversion['style_map']['probe-class-id']['id'] ?? '';
		$second_id = $second_conversion['style_map']['probe-class-id']['id'] ?? '';

		$this->assertNotSame( '', $first_id );
		$this->assertSame( $first_id, $second_id );
		$this->assertArrayHasKey( $first_id, $first_conversion['styles'] );
		$this->assertArrayHasKey( $second_id, $second_conversion['styles'] );

		$payload = $this->content_service->prepare_post_for_batch(
			$this->post_id,
			array(
				'page' => 'page',
			)
		);

		$this->assertIsArray( $payload );
		$this->assertStringContainsString( '"styles":["etch-section-style","' . $first_id . '"]', (string) $payload['etch_content'] );
	}

	private function resetStyleState(): void {
		foreach ( array(
			'bricks_global_classes',
			'etch_styles',
			'efs_style_map',
			'efs_active_migration',
			'efs_acss_inline_style_map',
		) as $option ) {
			delete_option( $option );
		}

		foreach ( array(
			'efs_cache_etch_styles',
			'efs_cache_style_map',
		) as $transient ) {
			delete_transient( $transient );
		}
	}
}
