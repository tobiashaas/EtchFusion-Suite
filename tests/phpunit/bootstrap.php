<?php
/**
 * PHPUnit bootstrap for Etch Fusion Suite.
 */

declare( strict_types=1 );

if ( ! defined( 'EFS_PHPUNIT' ) ) {
	define( 'EFS_PHPUNIT', true );
}

if ( ! defined( 'EFS_SKIP_WP_LOAD' ) ) {
	$skip_env = getenv( 'EFS_SKIP_WP_LOAD' );
	$skip     = false;

	if ( false !== $skip_env ) {
		$skip = in_array( strtolower( (string) $skip_env ), array( '1', 'true', 'yes', 'on' ), true );
	}

	define( 'EFS_SKIP_WP_LOAD', $skip );
}

if ( ! EFS_SKIP_WP_LOAD ) {
	$wp_env_path = getenv( 'WP_ENV_PATH' );
	$wp_path     = getenv( 'WP_PATH' );

	$candidate_paths = array_filter(
		array(
			$wp_env_path ? rtrim( $wp_env_path, "\\/" ) . '/wp-load.php' : null,
			$wp_path ? rtrim( $wp_path, "\\/" ) . '/wp-load.php' : null,
			realpath( __DIR__ . '/../../test-environment/wordpress-bricks/wp-load.php' ),
		),
		static function ( $path ) {
			return ! empty( $path ) && file_exists( $path );
		}
	);

	foreach ( $candidate_paths as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			break;
		}
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! EFS_SKIP_WP_LOAD ) {
		fwrite( STDERR, "[EFS PHPUnit] wp-load.php not found. Set WP_ENV_PATH or WP_PATH to your WordPress install.\n" );
	}
	define( 'ABSPATH', sys_get_temp_dir() . '/efs-phpunit/' );
}

$plugin_root = realpath( __DIR__ . '/../../etch-fusion-suite' );

if ( ! $plugin_root ) {
	fwrite( STDERR, "[EFS PHPUnit] Unable to resolve plugin root directory.\n" );
	return;
}

$plugin_bootstrap = $plugin_root . '/etch-fusion-suite.php';
$plugin_autoloader = $plugin_root . '/includes/autoloader.php';
$base_handler      = $plugin_root . '/includes/ajax/class-base-ajax-handler.php';

if ( file_exists( $plugin_autoloader ) ) {
	require_once $plugin_autoloader;
}

if ( EFS_SKIP_WP_LOAD ) {
	if ( file_exists( $base_handler ) ) {
		require_once $base_handler;
	} else {
		fwrite( STDERR, "[EFS PHPUnit] Base AJAX handler not found at expected path: {$base_handler}\n" );
	}

	return;
}

if ( file_exists( $plugin_bootstrap ) ) {
	require_once $plugin_bootstrap;
} else {
	fwrite( STDERR, "[EFS PHPUnit] Plugin bootstrap file not found at expected path: {$plugin_bootstrap}\n" );
}
