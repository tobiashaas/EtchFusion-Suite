<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$global = get_option( 'bricks_global_classes', array() );
foreach ( $global as $c ) {
	$name = $c['name'] ?? '';
	if ( false === stripos( $name, 'sierra' ) ) continue;
	if ( empty( $c['settings']['_cssCustom'] ) ) continue;

	echo "=== $name ===\n";
	echo "Custom CSS:\n" . $c['settings']['_cssCustom'] . "\n\n";
}

// Also compare etch_styles for feature-card-sierra and feature-grid-sierra
echo "\n=== Current etch_styles for feature-grid-sierra ===\n";
$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( '.feature-grid-sierra' === $sel || '.feature-card-sierra' === $sel ) {
		echo "[$sel]\n" . ( $s['css'] ?? '(empty)' ) . "\n\n";
	}
}
