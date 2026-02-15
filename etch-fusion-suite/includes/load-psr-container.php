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

$dir = dirname( __DIR__ ) . '/vendor/psr/container/src/';
if ( is_dir( $dir ) ) {
	require_once $dir . 'ContainerExceptionInterface.php';
	require_once $dir . 'NotFoundExceptionInterface.php';
	require_once $dir . 'ContainerInterface.php';
}
