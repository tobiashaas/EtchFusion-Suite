<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Test: Custom property names should NOT be renamed
$tests = array(
	'--image-width: 100%;'           => '--image-width: 100%;',        // var declaration - should NOT change
	'--content-width: 440px;'        => '--content-width: 440px;',     // var declaration - should NOT change
	'  width: 100%;'                 => '  inline-size: 100%;',        // regular property - SHOULD change
	'  max-width: 600px;'            => '  max-inline-size: 600px;',   // regular property - SHOULD change
	'width: var(--image-width);'     => 'inline-size: var(--image-width);', // property renamed, variable name not
	'--content-column-width: 30ch;'  => '--content-column-width: 30ch;', // var declaration - should NOT change
);

$physical_map = array(
	'width'     => 'inline-size',
	'height'    => 'block-size',
	'max-width' => 'max-inline-size',
);

$pass = 0;
$fail = 0;
foreach ( $tests as $input => $expected ) {
	$css = $input;
	foreach ( $physical_map as $physical => $logical ) {
		$css = preg_replace( '/(?<![a-zA-Z0-9_-])' . preg_quote( $physical, '/' ) . '\s*:/i', $logical . ':', $css );
	}
	if ( $css === $expected ) {
		echo "PASS: '$input' → '$css'\n";
		++$pass;
	} else {
		echo "FAIL: '$input'\n  Expected: '$expected'\n  Got:      '$css'\n";
		++$fail;
	}
}
echo "\n$pass passed, $fail failed.\n";
