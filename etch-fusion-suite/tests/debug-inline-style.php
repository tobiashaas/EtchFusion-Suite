<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Check ACSS map on Etch
$acss = get_option( 'efs_acss_inline_style_map', array() );
echo "is-bg in ACSS map: " . json_encode( $acss['is-bg'] ?? null ) . "\n";
echo "ACSS map keys sample: " . json_encode( array_slice( array_keys( $acss ), 0, 10 ) ) . "\n\n";

// Check current etch_styles for is-bg and hero-cali__bg-wrapper
$styles = get_option( 'etch_styles', array() );
foreach ( $styles as $id => $s ) {
	$sel = $s['selector'] ?? '';
	if ( '.is-bg' === $sel || '.hero-cali__bg-wrapper' === $sel ) {
		echo "[$sel] id=$id\n";
		echo "css: " . ( $s['css'] ?? '(empty)' ) . "\n\n";
	}
}

// Check current post 933 for the block with pnbehm context
$content = get_post_field( 'post_content', 933 );
preg_match_all( '/<!-- wp:etch\/element \{[^>]*"class":"[^"]*bg-wrapper[^"]*"[^>]*-->/', $content, $matches );
foreach ( $matches[0] as $m ) {
	echo substr( $m, 0, 300 ) . "\n\n";
}
