<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Find sierra classes in Bricks global classes
$global = get_option( 'bricks_global_classes', array() );
$sierra = array();
foreach ( $global as $c ) {
	$name = $c['name'] ?? '';
	if ( false !== stripos( $name, 'sierra' ) ) {
		$sierra[] = $c;
		echo "Bricks class: $name (id=" . ( $c['id'] ?? '' ) . ")\n";
		echo "  keys=" . json_encode( array_keys( $c['settings'] ?? [] ) ) . "\n";
	}
}
echo "\n";

// Check etch_styles on Bricks side (before export)
$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( false !== stripos( $sel, 'sierra' ) ) {
		echo "etch_styles[$id]: selector=$sel\n";
		$css = $s['css'] ?? '';
		echo "  css=" . substr( $css, 0, 200 ) . "\n\n";
	}
}
