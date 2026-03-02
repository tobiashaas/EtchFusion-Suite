<?php
/**
 * WordPress REST API & Loopback URL Filter for Docker Compatibility
 *
 * Ensures all REST API requests from within Docker containers use internal hostnames
 * instead of localhost, allowing health checks and loopback requests to succeed.
 *
 * @package Bricks2Etch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter WordPress REST base URL for Docker compatibility.
 *
 * When WordPress (or plugins) construct REST API URLs from within Docker containers,
 * they use the configured site URL (typically localhost:8888). These URLs fail when
 * accessed via internal requests because localhost inside a container doesn't resolve
 * to the actual WordPress site.
 *
 * This filter converts localhost URLs to Docker-internal hostnames.
 *
 * @param string $rest_url The REST API base URL.
 * @param string $path     The REST request path.
 * @return string Converted URL suitable for Docker container-to-container requests.
 */
function efs_filter_rest_url_for_docker( $rest_url, $path = '' ) {
	// Only apply Docker URL conversion if the helper is available.
	if ( ! function_exists( 'etch_fusion_suite_convert_to_internal_url' ) ) {
		return $rest_url;
	}

	// Use the existing Docker URL helper to translate localhost URLs.
	return etch_fusion_suite_convert_to_internal_url( $rest_url );
}
add_filter( 'rest_url', 'efs_filter_rest_url_for_docker', 1, 2 );
