<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );

// Show first 3 class-type entries
$shown = 0;
foreach ( $styles as $id => $entry ) {
	if ( ( $entry['type'] ?? '' ) === 'class' && $shown < 3 ) {
		echo "ID: $id\n";
		echo json_encode( $entry, JSON_PRETTY_PRINT ) . "\n\n";
		++$shown;
	}
}

// Also check: does bg--neutral-ultra-dark exist?
$found = false;
foreach ( $styles as $id => $entry ) {
	if ( false !== strpos( $entry['selector'] ?? '', 'bg--' ) ) {
		echo "bg-- entry: $id → " . json_encode( $entry ) . "\n";
		$found = true;
	}
}
if ( ! $found ) { echo "No bg-- entries in etch_styles\n"; }

// Check is-bg
$found2 = false;
foreach ( $styles as $id => $entry ) {
	if ( false !== strpos( $entry['selector'] ?? '', 'is-bg' ) ) {
		echo "is-bg entry: $id → " . json_encode( $entry ) . "\n";
		$found2 = true;
	}
}
if ( ! $found2 ) { echo "No is-bg entries in etch_styles\n"; }
