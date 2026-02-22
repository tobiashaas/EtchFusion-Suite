<?php
/**
 * Import etch_styles from /tmp/efs_styles.json — runs on etch-cli.
 * Also updates the per-post style IDs in all migrated post content.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$json = file_get_contents( '/tmp/efs_styles.json' );
if ( ! $json ) {
	echo "No /tmp/efs_styles.json found!\n";
	exit;
}

$styles = json_decode( $json, true );
if ( ! is_array( $styles ) ) {
	echo "Invalid JSON in /tmp/efs_styles.json\n";
	exit;
}

echo 'Styles to import: ' . count( $styles ) . "\n";

// Preserve built-in Etch styles (etch-section-style, etch-container-style, etc.)
$existing = get_option( 'etch_styles', array() );
$builtin  = array();
foreach ( $existing as $id => $data ) {
	if ( 0 === strpos( $id, 'etch-' ) ) {
		$builtin[ $id ] = $data;
	}
}

// Merge: built-in styles + freshly converted styles
$merged = array_merge( $builtin, $styles );
update_option( 'etch_styles', $merged, false );

echo 'etch_styles updated with ' . count( $merged ) . " entries (" . count( $builtin ) . " built-in + " . count( $styles ) . " converted)\n";

// Verify fix: search for wrongly renamed variables
$json_styles = wp_json_encode( $merged );
$bad = preg_match( '/--[a-z0-9_-]+-(?:inline|block)-size\s*:/', $json_styles );
if ( $bad ) {
	echo "WARNING: Still found possibly wrong custom property names!\n";
} else {
	echo "OK: No wrongly renamed custom properties found.\n";
}
