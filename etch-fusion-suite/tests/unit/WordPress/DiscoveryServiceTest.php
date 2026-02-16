<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Services\EFS_Discovery_Service;
use WP_UnitTestCase;

class DiscoveryServiceTest extends WP_UnitTestCase {

	public function test_discovery_service_returns_consistent_schema(): void {
		$container         = \etch_fusion_suite_container();
		$discovery_service = $container->get( 'discovery_service' );

		$this->assertInstanceOf( EFS_Discovery_Service::class, $discovery_service );

		$response = $discovery_service->generate_discovery_response( 5, false );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'post_types', $response );
		$this->assertArrayHasKey( 'custom_fields', $response );
		$this->assertArrayHasKey( 'taxonomies', $response );
		$this->assertArrayHasKey( 'media_stats', $response );
		$this->assertArrayHasKey( 'dynamic_data', $response );

		$this->assertIsArray( $response['post_types'] );
		$this->assertIsArray( $response['custom_fields'] );
		$this->assertIsArray( $response['taxonomies'] );
		$this->assertIsArray( $response['media_stats'] );
		$this->assertIsArray( $response['dynamic_data'] );
	}

	public function test_dynamic_data_converter_classifies_tags(): void {
		$container  = \etch_fusion_suite_container();
		$converter  = $container->get( 'dynamic_data_converter' );

		$this->assertInstanceOf( EFS_Dynamic_Data_Converter::class, $converter );
		$this->assertSame( 'green', $converter->classify_dynamic_tag( '{post_title}' ) );
		$this->assertSame( 'green', $converter->classify_dynamic_tag( '{acf:hero_title}' ) );
		$this->assertSame( 'yellow', $converter->classify_dynamic_tag( '{woo_price}' ) );
		$this->assertSame( 'red', $converter->classify_dynamic_tag( '{custom_unknown_tag}' ) );
	}
}

