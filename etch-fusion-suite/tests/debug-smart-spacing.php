<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$map  = get_option( 'efs_acss_inline_style_map', array() );
$keys = array_keys( $map );
echo 'Total ACSS keys: ' . count( $keys ) . "\n";

// Check smart-* keys
$smart_keys = array_filter( $keys, function( $k ) { return false !== strpos( $k, 'smart' ); } );
echo 'smart-* keys: ' . json_encode( array_values( $smart_keys ) ) . "\n";

// Also check if smart-spacing class exists in Bricks global classes
$global_classes = get_option( 'bricks_global_classes', array() );
$smart_global   = array_filter( $global_classes, function( $cls ) { return false !== strpos( $cls['name'] ?? '', 'smart' ); } );
echo 'smart-* global classes: ' . json_encode( array_values( array_map( fn( $c ) => $c['name'], $smart_global ) ) ) . "\n";

// Check which Bricks elements in any post use smart-spacing
$raw      = get_post_meta( 25195, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
$id_to_name = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}
echo "\n=== Post 25195 elements with smart-spacing ===\n";
foreach ( $elements as $el ) {
	$names = array_map( fn( $g ) => $id_to_name[ $g ] ?? $g, $el['settings']['_cssGlobalClasses'] ?? array() );
	if ( in_array( 'smart-spacing', $names, true ) ) {
		echo "  [{$el['name']}] id={$el['id']} classes=" . implode( ', ', $names ) . "\n";
	}
}
