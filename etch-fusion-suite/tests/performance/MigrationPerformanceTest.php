<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Performance;

use Bricks2Etch\Services\EFS_Content_Service;
use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use WP_UnitTestCase;

class MigrationPerformanceTest extends WP_UnitTestCase {
	/** @var \Bricks2Etch\Container\EFS_Service_Container */
	private $container;

	/** @var EFS_Content_Service */
	private $content_service;

	/** @var EFS_Template_Extractor_Service */
	private $template_extractor;

	protected function setUp(): void {
		parent::setUp();

		$this->container          = \etch_fusion_suite_container();
		$this->content_service     = $this->container->get( 'content_service' );
		$this->template_extractor  = $this->container->get( 'template_extractor_service' );

		$this->seed_bricks_content( 15 );
	}

	private function seed_bricks_content( int $count ): void {
		$sample_structure = array(
			array(
				'id'       => 'root-' . wp_generate_uuid4(),
				'name'     => 'section',
				'settings' => array(
					'tag'   => 'section',
					'width' => 'full',
				),
				'elements' => array(
					array(
						'id'       => 'text-' . wp_generate_uuid4(),
						'name'     => 'rich-text',
						'settings' => array(
							'content' => '<p>Lorem ipsum dolor sit amet.</p>',
						),
					),
				),
			),
		);

		for ( $i = 0; $i < $count; $i++ ) {
			$post_id = $this->factory->post->create(
				array(
					'post_title'   => 'Bricks Fixture #' . $i,
					'post_content' => '',
					'post_status'  => 'publish',
				)
			);

			update_post_meta( $post_id, '_bricks_page_content_2', $sample_structure );
			update_post_meta( $post_id, '_bricks_template_type', 'page' );
		}
	}

	public function test_content_analysis_completes_under_one_second(): void {
		$startMemory = memory_get_usage(true);
		$startTime   = microtime(true);

		$analysis = $this->content_service->analyze_content();

		$duration = microtime(true) - $startTime;
		$memory   = memory_get_usage(true) - $startMemory;

		$this->assertLessThan( 1.0, $duration, 'Content analysis should finish inside 1s for synthetic dataset.' );
		$this->assertLessThan( 50 * 1024 * 1024, $memory, 'Analysis should not allocate more than ~50MB additional memory.' );
		$this->assertArrayHasKey( 'total', $analysis );
	}

	public function test_template_extraction_stays_within_timing_budget(): void {
		$fixture = file_get_contents( ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html' );
		$this->assertNotFalse( $fixture, 'Fixture must be readable for performance test.' );

		$startTime = microtime(true);
		$payload   = $this->template_extractor->extract_from_html( $fixture );
		$duration  = microtime(true) - $startTime;

		$this->assertLessThan( 2.0, $duration, 'Template extraction should finish inside 2s for sample HTML.' );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'blocks', $payload );
	}
}
