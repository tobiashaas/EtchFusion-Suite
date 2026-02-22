<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$global = get_option( 'bricks_global_classes', array() );
foreach ( $global as $c ) {
	$name = $c['name'] ?? '';
	if ( false !== strpos( $name, 'bg-image' ) || false !== strpos( $name, '__image' ) ) {
		$settings = $c['settings'] ?? array();
		echo "$name:\n";
		echo "  _objectFit=" . json_encode( $settings['_objectFit'] ?? null ) . "\n";
		echo "  _width="     . json_encode( $settings['_width'] ?? null ) . "\n";
		echo "  _height="    . json_encode( $settings['_height'] ?? null ) . "\n";
		echo "  _cssCustom=" . json_encode( isset( $settings['_cssCustom'] ) ) . "\n";
		echo "  keys=" . json_encode( array_keys( $settings ) ) . "\n\n";
	}
}
