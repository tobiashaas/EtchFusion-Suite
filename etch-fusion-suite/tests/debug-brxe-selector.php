<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Find where [class*=brxe-] appears in Bricks global classes
$global = get_option( 'bricks_global_classes', array() );
$count  = 0;
foreach ( $global as $c ) {
	$settings = $c['settings'] ?? array();
	foreach ( $settings as $key => $value ) {
		if ( is_string( $value ) && false !== strpos( $value, 'brxe-' ) ) {
			echo "Class: " . ( $c['name'] ?? '' ) . ", key=$key, val=" . substr( $value, 0, 100 ) . "\n";
			$count++;
		}
	}
}
echo "Total classes with brxe- in CSS: $count\n\n";

// Also check in the CSS custom property
$acss = get_option( 'efs_acss_inline_style_map', array() );
foreach ( $acss as $name => $css ) {
	if ( false !== strpos( $css, 'brxe-' ) ) {
		echo "ACSS: $name\n";
	}
}

// Check what the generated CSS looks like for a class with brxe-
// Find one example
foreach ( $global as $c ) {
	$css = $c['settings']['_cssCustom'] ?? '';
	if ( is_string( $css ) && false !== strpos( $css, 'brxe-' ) ) {
		echo "\n=== Example CSS custom with brxe- ===\n";
		echo "Class: " . ( $c['name'] ?? '' ) . "\n";
		echo substr( $css, 0, 300 ) . "\n";
		break;
	}
}
