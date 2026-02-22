<?php
/**
 * Re-run only the CSS migration step with the current converter code.
 * Runs on bricks-cli. Sends result to Etch via active migration key.
 *
 * Usage: wp --allow-root --path=/var/www/html eval-file .../remigrate-css.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$container    = etch_fusion_suite_container();
$css_converter = $container->get( 'css_converter' );

echo "Running CSS conversion...\n";

// Run the CSS conversion — this fills the internal style map and generates etch_styles data.
$result = $css_converter->convert_bricks_classes_to_etch();

if ( is_wp_error( $result ) ) {
	echo 'Error: ' . $result->get_error_message() . "\n";
	exit;
}

// $result contains ['styles' => [...], 'custom_css' => '...', 'acss_inline_style_map' => [...]]
echo 'Classes converted: ' . ( isset( $result['styles'] ) ? count( $result['styles'] ) : 0 ) . "\n";

// Now send etch_styles to the target (Etch) site.
$active_migration = get_option( 'efs_active_migration', array() );
$target_url       = isset( $active_migration['target_url'] ) ? $active_migration['target_url'] : '';
$migration_key    = isset( $active_migration['migration_key'] ) ? $active_migration['migration_key'] : '';

if ( ! $target_url || ! $migration_key ) {
	echo "ERROR: No active migration found. Cannot send styles to Etch.\n";
	echo "Trying direct DB approach...\n";

	// Fallback: save converted styles to file for manual import
	file_put_contents( '/tmp/efs_styles.json', wp_json_encode( $result['styles'] ) );
	echo "Styles saved to /tmp/efs_styles.json (" . count( $result['styles'] ) . " entries)\n";
	exit;
}

echo "Sending styles to Etch ($target_url)...\n";

$api_client = $container->get( 'api_client' );
$response   = $api_client->send_styles( $target_url, $migration_key, $result['styles'] );

if ( is_wp_error( $response ) ) {
	echo 'API Error: ' . $response->get_error_message() . "\n";
	// Fallback: save to file
	file_put_contents( '/tmp/efs_styles.json', wp_json_encode( $result['styles'] ) );
	echo "Fallback: styles saved to /tmp/efs_styles.json\n";
} else {
	echo "Styles sent successfully!\n";
}

// Also update acss_inline_style_map on bricks side.
if ( ! empty( $result['acss_inline_style_map'] ) ) {
	update_option( 'efs_acss_inline_style_map', $result['acss_inline_style_map'] );
	echo "ACSS inline map updated (" . count( $result['acss_inline_style_map'] ) . " entries)\n";
}
