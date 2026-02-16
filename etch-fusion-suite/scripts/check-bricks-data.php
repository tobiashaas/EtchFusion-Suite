<?php
/**
 * Check where Bricks data is stored for pages.
 * Run via: wp eval-file wp-content/plugins/etch-fusion-suite/scripts/check-bricks-data.php
 */

$pages = get_posts( array(
	'post_type'   => 'page',
	'numberposts' => -1,
	'orderby'     => 'ID',
	'order'       => 'ASC',
) );

$stats = array(
	'total'              => count( $pages ),
	'meta_ok'            => 0,
	'meta_empty'         => 0,
	'content_serialized' => 0,
	'content_empty'      => 0,
	'content_normal'     => 0,
	'needs_move'         => 0,
);

foreach ( $pages as $p ) {
	$meta    = get_post_meta( $p->ID, '_bricks_page_content_2', true );
	$content = $p->post_content;

	$meta_ok           = ! empty( $meta ) && is_array( $meta );
	$content_serialized = ( substr( $content, 0, 2 ) === 'a:' );

	if ( $meta_ok ) {
		$stats['meta_ok']++;
	} else {
		$stats['meta_empty']++;
	}

	if ( $content_serialized ) {
		$stats['content_serialized']++;
	} elseif ( empty( $content ) ) {
		$stats['content_empty']++;
	} else {
		$stats['content_normal']++;
	}

	// Needs move: serialized data in content but no valid meta
	if ( $content_serialized && ! $meta_ok ) {
		$stats['needs_move']++;
	}
}

echo "=== Bricks Data Location Report ===\n";
echo "Total pages: {$stats['total']}\n\n";
echo "Meta _bricks_page_content_2:\n";
echo "  Valid array: {$stats['meta_ok']}\n";
echo "  Empty/missing: {$stats['meta_empty']}\n\n";
echo "Post content:\n";
echo "  Serialized Bricks data: {$stats['content_serialized']}\n";
echo "  Empty: {$stats['content_empty']}\n";
echo "  Normal HTML: {$stats['content_normal']}\n\n";
echo "Action needed:\n";
echo "  Pages needing content->meta move: {$stats['needs_move']}\n";

// Check global classes
$classes = get_option( 'bricks_global_classes', array() );
if ( is_string( $classes ) ) {
	$decoded = json_decode( $classes, true );
	echo "\nbricks_global_classes: STRING (json with " . ( is_array( $decoded ) ? count( $decoded ) : 0 ) . " entries)\n";
} elseif ( is_array( $classes ) ) {
	echo "\nbricks_global_classes: ARRAY with " . count( $classes ) . " entries\n";
} else {
	echo "\nbricks_global_classes: EMPTY/missing\n";
}
