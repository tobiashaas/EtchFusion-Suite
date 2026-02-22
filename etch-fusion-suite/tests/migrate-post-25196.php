<?php
// Re-migrate single post 25196 for debugging
$bricks_id = 25196;
$etch_id   = 927;

$mapping_option = get_option( 'efs_post_id_mapping', array() );

if ( ! class_exists( 'EFS_Migration_Controller' ) ) {
	$plugin_dir = WP_PLUGIN_DIR . '/etch-fusion-suite/';
	require_once $plugin_dir . 'etch-fusion-suite.php';
}

$container    = etch_fusion_suite_container();
$content_svc  = $container->get( 'content_migration_service' );

$result = $content_svc->migrate_single_post( $bricks_id, $mapping_option );
if ( is_wp_error( $result ) ) {
	echo 'Error: ' . $result->get_error_message() . "\n";
} else {
	// Save to temp file
	$out_dir = '/tmp/efs_posts';
	if ( ! is_dir( $out_dir ) ) {
		mkdir( $out_dir, 0755, true );
	}
	file_put_contents( $out_dir . '/' . $bricks_id . '_' . $etch_id . '.txt', $result );
	echo "OK {$bricks_id} → {$etch_id} (" . strlen( $result ) . " chars)\n";
	// Show image-related lines
	$lines = explode( "\n", $result );
	foreach ( $lines as $line ) {
		if ( strpos( $line, 'mediaId' ) !== false || strpos( $line, 'image' ) !== false || strpos( $line, 'content.strip' ) !== false ) {
			echo "  >>> " . trim( $line ) . "\n";
		}
	}
}
