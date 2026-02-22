<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$raw      = get_post_meta( 25194, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
$by_id    = array();
foreach ( $elements as $el ) { $by_id[ $el['id'] ] = $el; }

// Find all elements with hasLoop = true
foreach ( $elements as $el ) {
	if ( ! empty( $el['settings']['hasLoop'] ) ) {
		echo "=== Loop: [{$el['name']}] id={$el['id']} ===\n";
		echo "query: " . json_encode( $el['settings']['query'] ?? null ) . "\n";
		echo "loopType: " . json_encode( $el['settings']['loopType'] ?? null ) . "\n";
		echo "hasLoop: " . json_encode( $el['settings']['hasLoop'] ) . "\n\n";
	}
}
