<?php
/**
 * Migration Token Manager for Etch Fusion Suite
 *
 * Elegant migration system with domain-embedded tokens
 * Generates secure migration URLs with embedded authentication
 */

namespace Bricks2Etch\Core;

use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

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
	public function __construct( ?Migration_Repository_Interface $migration_repository = null ) {
		$this->error_handler = new \Bricks2Etch\Core\EFS_Error_Handler();

		if ( null === $migration_repository ) {
			$migration_repository = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
		}

		$this->migration_repository = $migration_repository;
	}

	/**
	 * Generate migration token (for API endpoint)
	 *
	 * @param string|null $target_url        Target URL (defaults to current site URL).
	 * @param int|null    $expiration_seconds Token expiration in seconds.
	 * @param string|null $source_url        Source site URL. When provided (reverse-generation
	 *                                       flow), used as the token payload `domain` so that
	 *                                       check_source_origin() on import endpoints validates
	 *                                       X-EFS-Source-Origin headers from this caller.
	 *                                       Defaults to home_url() (admin-generate flow).
	 * @return array Token data with token, expires, and domain.
	 */
	public function generate_migration_token( $target_url = null, $expiration_seconds = null, $source_url = null ) {
		if ( empty( $expiration_seconds ) ) {
			$expiration_seconds = self::TOKEN_EXPIRATION;
		}

		$issued_at         = time();
		$expires_timestamp = $issued_at + (int) $expiration_seconds;
		$site_url          = home_url();
		$target_url        = $target_url ? esc_url_raw( $target_url ) : $site_url;
		$target_url        = $this->normalize_target_url( $target_url );
		$security          = $this->analyze_url_security( $target_url );
		$previous_token    = $this->migration_repository->get_token_value();
		$had_previous      = ! empty( $previous_token );

		// Reverse-generation flow: source_url is the calling site; store it as `domain`
		// so that check_source_origin() can match X-EFS-Source-Origin on import endpoints.
		// Falls back to home_url() for the admin-generate flow.
		$domain = ( is_string( $source_url ) && '' !== trim( $source_url ) )
			? esc_url_raw( trim( $source_url ) )
			: $site_url;

		$payload = array(
			'target_url' => $target_url,
			'domain'     => $domain,
			'iat'        => $issued_at,
			'exp'        => $expires_timestamp,
			'jti'        => wp_generate_uuid4(),
		);

		$token = $this->encode_jwt( $payload );

		if ( $had_previous && ! hash_equals( (string) $previous_token, $token ) ) {
			$this->invalidate_token_transient( $previous_token );
		}

		$this->store_token( $token );
		$migration_url = $this->build_migration_url( $target_url, $token );

		$message = $had_previous
			? __( 'New migration key generated. Previous keys are now invalid.', 'etch-fusion-suite' )
			: __( 'Migration key generated.', 'etch-fusion-suite' );

		return array(
			'token'                      => $token,
			'expires'                    => $expires_timestamp,
			'domain'                     => $domain,
			'created_at'                 => current_time( 'mysql' ),
			'expires_at'                 => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
			'expiration_seconds'         => (int) $expiration_seconds,
			'migration_url'              => $migration_url,
			'https_warning'              => (bool) $security['https_warning'],
			'security_warning'           => (string) $security['warning_message'],
			'security'                   => $security,
			'treat_as_password_note'     => __( 'Treat this migration key like a password. Do not share it publicly.', 'etch-fusion-suite' ),
			'invalidated_previous_token' => $had_previous,
			'message'                    => $message,
			'payload'                    => $payload,
		);
	}

	/**
	 * Generate a one-time pairing code stored as a transient (15 min TTL).
	 * Returns the raw code (8 hex chars, displayed as "A3F7-K2M9").
	 *
	 * @return string
	 */
	public function generate_pairing_code(): string {
		$code = bin2hex( random_bytes( 4 ) ); // 8 hex chars, e.g. "a3f7k2m9"
		set_transient( 'efs_pairing_code', $code, 15 * MINUTE_IN_SECONDS );
		return $code;
	}

	/**
	 * Validate and consume a pairing code (one-time use).
	 * Normalises input: strips dash, lowercases before comparing.
	 *
	 * @param string $code Pairing code to validate.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function validate_and_consume_pairing_code( string $code ) {
		$stored = get_transient( 'efs_pairing_code' );
		if ( false === $stored || '' === $stored ) {
			return new \WP_Error( 'invalid_pairing_code', 'No active pairing code. Please generate a new one on the target site.' );
		}
		$normalise = static fn( $v ) => strtolower( str_replace( '-', '', (string) $v ) );
		if ( ! hash_equals( $normalise( $stored ), $normalise( $code ) ) ) {
			return new \WP_Error( 'invalid_pairing_code', 'Invalid pairing code.' );
		}
		delete_transient( 'efs_pairing_code' ); // one-time use
		return true;
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

		return isset( $token_data['migration_url'] ) ? (string) $token_data['migration_url'] : '';
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
			'token'             => $token,
			'payload'           => $payload,
			'created_at'        => current_time( 'mysql' ),
			'expires_at'        => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
			'expires_timestamp' => $expires_timestamp,
			'issued_at'         => isset( $payload['iat'] ) ? (int) $payload['iat'] : time(),
			'expires_in'        => max( 0, $expires_timestamp - time() ),
			'token_hash'        => substr( hash( 'sha256', $token ), 0, 16 ),
			'domain'            => $payload['domain'] ?? home_url(),
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
	 * Get current migration key display data if a valid token is stored.
	 * Used to show the key and expiry on the Etch setup page after reload.
	 *
	 * @return array Empty if no valid token; else keys: migration_url, expires_at, expiration_seconds.
	 */
	public function get_current_migration_display_data() {
		$token_data = $this->get_token_data();
		$token      = isset( $token_data['token'] ) ? $token_data['token'] : '';
		$expires_ts = isset( $token_data['expires_timestamp'] ) ? (int) $token_data['expires_timestamp'] : 0;

		if ( '' === $token || $expires_ts <= 0 || time() >= $expires_ts ) {
			return array();
		}

		// The migration URL always points to this (target) site regardless of which
		// site's URL is stored in the token payload's `domain` field.
		$url        = $this->build_migration_url( home_url(), $token );
		$expires_at = isset( $token_data['expires_at'] ) ? (string) $token_data['expires_at'] : '';

		return array(
			'migration_url'      => $url,
			'expires_at'         => $expires_at,
			'expiration_seconds' => max( 0, $expires_ts - time() ),
		);
	}

	/**
	 * Revoke the currently stored migration key immediately.
	 *
	 * @return bool True when revocation state was persisted.
	 */
	public function revoke_current_migration_key(): bool {
		$current_token = $this->migration_repository->get_token_value();
		if ( '' !== $current_token ) {
			$this->invalidate_token_transient( (string) $current_token );
		}

		return $this->migration_repository->delete_token_data();
	}

	/**
	 * Generate migration QR code data
	 */
	public function generate_qr_data( $target_domain = null ) {
		$token_data = $this->generate_migration_token( $target_domain );

		return array(
			'token'      => $token_data['token'],
			'qr_data'    => $token_data['migration_url'],
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
			'qr_data'    => $token_data['migration_url'],
			'expires_at' => $token_data['expires_at'],
		);
	}

	/**
	 * Analyze URL transport security.
	 *
	 * @param string $url Target URL.
	 * @return array
	 */
	public function analyze_url_security( string $url ): array {
		$normalized = $this->normalize_target_url( $url );
		$parsed     = wp_parse_url( $normalized );
		$scheme     = isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : '';
		$host       = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';

		$is_https      = 'https' === $scheme;
		$is_local_host = in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
		$warn_https    = ! $is_https && ! $is_local_host;
		$warning       = $warn_https
			? __( 'Warning: This URL does not use HTTPS. Migration keys can be intercepted over insecure networks.', 'etch-fusion-suite' )
			: '';

		return array(
			'url'             => $normalized,
			'host'            => $host,
			'is_https'        => $is_https,
			'is_localhost'    => $is_local_host,
			'https_warning'   => $warn_https,
			'warning_message' => $warning,
		);
	}

	/**
	 * Build copy-friendly migration URL containing token query parameter.
	 *
	 * @param string $target_url Base URL.
	 * @param string $token      Migration token.
	 * @return string
	 */
	private function build_migration_url( string $target_url, string $token ): string {
		$base = untrailingslashit( $this->normalize_target_url( $target_url ) );
		return $base . '/wp-json/efs/v1/migrate?token=' . rawurlencode( $token );
	}

	/**
	 * Normalize target URL.
	 *
	 * @param string $target_url Raw URL.
	 * @return string
	 */
	private function normalize_target_url( string $target_url ): string {
		$target_url = esc_url_raw( $target_url );
		if ( '' === $target_url ) {
			return home_url();
		}

		$parsed = wp_parse_url( $target_url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return home_url();
		}

		return $target_url;
	}

	/**
	 * Delete transient associated with a token hash.
	 *
	 * @param string $token Token value.
	 * @return void
	 */
	private function invalidate_token_transient( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		delete_transient( 'efs_token_' . substr( hash( 'sha256', $token ), 0, 16 ) );
	}

	/**
	 * Encode payload into JWT string
	 *
	 * @param array $payload JWT payload
	 * @return string
	 */
	private function encode_jwt( array $payload ) {
		return JWT::encode( $payload, $this->get_secret_key(), 'HS256' );
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

		try {
			$decoded       = JWT::decode( $token, new Key( $this->get_secret_key(), 'HS256' ) );
			$payload_array = (array) $decoded;
			return array(
				'header'  => array( 'alg' => 'HS256', 'typ' => 'JWT' ),
				'payload' => $payload_array,
			);
		} catch ( ExpiredException $e ) {
			return new \WP_Error( 'token_expired', 'Migration token has expired.' );
		} catch ( SignatureInvalidException $e ) {
			return new \WP_Error( 'token_invalid', 'Token signature mismatch.' );
		} catch ( BeforeValidException $e ) {
			return new \WP_Error( 'token_invalid', 'Token is not yet valid.' );
		} catch ( \UnexpectedValueException $e ) {
			return new \WP_Error( 'invalid_token', 'Malformed token provided.' );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'token_decode_failed', $e->getMessage() );
		}
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

		$header_json  = JWT::urlsafeB64Decode( $encoded_header );
		$payload_json = JWT::urlsafeB64Decode( $encoded_payload );
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

}
