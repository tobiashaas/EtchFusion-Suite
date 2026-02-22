<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$raw      = get_post_meta( 21749, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );

// Build global class id->name map
$global_classes = get_option( 'bricks_global_classes', array() );
$id_to_name = array();
foreach ( $global_classes as $cls ) {
	if ( isset( $cls['id'], $cls['name'] ) ) {
		$id_to_name[ $cls['id'] ] = $cls['name'];
	}
}

// Index by element id
$by_id = array();
foreach ( $elements as $el ) {
	$by_id[ $el['id'] ] = $el;
}

// Find date-wrapper via global class names
$date_wrapper_el = null;
foreach ( $elements as $el ) {
	$global_ids = $el['settings']['_cssGlobalClasses'] ?? array();
	foreach ( $global_ids as $gid ) {
		$name = $id_to_name[ $gid ] ?? '';
		if ( false !== strpos( $name, 'date-wrapper' ) ) {
			$date_wrapper_el = $el;
			break 2;
		}
	}
}

if ( ! $date_wrapper_el ) {
	echo "date-wrapper element NOT FOUND\n";
	// Dump all element names/classes for inspection
	foreach ( $elements as $el ) {
		$names = array();
		foreach ( $el['settings']['_cssGlobalClasses'] ?? array() as $gid ) {
			$names[] = $id_to_name[ $gid ] ?? $gid;
		}
		if ( ! empty( $names ) ) {
			echo "  [{$el['name']}] id={$el['id']} classes=" . implode( ', ', $names ) . "\n";
		}
	}
	exit;
}

echo "=== DATE-WRAPPER: [{$date_wrapper_el['name']}] id={$date_wrapper_el['id']} ===\n";
echo "Global classes: " . implode( ', ', array_map( fn( $g ) => $id_to_name[ $g ] ?? $g, $date_wrapper_el['settings']['_cssGlobalClasses'] ?? array() ) ) . "\n";
echo "Children: " . json_encode( $date_wrapper_el['children'] ?? array() ) . "\n\n";

foreach ( $date_wrapper_el['children'] ?? array() as $cid ) {
	$child = $by_id[ $cid ] ?? null;
	if ( ! $child ) { echo "  MISSING child $cid\n"; continue; }
	$cnames = implode( ', ', array_map( fn( $g ) => $id_to_name[ $g ] ?? $g, $child['settings']['_cssGlobalClasses'] ?? array() ) );
	echo "  [{$child['name']}] id={$child['id']} classes=$cnames\n";
	echo "  text=" . json_encode( $child['settings']['text'] ?? '' ) . "\n";
	echo "  dynData=" . json_encode( $child['settings']['useDynamicData'] ?? '' ) . "\n";
	$inline_css_class = $child['settings']['_cssClasses'] ?? '';
	if ( $inline_css_class ) echo "  _cssClasses=$inline_css_class\n";
	echo "\n";
}
