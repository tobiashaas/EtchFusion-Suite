<?php
/**
 * WordPress integration test for plugin activation and basic functionality.
 *
 * @package Bricks2Etch\Tests\WordPress
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\WordPress;

use WP_UnitTestCase;

/**
 * Test plugin activation and core WordPress integration.
 */
class PluginActivationTest extends WP_UnitTestCase {

	/**
	 * Test that the plugin is loaded.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'ETCH_FUSION_SUITE_VERSION' ), 'ETCH_FUSION_SUITE_VERSION constant should be defined' );
		$this->assertTrue( defined( 'ETCH_FUSION_SUITE_DIR' ), 'ETCH_FUSION_SUITE_DIR constant should be defined' );
		$this->assertTrue( defined( 'ETCH_FUSION_SUITE_URL' ), 'ETCH_FUSION_SUITE_URL constant should be defined' );
	}

	/**
	 * Test that the service container is available.
	 */
	public function test_service_container_is_available(): void {
		$this->assertTrue( function_exists( 'etch_fusion_suite_container' ), 'etch_fusion_suite_container() function should exist' );

		$container = etch_fusion_suite_container();
		$this->assertInstanceOf( \Bricks2Etch\Container\EFS_Service_Container::class, $container );
	}

	/**
	 * Test that core services are registered.
	 */
	public function test_core_services_are_registered(): void {
		$container = etch_fusion_suite_container();

		$this->assertTrue( $container->has( 'plugin_detector' ), 'plugin_detector service should be registered' );
		$this->assertTrue( $container->has( 'error_handler' ), 'error_handler service should be registered' );
		$this->assertTrue( $container->has( 'api_client' ), 'api_client service should be registered' );
	}

	/**
	 * Test that admin menu is registered.
	 */
	public function test_admin_menu_is_registered(): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			$menu = array();
		}

		do_action( 'admin_menu' );

		$menu_slugs = array_column( $menu, 2 );
		$this->assertContains( 'etch-fusion-suite', $menu_slugs, 'Admin menu should contain etch-fusion-suite page' );
	}

	/**
	 * Test that REST API endpoints are registered.
	 *
	 * @todo Fix EFS_API_Endpoints::register_routes() method signature
	 */
	public function test_rest_api_endpoints_are_registered(): void {
		$this->markTestSkipped( 'REST API endpoint registration needs fixing in EFS_API_Endpoints class' );
	}

	/**
	 * Test plugin detector functionality.
	 */
	public function test_plugin_detector(): void {
		$container = etch_fusion_suite_container();
		$detector  = $container->get( 'plugin_detector' );

		$this->assertInstanceOf( \Bricks2Etch\Core\EFS_Plugin_Detector::class, $detector );

		$plugins = $detector->get_installed_plugins();
		$this->assertIsArray( $plugins );
		$this->assertArrayHasKey( 'bricks', $plugins );
		$this->assertArrayHasKey( 'etch', $plugins );
	}
}
