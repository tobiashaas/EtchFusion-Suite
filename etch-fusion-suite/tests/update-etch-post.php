<?php
// Temporary: update Etch post from /tmp/efs_migrated_post.txt
// Usage: wp eval-file ... -- <etch_post_id>
if ( ! defined( 'ABSPATH' ) ) { exit; }
global $args;
$etch_post_id = isset( $args[0] ) ? (int) $args[0] : 0;
if ( $etch_post_id <= 0 ) {
	echo "Usage: -- <etch_post_id>\n";
	exit;
}
$content = file_get_contents( '/tmp/efs_migrated_post.txt' );
if ( ! $content ) {
	echo "No content in /tmp/efs_migrated_post.txt\n";
	exit;
}
$result = wp_update_post( array(
	'ID'           => $etch_post_id,
	'post_content' => $content,
), true );
if ( is_wp_error( $result ) ) {
	echo 'Error: ' . $result->get_error_message() . "\n";
} else {
	echo "Updated post $etch_post_id successfully.\n";
}
