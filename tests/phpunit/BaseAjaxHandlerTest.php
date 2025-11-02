<?php
/**
 * Base AJAX handler security gate tests.
 */

declare( strict_types=1 );

namespace {
	use EtchFusionSuite\Tests\BaseAjaxHandlerTest;

	if ( ! function_exists( 'check_ajax_referer' ) ) {
		function check_ajax_referer( $action, $field, $die = true ) {
			return BaseAjaxHandlerTest::$nonceValid;
		}
	}

	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $capability ) {
			return BaseAjaxHandlerTest::$capabilityGranted;
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return BaseAjaxHandlerTest::$currentUserId;
		}
	}

	if ( ! function_exists( 'wp_send_json_error' ) ) {
		function wp_send_json_error( $data, $status_code = null ) {
			BaseAjaxHandlerTest::$lastJsonError = array(
				'data'   => $data,
				'status' => $status_code,
			);

			return null;
		}
	}

	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = null ) {
			return $text;
		}
	}

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			$key = strtolower( (string) $key );

			return \preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return $value;
		}
	}
}

namespace EtchFusionSuite\Tests {

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use PHPUnit\Framework\TestCase;

final class BaseAjaxHandlerTest extends TestCase {
	public static bool $nonceValid = true;
	public static bool $capabilityGranted = true;
	public static int $currentUserId = 1;
	public static ?array $lastJsonError = null;

	/** @var array<int, array{success: bool, user: string, context: string}> */
	public static array $authAttempts = array();

	/** @var array<int, array{user: int, channel: string, action: string}> */
	public static array $authorizationFailures = array();

	protected function setUp(): void {
		parent::setUp();

		self::$nonceValid            = true;
		self::$capabilityGranted     = true;
		self::$currentUserId         = 42;
		self::$lastJsonError         = null;
		self::$authAttempts          = array();
		self::$authorizationFailures = array();
		$_REQUEST['action']          = 'efs_test_action';
	}

	protected function tearDown(): void {
		parent::tearDown();

		unset( $_REQUEST['action'] );
	}

	public function test_verify_request_rejects_invalid_nonce(): void {
		self::$nonceValid        = false;
		self::$capabilityGranted = true;

		$audit_logger = new StubAuditLogger();
		$handler      = new StubAjaxHandler( $audit_logger );

		$result = $handler->verifyRequestPublic( 'manage_options' );

		$this->assertFalse( $result, 'verify_request() should return false when nonce verification fails.' );
		$this->assertSame(
			array(
				'message' => 'The request could not be authenticated. Please refresh the page and try again.',
				'code'    => 'invalid_nonce',
			),
			self::$lastJsonError['data'],
			'Nonce failures should trigger the invalid_nonce JSON response.'
		);
		$this->assertSame( 401, self::$lastJsonError['status'], 'Nonce failure should set HTTP 401 status.' );
		$this->assertSame(
			array(
				array(
					'success' => false,
					'user'    => 'user_42',
					'context' => 'nonce',
				),
			),
			self::$authAttempts,
			'Nonce failure should be logged via audit logger.'
		);
		$this->assertSame( array(), self::$authorizationFailures, 'Nonce failure should not log authorization failures.' );
	}

	public function test_verify_request_rejects_when_capability_missing(): void {
		self::$nonceValid        = true;
		self::$capabilityGranted = false;

		$audit_logger = new StubAuditLogger();
		$handler      = new StubAjaxHandler( $audit_logger );

		$result = $handler->verifyRequestPublic( 'manage_options' );

		$this->assertFalse( $result, 'verify_request() should return false when capability check fails.' );
		$this->assertSame(
			array(
				'message' => 'You do not have permission to perform this action.',
				'code'    => 'forbidden',
			),
			self::$lastJsonError['data'],
			'Capability failures should trigger the forbidden JSON response.'
		);
		$this->assertSame( 403, self::$lastJsonError['status'], 'Capability failure should set HTTP 403 status.' );
		$this->assertSame( array(), self::$authAttempts, 'Capability failure should not record a successful auth attempt.' );
		$this->assertSame(
			array(
				array(
					'user'    => 42,
					'channel' => 'ajax_request',
					'action'  => 'efs_test_action',
				),
			),
			self::$authorizationFailures,
			'Capability failure should be registered via audit logger.'
		);
	}

	public function test_verify_request_passes_when_nonce_and_capability_valid(): void {
		self::$nonceValid        = true;
		self::$capabilityGranted = true;

		$audit_logger = new StubAuditLogger();
		$handler      = new StubAjaxHandler( $audit_logger );

		$result = $handler->verifyRequestPublic( 'manage_options' );

		$this->assertTrue( $result, 'verify_request() should return true when all checks pass.' );
		$this->assertNull( self::$lastJsonError, 'Successful verification should not send a JSON error response.' );
		$this->assertSame(
			array(
				array(
					'success' => true,
					'user'    => 'user_42',
					'context' => 'nonce',
				),
			),
			self::$authAttempts,
			'Successful authentication should be logged.'
		);
		$this->assertSame( array(), self::$authorizationFailures, 'No authorization failures should be logged on success.' );
	}
}

final class StubAjaxHandler extends EFS_Base_Ajax_Handler {
	public function __construct( StubAuditLogger $audit_logger ) {
		parent::__construct( null, null, $audit_logger );
	}

	protected function register_hooks() {
		// Hooks are not required for these tests.
	}

	public function verifyRequestPublic( string $capability ): bool {
		return $this->verify_request( $capability );
	}
}

final class StubAuditLogger {
	public function log_authentication_attempt( bool $success, string $user_id, string $context ): void {
		BaseAjaxHandlerTest::$authAttempts[] = array(
			'success' => $success,
			'user'    => $user_id,
			'context' => $context,
		);
	}

	public function log_authorization_failure( int $user_id, string $channel, string $action ): void {
		BaseAjaxHandlerTest::$authorizationFailures[] = array(
			'user'    => $user_id,
			'channel' => $channel,
			'action'  => $action,
		);
	}
}

}
