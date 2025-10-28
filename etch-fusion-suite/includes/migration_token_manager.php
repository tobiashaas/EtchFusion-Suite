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
	 * @param int $expiration_seconds Token expiration time in seconds
	 * @return array Token data with token, expires, and domain
	 */
	public function generate_migration_token( $expiration_seconds = null ) {
		if ( empty( $expiration_seconds ) ) {
			$expiration_seconds = self::TOKEN_EXPIRATION;
		}

		// Generate secure token
		$token = $this->generate_secure_token();

		// Store token with expiration
		$this->store_token( $token, $expiration_seconds );

		$current_timestamp = time();
		$expires_timestamp = $current_timestamp + $expiration_seconds;

		// Return token data
		return array(
			'token'      => $token,
			'expires'    => $expires_timestamp,
			'domain'     => home_url(),
			'created_at' => current_time( 'mysql' ),
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
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
		if ( empty( $target_domain ) ) {
			$target_domain = home_url();
		}

		if ( empty( $expiration_seconds ) ) {
			$expiration_seconds = self::TOKEN_EXPIRATION;
		}

		// Generate secure token
		$token = $this->generate_secure_token();

		// Store token with expiration
		$this->store_token( $token, $expiration_seconds );

		// Build migration URL (current site as base, target domain as parameter)
		$current_site_url  = home_url();
		$expires_timestamp = time() + $expiration_seconds;
		$migration_url     = add_query_arg(
			array(
				'domain'  => $target_domain,
				'token'   => $token,
				'expires' => $expires_timestamp,
			),
			$current_site_url
		);

		return $migration_url;
	}

	/**
	 * Generate secure migration token
	 */
	private function generate_secure_token() {
		// Generate a simple secure token (not RSA key pair)
		$token = wp_generate_password( 64, false );

		// Token will be stored by store_token() method - don't store it here
		return $token;
	}


	/**
	 * Store token with expiration
	 */
	private function store_token( $token, $expiration_seconds = null ) {
		if ( empty( $expiration_seconds ) ) {
			$expiration_seconds = self::TOKEN_EXPIRATION;
		}

		// Store simple token data
		$current_timestamp = time();
		$expires_timestamp = $current_timestamp + $expiration_seconds;

		$token_data = array(
			'token'      => $token,
			'created_at' => current_time( 'mysql' ),
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
			'domain'     => home_url(),
		);

		$this->migration_repository->save_token_data( $token_data );

		// Store token value for validation
		$this->migration_repository->save_token_value( $token );

		// Also store in transients for faster access
		set_transient( 'efs_token_' . substr( $token, 0, 16 ), $token_data, $expiration_seconds );
	}

	/**
	 * Validate migration token
	 *
	 * @param string $token Token to validate
	 * @param string $source_domain Source domain
	 * @param int $expires Expiration timestamp
	 * @return bool|\WP_Error
	 */
	public function validate_migration_token( $token, $source_domain, $expires ) {
		// Debug logging
		error_log( 'EFS Token Validation Debug:' );
		error_log( '- Received token: ' . substr( $token, 0, 20 ) . '...' );
		error_log( '- Source domain: ' . $source_domain );
		error_log( '- Expires: ' . $expires . ' (' . wp_date( 'Y-m-d H:i:s', $expires ) . ')' );
		error_log( '- Current time: ' . time() . ' (' . wp_date( 'Y-m-d H:i:s' ) . ')' );

		// Check expiration
		if ( time() > $expires ) {
			error_log( '- Token expired!' );
			return new \WP_Error( 'token_expired', 'Migration token has expired' );
		}

		// Get stored token value
		$stored_token = $this->migration_repository->get_token_value();
		error_log( '- Stored token: ' . ( $stored_token ? substr( $stored_token, 0, 20 ) . '...' : 'NOT_FOUND' ) );

		if ( empty( $stored_token ) ) {
			error_log( '- No stored token found!' );
			return new \WP_Error( 'invalid_token', 'No migration token found. Please generate a new key.' );
		}

		if ( $stored_token !== $token ) {
			error_log( '- Token mismatch!' );
			error_log( '- Expected: ' . substr( $stored_token, 0, 20 ) . '...' );
			error_log( '- Received: ' . substr( $token, 0, 20 ) . '...' );
			return new \WP_Error( 'invalid_token', 'Invalid migration token. Tokens do not match.' );
		}

		error_log( '- Token validation successful!' );
		return true;
	}

	/**
	 * Get migration token data
	 */
	public function get_token_data() {
		return $this->migration_repository->get_token_data();
	}

	/**
	 * Clean up expired tokens
	 */
	public function cleanup_expired_tokens() {
		$token_data = $this->migration_repository->get_token_data();

		if ( ! empty( $token_data ) && isset( $token_data['expires_at'] ) ) {
			$expires_timestamp = strtotime( $token_data['expires_at'] );

			if ( time() > $expires_timestamp ) {
				$this->migration_repository->delete_token_data();

				// Clean up transients
				$this->migration_repository->cleanup_expired_tokens();
			}
		}
	}

	/**
	 * Generate migration QR code data
	 */
	public function generate_qr_data( $target_domain = null ) {
		$migration_url = $this->generate_migration_url( $target_domain );

		$current_timestamp = time();
		$expires_timestamp = $current_timestamp + self::TOKEN_EXPIRATION;

		return array(
			'url'        => $migration_url,
			'qr_data'    => $migration_url, // Can be used with QR code libraries
			'expires_in' => self::TOKEN_EXPIRATION,
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
		);
	}

	/**
	 * Parse migration URL
	 */
	public function parse_migration_url( $url ) {
		$parsed       = wp_parse_url( $url );
		$query_params = array();

		if ( isset( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
		}

		return array(
			'domain'  => $query_params['domain'] ?? null,
			'token'   => $query_params['token'] ?? null,
			'expires' => isset( $query_params['expires'] ) ? (int) $query_params['expires'] : null,
		);
	}

	/**
	 * Create migration shortcut
	 */
	public function create_migration_shortcut( $target_domain ) {
		$migration_url = $this->generate_migration_url( $target_domain );

		// Create a short URL (optional)
		$short_url = wp_generate_password( 8, false );

		// Store short URL mapping
		set_transient( 'efs_short_' . $short_url, $migration_url, self::TOKEN_EXPIRATION );

		$expires_timestamp = time() + self::TOKEN_EXPIRATION;

		return array(
			'full_url'   => $migration_url,
			'short_url'  => home_url( '/migrate/' . $short_url ),
			'qr_data'    => $migration_url,
			'expires_at' => wp_date( 'Y-m-d H:i:s', $expires_timestamp ),
		);
	}
}
