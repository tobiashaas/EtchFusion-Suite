<?php
/**
 * Regression tests for the /validate REST endpoint permission callback.
 *
 * Asserts that allow_public_request() returns true for non-logged-in
 * server-to-server callers with a valid CORS origin, and propagates a
 * WP_Error for disallowed browser origins.
 *
 * Compatible with EFS_SKIP_WP_LOAD=1 (no live WordPress install required).
 */

declare( strict_types=1 );

namespace {
	// -----------------------------------------------------------------------
	// WordPress stubs required by EFS_API_Endpoints::allow_public_request()
	// and the private check_cors_origin() it delegates to.
	// -----------------------------------------------------------------------

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public string $code;
			public string $message;
			/** @var mixed */
			public $data;

			/**
			 * @param string $code    Error code.
			 * @param string $message Human-readable message.
			 * @param mixed  $data    Optional additional data.
			 */
			public function __construct( string $code = '', string $message = '', $data = '' ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( string $url ): string {
			return $url;
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return $value;
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		// Simulate a non-logged-in / non-admin server-to-server caller.
		function current_user_can( string $capability ): bool {
			return false;
		}
	}
}

namespace EtchFusionSuite\Tests {

use Bricks2Etch\Api\EFS_API_Endpoints;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class ValidateEndpointPermissionTest extends TestCase {

	/** @var ReflectionClass<EFS_API_Endpoints> */
	private ReflectionClass $refl;

	private ReflectionProperty $corsManagerProp;
	private ReflectionProperty $auditLoggerProp;

	protected function setUp(): void {
		parent::setUp();

		$this->refl            = new ReflectionClass( EFS_API_Endpoints::class );
		$this->corsManagerProp = $this->refl->getProperty( 'cors_manager' );
		$this->corsManagerProp->setAccessible( true );
		$this->auditLoggerProp = $this->refl->getProperty( 'audit_logger' );
		$this->auditLoggerProp->setAccessible( true );

		// Reset static state before every test.
		$this->corsManagerProp->setValue( null, null );
		$this->auditLoggerProp->setValue( null, null );
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	protected function tearDown(): void {
		parent::tearDown();

		$this->corsManagerProp->setValue( null, null );
		$this->auditLoggerProp->setValue( null, null );
		unset( $_SERVER['HTTP_ORIGIN'] );
	}

	/**
	 * Without a CORS manager, check_cors_origin() short-circuits to true.
	 * A non-logged-in caller must still be allowed through.
	 */
	public function test_allow_public_request_returns_true_without_cors_manager(): void {
		$result = EFS_API_Endpoints::allow_public_request( null );

		$this->assertTrue(
			$result,
			'allow_public_request() must return true when no CORS manager is configured.'
		);
	}

	/**
	 * Server-to-server calls omit the Origin header. CORS is a browser concern;
	 * the endpoint must be reachable even when is_origin_allowed() would reject
	 * a browser origin — because there is no Origin to check.
	 */
	public function test_allow_public_request_returns_true_without_origin_header(): void {
		// Install a CORS manager that would reject any browser request.
		$this->corsManagerProp->setValue( null, new StubCorsManager( false ) );
		unset( $_SERVER['HTTP_ORIGIN'] ); // Ensure header is absent.

		$result = EFS_API_Endpoints::allow_public_request( null );

		$this->assertTrue(
			$result,
			'allow_public_request() must return true for server-to-server calls without an Origin header.'
		);
	}

	/**
	 * A browser request from an allowed origin must pass through.
	 */
	public function test_allow_public_request_returns_true_for_allowed_origin(): void {
		$this->corsManagerProp->setValue( null, new StubCorsManager( true ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://allowed.example.com';

		$result = EFS_API_Endpoints::allow_public_request( null );

		$this->assertTrue(
			$result,
			'allow_public_request() must return true for a browser request from an allowed CORS origin.'
		);
	}

	/**
	 * Security regression guard: a browser request from a disallowed origin
	 * must be rejected with a WP_Error (not silently allowed or ignored).
	 */
	public function test_allow_public_request_propagates_cors_error_for_disallowed_origin(): void {
		$this->corsManagerProp->setValue( null, new StubCorsManager( false ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.attacker.example';

		$result = EFS_API_Endpoints::allow_public_request( null );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'allow_public_request() must propagate a WP_Error when the CORS origin is disallowed.'
		);

		/** @var \WP_Error $result */
		$this->assertSame(
			'cors_violation',
			$result->get_error_code(),
			'The WP_Error code must be cors_violation for a rejected CORS origin.'
		);
	}
}

/**
 * Minimal stub for the CORS manager dependency.
 */
final class StubCorsManager {

	private bool $allowed;

	public function __construct( bool $allowed ) {
		$this->allowed = $allowed;
	}

	public function is_origin_allowed( string $origin ): bool {
		return $this->allowed;
	}
}

}
