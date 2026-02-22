<?php
// Dump the raw Bricks elements for post 21749, find the hidden-accessible label
if ( ! defined( 'ABSPATH' ) ) { exit; }

$bricks_raw = get_post_meta( 21749, '_bricks_page_content_2', true );
$elements   = is_string( $bricks_raw ) ? json_decode( $bricks_raw, true ) : $bricks_raw;
if ( ! is_array( $elements ) ) {
	echo "Could not parse Bricks data\n";
	exit;
}

foreach ( $elements as $el ) {
	$label    = isset( $el['label'] ) ? $el['label'] : '';
	$settings = isset( $el['settings'] ) ? $el['settings'] : array();
	$name     = isset( $settings['_name'] ) ? $settings['_name'] : '';
	$cssClass = isset( $settings['_cssClasses'] ) ? $settings['_cssClasses'] : '';

	// Look for hidden-accessible or Label elements
	if (
		stripos( $label, 'label' ) !== false ||
		stripos( $label, 'hidden' ) !== false ||
		stripos( $cssClass, 'hidden' ) !== false
	) {
		echo "=== Element ID: " . $el['id'] . " ===\n";
		echo "Label: $label\n";
		echo "Name: $name\n";
		echo "CSS Classes: $cssClass\n";
		if ( isset( $settings['_style'] ) ) {
			echo "Inline style (repr): " . var_export( $settings['_style'], true ) . "\n";
		}
		if ( isset( $settings['_cssCustom'] ) ) {
			echo "Custom CSS (repr): " . var_export( substr( $settings['_cssCustom'], 0, 300 ), true ) . "\n";
		}
		echo "\n";
	}
}
