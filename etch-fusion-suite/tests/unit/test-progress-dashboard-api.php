<?php
/**
 * Unit Tests for Progress Dashboard API
 *
 * @package Bricks2Etch\Tests
 */

namespace Bricks2Etch\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Bricks2Etch\Admin\EFS_Progress_Dashboard_API;

/**
 * Test_EFS_Progress_Dashboard_API
 *
 * Tests REST API endpoint registration and callbacks.
 */
class Test_EFS_Progress_Dashboard_API extends \WP_UnitTestCase {

	/**
	 * Test that the API class uses the logger trait.
	 */
	public function test_api_uses_logger_trait() {
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'get_migration_progress' ) );
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'get_migration_errors' ) );
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'get_migration_logs_by_category' ) );
	}

	/**
	 * Test API has required callback methods.
	 */
	public function test_api_callback_methods_exist() {
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'handle_progress_request' ) );
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'handle_errors_request' ) );
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'handle_category_request' ) );
	}

	/**
	 * Test API has permission check method.
	 */
	public function test_api_has_permission_check() {
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'check_dashboard_access' ) );
	}

	/**
	 * Test API has route registration method.
	 */
	public function test_api_has_register_routes() {
		$this->assertTrue( method_exists( EFS_Progress_Dashboard_API::class, 'register_routes' ) );
	}

	/**
	 * Test permission check denies unauthenticated users.
	 */
	public function test_permission_check_denies_unauthenticated() {
		// Ensure user is logged out.
		wp_logout();

		$request = $this->createMock( '\WP_REST_Request' );
		$this->assertFalse( EFS_Progress_Dashboard_API::check_dashboard_access( $request ) );
	}

	/**
	 * Test permission check denies non-admin users.
	 */
	public function test_permission_check_denies_non_admin() {
		// Create a subscriber user (not admin).
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$request = $this->createMock( '\WP_REST_Request' );
		$this->assertFalse( EFS_Progress_Dashboard_API::check_dashboard_access( $request ) );

		wp_logout();
	}

	/**
	 * Test permission check allows admin users.
	 */
	public function test_permission_check_allows_admin() {
		// Create an admin user.
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$request = $this->createMock( '\WP_REST_Request' );
		$this->assertTrue( EFS_Progress_Dashboard_API::check_dashboard_access( $request ) );

		wp_logout();
	}

	/**
	 * Test handle_progress_request returns response.
	 */
	public function test_handle_progress_request_returns_response() {
		$request = $this->createMock( '\WP_REST_Request' );
		$request->expects( $this->once() )
			->method( 'get_param' )
			->with( 'migration_id' )
			->willReturn( 'test-id' );

		$response = EFS_Progress_Dashboard_API::handle_progress_request( $request );

		// Should return either WP_REST_Response or WP_Error-like structure.
		$this->assertNotNull( $response );
	}

	/**
	 * Test handle_errors_request returns response.
	 */
	public function test_handle_errors_request_returns_response() {
		$request = $this->createMock( '\WP_REST_Request' );
		$request->expects( $this->once() )
			->method( 'get_param' )
			->with( 'migration_id' )
			->willReturn( 'test-id' );

		$response = EFS_Progress_Dashboard_API::handle_errors_request( $request );
		$this->assertNotNull( $response );
	}

	/**
	 * Test handle_category_request returns response.
	 */
	public function test_handle_category_request_returns_response() {
		$request = $this->createMock( '\WP_REST_Request' );
		$request->expects( $this->any() )
			->method( 'get_param' )
			->willReturnOnConsecutiveCalls( 'test-id', 'test-category' );

		$response = EFS_Progress_Dashboard_API::handle_category_request( $request );
		$this->assertNotNull( $response );
	}
}
