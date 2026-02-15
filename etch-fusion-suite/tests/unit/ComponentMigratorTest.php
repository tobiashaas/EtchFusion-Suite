<?php
/**
 * Unit tests for Component Migrator.
 *
 * @package Bricks2Etch\Tests\Unit
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Component_Migrator;
use WP_UnitTestCase;

class ComponentMigratorTest extends WP_UnitTestCase {

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Component_Migrator */
	private $migrator;

	protected function setUp(): void {
		parent::setUp();
		$this->error_handler = new EFS_Error_Handler();
		$this->migrator      = new EFS_Component_Migrator( $this->error_handler, null, array() );
	}

	protected function tearDown(): void {
		delete_option( 'bricks_components' );
		delete_option( 'b2e_component_map' );
		parent::tearDown();
	}

	public function test_supports_returns_true_when_components_exist(): void {
		update_option( 'bricks_components', array( array( 'id' => 'abc', 'label' => 'Test', 'elements' => array() ) ) );
		self::assertTrue( $this->migrator->supports() );
	}

	public function test_supports_returns_false_when_no_components(): void {
		update_option( 'bricks_components', array() );
		self::assertFalse( $this->migrator->supports() );
	}

	public function test_validate_returns_valid_for_correct_structure(): void {
		update_option( 'bricks_components', array(
			array(
				'id'       => 'comp1',
				'label'    => 'Component 1',
				'elements' => array( array( 'id' => 'el1', 'parent' => 'comp1', 'name' => 'div' ) ),
			),
		) );
		$result = $this->migrator->validate();
		self::assertIsArray( $result );
		self::assertArrayHasKey( 'valid', $result );
		self::assertArrayHasKey( 'errors', $result );
		self::assertTrue( $result['valid'] );
		self::assertEmpty( $result['errors'] );
	}

	public function test_validate_detects_missing_required_fields(): void {
		update_option( 'bricks_components', array(
			array( 'id' => 'c1' ), // missing label and elements
			array( 'label' => 'Only label' ), // missing id
		) );
		$result = $this->migrator->validate();
		self::assertFalse( $result['valid'] );
		self::assertNotEmpty( $result['errors'] );
	}

	public function test_convert_properties_maps_types_correctly(): void {
		$properties = array(
			array( 'id' => 'p1', 'type' => 'text', 'default' => 'hello' ),
			array( 'id' => 'p2', 'type' => 'toggle', 'default' => true ),
			array( 'id' => 'p3', 'type' => 'number', 'default' => 42 ),
		);
		$converted = $this->migrator->convert_properties( $properties );
		self::assertSame( array( 'type' => 'string', 'default' => 'hello' ), $converted['p1'] );
		self::assertSame( array( 'type' => 'boolean', 'default' => true ), $converted['p2'] );
		self::assertSame( array( 'type' => 'number', 'default' => 42.0 ), $converted['p3'] );
	}

	public function test_convert_slot_element_generates_correct_block(): void {
		$slot_element = array( 'id' => 'slot-1', 'name' => 'slot' );
		$html = $this->migrator->convert_slot_element( $slot_element, 'comp1' );
		self::assertStringContainsString( 'wp:etch/slot', $html );
		self::assertStringContainsString( 'slotId', $html );
		self::assertStringContainsString( 'slot-1', $html );
		self::assertStringNotContainsString( 'etchData', $html );
	}

	public function test_is_slot_element(): void {
		self::assertTrue( $this->migrator->is_slot_element( array( 'name' => 'slot' ) ) );
		self::assertFalse( $this->migrator->is_slot_element( array( 'name' => 'container' ) ) );
	}

	public function test_component_mapping_stored_correctly(): void {
		$this->migrator->save_component_mapping( 'bricks-comp-1', 100 );
		$map = $this->migrator->get_component_mapping();
		self::assertSame( 100, $map['bricks-comp-1'] );
		self::assertSame( 100, EFS_Component_Migrator::get_etch_component_id( 'bricks-comp-1' ) );
		self::assertNull( EFS_Component_Migrator::get_etch_component_id( 'nonexistent' ) );
	}

	public function test_import_returns_wp_error(): void {
		$result = $this->migrator->import( array() );
		self::assertWPError( $result );
	}

	public function test_get_stats(): void {
		update_option( 'bricks_components', array(
			array( 'id' => 'a', 'label' => 'A', 'elements' => array(), 'properties' => array( 'p1' => 1 ) ),
			array( 'id' => 'b', 'label' => 'B', 'elements' => array( array( 'name' => 'slot' ) ) ),
		) );
		$stats = $this->migrator->get_stats();
		self::assertSame( 2, $stats['total'] );
		self::assertSame( 1, $stats['with_props'] );
		self::assertSame( 1, $stats['with_slots'] );
	}

	public function test_property_connection_mapping_applied_to_block_attributes(): void {
		$component = array(
			'id'         => 'comp-conn',
			'label'      => 'Component with connection',
			'properties' => array(
				array(
					'id'          => 'title',
					'type'        => 'text',
					'default'     => 'Connected Title',
					'connections' => array(
						array( 'element' => 'el1', 'setting' => 'text' ),
					),
				),
			),
			'elements'   => array(
				array(
					'id'       => 'el1',
					'parent'   => 'comp-conn',
					'name'     => 'heading',
					'settings' => array(),
				),
			),
		);
		$wp_block_data = $this->migrator->convert_component_to_wp_block( $component );
		self::assertNotWPError( $wp_block_data );
		self::assertIsArray( $wp_block_data );
		self::assertArrayHasKey( 'post_content', $wp_block_data );
		// Connected property default must appear in block output (heading text).
		self::assertStringContainsString( 'Connected Title', $wp_block_data['post_content'] );
	}
}
