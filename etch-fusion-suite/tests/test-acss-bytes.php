<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$map = get_option( 'efs_acss_inline_style_map', array() );
$css = isset( $map['acss_import_hidden-accessible'] ) ? $map['acss_import_hidden-accessible'] : '';
if ( ! $css ) { $css = isset( $map['hidden-accessible'] ) ? $map['hidden-accessible'] : ''; }
if ( ! $css ) { echo "Not found in map\n"; print_r( array_keys( $map ) ); exit; }

echo "CSS length: " . strlen( $css ) . "\n";
echo "First 60 chars repr: " . var_export( substr( $css, 0, 60 ), true ) . "\n";

// Find char after first semicolon
$semi_pos = strpos( $css, ';' );
if ( false !== $semi_pos ) {
	echo "Char after first ';' (pos " . ( $semi_pos + 1 ) . "): ord=" . ord( $css[ $semi_pos + 1 ] ) . "\n";
	echo "  (10=real newline, 92=backslash, 110=letter n)\n";
}
