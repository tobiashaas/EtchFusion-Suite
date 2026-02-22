<?php
$posts = get_posts( array( 'post_type' => 'any', 'posts_per_page' => -1, 'post_status' => 'any', 'meta_key' => '_bricks_page_content_2' ) );
$found = array();
foreach ( $posts as $p ) {
	$raw = get_post_meta( $p->ID, '_bricks_page_content_2', true );
	if ( is_array( $raw ) ) {
		$raw = json_encode( $raw );
	}
	$raw = (string) $raw;
	$has_img  = false !== strpos( $raw, 'featured_image' );
	$has_pc   = false !== strpos( $raw, 'post_content' );
	if ( $has_img || $has_pc ) {
		$found[] = $p->ID . ' [' . ( $has_img ? 'img' : '' ) . ( $has_pc ? ' post_content' : '' ) . ']: ' . $p->post_title;
	}
}
echo implode( "\n", $found ) . "\n";
