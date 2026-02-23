<?php
// Print Bricks→Etch post ID mapping (runs on etch-cli)
if ( ! defined( 'ABSPATH' ) ) { exit; }
$posts = get_posts( array( 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'any' ) );
foreach ( $posts as $post ) {
	$source_id = get_post_meta( $post->ID, '_efs_original_post_id', true );
	if ( $source_id ) {
		echo $source_id . ':' . $post->ID . ':' . $post->post_name . "\n";
	}
}
