<?php
/**
 * Standalone migration trigger for testing.
 * Usage: wp eval-file wp-content/plugins/etch-fusion-suite/scripts/run-migration-test.php
 */

if ( ! function_exists( 'bricks_is_builder' ) ) {
	function bricks_is_builder() {
		return true;
	}
}

// Generate a fresh migration token on the Etch side is done externally.
// This script expects the token and target_url as WP options.
$target_url    = 'http://localhost:8889';
$migration_key = get_option( 'efs_test_migration_key', '' );

if ( empty( $migration_key ) ) {
	fwrite( STDERR, "ERROR: Set efs_test_migration_key option first.\n" );
	exit( 1 );
}

$container  = etch_fusion_suite_container();
$controller = $container->get( 'migration_controller' );

$result = $controller->start_migration(
	array(
		'migration_key' => $migration_key,
		'target_url'    => $target_url,
		'batch_size'    => 2,
	)
);

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Migration error: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
