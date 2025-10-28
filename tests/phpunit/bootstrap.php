<?php
/**
 * PHPUnit bootstrap for Etch Fusion Suite.
 */

declare( strict_types=1 );

if ( ! defined( 'EFS_PHPUNIT' ) ) {
	define( 'EFS_PHPUNIT', true );
}

// Attempt to locate wp-load.php from wp-env or custom path.
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

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "[EFS PHPUnit] wp-load.php not found. Set WP_ENV_PATH or WP_PATH to your WordPress install.\n" );
	define( 'ABSPATH', sys_get_temp_dir() . '/efs-phpunit/' );
}

$plugin_bootstrap = realpath( __DIR__ . '/../../etch-fusion-suite/etch-fusion-suite.php' );

if ( $plugin_bootstrap && file_exists( $plugin_bootstrap ) ) {
	require_once $plugin_bootstrap;
} else {
	fwrite( STDERR, "[EFS PHPUnit] Plugin bootstrap file not found at expected path: {$plugin_bootstrap}\n" );
}
