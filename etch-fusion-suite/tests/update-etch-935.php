<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$etch_post_id = 935;
$content = file_get_contents( '/tmp/efs_migrated_post.txt' );
if ( ! $content ) { echo "No content!\n"; exit; }
$result = wp_update_post( array( 'ID' => $etch_post_id, 'post_content' => $content ), true );
is_wp_error( $result ) ? print( 'Error: ' . $result->get_error_message() . "\n" ) : print( "Updated post $etch_post_id\n" );
