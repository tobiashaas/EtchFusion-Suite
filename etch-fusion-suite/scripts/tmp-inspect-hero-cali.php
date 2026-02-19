<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
$mode    = isset( $args[1] ) ? (string) $args[1] : 'bricks';

if ( $post_id <= 0 ) {
	echo "missing-post-id\n";
	return;
}

if ( 'bricks' === $mode ) {
	$elements = get_post_meta( $post_id, '_bricks_page_content_2', true );
	$globals  = get_option( 'bricks_global_classes', array() );
	$name_map = array();

	if ( is_array( $globals ) ) {
		foreach ( $globals as $global_class ) {
			if ( ! is_array( $global_class ) || empty( $global_class['id'] ) ) {
				continue;
			}
			$id   = (string) $global_class['id'];
			$name = isset( $global_class['name'] ) ? (string) $global_class['name'] : '';
			$name = ltrim( preg_replace( '/^acss_import_/', '', $name ), '.' );
			if ( '' !== $name ) {
				$name_map[ $id ] = $name;
			}
		}
	}

	foreach ( (array) $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$classes  = array();

		if ( isset( $settings['_cssClasses'] ) && is_string( $settings['_cssClasses'] ) && '' !== trim( $settings['_cssClasses'] ) ) {
			$classes = array_merge( $classes, preg_split( '/\s+/', trim( $settings['_cssClasses'] ) ) );
		}

		if ( ! empty( $settings['_cssGlobalClasses'] ) && is_array( $settings['_cssGlobalClasses'] ) ) {
			foreach ( $settings['_cssGlobalClasses'] as $class_id ) {
				$class_id = (string) $class_id;
				if ( isset( $name_map[ $class_id ] ) ) {
					$classes[] = $name_map[ $class_id ];
				}
			}
		}

		$classes = array_values( array_unique( array_filter( $classes ) ) );
		if ( empty( $classes ) ) {
			continue;
		}

		$element_id   = isset( $element['id'] ) ? (string) $element['id'] : '';
		$element_name = isset( $element['name'] ) ? (string) $element['name'] : '';
		echo $element_id . '|' . $element_name . '|' . implode( ' ', $classes ) . "\n";
	}
	return;
}

$content = (string) get_post_field( 'post_content', $post_id );
if ( preg_match_all( '/"class":"([^"]+)"/', $content, $matches ) ) {
	$classes = array();
	foreach ( $matches[1] as $class_blob ) {
		$class_blob = str_replace( '\u002d', '-', (string) $class_blob );
		$parts      = preg_split( '/\s+/', trim( $class_blob ) );
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part ) {
				$classes[] = $part;
			}
		}
	}
	$classes = array_values( array_unique( $classes ) );
	sort( $classes );
	foreach ( $classes as $class_name ) {
		echo $class_name . "\n";
	}
}
