<?php
/**
 * Migration Token Manager for Etch Fusion Suite
 *
 * Elegant migration system with domain-embedded tokens
 * Generates secure migration URLs with embedded authentication
 */

namespace Bricks2Etch\Core;

use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Token_Manager {

	/**
	 * Error handler instance
	 */
	private $error_handler;

	/**
	 * Migration repository instance
	 */
	private $migration_repository;

	/**
	 * Token expiration time (8 hours)
	 */
	const TOKEN_EXPIRATION = 8 * HOUR_IN_SECONDS;

	/**
	 * Option key storing the JWT signing secret
	 */
	private const TOKEN_SECRET_OPTION = 'efs_migration_jwt_secret';

	/**
	 * Constructor
	 *
	 * @param Migration_Repository_Interface|null $migration_repository
	 */
	public function __construct( Migration_Repository_Interface $migration_repository = null ) {
		$this->error_handler = new \Bricks2Etch\Core\EFS_Error_Handler();

		if ( null === $migration_repository ) {
			$migration_repository = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
		}

		$this->migration_repository = $migration_repository;
	}

	/**
	 * Generate migration token (for API endpoint)
	 *
	 * @param string $target_url Target URL
	 * @param int $expiration_seconds Token expiration time in seconds
	 * @return array Token data with token, expires, and domain
	 */
	public function generate_migration_token( $target_url = null, $expiration_seconds = null ) {
		if ( empty( $expiration_seconds ) ) {
			$expiration_seconds = self::TOKEN_EXPIRATION;
		}

		$issued_at         = time();
		$expires_timestamp = $issued_at + (int) $expiration_seconds;
		$site_url          = home_url();
		$target_url        = $target_url ? esc_url_raw( $target_url ) : $site_url;

		$payload = array(
			'target_url' => $target_url,
			'domain'     => $site_url,
			'iat'        => $issued_at,
			'exp'        => $expires_timestamp,
		);

		$token = $this->encode_jwt( $payload );

		$this->store_token( $token );

		return array(
			'token'      => $token,
			'expires'    => $expires_timestamp,
			'domain'     => $site_url,
			'created_at' => current_time( 'mysql' ),
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
			'payload'    => $payload,
		);
	}

	/**
	 * Generate migration URL with embedded token
	 *
	 * @param string $target_domain Target domain
	 * @param int $expiration_seconds Token expiration time in seconds
	 * @return string Migration URL
	 */
	public function generate_migration_url( $target_domain = null, $expiration_seconds = null ) {
		$token_data = $this->generate_migration_token( $target_domain, $expiration_seconds );

		return $token_data['token'];
	}

	/**
	 * Store token with expiration
	 */
	private function store_token( $token ) {
		$decoded = $this->decode_jwt( $token );

		if ( is_wp_error( $decoded ) ) {
			$this->error_handler->log_error(
				'token_store_failed',
				array(
					'reason' => $decoded->get_error_message(),
				)
			);

			return;
		}

		$payload           = $decoded['payload'];
		$expires_timestamp = isset( $payload['exp'] ) ? (int) $payload['exp'] : ( time() + self::TOKEN_EXPIRATION );
		$ttl               = max( 60, $expires_timestamp - time() );

		$token_data = array(
			'token'      => $token,
			'payload'    => $payload,
			'created_at' => current_time( 'mysql' ),
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
			'domain'     => $payload['domain'] ?? home_url(),
		);

		$this->migration_repository->save_token_data( $token_data );
		$this->migration_repository->save_token_value( $token );

		set_transient( 'efs_token_' . substr( hash( 'sha256', $token ), 0, 16 ), $token_data, $ttl );
	}

	/**
	 * Validate migration token
	 *
	 * @param string $token Token to validate
	 * @return array|\WP_Error
	 */
	public function validate_migration_token( $token ) {
		$decoded = $this->decode_jwt( $token );

		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$payload           = $decoded['payload'];
		$expires_timestamp = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;

		if ( empty( $expires_timestamp ) ) {
			return new \WP_Error( 'invalid_token', 'Token payload missing expiration.' );
		}

		if ( time() > $expires_timestamp ) {
			return new \WP_Error( 'token_expired', 'Migration token has expired.' );
		}

		$stored_token = $this->migration_repository->get_token_value();

		if ( empty( $stored_token ) ) {
			return new \WP_Error( 'invalid_token', 'No migration token found. Please generate a new key.' );
		}

		if ( ! hash_equals( $stored_token, $token ) ) {
			return new \WP_Error( 'invalid_token', 'Invalid migration token. Tokens do not match.' );
		}

		return $payload;
	}

	/**
	 * Get migration token data
	 */
	public function get_token_data() {
		return $this->migration_repository->get_token_data();
	}

	/**
	 * Generate migration QR code data
	 */
	public function generate_qr_data( $target_domain = null ) {
		$token_data = $this->generate_migration_token( $target_domain );

		return array(
			'token'      => $token_data['token'],
			'qr_data'    => $token_data['token'],
			'expires_in' => self::TOKEN_EXPIRATION,
			'expires_at' => $token_data['expires_at'],
		);
	}

	/**
	 * Create migration shortcut
	 */
	public function create_migration_shortcut( $target_domain ) {
		$token_data = $this->generate_migration_token( $target_domain );

		return array(
			'token'      => $token_data['token'],
			'qr_data'    => $token_data['token'],
			'expires_at' => $token_data['expires_at'],
		);
	}

	/**
	 * Encode payload into JWT string
	 *
	 * @param array $payload JWT payload
	 * @return string
	 */
	private function encode_jwt( array $payload ) {
		$header = array(
			'alg' => 'HS256',
			'typ' => 'JWT',
		);

		$segments = array(
			$this->base64url_encode( wp_json_encode( $header ) ),
			$this->base64url_encode( wp_json_encode( $payload ) ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $this->get_secret_key(), true );

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Decode a JWT token
	 *
	 * @param string $token JWT string
	 * @return array|\WP_Error
	 */
	private function decode_jwt( $token ) {
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return new \WP_Error( 'invalid_token', 'Token is empty.' );
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_token', 'Malformed token provided.' );
		}

		list( $encoded_header, $encoded_payload, $encoded_signature ) = $parts;

		$header = json_decode( $this->base64url_decode( $encoded_header ) ?? '', true );

		if ( ! is_array( $header ) || ( $header['alg'] ?? '' ) !== 'HS256' ) {
			return new \WP_Error( 'invalid_token', 'Unsupported token algorithm.' );
		}

		$payload_json = $this->base64url_decode( $encoded_payload );
		$payload      = json_decode( $payload_json ?? '', true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_token', 'Invalid token payload.' );
		}

		$signature          = $this->base64url_decode( $encoded_signature );
		$expected_signature = hash_hmac( 'sha256', $encoded_header . '.' . $encoded_payload, $this->get_secret_key(), true );

		if ( ! $signature || ! hash_equals( $expected_signature, $signature ) ) {
			return new \WP_Error( 'invalid_token', 'Token signature mismatch.' );
		}

		return array(
			'header'  => $header,
			'payload' => $payload,
		);
	}

	/**
	 * Decode migration key locally without persisting state.
	 *
	 * @param string $token Migration key token.
	 * @return array|\WP_Error
	 */
	public function decode_migration_key_locally( $token ) {
		if ( ! is_string( $token ) || '' === trim( $token ) ) {
			return new \WP_Error( 'invalid_token', 'Token is empty.' );
		}

		$parts = explode( '.', $token );

		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_token', 'Malformed token provided.' );
		}

		list( $encoded_header, $encoded_payload ) = $parts;

		$header_json  = $this->base64url_decode( $encoded_header );
		$payload_json = $this->base64url_decode( $encoded_payload );
		$header       = is_string( $header_json ) ? json_decode( $header_json, true ) : null;
		$payload      = is_string( $payload_json ) ? json_decode( $payload_json, true ) : null;

		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'invalid_token', 'Unable to parse token payload.' );
		}

		return array(
			'header'  => is_array( $header ) ? $header : array(),
			'payload' => $payload,
		);
	}

	/**
	 * Retrieve or create JWT signing secret
	 *
	 * @return string
	 */
	private function get_secret_key() {
		$secret = get_option( self::TOKEN_SECRET_OPTION );

		if ( empty( $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::TOKEN_SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Perform base64url encoding
	 *
	 * @param string $data Raw data
	 * @return string
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Perform base64url decoding
	 *
	 * @param string $data Base64url encoded string
	 * @return string|null
	 */
	private function base64url_decode( $data ) {
		if ( ! is_string( $data ) ) {
			return null;
		}

		$remainder = strlen( $data ) % 4;

		if ( $remainder > 0 ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}

		$decoded = base64_decode( strtr( $data, '-_', '+/' ) );

		return false === $decoded ? null : $decoded;
	}
}
