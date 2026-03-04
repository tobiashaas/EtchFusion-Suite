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
function etch_fusion_suite_filter_rest_url_for_docker( $rest_url, $path = '' ) {
	// DISABLED: This filter was converting ALL REST URLs to Docker internal names,
	// which broke browser-side requests that need localhost URLs.
	// Docker loopback URL conversion is handled by docker-url-helper.php in loopback handlers,
	// not by a global REST filter that applies to all requests.
	return $rest_url;
}
// add_filter( 'rest_url', 'etch_fusion_suite_filter_rest_url_for_docker', 1, 2 );
