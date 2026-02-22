<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$post = get_post( 935 );
$content = $post->post_content;

// Find the time block line
$lines = explode( "\n", $content );
foreach ( $lines as $line ) {
	if ( false !== strpos( $line, 'dateFormat' ) ) {
		echo "RAW LINE: " . $line . "\n";
		// Check for backslash-escaped quotes
		$has_escaped = false !== strpos( $line, '\\"' );
		echo "Has escaped quotes (\\\"): " . ( $has_escaped ? 'YES' : 'NO' ) . "\n";
		// Try to extract the JSON part
		if ( preg_match( '/wp:etch\/[\w-]+ ({.+?})\s*--/', $line, $m ) ) {
			$json_str = $m[1];
			$decoded = json_decode( $json_str, true );
			echo "JSON valid: " . ( $decoded !== null ? 'YES' : 'NO - ERROR: ' . json_last_error_msg() ) . "\n";
			if ( $decoded ) {
				$attrs = $decoded['attributes'] ?? array();
				echo "datetime attr: " . json_encode( $attrs['datetime'] ?? 'NOT SET' ) . "\n";
				echo "content: " . json_encode( $decoded['content'] ?? 'NOT SET' ) . "\n";
			}
		}
		echo "\n";
	}
}
