<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Migration_Endpoints;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository;
use Bricks2Etch\Services\EFS_Content_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class MigrationEndpointsTest extends WP_UnitTestCase {
	/** @var EFS_Migration_Token_Manager */
	private $token_manager;

	/** @var EFS_WordPress_Migration_Repository */
	private $migration_repository;

	/** @var EFS_Content_Service */
	private $content_service;

	protected function setUp(): void {
		parent::setUp();

		$container                 = \etch_fusion_suite_container();
		$this->token_manager       = $container->get( 'token_manager' );
		$this->migration_repository = $container->get( 'migration_repository' );
		$this->content_service     = $container->get( 'content_service' );

		$this->resetMigrationState();
	}

	protected function tearDown(): void {
		$this->resetMigrationState();
		parent::tearDown();
	}

	public function test_prepare_post_for_batch_includes_source_post_type(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Source Page',
				'post_content' => '<!-- wp:paragraph --><p>Source</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);

		$payload = $this->content_service->prepare_post_for_batch(
			$post_id,
			array(
				'page' => 'post',
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'page', $payload['source_post_type'] );
		$this->assertSame( 'post', $payload['post']['post_type'] );
	}

	public function test_import_posts_counts_mapped_source_post_type_and_allows_completion(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'posts' => array(
					'page' => 1,
				),
			)
		);

		$import_request = $this->create_json_request(
			'POST',
			array(
				'posts' => array(
					$this->make_batch_item(
						987654,
						'page',
						'post',
						array(
							'post_title' => 'Mapped Source Page',
							'post_name'  => 'mapped-source-page',
						),
						'<!-- wp:paragraph --><p>Imported</p><!-- /wp:paragraph -->'
					),
				),
			),
			$token,
			array(
				'X-EFS-Items-Total'      => '1',
				'X-EFS-PostType-Totals'  => wp_json_encode( array( 'page' => 1 ) ),
			)
		);

		$import_response = EFS_API_Migration_Endpoints::import_posts( $import_request );
		$this->assertInstanceOf( WP_REST_Response::class, $import_response );

		$import_data = $import_response->get_data();
		$this->assertTrue( $import_data['success'] );
		$this->assertSame( 'success', $import_data['results'][0]['status'] );
		$this->assertSame( 'page', $import_data['results'][0]['post_type'] );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 1, $receiving_state['items_received'] );
		$this->assertSame( 1, $receiving_state['items_total'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['received'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['total'] );

		$complete_request = $this->create_json_request( 'POST', array(), $token );
		$complete_result  = EFS_API_Migration_Endpoints::complete_migration( $complete_request );

		$this->assertInstanceOf( WP_REST_Response::class, $complete_result );
		$this->assertTrue( $complete_result->get_data()['success'] );
	}

	public function test_import_posts_counts_only_successful_items_in_mixed_batch(): void {
		$token = $this->create_migration_token();

		$this->import_init_totals(
			$token,
			array(
				'posts' => array(
					'page' => 2,
				),
			)
		);

		$import_request = $this->create_json_request(
			'POST',
			array(
				'posts' => array(
					$this->make_batch_item(
						2001,
						'page',
						'post',
						array(
							'post_title' => 'First Batched Page',
							'post_name'  => 'first-batched-page',
						)
					),
					array(
						'etch_content' => '<!-- wp:paragraph --><p>Broken</p><!-- /wp:paragraph -->',
					),
				),
			),
			$token,
			array(
				'X-EFS-Items-Total'     => '2',
				'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 2 ) ),
			)
		);

		$import_response = EFS_API_Migration_Endpoints::import_posts( $import_request );
		$this->assertInstanceOf( WP_REST_Response::class, $import_response );

		$import_data = $import_response->get_data();
		$this->assertCount( 2, $import_data['results'] );
		$this->assertSame( 'success', $import_data['results'][0]['status'] );
		$this->assertSame( 'failed', $import_data['results'][1]['status'] );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 1, $receiving_state['items_received'] );
		$this->assertSame( 2, $receiving_state['items_total'] );
		$this->assertSame( 1, $receiving_state['items_by_type']['page']['received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['page']['total'] );

		$complete_result = EFS_API_Migration_Endpoints::complete_migration(
			$this->create_json_request( 'POST', array(), $token )
		);

		$this->assertInstanceOf( WP_Error::class, $complete_result );
		$this->assertSame( 'migration_incomplete', $complete_result->get_error_code() );
		$this->assertStringContainsString( 'page (1/2)', $complete_result->get_error_message() );
	}

	public function test_import_posts_updates_existing_target_post_via_meta_mapping(): void {
		$token            = $this->create_migration_token();
		$source_post_id   = 3001;
		$existing_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Existing Meta Target',
				'post_name'   => 'existing-meta-target',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $existing_post_id, '_efs_original_post_id', $source_post_id );

		$import_request = $this->create_json_request(
			'POST',
			array(
				'posts' => array(
					$this->make_batch_item(
						$source_post_id,
						'page',
						'page',
						array(
							'post_title' => 'Updated Through Meta',
							'post_name'  => 'updated-through-meta',
						),
						'<!-- wp:paragraph --><p>Meta update</p><!-- /wp:paragraph -->'
					),
				),
			),
			$token,
			array(
				'X-EFS-Items-Total'     => '1',
				'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 1 ) ),
			)
		);

		$import_response = EFS_API_Migration_Endpoints::import_posts( $import_request );
		$this->assertInstanceOf( WP_REST_Response::class, $import_response );

		$import_data = $import_response->get_data();
		$this->assertSame( $existing_post_id, $import_data['results'][0]['post_id'] );

		$updated_post = get_post( $existing_post_id );
		$this->assertInstanceOf( \WP_Post::class, $updated_post );
		$this->assertSame( 'Updated Through Meta', $updated_post->post_title );
		$this->assertSame( 'updated-through-meta', $updated_post->post_name );
		$this->assertSame( 'page', $updated_post->post_type );
	}

	public function test_import_posts_updates_existing_target_post_via_option_mapping(): void {
		$token            = $this->create_migration_token();
		$source_post_id   = 3002;
		$existing_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Existing Option Target',
				'post_name'   => 'existing-option-target',
				'post_status' => 'draft',
			)
		);

		update_option(
			'efs_post_mappings',
			array(
				$source_post_id => $existing_post_id,
			)
		);

		$import_request = $this->create_json_request(
			'POST',
			array(
				'posts' => array(
					$this->make_batch_item(
						$source_post_id,
						'page',
						'post',
						array(
							'post_title' => 'Updated Through Option',
							'post_name'  => 'updated-through-option',
						),
						'<!-- wp:paragraph --><p>Option update</p><!-- /wp:paragraph -->'
					),
				),
			),
			$token,
			array(
				'X-EFS-Items-Total'     => '1',
				'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 1 ) ),
			)
		);

		$import_response = EFS_API_Migration_Endpoints::import_posts( $import_request );
		$this->assertInstanceOf( WP_REST_Response::class, $import_response );

		$import_data = $import_response->get_data();
		$this->assertSame( $existing_post_id, $import_data['results'][0]['post_id'] );

		$updated_post = get_post( $existing_post_id );
		$this->assertInstanceOf( \WP_Post::class, $updated_post );
		$this->assertSame( 'Updated Through Option', $updated_post->post_title );
		$this->assertSame( 'updated-through-option', $updated_post->post_name );
	}

	public function test_import_posts_updates_existing_target_post_via_slug_match(): void {
		$token            = $this->create_migration_token();
		$source_post_id   = 3003;
		$existing_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Existing Slug Target',
				'post_name'   => 'slug-match-target',
				'post_status' => 'draft',
			)
		);

		$import_request = $this->create_json_request(
			'POST',
			array(
				'posts' => array(
					$this->make_batch_item(
						$source_post_id,
						'page',
						'post',
						array(
							'post_title' => 'Updated Through Slug',
							'post_name'  => 'slug-match-target',
						),
						'<!-- wp:paragraph --><p>Slug update</p><!-- /wp:paragraph -->'
					),
				),
			),
			$token,
			array(
				'X-EFS-Items-Total'     => '1',
				'X-EFS-PostType-Totals' => wp_json_encode( array( 'page' => 1 ) ),
			)
		);

		$import_response = EFS_API_Migration_Endpoints::import_posts( $import_request );
		$this->assertInstanceOf( WP_REST_Response::class, $import_response );

		$import_data = $import_response->get_data();
		$this->assertSame( $existing_post_id, $import_data['results'][0]['post_id'] );

		$updated_post = get_post( $existing_post_id );
		$this->assertInstanceOf( \WP_Post::class, $updated_post );
		$this->assertSame( 'Updated Through Slug', $updated_post->post_title );

		$mappings = get_option( 'efs_post_mappings', array() );
		$this->assertIsArray( $mappings );
		$this->assertSame( $existing_post_id, $mappings[ $source_post_id ] );
	}

	public function test_complete_migration_rejects_incomplete_post_type_totals(): void {
		$token = $this->create_migration_token();

		$this->migration_repository->save_receiving_state(
			array(
				'status'         => 'receiving',
				'items_received' => 1,
				'items_total'    => 2,
				'items_by_type'  => array(
					'page' => array(
						'received' => 1,
						'total'    => 2,
					),
				),
			)
		);

		$complete_result = EFS_API_Migration_Endpoints::complete_migration(
			$this->create_json_request( 'POST', array(), $token )
		);

		$this->assertInstanceOf( WP_Error::class, $complete_result );
		$this->assertSame( 'migration_incomplete', $complete_result->get_error_code() );
		$this->assertStringContainsString( 'page (1/2)', $complete_result->get_error_message() );
	}

	public function test_complete_migration_rejects_global_item_count_mismatch(): void {
		$token = $this->create_migration_token();

		$this->migration_repository->save_receiving_state(
			array(
				'status'         => 'receiving',
				'items_received' => 2,
				'items_total'    => 3,
				'items_by_type'  => array(),
			)
		);

		$complete_result = EFS_API_Migration_Endpoints::complete_migration(
			$this->create_json_request( 'POST', array(), $token )
		);

		$this->assertInstanceOf( WP_Error::class, $complete_result );
		$this->assertSame( 'migration_incomplete', $complete_result->get_error_code() );
		$this->assertStringContainsString( 'Received 2 of 3 items.', $complete_result->get_error_message() );
	}

	public function test_complete_migration_reconciles_failed_items_from_completion_payload(): void {
		$token = $this->create_migration_token();

		$this->migration_repository->save_receiving_state(
			array(
				'status'         => 'receiving',
				'items_received' => 3,
				'items_total'    => 5,
				'items_by_type'  => array(
					'media' => array(
						'received' => 2,
						'total'    => 4,
					),
					'page'  => array(
						'received' => 1,
						'total'    => 1,
					),
				),
			)
		);

		$complete_result = EFS_API_Migration_Endpoints::complete_migration(
			$this->create_json_request(
				'POST',
				array(
					'expected_receipts_by_type' => array(
						'media' => 2,
						'page'  => 1,
					),
				),
				$token
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $complete_result );
		$this->assertTrue( $complete_result->get_data()['success'] );

		$receiving_state = $this->migration_repository->get_receiving_state();
		$this->assertSame( 'completed', $receiving_state['status'] );
		$this->assertSame( 3, $receiving_state['items_received'] );
		$this->assertSame( 3, $receiving_state['items_total'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['media']['received'] );
		$this->assertSame( 2, $receiving_state['items_by_type']['media']['total'] );
	}

	private function create_migration_token(): string {
		$token_data = $this->token_manager->generate_migration_token(
			'https://target.test',
			HOUR_IN_SECONDS,
			'https://source.test'
		);

		return $token_data['token'];
	}

	private function import_init_totals( string $token, array $totals ): void {
		$init_response = EFS_API_Migration_Endpoints::import_init_totals(
			$this->create_json_request( 'POST', $totals, $token )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $init_response );
	}

	private function make_batch_item(
		int $source_post_id,
		string $source_post_type = 'page',
		string $target_post_type = 'post',
		array $post_overrides = array(),
		?string $etch_content = null
	): array {
		$post = array_merge(
			array(
				'ID'          => $source_post_id,
				'post_title'  => 'Imported Post ' . $source_post_id,
				'post_name'   => 'imported-post-' . $source_post_id,
				'post_type'   => $target_post_type,
				'post_date'   => current_time( 'mysql' ),
				'post_status' => 'publish',
			),
			$post_overrides
		);

		return array(
			'post'             => $post,
			'source_post_type' => $source_post_type,
			'etch_content'     => $etch_content ?? '<!-- wp:paragraph --><p>Imported</p><!-- /wp:paragraph -->',
			'etch_loops'       => array(),
		);
	}

	private function create_json_request( string $method, array $body, string $token, array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, '/efs/v1/test' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-EFS-Source-Origin', 'https://source.test' );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, (string) $value );
		}

		$request->set_body( wp_json_encode( $body ) );

		return $request;
	}

	private function resetMigrationState(): void {
		delete_option( 'efs_post_mappings' );
		delete_option( 'b2e_post_mappings' );
		delete_option( 'etch_loops' );
		delete_option( 'efs_migration_token' );
		delete_option( 'efs_migration_token_value' );
		delete_option( 'efs_migration_token_expires' );
		delete_option( 'efs_receiving_migration' );

		delete_transient( 'efs_cache_receiving_state' );
		delete_transient( 'efs_cache_migration_token_data' );
		delete_transient( 'efs_cache_migration_token_value' );

		if ( method_exists( $this->migration_repository, 'clear_receiving_state' ) ) {
			$this->migration_repository->clear_receiving_state();
		}
	}
}
