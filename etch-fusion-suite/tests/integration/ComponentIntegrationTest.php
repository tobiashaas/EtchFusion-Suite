<?php
/**
 * Integration tests for Component Migrator.
 *
 * @package Bricks2Etch\Tests\Integration
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Integration;

use Bricks2Etch\Migrators\EFS_Component_Migrator;
use WP_UnitTestCase;

class ComponentIntegrationTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'bricks_components' );
		delete_option( 'efs_component_map' );
		parent::tearDown();
	}

	public function test_full_component_migration_dry_run(): void {
		$components = array(
			array(
				'id'       => 'int-comp-1',
				'label'    => 'Test Component',
				'elements' => array(
					array( 'id' => 'root', 'parent' => 'int-comp-1', 'name' => 'container', 'settings' => array(), 'children' => array() ),
				),
			),
		);
		update_option( 'bricks_components', $components );

		$container = \etch_fusion_suite_container();
		self::assertTrue( $container->has( 'component_migrator' ) );
		$migrator = $container->get( 'component_migrator' );
		self::assertInstanceOf( EFS_Component_Migrator::class, $migrator );
		self::assertTrue( $migrator->supports() );

		$result = $migrator->migrate( 'http://example.com', 'fake-jwt', true );
		self::assertTrue( $result );
	}

	public function test_nested_component_structure_validation(): void {
		$components = array(
			array(
				'id'       => 'parent',
				'label'    => 'Parent',
				'elements' => array(
					array( 'id' => 'e1', 'parent' => 'parent', 'name' => 'container', 'cid' => 'child', 'children' => array() ),
				),
			),
			array(
				'id'       => 'child',
				'label'    => 'Child',
				'elements' => array( array( 'id' => 'e2', 'parent' => 'child', 'name' => 'div' ) ),
			),
		);
		update_option( 'bricks_components', $components );
		$migrator = \etch_fusion_suite_container()->get( 'component_migrator' );
		$validation = $migrator->validate();
		self::assertIsArray( $validation );
		self::assertArrayHasKey( 'valid', $validation );
	}

	public function test_component_with_slots_convert(): void {
		$components = array(
			array(
				'id'       => 'with-slot',
				'label'    => 'With Slot',
				'elements' => array(
					array( 'id' => 's1', 'parent' => 'with-slot', 'name' => 'slot' ),
				),
			),
		);
		update_option( 'bricks_components', $components );
		$migrator = \etch_fusion_suite_container()->get( 'component_migrator' );
		$wp_block = $migrator->convert_component_to_wp_block( $components[0] );
		self::assertIsArray( $wp_block );
		self::assertSame( 'With Slot', $wp_block['post_title'] );
		self::assertSame( 'wp_block', $wp_block['post_type'] );
		self::assertStringContainsString( 'etch/slot', $wp_block['post_content'] );
	}
}
