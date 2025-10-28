<?php
/**
 * Environment Detector
 *
 * Detects the current environment (local, development, staging, production)
 * and provides environment-specific security settings.
 *
 * @package    Bricks2Etch
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Environment Detector Class
 *
 * Provides environment detection and environment-specific security configuration.
 */
class EFS_Environment_Detector {

	/**
	 * Local development domains
	 *
	 * @var array
	 */
	private $local_domains = array(
		'localhost',
		'.local',
		'.test',
		'.dev',
		'127.0.0.1',
		'::1',
	);

	/**
	 * Check if running in local environment
	 *
	 * @return bool True if local environment, false otherwise.
	 */
	public function is_local_environment() {
		// Check WP_ENVIRONMENT_TYPE constant first (WordPress 5.5+)
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env_type = wp_get_environment_type();
			if ( 'local' === $env_type ) {
				return true;
			}
		}

		// Check WP_LOCAL_DEV constant
		if ( defined( 'WP_LOCAL_DEV' ) && constant( 'WP_LOCAL_DEV' ) ) {
			return true;
		}

		// Check domain/host
		$host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}
		if ( '' === $host && isset( $_SERVER['SERVER_NAME'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
		}

		// Check against local domains
		foreach ( $this->local_domains as $local_domain ) {
			if ( strpos( $host, $local_domain ) !== false ) {
				return true;
			}
		}

		// Check IP address
		$server_addr = '';
		if ( isset( $_SERVER['SERVER_ADDR'] ) ) {
			$server_addr = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) );
		}
		if ( in_array( $server_addr, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		// Check for Docker internal IPs
		if ( $this->is_docker_environment() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if running in development environment
	 *
	 * @return bool True if development environment, false otherwise.
	 */
	public function is_development() {
		// Check WP_ENVIRONMENT_TYPE
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env_type = wp_get_environment_type();
			if ( in_array( $env_type, array( 'local', 'development' ), true ) ) {
				return true;
			}
		}

		// Check WP_DEBUG
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}

		// Check if local environment
		if ( $this->is_local_environment() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if running in production environment
	 *
	 * @return bool True if production environment, false otherwise.
	 */
	public function is_production() {
		// Check WP_ENVIRONMENT_TYPE
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env_type = wp_get_environment_type();
			if ( 'production' === $env_type ) {
				return true;
			}
		}

		// If not local or development, assume production
		return ! $this->is_development();
	}

	/**
	 * Check if HTTPS should be required
	 *
	 * @return bool True if HTTPS should be required, false otherwise.
	 */
	public function should_require_https() {
		// Always require HTTPS in production
		if ( $this->is_production() ) {
			return true;
		}

		// Don't require HTTPS in local/development
		return false;
	}

	/**
	 * Get environment type
	 *
	 * @return string Environment type (local, development, staging, production).
	 */
	public function get_environment_type() {
		// Use WordPress function if available
		if ( function_exists( 'wp_get_environment_type' ) ) {
			return wp_get_environment_type();
		}

		// Fallback detection
		if ( $this->is_local_environment() ) {
			return 'local';
		}

		if ( $this->is_development() ) {
			return 'development';
		}

		// Check for staging indicators
		$host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		}
		if ( strpos( $host, 'staging' ) !== false || strpos( $host, 'stage' ) !== false ) {
			return 'staging';
		}

		return 'production';
	}

	/**
	 * Get allowed IPs for local development
	 *
	 * Returns array of IP addresses that should be allowed in local development.
	 *
	 * @return array Array of allowed IP addresses.
	 */
	public function get_allowed_ips() {
		$allowed_ips = array(
			'127.0.0.1',
			'::1',
		);

		// Add Docker internal IPs if in Docker environment
		if ( $this->is_docker_environment() ) {
			$allowed_ips[] = '172.17.0.1';  // Docker bridge default
			$allowed_ips[] = '172.18.0.1';  // Docker custom bridge
			$allowed_ips[] = '172.19.0.1';  // Docker custom bridge
			$allowed_ips[] = '172.20.0.1';  // Docker custom bridge
		}

		return $allowed_ips;
	}

	/**
	 * Check if running in Docker environment
	 *
	 * @return bool True if Docker environment, false otherwise.
	 */
	private function is_docker_environment() {
		// Check for .dockerenv file
		if ( file_exists( '/.dockerenv' ) ) {
			return true;
		}

		// Check for Docker in cgroup
		if ( file_exists( '/proc/self/cgroup' ) ) {
			$cgroup = file_get_contents( '/proc/self/cgroup' );
			if ( strpos( $cgroup, 'docker' ) !== false ) {
				return true;
			}
		}

		// Check environment variables
		if ( getenv( 'DOCKER_CONTAINER' ) || getenv( 'WORDPRESS_DB_HOST' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get environment info
	 *
	 * Returns array of environment information for debugging.
	 *
	 * @return array Environment information.
	 */
	public function get_environment_info() {
		return array(
			'type'           => $this->get_environment_type(),
			'is_local'       => $this->is_local_environment(),
			'is_development' => $this->is_development(),
			'is_production'  => $this->is_production(),
			'is_docker'      => $this->is_docker_environment(),
			'require_https'  => $this->should_require_https(),
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'host'           => isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '',
		);
	}
}
