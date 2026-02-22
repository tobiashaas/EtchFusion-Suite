<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( '.feature-grid-sierra' === $sel || '.feature-card-sierra' === $sel || '.feature-card-sierra__controls' === $sel ) {
		echo "[$sel]\n";
		echo ( $s['css'] ?? '(empty)' ) . "\n\n";
	}
}
