<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Check ACSS inline style map for hidden-accessible
$acss_map = get_option( 'efs_acss_inline_style_map', array() );
echo 'ACSS map entries: ' . count( $acss_map ) . "\n";

// Search by class name (value) — map is classId => CSS declarations
$global_classes = get_option( 'bricks_global_classes', array() );
if ( is_string( $global_classes ) ) {
	$global_classes = json_decode( $global_classes, true ) ?: array();
}

foreach ( $global_classes as $class ) {
	$name = $class['name'] ?? '';
	if ( stripos( $name, 'hidden' ) !== false ) {
		$id  = $class['id'] ?? '';
		echo "Class: $name (ID: $id)\n";
		if ( isset( $acss_map[ $id ] ) ) {
			echo "Inline CSS (repr): " . var_export( $acss_map[ $id ], true ) . "\n";
		} else {
			echo "(not in ACSS map)\n";
		}

		// Show raw CSS from class
		$settings = $class['settings'] ?? array();
		if ( isset( $settings['css'] ) ) {
			echo "Raw CSS (repr): " . var_export( substr( $settings['css'], 0, 400 ), true ) . "\n";
		}
		echo "\n";
	}
}
