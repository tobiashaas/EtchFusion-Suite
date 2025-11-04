<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\Abstract_Migrator;
use Bricks2Etch\Migrators\EFS_Migrator_Discovery;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Services\EFS_Content_Service;
use Bricks2Etch\Services\EFS_CSS_Service;
use Bricks2Etch\Services\EFS_Media_Service;
use WP_UnitTestCase;

class MigratorTest extends WP_UnitTestCase {
	/** @var EFS_Migrator_Registry */
	private $registry;

	/** @var EFS_Error_Handler */
	private $error_handler;

	protected function setUp(): void {
		parent::setUp();

		$container           = \etch_fusion_suite_container();
		$this->registry      = $container->get( 'migrator_registry' );
		$this->error_handler = $container->get( 'error_handler' );

		$this->registry->clear();
	}

	protected function tearDown(): void {
		remove_all_actions( 'efs_register_migrators' );
		remove_all_filters( 'efs_migrators_discovered' );

		EFS_Migrator_Discovery::discover_migrators( $this->registry );

		parent::tearDown();
	}

	public function test_registry_registers_and_sorts_migrators_by_priority(): void {
		$alpha = $this->createStubMigrator( 'alpha', 20 );
		$beta  = $this->createStubMigrator( 'beta', 10 );

		$this->registry->register( $alpha );
		$this->registry->register( $beta );

		$all = array_keys( $this->registry->get_all() );

		$this->assertSame( array( 'beta', 'alpha' ), $all );
		$this->assertTrue( $this->registry->has( 'alpha' ) );
		$this->assertSame( $beta, $this->registry->get( 'beta' ) );
	}

	public function test_get_supported_filters_out_unsupported_migrators(): void {
		$supported   = $this->createStubMigrator( 'supported', 10, true );
		$unsupported = $this->createStubMigrator( 'unsupported', 15, false );

		$this->registry->register( $supported );
		$this->registry->register( $unsupported );

		$results = $this->registry->get_supported();

		$this->assertArrayHasKey( 'supported', $results );
		$this->assertArrayNotHasKey( 'unsupported', $results );
	}

	public function test_discovery_registers_builtin_and_third_party_migrators(): void {
		$thirdParty = $this->createStubMigrator( 'third_party', 50 );

		add_action(
			'efs_register_migrators',
			function ( EFS_Migrator_Registry $registry ) use ( $thirdParty ) {
				$registry->register( $thirdParty );
			},
			10,
			1
		);

		EFS_Migrator_Discovery::discover_migrators( $this->registry );

		$this->assertTrue( $this->registry->has( 'third_party' ) );
		$this->assertGreaterThan( 0, $this->registry->count() );
	}

	public function test_validate_contract_returns_expected_structure(): void {
		$migrator = $this->createStubMigrator(
			'contract',
			5,
			true,
			array(
				'valid'  => false,
				'errors' => array( 'Requires attention' ),
			)
		);

		$this->registry->register( $migrator );
		$result = $this->registry->get( 'contract' )->validate();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid', $result );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	private function createStubMigrator(
		string $type,
		int $priority,
		bool $supports = true,
		?array $validateResult = null
	): Migrator_Interface {
		$validateResult = $validateResult ?? array( 'valid' => true, 'errors' => array() );

		$error_handler = $this->error_handler;

		return new class( $error_handler, $type, $priority, $supports, $validateResult ) extends Abstract_Migrator {
			private $supports;
			private $validateResult;
			private $stats;

			public function __construct( EFS_Error_Handler $error_handler, string $type, int $priority, bool $supports, array $validateResult ) {
				parent::__construct( $error_handler );
				$this->name           = ucfirst( $type ) . ' Migrator';
				$this->type           = $type;
				$this->priority       = $priority;
				$this->supports       = $supports;
				$this->validateResult = $validateResult;
				$this->stats          = array( 'type' => $type, 'priority' => $priority );
			}

			public function supports() {
				return $this->supports;
			}

			public function export() {
				return array();
			}

			public function import( $data ) {
				return array( 'imported' => count( (array) $data ) );
			}

			public function migrate( $target_url, $api_key ) {
				return true;
			}

			public function validate() {
				return $this->validateResult;
			}

			public function get_stats() {
				return $this->stats;
			}
		};
	}
}
