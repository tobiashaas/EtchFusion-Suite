<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Check what post type hero-cali post is
echo "Post type of 25194: " . get_post_type( 25194 ) . "\n";

// Check all bricks pages
$posts = get_posts([
	'post_type' => get_post_types(['public' => true]),
	'numberposts' => -1,
	'meta_query' => [['key' => '_bricks_page_content_2', 'compare' => 'EXISTS']],
]);
$types = array_unique( array_map( 'get_post_type', $posts ) );
echo "Post types with Bricks content: " . json_encode( $types ) . "\n\n";

// Check etch_styles JSON for hero-cali__bg-wrapper
$json = file_get_contents( '/tmp/efs_styles.json' );
$styles = json_decode( $json, true );
foreach ( $styles as $id => $s ) {
	if ( '.hero-cali__bg-wrapper' === ( $s['selector'] ?? '' ) ) {
		echo "hero-cali__bg-wrapper CSS:\n" . $s['css'] . "\n";
	}
}
