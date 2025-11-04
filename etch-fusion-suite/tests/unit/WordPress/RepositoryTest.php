<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository;
use Bricks2Etch\Repositories\EFS_WordPress_Settings_Repository;
use Bricks2Etch\Repositories\EFS_WordPress_Style_Repository;
use WP_UnitTestCase;

class RepositoryTest extends WP_UnitTestCase {
	/** @var EFS_WordPress_Settings_Repository */
	private $settings_repository;

	/** @var EFS_WordPress_Migration_Repository */
	private $migration_repository;

	/** @var EFS_WordPress_Style_Repository */
	private $style_repository;

	protected function setUp(): void {
		parent::setUp();

		$container = \etch_fusion_suite_container();
		$this->settings_repository  = $container->get( 'settings_repository' );
		$this->migration_repository = $container->get( 'migration_repository' );
		$this->style_repository     = $container->get( 'style_repository' );

		$this->resetOptions();
	}

	protected function tearDown(): void {
		$this->resetOptions();
		parent::tearDown();
	}

	private function resetOptions(): void {
		foreach ( array(
			'efs_settings',
			'efs_api_key',
			'efs_migration_settings',
			'efs_cors_allowed_origins',
			'efs_security_settings',
			'efs_migration_progress',
			'efs_current_migration_id',
			'efs_last_migration',
			'efs_migration_steps',
			'efs_migration_stats',
			'efs_migration_token',
			'efs_migration_token_value',
			'etch_styles',
			'efs_style_map',
			'etch_svg_version',
			'etch_global_stylesheets',
			'bricks_global_classes',
		) as $option ) {
			delete_option( $option );
		}

		foreach ( array(
			'efs_cache_settings_plugin',
			'efs_cache_settings_api_key',
			'efs_cache_settings_migration',
			'efs_cache_cors_origins',
			'efs_cache_security_settings',
			'efs_cache_migration_progress',
			'efs_cache_migration_steps',
			'efs_cache_migration_stats',
			'efs_cache_migration_token_data',
			'efs_cache_migration_token_value',
			'efs_cache_etch_styles',
			'efs_cache_style_map',
			'efs_cache_svg_version',
			'efs_cache_global_stylesheets',
			'efs_cache_bricks_global_classes',
		) as $transient ) {
			delete_transient( $transient );
		}
	}

	public function test_settings_repository_defaults_and_cache_invalidation(): void {
		$defaults = $this->settings_repository->get_migration_settings();
		$this->assertIsArray( $defaults );
		$this->assertEmpty( $defaults );

		$this->settings_repository->save_migration_settings( array( 'batch_size' => 25 ) );
		$stored = $this->settings_repository->get_migration_settings();
		$this->assertSame( 25, $stored['batch_size'] );

		$this->settings_repository->get_plugin_settings();
		update_option( 'efs_settings', array( 'in_cache' => true ) );
		$cached = $this->settings_repository->get_plugin_settings();
		$this->assertArrayNotHasKey( 'in_cache', $cached, 'Cached value should be returned before invalidation.' );

		$this->settings_repository->save_plugin_settings( array( 'foo' => 'bar' ) );
		$refreshed = $this->settings_repository->get_plugin_settings();
		$this->assertSame( 'bar', $refreshed['foo'] );

		$security = $this->settings_repository->get_security_settings();
		$this->assertTrue( $security['rate_limit_enabled'] );
	}

	public function test_migration_repository_progress_and_token_storage(): void {
		$progress = $this->migration_repository->get_progress();
		$this->assertIsArray( $progress );
		$this->assertEmpty( $progress );

		$this->migration_repository->save_progress( array( 'status' => 'running' ) );
		$storedProgress = $this->migration_repository->get_progress();
		$this->assertSame( 'running', $storedProgress['status'] );
		$this->assertArrayHasKey( 'migrationId', $storedProgress );
		$this->assertSame( '', $storedProgress['migrationId'] );

		$this->migration_repository->save_progress( array( 'status' => 'running', 'migrationId' => 'efs-test-id' ) );
		$progressWithId = $this->migration_repository->get_progress();
		$this->assertSame( 'efs-test-id', $progressWithId['migrationId'] );

		$this->migration_repository->save_steps( array( 'validate' => array( 'status' => 'pending' ) ) );
		$steps = $this->migration_repository->get_steps();
		$this->assertArrayHasKey( 'validate', $steps );

		$this->migration_repository->save_token_value( 'token-123' );
		$this->assertSame( 'token-123', $this->migration_repository->get_token_value() );

		$this->migration_repository->save_stats( array( 'runs' => 1 ) );
		$stats = $this->migration_repository->get_stats();
		$this->assertSame( 1, $stats['runs'] );

		$this->migration_repository->get_progress();
		update_option( 'efs_migration_progress', array( 'status' => 'cached' ) );
		$this->assertSame( 'running', $this->migration_repository->get_progress()['status'], 'Transient cache should return previous value.' );

		$this->migration_repository->save_progress( array( 'status' => 'complete' ) );
		$this->assertSame( 'complete', $this->migration_repository->get_progress()['status'] );
	}

	public function test_style_repository_persists_style_artifacts(): void {
		$this->style_repository->save_etch_styles( array( 'colors' => array( '#fff' ) ) );
		$this->assertSame( array( '#fff' ), $this->style_repository->get_etch_styles()['colors'] );

		$this->style_repository->save_style_map( array( 'old' => 'new' ) );
		$this->assertSame( 'new', $this->style_repository->get_style_map()['old'] );

		$currentVersion = $this->style_repository->get_svg_version();
		$newVersion     = $this->style_repository->increment_svg_version();
		$this->assertSame( $currentVersion + 1, $newVersion );

		$this->style_repository->save_global_stylesheets( array( 'main' => '.class{}' ) );
		$this->assertArrayHasKey( 'main', $this->style_repository->get_global_stylesheets() );

		$this->style_repository->get_etch_styles();
		update_option( 'etch_styles', array( 'stale' => true ) );
		$this->assertArrayNotHasKey( 'stale', $this->style_repository->get_etch_styles() );

		$this->style_repository->invalidate_style_cache();
		$this->assertArrayHasKey( 'stale', $this->style_repository->get_etch_styles() );
	}
}
