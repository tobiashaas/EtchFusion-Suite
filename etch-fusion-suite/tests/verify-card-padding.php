<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
$found_old = array();
$found_new = array();
foreach ( $styles as $id => $s ) {
	$css = $s['css'] ?? '';
	if ( false !== strpos( $css, 'fr-card-padding' ) ) {
		$found_old[] = $id . ': ' . ( $s['selector'] ?? '' );
	}
	if ( false !== strpos( $css, '--card-padding' ) ) {
		$found_new[] = $id . ': ' . ( $s['selector'] ?? '' );
	}
}
echo 'Still has --fr-card-padding: ' . count( $found_old ) . "\n";
foreach ( $found_old as $l ) { echo "  OLD: $l\n"; }
echo 'Has --card-padding: ' . count( $found_new ) . "\n";
foreach ( array_slice( $found_new, 0, 5 ) as $l ) { echo "  NEW: $l\n"; }
