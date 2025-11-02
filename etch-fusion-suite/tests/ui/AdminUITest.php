<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\UI;

use Bricks2Etch\Admin\EFS_Admin_Interface;
use Bricks2Etch\Controllers\EFS_Dashboard_Controller;
use Bricks2Etch\Controllers\EFS_Migration_Controller;
use Bricks2Etch\Controllers\EFS_Settings_Controller;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use WP_UnitTestCase;

class AdminUITest extends WP_UnitTestCase {
	/** @var \Bricks2Etch\Container\EFS_Service_Container */
	private $container;

	protected function setUp(): void {
		parent::setUp();

		$this->container = \etch_fusion_suite_container();
		$this->reset_plugin_detector();
		delete_option( 'efs_feature_flags' );
		delete_option( 'efs_settings' );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		delete_option( 'efs_feature_flags' );
		delete_option( 'efs_settings' );
		$this->reset_plugin_detector();
		parent::tearDown();
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
		$this->assertStringContainsString( 'data-efs-accordion', $output );
		$this->assertStringContainsString( 'data-efs-accordion-section', $output );
		$this->assertStringContainsString( 'data-efs-accordion-header', $output );
		$this->assertStringContainsString( 'data-efs-accordion-content', $output );
	}

	public function test_dashboard_context_includes_required_keys(): void {
		/** @var EFS_Dashboard_Controller $controller */
		$controller = $this->container->get( 'dashboard_controller' );

		$context = $controller->get_dashboard_context();

		foreach ( array( 'is_bricks_site', 'is_etch_site', 'site_url', 'nonce', 'saved_templates', 'template_extractor_enabled', 'feature_flags_section_id' ) as $key ) {
			$this->assertArrayHasKey( $key, $context );
		}
	}

	public function test_dashboard_context_reflects_feature_flag_state(): void {
		/** @var EFS_Dashboard_Controller $controller */
		$controller = $this->container->get( 'dashboard_controller' );
		update_option( 'efs_feature_flags', array( 'template_extractor' => false ) );

		$context = $controller->get_dashboard_context();
		$this->assertFalse( $context['template_extractor_enabled'] );

		update_option( 'efs_feature_flags', array( 'template_extractor' => true ) );
		$context = $controller->get_dashboard_context();
		$this->assertTrue( $context['template_extractor_enabled'] );
	}

	public function test_bricks_dashboard_includes_accordion_structure(): void {
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-accordion', $output );
		$this->assertGreaterThanOrEqual( 3, substr_count( $output, 'data-efs-accordion-section' ) );
		foreach ( array( 'connection', 'migration_key', 'migration' ) as $section ) {
			$this->assertStringContainsString( 'data-section="' . $section . '"', $output );
		}
	}

	public function test_etch_dashboard_includes_accordion_structure(): void {
		$this->set_etch_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-accordion', $output );
		$this->assertGreaterThanOrEqual( 4, substr_count( $output, 'data-efs-accordion-section' ) );
		foreach ( array( 'application_password', 'site_url', 'migration_key', 'feature_flags' ) as $section ) {
			$this->assertStringContainsString( 'data-section="' . $section . '"', $output );
		}
	}

	public function test_migration_key_component_renders_in_bricks_context(): void {
		update_option( 'efs_settings', array( 'target_url' => 'https://target.example', 'api_key' => 'abc123' ) );
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-migration-key-component', $output );
		$this->assertStringContainsString( 'data-context="bricks"', $output );
		$this->assertStringContainsString( 'name="api_key"', $output );
		$this->assertStringContainsString( 'name="target_url"', $output );
		$this->assertStringContainsString( 'value="https://target.example"', $output );
		$this->assertStringContainsString( 'value="abc123"', $output );
	}

	public function test_migration_key_component_renders_in_etch_context(): void {
		$this->set_etch_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		$expected_url = home_url();

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-migration-key-component', $output );
		$this->assertStringContainsString( 'data-context="etch"', $output );
		$this->assertStringContainsString( 'name="target_url"', $output );
		$this->assertStringContainsString( 'value="' . esc_attr( $expected_url ) . '"', $output );
		$this->assertStringNotContainsString( 'name="api_key"', $output );
	}

	public function test_template_extractor_tab_shows_disabled_state_when_flag_off(): void {
		$this->set_etch_environment();
		update_option( 'efs_feature_flags', array( 'template_extractor' => false ) );
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-tab="templates"', $output );
		$this->assertStringContainsString( 'is-disabled', $output );
		$this->assertStringContainsString( 'data-efs-feature-disabled="true"', $output );
	}

	public function test_template_extractor_tab_enabled_when_flag_on(): void {
		$this->set_etch_environment();
		update_option( 'efs_feature_flags', array( 'template_extractor' => true ) );
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-efs-tab="templates"', $output );
		$this->assertStringNotContainsString( 'is-disabled', $output );
	}

	public function test_bricks_setup_section_renders_expected_fields(): void {
		update_option( 'efs_settings', array( 'target_url' => 'https://target.example', 'api_key' => 'abc123' ) );
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-section="connection"', $output );
		$this->assertStringContainsString( 'data-section="migration_key"', $output );
		$this->assertStringContainsString( 'data-section="migration"', $output );
		$this->assertMatchesRegularExpression( '/name="target_url" value="https:\/\/target\.example"/', $output );
		$this->assertMatchesRegularExpression( '/name="api_key" value="abc123"/', $output );
	}

	public function test_etch_setup_section_renders_expected_fields(): void {
		$this->set_etch_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-section="application_password"', $output );
		$this->assertStringContainsString( 'data-section="site_url"', $output );
		$this->assertStringContainsString( 'data-section="migration_key"', $output );
		$this->assertStringContainsString( 'data-section="feature_flags"', $output );
		$this->assertMatchesRegularExpression( '/name="target_url" value="https?:\/\/.+"/', $output );
		$this->assertStringNotContainsString( 'name="api_key"', $output );
	}

	public function test_connection_settings_form_has_grouped_actions(): void {
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'efs-actions efs-actions--inline', $output );
		$this->assertStringContainsString( 'Save Connection Settings', $output );
		$this->assertStringContainsString( 'Test Connection', $output );
	}

	public function test_migration_form_has_grouped_actions(): void {
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'data-section="migration"', $output );
		$this->assertStringContainsString( 'Start Migration', $output );
		$this->assertStringContainsString( 'data-efs-cancel-migration', $output );
	}

	public function test_settings_are_passed_to_migration_key_component(): void {
		update_option( 'efs_settings', array( 'target_url' => 'https://target.example', 'api_key' => 'abc123' ) );
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="https://target.example"', $output );
		$this->assertStringContainsString( 'name="api_key"', $output );
	}

	public function test_dashboard_renders_without_errors_on_bricks_site(): void {
		$this->set_bricks_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	public function test_dashboard_renders_without_errors_on_etch_site(): void {
		$this->set_etch_environment();
		/** @var EFS_Admin_Interface $admin */
		$admin = $this->container->get( 'admin_interface' );

		ob_start();
		$admin->render_dashboard();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
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

	/**
	 * Helper to toggle environment to Bricks site context.
	 */
	private function set_bricks_environment(): void {
		update_option( 'efs_feature_flags', array( 'template_extractor' => true ) );
		$this->mock_plugin_detector( true, false );
	}

	/**
	 * Helper to toggle environment to Etch site context.
	 */
	private function set_etch_environment(): void {
		update_option( 'efs_feature_flags', array( 'template_extractor' => true ) );
		$this->mock_plugin_detector( false, true );
	}

	private function mock_plugin_detector( bool $is_bricks, bool $is_etch ): void {
		$error_handler = $this->container->get( 'error_handler' );
		$stub         = new class( $error_handler, $is_bricks, $is_etch ) extends EFS_Plugin_Detector {
			private $bricks;
			private $etch;

			public function __construct( $error_handler, bool $bricks, bool $etch ) {
				parent::__construct( $error_handler );
				$this->bricks = $bricks;
				$this->etch   = $etch;
			}

			public function is_bricks_active() {
				return $this->bricks;
			}

			public function is_etch_active() {
				return $this->etch;
			}
		};

		$this->container->singleton( 'plugin_detector', $stub );
	}

	private function reset_plugin_detector(): void {
		$this->container->singleton(
			'plugin_detector',
			function ( $container ) {
				return new EFS_Plugin_Detector( $container->get( 'error_handler' ) );
			}
		);
	}

}
