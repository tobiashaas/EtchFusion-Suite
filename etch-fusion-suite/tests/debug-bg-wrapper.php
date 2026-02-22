<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// 1. Check hero-cali__bg-wrapper Bricks class settings
$global = get_option( 'bricks_global_classes', array() );
foreach ( $global as $c ) {
	if ( false !== strpos( $c['name'] ?? '', 'bg-wrapper' ) && false !== strpos( $c['name'] ?? '', 'cali' ) ) {
		echo "Class: " . $c['name'] . "\n";
		echo "Settings: " . json_encode( $c['settings'] ?? [] ) . "\n\n";
	}
}

// 2. Find the bg-wrapper element in post 25194
$raw      = get_post_meta( 25194, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );
foreach ( $elements as $el ) {
	if ( 'block' !== ( $el['name'] ?? '' ) ) continue;
	$classes = $el['settings']['_cssGlobalClasses'] ?? [];
	// Check if any class looks like bg-wrapper
	$global_by_id = array();
	foreach ( get_option( 'bricks_global_classes', [] ) as $gc ) {
		$global_by_id[ $gc['id'] ] = $gc['name'] ?? '';
	}
	foreach ( $classes as $cid ) {
		if ( false !== strpos( $global_by_id[ $cid ] ?? '', 'bg-wrapper' ) ) {
			echo "=== Block element [{$el['id']}] ===\n";
			echo "_cssGlobalClasses: " . json_encode( array_map( fn($id) => $global_by_id[$id] ?? $id, $classes ) ) . "\n";
			echo "Settings keys: " . json_encode( array_keys( $el['settings'] ?? [] ) ) . "\n";
			$s = $el['settings'];
			echo "_width: "     . json_encode( $s['_width'] ?? null ) . "\n";
			echo "_display: "   . json_encode( $s['_display'] ?? null ) . "\n";
			echo "_direction: " . json_encode( $s['_direction'] ?? null ) . "\n";
			echo "\n";
		}
	}
}

// 3. Check current ACSS inline map for is-bg
$acss = get_option( 'efs_acss_inline_style_map', array() );
$is_bg = $acss['is-bg'] ?? '(not found)';
echo "ACSS inline map for is-bg: $is_bg\n";
