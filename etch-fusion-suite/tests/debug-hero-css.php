<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $entry ) {
	$sel = $entry['selector'] ?? '';
	if ( false !== strpos( $sel, 'hero-cali__bg-image' ) || false !== strpos( $sel, 'bg-image' ) ) {
		echo "ID: $id\n";
		echo "Selector: $sel\n";
		echo "CSS: " . ( $entry['css'] ?? '' ) . "\n\n";
	}
}
