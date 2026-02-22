<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Test how json_encode handles actual newlines in CSS
$css = "position: absolute !important;\n  inline-size: 1px !important;\n  block-size: 1px !important;";
$attrs = array(
	'metadata'   => array( 'name' => 'Test' ),
	'tag'        => 'span',
	'attributes' => array( 'style' => $css ),
	'styles'     => array(),
);

$encoded = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
echo "Encoded:\n" . $encoded . "\n\n";

// Check how the newline appears in the output
$pos = strpos( $encoded, 'important;' );
if ( false !== $pos ) {
	echo "Chars after 'important;': " . substr( $encoded, $pos + 10, 5 ) . "\n";
	echo "Char codes: ";
	for ( $i = $pos + 10; $i < $pos + 15 && $i < strlen( $encoded ); $i++ ) {
		echo ord( $encoded[$i] ) . ' ';
	}
	echo "\n";
}

// Also test: does the css string actually have a real newline?
echo "\nCSS has real newline: " . ( false !== strpos( $css, "\n" ) ? 'YES' : 'NO' ) . "\n";
echo "CSS char at position 34: " . ord( $css[34] ) . " (10=LF)\n";
