<?php
/**
 * Docker internal URL helper – convert localhost URLs for container-to-container requests.
 *
 * Used by the API client and AJAX handlers so every outbound request (migration start,
 * resume, validate, etc.) uses the internal host (e.g. http://wordpress) when running in wp-env.
 *
 * @package Etch_Fusion_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {

	/**
	 * Convert a localhost/browser URL to the internal URL for server-side requests (e.g. in Docker).
	 *
	 * @param string $url External URL (e.g. http://localhost:8888).
	 * @return string URL suitable for container-to-container or host requests.
	 */
	function etch_fusion_suite_convert_to_internal_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$etch_internal = etch_fusion_suite_resolve_internal_service_host(
			array(
				'efs-etch',
				'etch-wordpress-etch-1',
				'etchfusion-suite-wordpress-etch-1',
			)
		);

		$default_internal = 'http://efs-etch';
		if ( $etch_internal ) {
			$default_internal = $etch_internal;
		}

		$bricks_internal = etch_fusion_suite_resolve_bricks_internal_host();
		$bricks_8888     = $bricks_internal ? $bricks_internal : etch_fusion_suite_get_docker_host_fallback( 8888 );

		$replacements = array(
			'https://localhost:8081' => $default_internal,
			'http://localhost:8081'  => $default_internal,
			'https://localhost:8888' => $bricks_8888,
			'http://localhost:8888'  => $bricks_8888,
			'https://localhost:8889' => 'http://tests-wordpress',
			'http://localhost:8889'  => 'http://tests-wordpress',
		);

		$normalized = strtr( $url, array_filter( $replacements ) );

		if ( false !== strpos( $normalized, 'localhost:8081' ) ) {
			$normalized = str_replace( 'localhost:8081', 'efs-etch', $normalized );
			if ( 0 === strpos( $normalized, 'efs-etch' ) ) {
				$normalized = 'http://' . $normalized;
			}
		}

		if ( false !== strpos( $normalized, 'localhost:8889' ) ) {
			$fallback = etch_fusion_suite_get_docker_host_fallback( 8889 );
			if ( $fallback ) {
				$normalized = str_replace( 'localhost:8889', preg_replace( '#^https?://#', '', $fallback ), $normalized );
				if ( 0 === strpos( $normalized, 'host.docker.internal' ) ) {
					$normalized = 'http://' . $normalized;
				}
			}
		}

		return $normalized;
	}
}

if ( ! function_exists( 'etch_fusion_suite_resolve_internal_service_host' ) ) {

	/**
	 * Resolve internal Docker service host for Etch target.
	 *
	 * @param array $candidates Hostname candidates.
	 * @return string|null HTTP base URL for internal service.
	 */
	function etch_fusion_suite_resolve_internal_service_host( array $candidates ) {
		$candidates = apply_filters( 'etch_fusion_suite_internal_service_host_candidates', $candidates );
		$candidates = apply_filters_deprecated(
			'efs_internal_service_host_candidates',
			array( $candidates ),
			'0.11.27',
			'etch_fusion_suite_internal_service_host_candidates'
		);

		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
				continue;
			}

			$resolved = gethostbyname( $candidate );
			if ( empty( $resolved ) || $resolved === $candidate ) {
				continue;
			}

			if ( filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				return sprintf( 'http://%s', $candidate );
			}
		}

		return null;
	}
}

if ( ! function_exists( 'etch_fusion_suite_resolve_bricks_internal_host' ) ) {

	/**
	 * Resolve Bricks WordPress host inside Docker (wp-env: service "WordPress", port 80).
	 *
	 * Result is cached in a static variable (per-request) and a transient (5 min, cross-request)
	 * to avoid repeated slow DNS lookups in Docker-on-Windows environments.
	 *
	 * @return string|null HTTP base URL (e.g. http://wordpress) or null when not in Docker.
	 */
	function etch_fusion_suite_resolve_bricks_internal_host() {
		static $cache = array();

		$candidates = array( 'wordpress', 'bricks' );
		$candidates = apply_filters( 'etch_fusion_suite_bricks_internal_host_candidates', $candidates );

		$cache_key = 'efs_bricks_host_' . substr( md5( implode( ',', $candidates ) ), 0, 8 );

		if ( array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		$transient = get_transient( $cache_key );
		if ( false !== $transient ) {
			$cache[ $cache_key ] = '' !== $transient ? $transient : null;
			return $cache[ $cache_key ];
		}

		$result = null;
		foreach ( $candidates as $candidate ) {
			if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
				continue;
			}

			$resolved = gethostbyname( $candidate );
			if ( empty( $resolved ) || $resolved === $candidate ) {
				continue;
			}

			if ( filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				$result = sprintf( 'http://%s', $candidate );
				break;
			}
		}

		set_transient( $cache_key, null !== $result ? $result : '', 300 );
		$cache[ $cache_key ] = $result;

		return $result;
	}
}

if ( ! function_exists( 'etch_fusion_suite_get_docker_host_fallback' ) ) {

	/**
	 * Derive container-aware fallback host for localhost targets.
	 *
	 * @param int $port Target port.
	 * @return string|null Normalized base URL or null when not available.
	 */
	function etch_fusion_suite_get_docker_host_fallback( $port ) {
		$port = absint( $port );
		if ( $port <= 0 ) {
			return null;
		}

		$hosts = array();

		$env_host = getenv( 'EFS_DOCKER_HOST' );
		if ( $env_host ) {
			$hosts[] = $env_host;
		}

		$hosts = array_merge(
			$hosts,
			array(
				'host.docker.internal',
				'gateway.docker.internal',
				'docker.for.mac.localhost',
				'docker.for.win.localhost',
				'172.17.0.1',
			)
		);

		$hosts = apply_filters( 'etch_fusion_suite_docker_host_candidates', array_unique( $hosts ), $port );
		$hosts = apply_filters_deprecated(
			'efs_docker_host_candidates',
			array( $hosts, $port ),
			'0.11.27',
			'etch_fusion_suite_docker_host_candidates'
		);

		foreach ( $hosts as $host ) {
			if ( ! is_string( $host ) || '' === trim( $host ) ) {
				continue;
			}

			$resolved = gethostbyname( $host );
			if ( empty( $resolved ) || $resolved === $host ) {
				if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
					return sprintf( 'http://%s:%d', $host, $port );
				}
				continue;
			}

			if ( filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				return sprintf( 'http://%s:%d', $resolved, $port );
			}
		}

		return null;
	}
}
