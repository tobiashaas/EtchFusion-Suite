<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$raw = get_post_meta( 21749, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );

// Find elements with date-wrapper class or inside it
foreach ( $elements as $el ) {
	$classes = $el['settings']['_cssClasses'] ?? $el['settings']['cssClasses'] ?? '';
	$classes_arr = $el['settings']['_cssGlobalClasses'] ?? array();
	$tag = $el['name'] ?? '';
	$id  = $el['id'] ?? '';

	// Check for date-wrapper by class string
	if ( false !== strpos( (string) $classes, 'date-wrapper' ) ) {
		echo "=== DATE-WRAPPER ELEMENT ===\n";
		echo "ID: $id | Tag: $tag\n";
		echo "Classes: $classes\n";
		echo "Children: " . json_encode( $el['children'] ?? array() ) . "\n";
		echo "Settings keys: " . implode( ', ', array_keys( $el['settings'] ?? array() ) ) . "\n\n";
	}
}

// Also find children of date-wrapper
$date_wrapper_id = null;
foreach ( $elements as $el ) {
	$classes = $el['settings']['_cssClasses'] ?? '';
	if ( false !== strpos( (string) $classes, 'date-wrapper' ) ) {
		$date_wrapper_id = $el['id'];
		break;
	}
}

if ( $date_wrapper_id ) {
	echo "=== CHILDREN OF DATE-WRAPPER ($date_wrapper_id) ===\n";
	$by_id = array();
	foreach ( $elements as $el ) { $by_id[ $el['id'] ] = $el; }

	$wrapper = $by_id[ $date_wrapper_id ];
	foreach ( ( $wrapper['children'] ?? array() ) as $child_id ) {
		$child = $by_id[ $child_id ] ?? null;
		if ( ! $child ) { echo "  MISSING: $child_id\n"; continue; }
		echo "  [{$child['name']}] id={$child['id']}\n";
		echo "  classes: " . ( $child['settings']['_cssClasses'] ?? '' ) . "\n";
		echo "  text: " . json_encode( $child['settings']['text'] ?? $child['settings']['content'] ?? '' ) . "\n";
		echo "  dynamic: " . json_encode( $child['settings']['useDynamicData'] ?? '' ) . "\n\n";
	}
}
