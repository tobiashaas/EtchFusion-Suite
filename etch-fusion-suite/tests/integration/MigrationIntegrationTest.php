<?php
/**
 * Integration tests for Migration workflow.
 *
 * @package Bricks2Etch\Tests\Integration
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Integration;

use Bricks2Etch\Migrators\EFS_Migrator_Discovery;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Services\EFS_Migration_Service;
use WP_UnitTestCase;

class MigrationIntegrationTest extends WP_UnitTestCase {

	/** @var int */
	private $test_post_id;

	protected function setUp(): void {
		parent::setUp();

		$this->test_post_id = $this->factory->post->create(
			array(
				'post_title'   => 'EFS Integration Test',
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);

		update_post_meta(
			$this->test_post_id,
			'_bricks_page_content_2',
			array(
				array(
					'id'       => 'test-section',
					'name'     => 'section',
					'settings' => array( 'tag' => 'section' ),
					'elements' => array(),
				),
			)
		);

		update_post_meta( $this->test_post_id, '_bricks_template_type', 'page' );
	}

	protected function tearDown(): void {
		if ( $this->test_post_id ) {
			wp_delete_post( $this->test_post_id, true );
		}

		parent::tearDown();
	}

	public function test_container_resolves_migration_service_and_dependencies(): void {
		$container        = \etch_fusion_suite_container();
		$migration_service = $container->get( 'migration_service' );

		$this->assertInstanceOf( EFS_Migration_Service::class, $migration_service );
		$this->assertTrue( $container->has( 'migration_repository' ) );
		$this->assertTrue( $container->has( 'plugin_detector' ) );
	}

	public function test_css_converter_available_for_migration_flow(): void {
		$container = \etch_fusion_suite_container();
		$this->assertTrue( $container->has( 'css_converter' ) );

		$converter = $container->get( 'css_converter' );
		$this->assertInstanceOf( EFS_CSS_Converter::class, $converter );

		$sample_css = array( 'styles' => array( '.efs' => array( 'color' => '#fff' ) ) );
		$result     = $converter->convert( $sample_css );
		$this->assertIsArray( $result );
	}

	public function test_registry_discovers_builtin_and_custom_migrators(): void {
		$container = \etch_fusion_suite_container();
		/** @var EFS_Migrator_Registry $registry */
		$registry = $container->get( 'migrator_registry' );

		$registry->clear();

		$custom_migrator = new class() implements Migrator_Interface {
			public function get_name() {
				return 'Custom Migrator';
			}

			public function get_type() {
				return 'custom';
			}

			public function get_priority() {
				return 5;
			}

			public function supports() {
				return true;
			}

			public function migrate( $target_url, $api_key ) {
				return array();
			}

			public function validate() {
				return array( 'valid' => true, 'errors' => array() );
			}

			public function export() {
				return array();
			}

			public function import( $data ) {
				return array();
			}

			public function get_stats() {
				return array();
			}
		};

		add_action( 'efs_register_migrators', static function ( EFS_Migrator_Registry $registry ) use ( $custom_migrator ) {
			$registry->register( $custom_migrator );
		}, 10, 1 );

		EFS_Migrator_Discovery::discover_migrators( $registry );

		$this->assertTrue( $registry->has( 'cpt' ) );
		$this->assertTrue( $registry->has( 'custom' ) );
		$this->assertArrayHasKey( 'custom', $registry->get_supported() );
	}

	public function test_bricks_content_fixture_detected_by_services(): void {
		$container       = \etch_fusion_suite_container();
		$content_service = $container->get( 'content_service' );

		$analysis = $content_service->analyze_content();

		$this->assertIsArray( $analysis );
		$this->assertArrayHasKey( 'total', $analysis );
	}
}

