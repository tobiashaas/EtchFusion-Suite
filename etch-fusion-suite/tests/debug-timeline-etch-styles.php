<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( false !== strpos( $sel, 'fr-timeline-delta' ) && false === strpos( $sel, 'fr-timeline-delta__' ) ) {
		echo $id . ': ' . $sel . "\n";
		echo ( $s['css'] ?? '' ) . "\n---\n";
	}
}
