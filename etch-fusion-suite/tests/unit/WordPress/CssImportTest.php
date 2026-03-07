<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Migration_Endpoints;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class CssImportTest extends WP_UnitTestCase {
	/** @var EFS_Migration_Token_Manager */
	private $token_manager;

	/** @var EFS_WordPress_Migration_Repository */
	private $migration_repository;

	protected function setUp(): void {
		parent::setUp();

		$container                  = \etch_fusion_suite_container();
		$this->token_manager        = $container->get( 'token_manager' );
		$this->migration_repository = $container->get( 'migration_repository' );

		$this->resetStyleState();
	}

	protected function tearDown(): void {
		$this->resetStyleState();
		parent::tearDown();
	}

	public function test_import_css_classes_preserves_style_ids_and_builtin_keys(): void {
		$token    = $this->create_migration_token();
		$response = EFS_API_Migration_Endpoints::import_css_classes(
			$this->create_json_request(
				array(
					'styles' => array(
						'abc1234' => array(
							'type'       => 'class',
							'selector'   => '.hero-card',
							'collection' => 'default',
							'css'        => 'color: red;',
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
					),
				),
				$token
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$stored_styles = get_option( 'etch_styles', array() );
		$this->assertIsArray( $stored_styles );
		$this->assertArrayHasKey( 'abc1234', $stored_styles );
		$this->assertArrayHasKey( 'etch-section-style', $stored_styles );
		$this->assertArrayNotHasKey( '.hero-card', $stored_styles );
		$this->assertSame( '.hero-card', $stored_styles['abc1234']['selector'] );

		$style_map = get_option( 'efs_style_map', array() );
		$this->assertSame( 'abc1234', $style_map['brick-class-1']['id'] );
		$this->assertSame( '.hero-card', $style_map['brick-class-1']['selector'] );
	}

	public function test_import_css_classes_rekeys_legacy_selector_key_to_current_style_id(): void {
		update_option(
			'etch_styles',
			array(
				'.hero-card' => array(
					'type'       => 'class',
					'selector'   => '.hero-card',
					'collection' => 'default',
					'css'        => 'color: green;',
					'readonly'   => false,
				),
			)
		);

		$token    = $this->create_migration_token();
		$response = EFS_API_Migration_Endpoints::import_css_classes(
			$this->create_json_request(
				array(
					'styles' => array(
						'new5678' => array(
							'type'       => 'class',
							'selector'   => '.hero-card',
							'collection' => 'default',
							'css'        => 'color: blue;',
							'readonly'   => false,
						),
					),
					'style_map' => array(
						'brick-class-1' => array(
							'id'       => 'new5678',
							'selector' => '.hero-card',
						),
					),
				),
				$token
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$stored_styles = get_option( 'etch_styles', array() );
		$this->assertIsArray( $stored_styles );
		$this->assertArrayHasKey( 'new5678', $stored_styles );
		$this->assertArrayNotHasKey( '.hero-card', $stored_styles );
		$this->assertSame( 'color: blue;', $stored_styles['new5678']['css'] );

		$style_map = get_option( 'efs_style_map', array() );
		$this->assertSame( 'new5678', $style_map['brick-class-1']['id'] );
	}

	public function test_import_css_classes_increments_receiving_state_by_style_map_count(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'css' => 2,
			)
		);

		$response = EFS_API_Migration_Endpoints::import_css_classes(
			$this->create_json_request(
				array(
					'styles' => array(
						'abc1234' => array(
							'type'       => 'class',
							'selector'   => '.hero-card',
							'collection' => 'default',
							'css'        => 'color: red;',
							'readonly'   => false,
						),
						'def5678' => array(
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
				),
				$token,
				array(
					'X-EFS-Items-Total' => '2',
					'X-EFS-Phase-Total' => '2',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 2, $receiving_state['items_received'] );
		$this->assertSame( 2, $receiving_state['items_total'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['total'] );
	}

	public function test_import_global_css_does_not_increment_css_class_progress(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'css' => 2,
			)
		);

		$response = EFS_API_Migration_Endpoints::import_global_css(
			$this->create_json_request(
				array(
					'id'   => 'bricks-global',
					'name' => 'Migrated from Bricks',
					'css'  => 'body { color: red; }',
					'type' => 'custom',
				),
				$token,
				array(
					'X-EFS-Items-Total' => '2',
					'X-EFS-Phase-Total' => '2',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 0, $receiving_state['items_received'] );
		$this->assertSame( 2, $receiving_state['items_total'] );
		$this->assertSame( 0, $receiving_state['items_by_type']['css']['received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['total'] );
	}

	public function test_global_css_import_route_is_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/efs/v1/import/global-css', $routes );
	}

	public function test_cross_phase_receiving_totals_remain_aggregated(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'css'   => 2,
				'posts' => array(
					'page' => 1,
				),
			)
		);

		$css_response = EFS_API_Migration_Endpoints::import_css_classes(
			$this->create_json_request(
				array(
					'styles' => array(
						'abc1234' => array(
							'type'       => 'class',
							'selector'   => '.hero-card',
							'collection' => 'default',
							'css'        => 'color: red;',
							'readonly'   => false,
						),
						'def5678' => array(
							'type'       => 'class',
							'selector'   => '.hero-card-alt',
							'collection' => 'default',
							'css'        => 'color: blue;',
							'readonly'   => false,
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
				),
				$token,
				array(
					'X-EFS-Items-Total' => '2',
					'X-EFS-Phase-Total' => '2',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $css_response );

		$post_response = EFS_API_Migration_Endpoints::import_posts(
			$this->create_json_request(
				array(
					'posts' => array(
						array(
							'post' => array(
								'ID'          => 2001,
								'post_title'  => 'Imported Page',
								'post_name'   => 'imported-page',
								'post_type'   => 'page',
								'post_date'   => current_time( 'mysql' ),
								'post_status' => 'publish',
							),
							'source_post_type' => 'page',
							'etch_content'     => '<!-- wp:paragraph --><p>Imported</p><!-- /wp:paragraph -->',
						),
					),
				),
				$token,
				array(
					'X-EFS-Items-Total'     => '1',
					'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 1 ) ),
				),
				'/efs/v1/import/posts'
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $post_response );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 3, $receiving_state['items_total'] );
		$this->assertSame( 3, $receiving_state['items_received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['total'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['received'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['total'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['received'] );
	}

	public function test_receiving_totals_self_heal_after_oversized_init_css_total(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'css'   => 5381,
				'posts' => array(
					'page' => 1,
				),
			)
		);

		$css_response = EFS_API_Migration_Endpoints::import_css_classes(
			$this->create_json_request(
				array(
					'styles' => array(
						'abc1234' => array(
							'type'       => 'class',
							'selector'   => '.hero-card',
							'collection' => 'default',
							'css'        => 'color: red;',
							'readonly'   => false,
						),
						'def5678' => array(
							'type'       => 'class',
							'selector'   => '.hero-card-alt',
							'collection' => 'default',
							'css'        => 'color: blue;',
							'readonly'   => false,
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
				),
				$token,
				array(
					'X-EFS-Items-Total' => '2',
					'X-EFS-Phase-Total' => '2',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $css_response );

		$post_response = EFS_API_Migration_Endpoints::import_posts(
			$this->create_json_request(
				array(
					'posts' => array(
						array(
							'post' => array(
								'ID'          => 2002,
								'post_title'  => 'Imported Page',
								'post_name'   => 'imported-page-healed',
								'post_type'   => 'page',
								'post_date'   => current_time( 'mysql' ),
								'post_status' => 'publish',
							),
							'source_post_type' => 'page',
							'etch_content'     => '<!-- wp:paragraph --><p>Imported</p><!-- /wp:paragraph -->',
						),
					),
				),
				$token,
				array(
					'X-EFS-Items-Total'     => '1',
					'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 1 ) ),
				),
				'/efs/v1/import/posts'
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $post_response );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 3, $receiving_state['items_total'] );
		$this->assertSame( 3, $receiving_state['items_received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['total'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['css']['received'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['total'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['received'] );

		$complete_response = EFS_API_Migration_Endpoints::complete_migration(
			$this->create_json_request( array(), $token, array(), '/efs/v1/import/complete' )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $complete_response );
		$this->assertTrue( $complete_response->get_data()['success'] );
	}

	private function create_migration_token(): string {
		$token_data = $this->token_manager->generate_migration_token(
			'https://target.test',
			HOUR_IN_SECONDS,
			'https://source.test'
		);

		return $token_data['token'];
	}

	private function create_json_request( array $body, string $token, array $headers = array(), string $route = '/efs/v1/import/css-classes' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-EFS-Source-Origin', 'https://source.test' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( (string) $name, (string) $value );
		}
		$request->set_body( wp_json_encode( $body ) );

		return $request;
	}

	private function import_init_totals( string $token, array $totals ): void {
		$request = $this->create_json_request( $totals, $token, array(), '/efs/v1/import/init-totals' );
		$response = EFS_API_Migration_Endpoints::import_init_totals( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
	}

	private function resetStyleState(): void {
		foreach ( array(
			'etch_styles',
			'efs_style_map',
			'etch_svg_version',
			'etch_global_stylesheets',
			'efs_migration_token',
			'efs_migration_token_value',
			'efs_migration_token_expires',
			'efs_receiving_migration',
		) as $option ) {
			delete_option( $option );
		}

		foreach ( array(
			'efs_cache_etch_styles',
			'efs_cache_style_map',
			'efs_cache_svg_version',
			'efs_cache_global_stylesheets',
			'efs_cache_migration_token_data',
			'efs_cache_migration_token_value',
			'efs_cache_receiving_state',
		) as $transient ) {
			delete_transient( $transient );
		}
	}
}
