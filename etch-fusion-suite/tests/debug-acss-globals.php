<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$global_classes = get_option( 'bricks_global_classes', array() );
$acss_classes   = array();

foreach ( $global_classes as $cls ) {
	$name = $cls['name'] ?? '';
	// ACSS utility classes: bg--, is-bg, smart-spacing, hidden-accessible
	if (
		0 === strpos( $name, 'bg--' )
		|| 0 === strpos( $name, 'is-bg' )
		|| 'smart-spacing' === $name
		|| 'hidden-accessible' === $name
	) {
		$acss_classes[] = $name;
	}
}

echo 'ACSS utility classes in Bricks global classes: ' . count( $acss_classes ) . "\n";
sort( $acss_classes );
foreach ( $acss_classes as $c ) {
	echo "  $c\n";
}

// Also check which of these are actually USED in test posts
$bricks_posts = array( 25199, 25197, 25196, 25195, 25194, 25192, 21749, 19772, 16790, 10083, 8296, 4042 );
$id_to_name   = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}
$name_to_id = array_flip( $id_to_name );

$used = array();
foreach ( $bricks_posts as $post_id ) {
	$raw      = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
	if ( empty( $elements ) ) continue;
	foreach ( $elements as $el ) {
		foreach ( $el['settings']['_cssGlobalClasses'] ?? array() as $gid ) {
			$cname = $id_to_name[ $gid ] ?? '';
			if (
				0 === strpos( $cname, 'bg--' )
				|| 0 === strpos( $cname, 'is-bg' )
				|| 'smart-spacing' === $cname
				|| 'hidden-accessible' === $cname
			) {
				$used[ $cname ] = true;
			}
		}
	}
}
echo "\nACSS utility classes USED in test posts:\n";
foreach ( array_keys( $used ) as $c ) {
	echo "  $c\n";
}
