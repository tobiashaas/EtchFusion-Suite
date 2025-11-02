<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\WordPress;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Custom_Fields_Migrator;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Security\EFS_Environment_Detector;
use Bricks2Etch\Security\EFS_Input_Validator;

/**
 * @coversNothing
 * @psalm-suppress PropertyNotSetInConstructor
 */
class StrictComparisonTest extends \WP_UnitTestCase {

    /**
     * @var EFS_Input_Validator
     */
    private $validator;

    /**
     * @var EFS_Environment_Detector
     */
    private $environmentDetector;

    public function setUp(): void {
        parent::setUp();

        $this->validator            = new EFS_Input_Validator();
        $this->environmentDetector  = new EFS_Environment_Detector();
    }

    /**
     * @dataProvider urlSchemeProvider
     */
    public function test_validate_url_with_strict_scheme_matching( string $url, bool $shouldPass ): void {
        if ( $shouldPass ) {
            $validated = $this->validator->validate_url( $url );
            $this->assertSame( esc_url_raw( $url ), $validated );
            return;
        }

        $this->expectException( \InvalidArgumentException::class );
        $this->validator->validate_url( $url );
    }

    public function urlSchemeProvider(): array {
        return array(
            'http'          => array( 'http://example.com', true ),
            'https'         => array( 'https://example.com', true ),
            'HTTP uppercase' => array( 'HTTP://example.com', true ),
            'HTTPS uppercase' => array( 'HTTPS://example.com', true ),
            'FTP scheme'    => array( 'ftp://example.com', false ),
            'numeric scheme' => array( '123://example.com', false ),
            'boolean scheme true' => array( 'true://example.com', false ),
        );
    }

    /**
     * @dataProvider arrayKeyProvider
     */
    public function test_validate_array_with_strict_key_matching( array $input, array $allowedKeys, bool $shouldPass ): void {
        if ( $shouldPass ) {
            $validated = $this->validator->validate_array( $input, $allowedKeys );
            $this->assertSame( array_keys( $input ), array_keys( $validated ) );
            return;
        }

        $this->expectException( \InvalidArgumentException::class );
        $this->validator->validate_array( $input, $allowedKeys );
    }

    public function arrayKeyProvider(): array {
        return array(
            'valid string keys'        => array( array( 'name' => 'Alice', 'email' => 'alice@example.com' ), array( 'name', 'email' ), true ),
            'numeric keys rejected'    => array( array( 0 => 'foo', 1 => 'bar' ), array( '0', '1' ), false ),
            'string numeric mismatch'  => array( array( '0' => 'foo' ), array( 0 ), false ),
            'boolean key rejected'     => array( array( true => 'value' ), array( 'true' ), false ),
        );
    }

    /**
     * @dataProvider environmentProvider
     */
    public function test_is_development_with_strict_env_type_matching( string $envType, bool $expected ): void {
        $callback = static function () use ( $envType ) {
            return $envType;
        };

        add_filter( 'pre_option_wp_environment_type', $callback );

        $this->assertSame( $expected, $this->environmentDetector->is_development() );

        remove_filter( 'pre_option_wp_environment_type', $callback );
    }

    public function environmentProvider(): array {
        return array(
            'local'       => array( 'local', true ),
            'development' => array( 'development', true ),
            'production'  => array( 'production', false ),
            'staging'     => array( 'staging', false ),
            'numeric'     => array( '0', false ),
            'boolean true' => array( 'true', false ),
        );
    }

    /**
     * @dataProvider auditSeverityProvider
     */
    public function test_audit_logger_sanitize_severity_strict_matching( $inputSeverity, string $expected ): void {
        $logger = new EFS_Audit_Logger();

        $reflection = new \ReflectionClass( EFS_Audit_Logger::class );
        $method     = $reflection->getMethod( 'sanitize_severity' );
        $method->setAccessible( true );

        $this->assertSame( $expected, $method->invoke( $logger, $inputSeverity ) );
    }

    public function auditSeverityProvider(): array {
        return array(
            'lowercase valid'  => array( 'high', 'high' ),
            'uppercase valid'  => array( 'CRITICAL', 'critical' ),
            'numeric'          => array( 1, 'low' ),
            'boolean true'     => array( true, 'low' ),
        );
    }

    /**
     * @dataProvider sensitiveKeyProvider
     */
    public function test_audit_logger_is_sensitive_key_strict_matching( $key, bool $expected ): void {
        $logger = new EFS_Audit_Logger();

        $reflection = new \ReflectionClass( EFS_Audit_Logger::class );
        $method     = $reflection->getMethod( 'is_sensitive_key' );
        $method->setAccessible( true );

        $this->assertSame( $expected, $method->invoke( $logger, $key ) );
    }

    public function sensitiveKeyProvider(): array {
        return array(
            'api_key'     => array( 'api_key', true ),
            'API_KEY'     => array( 'API_KEY', true ),
            'partial match' => array( 'my_api_key', false ),
            'numeric zero' => array( 0, false ),
            'boolean true' => array( true, false ),
        );
    }

    /**
     * @dataProvider coreMetaKeyProvider
     */
    public function test_custom_fields_migrator_filters_core_keys_strictly( string $meta_key, bool $expected_present ): void {
        $error_handler = new EFS_Error_Handler();
        $api_client    = new EFS_API_Client( $error_handler );

        $migrator = new EFS_Custom_Fields_Migrator( $error_handler, $api_client );

        $reflection = new \ReflectionClass( EFS_Custom_Fields_Migrator::class );
        $method     = $reflection->getMethod( 'get_post_meta' );
        $method->setAccessible( true );

        $meta_rows = array(
            (object) array( 'meta_key' => 'custom_key', 'meta_value' => maybe_serialize( 'custom' ) ),
            (object) array( 'meta_key' => $meta_key, 'meta_value' => maybe_serialize( 'value-under-test' ) ),
        );

        $original_wpdb   = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->create_wpdb_stub( $meta_rows );

        try {
            $result = $method->invoke( $migrator, 123 );
        } finally {
            $GLOBALS['wpdb'] = $original_wpdb;
        }

        $this->assertArrayHasKey( 'custom_key', $result, 'Baseline custom key should remain.' );

        if ( $expected_present ) {
            $this->assertArrayHasKey( $meta_key, $result );
            $this->assertSame( 'value-under-test', $result[ $meta_key ] );
        } else {
            $this->assertArrayNotHasKey( $meta_key, $result );
        }
    }

    public function coreMetaKeyProvider(): array {
        return array(
            'custom key (kept)'     => array( 'custom_key', true ),
            '_edit_lock (skip)'     => array( '_edit_lock', false ),
            '_wp_old_slug (skip)'   => array( '_wp_old_slug', false ),
            'numeric string kept'   => array( '0', true ),
            'boolean string kept'   => array( 'true', true ),
        );
    }

    /**
     * @dataProvider httpMethodProvider
     */
    public function test_api_client_request_body_with_strict_method_matching( string $method, bool $expectsBody ): void {
        $error_handler = new EFS_Error_Handler();

        $client = new EFS_API_Client( $error_handler );

        $captured_args = null;
        $response_body = wp_json_encode( array( 'ok' => true ) );

        $callback = static function ( $preempt, $args ) use ( &$captured_args, $response_body ) {
            $captured_args = $args;
            return array( 'response' => array( 'code' => 200 ), 'body' => $response_body );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $result = $this->invokeSendRequest( $client, 'https://example.com', 'efs_example', '/demo', $method, array( 'payload' => true ) );

        remove_filter( 'pre_http_request', $callback, 10 );

        $this->assertIsArray( $result );
        $this->assertSame( array( 'ok' => true ), $result );

        $this->assertIsArray( $captured_args );

        if ( $expectsBody ) {
            $this->assertArrayHasKey( 'body', $captured_args );
        } else {
            $this->assertArrayNotHasKey( 'body', $captured_args );
        }
    }

    public function httpMethodProvider(): array {
        return array(
            'POST body expected'   => array( 'POST', true ),
            'PUT body expected'    => array( 'PUT', true ),
            'PATCH body expected'  => array( 'PATCH', true ),
            'GET no body'          => array( 'GET', false ),
            'DELETE no body'       => array( 'DELETE', false ),
            'lowercase post'       => array( 'post', false ),
            'numeric method'       => array( '1', false ),
        );
    }

    /**
     * @dataProvider inArrayEdgeCaseProvider
     */
    public function test_in_array_edge_cases_remain_distinct( $needle, array $haystack, bool $expected ): void {
        $result = in_array( $needle, $haystack, true );
        $this->assertSame( $expected, $result );
    }

    public function inArrayEdgeCaseProvider(): array {
        return array(
            'empty string vs zero'      => array( '', array( 0, false, null ), false ),
            'zero vs string zero'       => array( 0, array( '0', '', false ), false ),
            'numeric string vs integer' => array( '1', array( 1, 2, 3 ), false ),
            'integer vs numeric string' => array( 1, array( '1', '2', '3' ), false ),
            'boolean true vs yes'       => array( true, array( 'yes', 'no' ), false ),
        );
    }

    private function invokeSendRequest( EFS_API_Client $client, string $url, string $apiKey, string $endpoint, string $method, array $data = null ) {
        $reflection = new \ReflectionClass( EFS_API_Client::class );
        $methodRef  = $reflection->getMethod( 'send_request' );
        $methodRef->setAccessible( true );

        return $methodRef->invoke( $client, $url, $apiKey, $endpoint, $method, $data );
    }

    private function create_wpdb_stub( array $results ) {
        return new class( $results ) {
            public $postmeta = 'wp_postmeta';

            private $results;

            public function __construct( array $results ) {
                $this->results = $results;
            }

            public function prepare( $query, $post_id ) {
                return str_replace( '%d', (string) (int) $post_id, $query );
            }

            public function get_results( $query ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
                return $this->results;
            }
        };
    }
}
