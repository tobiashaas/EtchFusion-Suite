<?php
/**
 * PHPUnit bootstrap file for Etch Fusion Suite.
 *
 * @package Bricks2Etch\Tests
 */

declare(strict_types=1);

if ( ! defined( 'EFS_TESTS_DIR' ) ) {
	define( 'EFS_TESTS_DIR', __DIR__ );
}

if ( ! defined( 'ETCH_FUSION_SUITE_DIR' ) ) {
	define( 'ETCH_FUSION_SUITE_DIR', rtrim( dirname( __DIR__ ), '/\\' ) . '/' );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir && defined( 'WP_PHPUNIT__DIR' ) ) {
	$_tests_dir = WP_PHPUNIT__DIR;
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_core_dir = getenv( 'WP_CORE_DIR' );

if ( ! $_core_dir ) {
	$_core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find WordPress test suite at: {$_tests_dir}\n" );
	fwrite( STDERR, "Install via: wp scaffold plugin-tests etch-fusion-suite --dir=. && bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n" );
	exit( 1 );
}

if ( file_exists( ETCH_FUSION_SUITE_DIR . '/vendor/autoload.php' ) ) {
	require_once ETCH_FUSION_SUITE_DIR . '/vendor/autoload.php';
}

require_once $_tests_dir . '/includes/functions.php';

if ( ! function_exists( '_etch_fusion_suite_manually_load_plugin' ) ) {
	function _etch_fusion_suite_manually_load_plugin(): void {
		require_once ETCH_FUSION_SUITE_DIR . '/etch-fusion-suite.php';
	}
}

tests_add_filter( 'muplugins_loaded', '_etch_fusion_suite_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
