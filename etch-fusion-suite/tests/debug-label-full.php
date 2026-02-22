<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$bricks_raw = get_post_meta( 21749, '_bricks_page_content_2', true );
$elements   = is_string( $bricks_raw ) ? json_decode( $bricks_raw, true ) : $bricks_raw;

foreach ( $elements as $el ) {
	$label = isset( $el['label'] ) ? $el['label'] : '';
	if ( stripos( $label, 'label' ) !== false && stripos( $label, 'hidden' ) !== false ) {
		echo "ID: " . $el['id'] . "\n";
		echo "Name: " . ( $el['name'] ?? 'n/a' ) . "\n";
		echo "Tag: " . ( $el['settings']['tag'] ?? $el['settings']['_tag'] ?? 'n/a' ) . "\n";
		$settings = $el['settings'] ?? array();
		foreach ( $settings as $k => $v ) {
			if ( is_scalar( $v ) && $v !== '' ) {
				echo "  $k: " . substr( (string) $v, 0, 200 ) . "\n";
			}
		}
		echo "\n";
		break; // just first one
	}
}
