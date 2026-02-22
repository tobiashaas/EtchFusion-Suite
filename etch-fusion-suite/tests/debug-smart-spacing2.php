<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$global_classes = get_option( 'bricks_global_classes', array() );
$id_to_name = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}

// Find the smart-spacing global class ID
$smart_id = '';
foreach ( $global_classes as $cls ) {
	if ( ( $cls['name'] ?? '' ) === 'smart-spacing' ) {
		$smart_id = $cls['id'];
		break;
	}
}
echo "smart-spacing global class ID: $smart_id\n\n";

// Scan all test posts for elements that have smart-spacing as global class
$bricks_posts = array( 25199, 25197, 25196, 25195, 25194, 25192, 21749, 19772, 16790, 10083 );

foreach ( $bricks_posts as $post_id ) {
	$raw      = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
	if ( empty( $elements ) ) continue;

	$found = array();
	foreach ( $elements as $el ) {
		$global_ids = $el['settings']['_cssGlobalClasses'] ?? array();
		if ( in_array( $smart_id, $global_ids, true ) ) {
			$names = implode( ', ', array_map( fn( $g ) => $id_to_name[ $g ] ?? $g, $global_ids ) );
			$found[] = "[{$el['name']}] id={$el['id']} classes=$names";
		}
	}

	if ( ! empty( $found ) ) {
		echo "Post $post_id:\n";
		foreach ( $found as $f ) {
			echo "  $f\n";
		}
	}
}
