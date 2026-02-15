<?php
/**
 * Loads PSR Container interfaces from vendor. Used by the main plugin so this code
 * is always read from disk (not from OPcache), ensuring composer dependencies are
 * available after "composer install".
 *
 * @internal
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

$etch_fusion_suite_psr_container_dir = dirname( __DIR__ ) . '/vendor/psr/container/src/';
if ( is_dir( $etch_fusion_suite_psr_container_dir ) ) {
	require_once $etch_fusion_suite_psr_container_dir . 'ContainerExceptionInterface.php';
	require_once $etch_fusion_suite_psr_container_dir . 'NotFoundExceptionInterface.php';
	require_once $etch_fusion_suite_psr_container_dir . 'ContainerInterface.php';
}
