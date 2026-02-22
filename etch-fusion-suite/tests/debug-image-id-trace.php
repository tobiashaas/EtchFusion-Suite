<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Simulate exactly what happens when converting the hero-cali image element
$container = etch_fusion_suite_container();
$converter  = $container->get( 'element_factory' );
$ddc        = $container->get( 'dynamic_data_converter' );

// Load the hero-cali post data
$raw      = get_post_meta( 25194, '_bricks_page_content_2', true );
$elements = is_array( $raw ) ? $raw : json_decode( $raw, true );

// Find the image element
$img_el = null;
foreach ( $elements as $el ) {
	if ( 'image' === ( $el['name'] ?? '' ) && 'mwughu' === ( $el['id'] ?? '' ) ) {
		$img_el = $el;
		break;
	}
}

if ( ! $img_el ) { echo "Image element not found\n"; exit; }

echo "=== Image element settings ===\n";
echo "image field: " . json_encode( $img_el['settings']['image'] ?? '' ) . "\n\n";

// Simulate loop context
$context = array(
	'scope'      => 'loop',
	'loop_alias' => 'item',
	'loop_query' => array( 'objectType' => 'post' ),
);

// Convert element
$block_html = $converter->convert_element( $img_el, array(), array() );
echo "=== Block HTML (before convert_content) ===\n";
echo $block_html . "\n\n";

// Apply dynamic data conversion
$converted = $ddc->convert_content( $block_html, $context );
echo "=== Block HTML (after convert_content) ===\n";
echo $converted . "\n";
