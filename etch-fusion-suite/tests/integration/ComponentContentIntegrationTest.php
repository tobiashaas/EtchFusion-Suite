<?php
/**
 * Content migration integration tests for component reference replacement.
 *
 * @package Bricks2Etch\Tests\Integration
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Integration;

use Bricks2Etch\Converters\Elements\EFS_Element_Component;
use Bricks2Etch\Migrators\EFS_Component_Migrator;
use WP_UnitTestCase;

class ComponentContentIntegrationTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		delete_option( 'efs_component_map' );
		parent::tearDown();
	}

	public function test_component_reference_replaced_in_content(): void {
		update_option( 'efs_component_map', array( 'bricks-comp-123' => 456 ) );

		$converter = new EFS_Element_Component( array() );
		$element   = array(
			'cid'        => 'bricks-comp-123',
			'properties' => array( 'title' => 'Hello' ),
		);
		$html = $converter->convert( $element, array() );
		self::assertNotEmpty( $html );
		self::assertStringContainsString( 'etch/component', $html );
		self::assertStringContainsString( '"ref":456', $html );
		self::assertSame( 456, EFS_Component_Migrator::get_etch_component_id( 'bricks-comp-123' ) );
	}

	public function test_component_converter_returns_empty_when_cid_missing(): void {
		$converter = new EFS_Element_Component( array() );
		$element   = array( 'name' => 'template', 'properties' => array() );
		$html = $converter->convert( $element, array() );
		self::assertSame( '', $html );
	}

	public function test_component_converter_returns_empty_when_no_mapping(): void {
		delete_option( 'efs_component_map' );
		$converter = new EFS_Element_Component( array() );
		$element   = array( 'cid' => 'unknown-comp' );
		$html = $converter->convert( $element, array() );
		self::assertSame( '', $html );
	}
}
