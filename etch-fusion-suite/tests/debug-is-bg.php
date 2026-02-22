<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$global_classes = get_option( 'bricks_global_classes', array() );

// Find is-bg
foreach ( $global_classes as $cls ) {
	if ( ( $cls['name'] ?? '' ) === 'is-bg' ) {
		echo "is-bg class data:\n";
		echo json_encode( array(
			'id'       => $cls['id'] ?? '',
			'name'     => $cls['name'] ?? '',
			'category' => $cls['category'] ?? '(none)',
			'settings' => array_keys( $cls['settings'] ?? array() ),
		), JSON_PRETTY_PRINT ) . "\n";
	}
}

// Check which elements use is-bg and via which field
$bricks_posts = array( 25199, 25197, 25196, 25195, 25194, 25192, 21749, 19772, 16790 );
$id_to_name   = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}

foreach ( $bricks_posts as $post_id ) {
	$raw      = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
	if ( empty( $elements ) ) continue;
	foreach ( $elements as $el ) {
		// Check _cssGlobalClasses
		foreach ( $el['settings']['_cssGlobalClasses'] ?? array() as $gid ) {
			if ( ( $id_to_name[ $gid ] ?? '' ) === 'is-bg' ) {
				echo "Post $post_id: [{$el['name']}] id={$el['id']} has is-bg via _cssGlobalClasses (id=$gid)\n";
			}
		}
		// Check _cssClasses (plain string)
		$css_classes = $el['settings']['_cssClasses'] ?? '';
		if ( false !== strpos( (string) $css_classes, 'is-bg' ) ) {
			echo "Post $post_id: [{$el['name']}] id={$el['id']} has is-bg via _cssClasses: '$css_classes'\n";
		}
	}
}
