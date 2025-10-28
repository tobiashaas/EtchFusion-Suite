<?php
/**
 * Unit tests for Service Container.
 *
 * @package Bricks2Etch\Tests\Unit
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Container\EFS_Service_Container;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Services\EFS_Migration_Service;
use WP_UnitTestCase;

class ServiceContainerTest extends WP_UnitTestCase {

	/**
	 * @var EFS_Service_Container
	 */
	private $container;

	protected function setUp(): void {
		parent::setUp();

		$this->container = efs_container();
	}

	public function test_container_returns_singleton_instance(): void {
		$this->assertInstanceOf( EFS_Service_Container::class, $this->container );

		$this->assertSame( $this->container, efs_container() );
	}

	public function test_core_services_are_registered(): void {
		$this->assertTrue( $this->container->has( 'migration_service' ) );
		$this->assertTrue( $this->container->has( 'settings_repository' ) );
		$this->assertTrue( $this->container->has( 'admin_interface' ) );
	}

	public function test_resolving_migration_service_wires_dependencies(): void {
		$migration_service = $this->container->get( 'migration_service' );

		$this->assertInstanceOf( EFS_Migration_Service::class, $migration_service );
	}

	public function test_audit_logger_uses_error_handler_dependency(): void {
		/** @var EFS_Audit_Logger $logger */
		$logger = $this->container->get( 'audit_logger' );

		$logger->log_security_event( 'unit_container', 'low', 'Testing audit logger wiring.' );

		$logs = get_option( 'efs_security_log', array() );
		$this->assertNotEmpty( $logs );
	}

	public function test_registering_custom_singleton_persists(): void {
		$custom = new \stdClass();
		$custom->foo = 'bar';

		$this->container->singleton( 'efs_custom_singleton', static function () use ( $custom ) {
			return $custom;
		} );

		$this->assertSame( $custom, $this->container->get( 'efs_custom_singleton' ) );
	}

	public function test_factory_definition_creates_new_instances(): void {
		$this->container->factory( 'efs_factory', static function () {
			return new \stdClass();
		} );

		$first  = $this->container->get( 'efs_factory' );
		$second = $this->container->get( 'efs_factory' );

		$this->assertNotSame( $first, $second );
	}
}

