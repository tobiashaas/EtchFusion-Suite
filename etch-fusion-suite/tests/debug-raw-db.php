<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
// Direct DB read, bypassing all WP filters
$row = $wpdb->get_row( "SELECT post_content FROM {$wpdb->posts} WHERE ID = 935", ARRAY_A );
$content = $row['post_content'] ?? '';

foreach ( explode( "\n", $content ) as $line ) {
	if ( false !== strpos( $line, 'dateFormat' ) ) {
		echo "RAW DB LINE: $line\n";
		$has_escaped = false !== strpos( $line, '\\"' );
		echo "Has escaped quotes (\\\"): " . ( $has_escaped ? 'YES' : 'NO' ) . "\n";
		// Validate JSON
		if ( preg_match( '/wp:etch\/[\w-]+ ({.+?})\s*--/', $line, $m ) ) {
			$decoded = json_decode( $m[1], true );
			echo "JSON valid: " . ( $decoded !== null ? 'YES' : 'NO - ' . json_last_error_msg() ) . "\n";
		}
		echo "\n";
	}
}
