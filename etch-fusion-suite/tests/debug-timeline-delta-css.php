<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$classes = get_option( 'bricks_global_classes', array() );
foreach ( $classes as $cls ) {
	if ( isset( $cls['name'] ) && false !== strpos( $cls['name'], 'fr-timeline-delta' ) ) {
		echo $cls['name'] . ":\n";
		$settings = $cls['settings'] ?? array();
		// Show gap, _gap, column-gap, row-gap, custom CSS
		foreach ( array( 'gap', '_gap', 'column-gap', 'row-gap', 'columnGap', 'rowGap', '_cssCustom', 'cssCustom' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				echo "  $key = " . json_encode( $settings[ $key ] ) . "\n";
			}
		}
		// Also show full settings for inspection
		echo "  FULL: " . json_encode( $settings ) . "\n\n";
	}
}
