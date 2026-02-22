<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$styles = get_option( 'etch_styles', [] );
foreach ( $styles as $id => $s ) {
	if ( '.hero-cali__bg-wrapper' === ( $s['selector'] ?? '' ) ) {
		echo "ID: $id\nCSS: " . $s['css'] . "\n";
	}
}
