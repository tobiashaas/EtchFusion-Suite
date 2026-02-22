<?php
// Check the converted output for post 21749 to see how the hidden-accessible style looks
if ( ! defined( 'ABSPATH' ) ) { exit; }

$file = '/tmp/efs_posts/21749_to_935.txt';
if ( ! file_exists( $file ) ) {
	echo "File not found: $file\n";
	exit;
}

$content = file_get_contents( $file );
$pos     = strpos( $content, 'hidden-accessible' );
if ( false === $pos ) {
	echo "No hidden-accessible found in output\n";
	exit;
}

// Get 400 chars around it
$excerpt = substr( $content, max( 0, $pos - 20 ), 400 );
echo "Raw excerpt around 'hidden-accessible':\n";
echo $excerpt . "\n\n";

// Find the style value
$style_pos = strpos( $content, '"style":"', $pos );
if ( false !== $style_pos ) {
	$style_end = strpos( $content, '"', $style_pos + 9 );
	$style_val = substr( $content, $style_pos + 9, 80 );
	echo "Style value (80 chars): " . $style_val . "\n";
	echo "Char codes: ";
	for ( $i = $style_pos + 9; $i < $style_pos + 50; $i++ ) {
		echo ord( $content[ $i ] ) . ' ';
	}
	echo "\n";
	echo "(10=newline, 92=backslash, 110=n)\n";
}
