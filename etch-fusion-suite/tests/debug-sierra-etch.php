<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( false !== stripos( $sel, 'sierra' ) ) {
		echo "ID: $id\n";
		echo "Selector: $sel\n";
		echo "CSS:\n" . ( $s['css'] ?? '(empty)' ) . "\n";
		echo "---\n\n";
	}
}
