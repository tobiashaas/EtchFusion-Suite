<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\UI;

use Bricks2Etch\Admin\EFS_Admin_Interface;
use Bricks2Etch\Controllers\EFS_Dashboard_Controller;
use Bricks2Etch\Controllers\EFS_Migration_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use WP_UnitTestCase;

class AdminUITest extends WP_UnitTestCase {
	/** @var \Bricks2Etch\Container\EFS_Service_Container */
	private $container;

	protected function setUp(): void {
		parent::setUp();

		$this->container = \etch_fusion_suite_container();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_admin_menu_registration(): void {
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		$admin->add_admin_menu();

		global $menu;

		$menu_slugs = array_column( $menu, 2 );
		$this->assertContains( 'etch-fusion-suite', $menu_slugs );
	}

	public function test_dashboard_render_outputs_markup(): void {
		ob_start();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Etch Fusion Suite', $output );
		$this->assertStringContainsString( 'Recent Logs', $output );
	}

	public function test_dashboard_context_includes_required_keys(): void {
		/** @var EFS_Dashboard_Controller $controller */
		$controller = $this->container->get( 'dashboard_controller' );

		$context = $controller->get_dashboard_context();

		foreach ( array( 'is_bricks_site', 'is_etch_site', 'site_url', 'nonce', 'saved_templates' ) as $key ) {
			$this->assertArrayHasKey( $key, $context );
		}
	}

	public function test_settings_controller_persists_sanitised_payload(): void {
		/** @var EFS_Settings_Controller $controller */
		$controller = $this->container->get( 'settings_controller' );

		$result = $controller->save_settings(
			array(
				'target_url'    => 'https://example.com',
				'api_key'       => 'abcdefghijklmnopqrstuvwxyz1234567890',
				'migration_key' => 'key',
			)
		);

		$this->assertArrayHasKey( 'message', $result );
		$this->assertSame( 'https://example.com', $result['settings']['target_url'] );
	}

	public function test_migration_controller_requires_token_for_start(): void {
		/** @var EFS_Migration_Controller $controller */
		$controller = $this->container->get( 'migration_controller' );

		$result = $controller->start_migration( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_token', $result->get_error_code() );
	}

	public function test_template_extractor_supported_sources_and_extraction(): void {
		/** @var EFS_Template_Extractor_Service $extractor */
		$extractor = $this->container->get( 'template_extractor_service' );

		$sources = $extractor->get_supported_sources();
		$this->assertContains( 'framer_html', $sources, 'Extractor should expose supported sources.' );

		$fixture = file_get_contents( ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html' );
		$this->assertNotFalse( $fixture, 'Fixture must be readable.' );

		$payload = $extractor->extract_from_html( $fixture );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'blocks', $payload );
	}

	public function test_audit_logger_records_events_for_dashboard(): void {
		/** @var EFS_Audit_Logger $logger */
		$logger = $this->container->get( 'audit_logger' );
		$logger->log_security_event( 'ui_test', 'low', 'UI test log entry.' );

		$logs = $logger->get_security_logs( 10 );
		$this->assertNotEmpty( $logs );
		$this->assertSame( 'ui_test', $logs[0]['event_type'] );
	}
}
