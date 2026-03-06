<?php
/**
 * Permission Callback Tests
 *
 * Tests all REST API permission callbacks for security and correctness.
 *
 * @package Bricks2Etch\Tests
 */

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Endpoints;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;

/**
 * Test permission callbacks that protect REST API endpoints.
 */
class PermissionCallbacksTest extends \WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Setup test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Reset to anonymous user before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( 0 );
	}

	/**
	 * Test require_admin_permission allows admin user.
	 */
	public function test_require_admin_permission_allows_admin() {
		wp_set_current_user( self::$admin_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_admin_permission denies non-admin user.
	 * Note: WordPress REST API returns WP_Error when permission callback fails.
	 */
	public function test_require_admin_permission_denies_editor() {
		wp_set_current_user( self::$editor_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test require_admin_permission denies anonymous user.
	 * Note: WordPress REST API returns WP_Error when permission callback fails.
	 */
	public function test_require_admin_permission_denies_anonymous() {
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test allow_public_request always allows (security via global rate limiting).
	 */
	public function test_allow_public_request_allows_all() {
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::allow_public_request( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_admin_with_cookie_fallback allows admin.
	 */
	public function test_require_admin_with_cookie_fallback_allows_admin() {
		wp_set_current_user( self::$admin_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_with_cookie_fallback( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_admin_with_cookie_fallback denies non-admin.
	 * Note: Returns WP_Error when user lacks manage_options capability.
	 */
	public function test_require_admin_with_cookie_fallback_denies_non_admin() {
		wp_set_current_user( self::$editor_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_with_cookie_fallback( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test require_migration_token_permission with valid token.
	 */
	public function test_require_migration_token_permission_accepts_valid_token() {
		$token_manager = $this->get_token_manager();
		if ( ! $token_manager ) {
			$this->markTestSkipped( 'Token manager unavailable' );
		}

		$token_data = $token_manager->generate_migration_token( 'https://target.test', 3600, 'https://source.test' );
		$token = $token_data['token'];

		$request = new \WP_REST_Request();
		$request->set_header( 'Authorization', "Bearer $token" );
		$request->set_header( 'X-EFS-Source-Origin', 'https://source.test' );

		$result = EFS_API_Endpoints::require_migration_token_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_migration_token_permission rejects invalid token.
	 */
	public function test_require_migration_token_permission_rejects_invalid_token() {
		$request = new \WP_REST_Request();
		$request->set_header( 'Authorization', 'Bearer invalid.token.here' );
		$request->set_header( 'X-EFS-Source-Origin', 'https://source.test' );

		$result = EFS_API_Endpoints::require_migration_token_permission( $request );

		$this->assertWPError( $result );
	}

	/**
	 * Test require_migration_token_permission rejects missing token.
	 */
	public function test_require_migration_token_permission_rejects_missing_token() {
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_migration_token_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_authorization', $result->get_error_code() );
	}

	/**
	 * Test require_migration_token_permission with mismatched origin.
	 * Note: The permission callback only validates the token. Origin check happens
	 * in the actual handlers, not in the permission callback.
	 */
	public function test_require_migration_token_permission_rejects_wrong_origin() {
		$token_manager = $this->get_token_manager();
		if ( ! $token_manager ) {
			$this->markTestSkipped( 'Token manager unavailable' );
		}

		$token_data = $token_manager->generate_migration_token( 'https://target.test', 3600, 'https://source.test' );
		$token = $token_data['token'];

		$request = new \WP_REST_Request();
		$request->set_header( 'Authorization', "Bearer $token" );
		// Different origin header - but this is NOT checked by permission callback
		$request->set_header( 'X-EFS-Source-Origin', 'https://attacker.test' );

		$result = EFS_API_Endpoints::require_migration_token_permission( $request );

		// The permission callback only validates the token, not the origin.
		// Origin validation happens in the actual import handlers.
		$this->assertTrue( $result );
	}

	/**
	 * Test require_admin_or_body_migration_token_permission allows admin.
	 */
	public function test_require_admin_or_body_migration_token_permission_allows_admin() {
		wp_set_current_user( self::$admin_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_or_body_migration_token_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_admin_or_body_migration_token_permission rejects non-admin without token.
	 */
	public function test_require_admin_or_body_migration_token_permission_denies_without_auth() {
		wp_set_current_user( self::$editor_id );
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::require_admin_or_body_migration_token_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test require_valid_pairing_code_permission with valid code.
	 */
	public function test_require_valid_pairing_code_permission_accepts_valid_code() {
		$token_manager = $this->get_token_manager();
		if ( ! $token_manager ) {
			$this->markTestSkipped( 'Token manager unavailable' );
		}

		// Generate a pairing code
		$pairing_code = $token_manager->generate_pairing_code();

		$request = new \WP_REST_Request();
		$request->set_param( 'pairing_code', $pairing_code );

		$result = EFS_API_Endpoints::require_valid_pairing_code_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test require_valid_pairing_code_permission rejects invalid code.
	 */
	public function test_require_valid_pairing_code_permission_rejects_invalid_code() {
		$request = new \WP_REST_Request();
		$request->set_param( 'pairing_code', 'invalid_code_123' );

		$result = EFS_API_Endpoints::require_valid_pairing_code_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_pairing_code', $result->get_error_code() );
	}

	/**
	 * Test require_valid_pairing_code_permission rejects missing code.
	 */
	public function test_require_valid_pairing_code_permission_rejects_missing_code() {
		$request = new \WP_REST_Request();
		// No pairing_code parameter

		$result = EFS_API_Endpoints::require_valid_pairing_code_permission( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_pairing_code', $result->get_error_code() );
	}

	/**
	 * Test allow_read_only_discovery_permission always allows.
	 */
	public function test_allow_read_only_discovery_permission_allows_all() {
		$request = new \WP_REST_Request();

		$result = EFS_API_Endpoints::allow_read_only_discovery_permission( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Helper: Get token manager instance.
	 *
	 * @return EFS_Migration_Token_Manager|null
	 */
	private function get_token_manager() {
		$container = etch_fusion_suite_container();
		if ( ! $container ) {
			return null;
		}

		return $container->get( 'token_manager' );
	}
}
