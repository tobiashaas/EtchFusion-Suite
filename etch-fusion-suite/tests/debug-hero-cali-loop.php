<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$raw      = get_post_meta( 25194, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
$by_id    = array();
foreach ( $elements as $el ) { $by_id[ $el['id'] ] = $el; }

// Find image mwughu and walk up to loop parent
$img = $by_id['mwughu'] ?? null;
if ( ! $img ) { echo "Image not found\n"; exit; }

echo "=== IMAGE ===\n";
echo "image settings: " . json_encode( $img['settings']['image'] ?? '' ) . "\n";
echo "parent: " . ( $img['parent'] ?? 'none' ) . "\n\n";

// Walk up the parent chain
$pid = $img['parent'] ?? '';
$depth = 0;
while ( $pid && $depth < 8 ) {
	$p = $by_id[ $pid ] ?? null;
	if ( ! $p ) break;
	echo "=== PARENT (depth $depth): [{$p['name']}] id=$pid ===\n";
	$has_loop = isset( $p['settings']['hasLoop'] ) || isset( $p['settings']['loopType'] ) || isset( $p['settings']['query'] );
	echo "hasLoop: " . json_encode( $p['settings']['hasLoop'] ?? null ) . "\n";
	echo "loopType: " . json_encode( $p['settings']['loopType'] ?? null ) . "\n";
	echo "query type: " . json_encode( $p['settings']['query']['objectType'] ?? null ) . "\n";
	echo "\n";
	$pid = $p['parent'] ?? '';
	$depth++;
}

// Also check current Etch output for this image in post 933
echo "\n=== CURRENT ETCH OUTPUT (post 933, image-related) ===\n";
