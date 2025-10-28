<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Ajax\EFS_Base_Ajax_Handler;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Security\EFS_CORS_Manager;
use Bricks2Etch\Security\EFS_Environment_Detector;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Security\EFS_Rate_Limiter;
use Bricks2Etch\Security\EFS_Security_Headers;

class SecurityTest extends \WP_UnitTestCase {
    /** @var \Bricks2Etch\Container\EFS_Service_Container */
    private $container;

    /**
     * Tracks whether a custom wp_die handler is registered.
     *
     * @var bool
     */
    private $wp_die_handler_registered = false;

    /**
     * Captures the last payload passed to wp_die.
     *
     * @var mixed
     */
    private $last_wp_die_payload;

    /**
     * Captures the last buffered output emitted before wp_die.
     *
     * @var string|null
     */
    private $last_wp_json_output;

    protected function setUp(): void {
        parent::setUp();

        $this->container = \efs_container();

        delete_option( 'efs_cors_allowed_origins' );
        delete_option( 'efs_security_settings' );
        delete_option( 'efs_security_log' );
    }

    protected function tearDown(): void {
        if ( $this->wp_die_handler_registered ) {
            remove_filter( 'wp_die_handler', array( $this, 'filter_wp_die_handler' ) );
            $this->wp_die_handler_registered = false;
        }

        parent::tearDown();
    }

    /**
     * Register custom wp_die handler to capture JSON payloads during AJAX responses.
     */
    private function register_wp_die_interceptor(): void {
        if ( $this->wp_die_handler_registered ) {
            return;
        }

        add_filter( 'wp_die_handler', array( $this, 'filter_wp_die_handler' ) );
        $this->wp_die_handler_registered = true;
    }

    /**
     * Filter callback returning the callable used by wp_die.
     *
     * @return callable
     */
    public function filter_wp_die_handler() {
        return array( $this, 'wp_die_handler' );
    }

    /**
     * Custom wp_die handler that records payloads for assertions.
     *
     * @param mixed $message Message passed to wp_die.
     * @return never
     * @throws \RuntimeException Always thrown to stop execution flow.
     */
    public function wp_die_handler( $message ) {
        if ( ob_get_level() > 0 ) {
            $this->last_wp_json_output = ob_get_contents();
            ob_clean();
        }

        $this->last_wp_die_payload = $message;
        throw new \RuntimeException( 'wp_die' );
    }

    public function test_cors_manager_returns_default_origins_when_not_configured(): void {
        /** @var EFS_CORS_Manager $manager */
        $manager = $this->container->get( 'cors_manager' );

        $origins = $manager->get_allowed_origins();

        $this->assertContains( 'http://localhost:8888', $origins );
        $this->assertContains( 'http://localhost:8889', $origins );
    }

    public function test_rate_limiter_enforces_limit_within_window(): void {
        /** @var EFS_Rate_Limiter $rateLimiter */
        $rateLimiter = $this->container->get( 'rate_limiter' );

        $identifier = 'security-test-' . wp_rand();
        $action     = 'unit_security_limit';
        $limit      = 5;
        $window     = 60;

        $transientKey = 'efs_rate_limit_' . $action . '_' . md5( $identifier );
        delete_transient( $transientKey );

        for ( $i = 0; $i < $limit; $i++ ) {
            $rateLimiter->record_request( $identifier, $action, $window );
        }

        $this->assertTrue(
            $rateLimiter->check_rate_limit( $identifier, $action, $limit, $window ),
            'Rate limiter should block once the limit is reached.'
        );
    }

    public function test_rate_limiter_remaining_attempts_and_reset(): void {
        $rate_limiter = new EFS_Rate_Limiter();
        $identifier   = 'unit-rate-' . wp_rand();
        $action       = 'unit_window_test';

        delete_transient( 'efs_rate_limit_' . $action . '_' . md5( $identifier ) );

        $rate_limiter->record_request( $identifier, $action, 60 );
        $remaining = $rate_limiter->get_remaining_attempts( $identifier, $action, 5, 60 );

        $this->assertSame( 4, $remaining, 'Recording a request should decrement remaining attempts.' );

        $rate_limiter->reset_limit( $identifier, $action );
        $this->assertSame( 5, $rate_limiter->get_remaining_attempts( $identifier, $action, 5, 60 ), 'Reset should restore full allowance.' );
    }

    public function test_input_validator_rejects_invalid_url(): void {
        /** @var EFS_Input_Validator $validator */
        $validator = $this->container->get( 'input_validator' );

        $this->expectException( \InvalidArgumentException::class );
        $validator->validate_url( 'nota:url', true );
    }

    public function test_input_validator_records_field_context_on_failure(): void {
        $data  = array( 'endpoint' => 'nota:url' );
        $rules = array( 'endpoint' => array( 'type' => 'url', 'required' => true ) );

        try {
            EFS_Input_Validator::validate_request_data( $data, $rules );
            $this->fail( 'Validation should throw for invalid URL.' );
        } catch ( \InvalidArgumentException $exception ) {
            $details = EFS_Input_Validator::get_last_error_details();
            $this->assertSame( 'url_invalid_format', $details['code'], 'Validator should expose precise error code.' );
            $this->assertSame( 'endpoint', $details['context']['field'], 'Failing field should be recorded in context.' );
        }
    }

    public function test_security_headers_builds_admin_csp_policy(): void {
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }

        set_current_screen( 'dashboard' );

        $headers = new EFS_Security_Headers();
        $csp     = $headers->get_csp_policy();

        $this->assertStringContainsString( "script-src", $csp );
        $this->assertStringContainsString( "'unsafe-inline'", $csp );
    }

    public function test_audit_logger_persists_security_events(): void {
        /** @var EFS_Audit_Logger $logger */
        $logger = $this->container->get( 'audit_logger' );

        $logger->log_security_event( 'unit_test_event', 'low', 'Unit test event recorded.' );

        $stored = get_option( 'efs_security_log', array() );
        $this->assertNotEmpty( $stored );
        $this->assertSame( 'unit_test_event', $stored[0]['event_type'] );
    }

    public function test_environment_detector_identifies_local_environment(): void {
        /** @var EFS_Environment_Detector $detector */
        $detector = $this->container->get( 'environment_detector' );

        $original_host = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'test.local';

        $this->assertTrue( $detector->is_local_environment() );

        if ( null === $original_host ) {
            unset( $_SERVER['HTTP_HOST'] );
        } else {
            $_SERVER['HTTP_HOST'] = $original_host;
        }
    }

    public function test_cors_manager_disallows_unknown_origin(): void {
        /** @var EFS_CORS_Manager $manager */
        $manager = $this->container->get( 'cors_manager' );

        $this->assertFalse( $manager->is_origin_allowed( 'https://untrusted.example.com' ) );
        $this->assertTrue( $manager->is_origin_allowed( 'http://localhost:8888/' ), 'Trailing slash should be ignored for allowed origins.' );
    }

    public function test_security_headers_skip_options_requests(): void {
        $headers = new EFS_Security_Headers();

        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $this->assertFalse( $headers->should_add_headers(), 'OPTIONS requests should bypass security headers.' );

        unset( $_SERVER['REQUEST_METHOD'] );
    }

    public function test_ajax_handler_rate_limit_integration_allows_within_limit(): void {
        $rate_limiter = new Stub_Rate_Limiter( false );
        $handler      = new Stub_Ajax_Handler( $rate_limiter, $this->container->get( 'input_validator' ) );

        $result = $handler->public_check_rate_limit( 'unit_action', 5, 60 );

        $this->assertTrue( $result );
        $this->assertSame( 1, $rate_limiter->record_calls, 'Successful checks should record the request.' );
    }

    public function test_ajax_handler_rate_limit_rejects_when_exceeded(): void {
        $this->register_wp_die_interceptor();

        $rate_limiter = new Stub_Rate_Limiter( true );
        $handler      = new Stub_Ajax_Handler( $rate_limiter, $this->container->get( 'input_validator' ) );

        ob_start();
        try {
            $handler->public_check_rate_limit( 'limited_action', 1, 60 );
            $this->fail( 'Expected wp_die when rate limit exceeded.' );
        } catch ( \RuntimeException $exception ) {
            $this->assertSame( 'wp_die', $exception->getMessage() );
            $payload = json_decode( (string) $this->last_wp_json_output, true );
            $this->assertSame( 'rate_limit_exceeded', $payload['data']['code'] ?? null );
        } finally {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
        }
    }

    public function test_ajax_handler_validate_input_returns_validated_payload(): void {
        $handler = new Stub_Ajax_Handler( new Stub_Rate_Limiter( false ), $this->container->get( 'input_validator' ) );

        $payload = array( 'api_key' => str_repeat( 'A', 20 ) );
        $rules   = array( 'api_key' => array( 'type' => 'api_key' ) );

        $validated = $handler->public_validate_input( $payload, $rules );

        $this->assertSame( $payload['api_key'], $validated['api_key'] );
    }

    public function test_ajax_handler_validate_input_emits_context_on_failure(): void {
        $this->register_wp_die_interceptor();

        $handler = new Stub_Ajax_Handler( new Stub_Rate_Limiter( false ), $this->container->get( 'input_validator' ) );

        ob_start();
        try {
            $handler->public_validate_input(
                array( 'endpoint' => 'nota:url' ),
                array( 'endpoint' => array( 'type' => 'url' ) )
            );
            $this->fail( 'Expected wp_die for invalid payload.' );
        } catch ( \RuntimeException $exception ) {
            $this->assertSame( 'wp_die', $exception->getMessage() );
            $payload = json_decode( (string) $this->last_wp_json_output, true );

            $this->assertSame( 'invalid_input', $payload['data']['code'] ?? null );
            $this->assertSame( 'endpoint', $payload['data']['details']['context']['field'] ?? null );
        } finally {
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
        }
    }
}

class Stub_Rate_Limiter extends EFS_Rate_Limiter {

    /** @var bool */
    public $force_exceeded;

    /** @var int */
    public $check_calls = 0;

    /** @var int */
    public $record_calls = 0;

    public function __construct( bool $force_exceeded ) {
        $this->force_exceeded = $force_exceeded;
    }

    public function check_rate_limit( $identifier, $action, $limit = 60, $window = 60 ) {
        $this->check_calls++;
        return $this->force_exceeded;
    }

    public function record_request( $identifier, $action, $window = 60 ) {
        $this->record_calls++;
    }

    public function get_identifier() {
        return 'stub-rate';
    }
}

class Stub_Ajax_Handler extends EFS_Base_Ajax_Handler {

    protected function register_hooks() {}

    public function public_check_rate_limit( $action, $limit = 60, $window = 60 ) {
        return $this->check_rate_limit( $action, $limit, $window );
    }

    public function public_validate_input( array $data, array $rules ) {
        return $this->validate_input( $data, $rules );
    }
}
