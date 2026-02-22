<?php
// Show full loop query object for post 25196
$raw = get_post_meta( 25196, '_bricks_page_content_2', true );
if ( is_array( $raw ) ) {
	$elements = $raw;
} else {
	$elements = json_decode( $raw, true );
}
foreach ( $elements as $el ) {
	if ( ! empty( $el['settings']['hasLoop'] ) ) {
		echo "Element name: " . $el['name'] . ", id: " . $el['id'] . "\n";
		echo "Query: " . json_encode( $el['settings']['query'] ) . "\n\n";
	}
}
