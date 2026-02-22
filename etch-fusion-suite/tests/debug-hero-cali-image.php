<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Find the image element in Hero Cali (post 25194)
$raw      = get_post_meta( 25194, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );

$global_classes = get_option( 'bricks_global_classes', array() );
$id_to_name     = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}

foreach ( $elements as $el ) {
	if ( 'image' !== ( $el['name'] ?? '' ) ) continue;

	$cnames = implode( ', ', array_map( fn( $g ) => $id_to_name[ $g ] ?? $g, $el['settings']['_cssGlobalClasses'] ?? array() ) );
	echo "=== Image [{$el['id']}] classes=$cnames ===\n";
	echo "useDynamicData: " . json_encode( $el['settings']['useDynamicData'] ?? '' ) . "\n";
	echo "dynamicData:    " . json_encode( $el['settings']['dynamicData'] ?? '' ) . "\n";
	echo "image src:      " . json_encode( $el['settings']['image'] ?? '' ) . "\n";
	echo "tag:            " . json_encode( $el['settings']['tag'] ?? '' ) . "\n";
	echo "_width:         " . json_encode( $el['settings']['_width'] ?? '' ) . "\n";
	echo "_height:        " . json_encode( $el['settings']['_height'] ?? '' ) . "\n";
	echo "_objectFit:     " . json_encode( $el['settings']['_objectFit'] ?? '' ) . "\n";
	echo "parent:         " . json_encode( $el['parent'] ?? '' ) . "\n";
	echo "\n";
}
