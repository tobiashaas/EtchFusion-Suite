<?php
// Debug: show loop queries for posts with featured_image dynamic data
$bricks_ids = array( 25196, 25195, 21749 );
foreach ( $bricks_ids as $bricks_id ) {
	$raw = get_post_meta( $bricks_id, '_bricks_page_content_2', true );
	if ( is_array( $raw ) ) {
		$elements = $raw;
	} else {
		$elements = json_decode( $raw, true );
	}
	if ( ! is_array( $elements ) ) {
		continue;
	}
	foreach ( $elements as $el ) {
		if ( ! empty( $el['settings']['hasLoop'] ) && ! empty( $el['settings']['query'] ) ) {
			$qt = $el['settings']['query']['post_type'] ?? 'NOT SET';
			echo "Bricks {$bricks_id} [{$el['name']}]: hasLoop=true, post_type=" . ( is_array( $qt ) ? implode( ',', $qt ) : $qt ) . "\n";
		}
	}
}
