<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Services\EFS_CSS_Service;
use WP_UnitTestCase;

class CssServiceTest extends WP_UnitTestCase {
	public function test_get_migration_css_class_counts_uses_actual_conversion_payload_count(): void {
		$payload = array(
			'styles'    => array(
				'abc1234'            => array(
					'type'     => 'class',
					'selector' => '.hero-card',
				),
				'def5678'            => array(
					'type'     => 'class',
					'selector' => '.hero-card-alt',
				),
				'etch-section-style' => array(
					'type'     => 'element',
					'selector' => ':where([data-etch-element="section"])',
				),
			),
			'style_map' => array(
				'brick-class-1' => array(
					'id'       => 'abc1234',
					'selector' => '.hero-card',
				),
				'brick-class-2' => array(
					'id'       => 'def5678',
					'selector' => '.hero-card-alt',
				),
			),
		);

		$converter = $this->getMockBuilder( EFS_CSS_Converter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'convert_bricks_classes_to_etch' ) )
			->getMock();

		$converter->expects( $this->once() )
			->method( 'convert_bricks_classes_to_etch' )
			->willReturn( $payload );

		$service = new EFS_CSS_Service(
			$converter,
			new EFS_API_Client( new EFS_Error_Handler() ),
			new EFS_Error_Handler()
		);

		$this->assertSame(
			array(
				'total'      => 2,
				'to_migrate' => 2,
			),
			$service->get_migration_css_class_counts( array( 'page' ) )
		);
	}

	public function test_migrate_css_classes_reports_actual_style_count_and_reuses_cached_payload(): void {
		$payload = array(
			'styles'    => array(
				'abc1234'            => array(
					'type'       => 'class',
					'selector'   => '.hero-card',
					'collection' => 'default',
					'css'        => 'color: red;',
					'readonly'   => false,
				),
				'def5678'            => array(
					'type'       => 'class',
					'selector'   => '.hero-card-alt',
					'collection' => 'default',
					'css'        => 'color: blue;',
					'readonly'   => false,
				),
				'etch-section-style' => array(
					'type'       => 'element',
					'selector'   => ':where([data-etch-element="section"])',
					'collection' => 'default',
					'css'        => 'display: flex;',
					'readonly'   => true,
				),
			),
			'style_map' => array(
				'brick-class-1' => array(
					'id'       => 'abc1234',
					'selector' => '.hero-card',
				),
				'brick-class-2' => array(
					'id'       => 'def5678',
					'selector' => '.hero-card-alt',
				),
			),
		);

		$converter = $this->getMockBuilder( EFS_CSS_Converter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'convert_bricks_classes_to_etch' ) )
			->getMock();

		$converter->expects( $this->once() )
			->method( 'convert_bricks_classes_to_etch' )
			->willReturn( $payload );

		$api_client = $this->getMockBuilder( EFS_API_Client::class )
			->setConstructorArgs( array( new EFS_Error_Handler() ) )
			->onlyMethods( array( 'send_css_styles' ) )
			->getMock();

		$api_client->expects( $this->once() )
			->method( 'send_css_styles' )
			->with( 'http://target.local', 'token-123', $payload )
			->willReturn( array( 'success' => true ) );

		$service = new EFS_CSS_Service(
			$converter,
			$api_client,
			new EFS_Error_Handler()
		);

		$this->assertSame( 2, $service->get_migration_css_class_counts( array( 'page' ) )['to_migrate'] );

		$result = $service->migrate_css_classes( 'http://target.local', 'token-123' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['migrated'] );
	}
}
