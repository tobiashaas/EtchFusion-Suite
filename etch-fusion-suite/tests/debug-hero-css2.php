<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $entry ) {
	$sel = $entry['selector'] ?? '';
	if ( '.hero-cali__bg-image' === $sel ) {
		echo "ID: $id\n";
		echo "Selector: $sel\n";
		echo "CSS:\n" . ( $entry['css'] ?? '' ) . "\n";
	}
}
