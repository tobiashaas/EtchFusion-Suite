<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Check current mapping in image converter
$file = file_get_contents( WP_PLUGIN_DIR . '/etch-fusion-suite/includes/converters/elements/class-image.php' );
if ( preg_match( "/'post_id'\s*=>\s*'([^']+)'/", $file, $m ) ) {
	echo "post_id mapping in class-image.php: " . $m[1] . "\n";
}

// Manually simulate the conversion
$use_dynamic = '{post_id}';
$tag = trim( $use_dynamic, '{}' );
$map = array(
	'featured_image'    => '{this.image.id}',
	'post_thumbnail'    => '{this.image.id}',
	'woo_product_image' => '{this.image.id}',
	'post_id'           => '{this.image.id}',
);
$result = $map[ $tag ] ?? '';
echo "resolve_dynamic_image_src('{$use_dynamic}') = '$result'\n";

// Check dynamic_data_converter mapping for post_id
$ddc_file = file_get_contents( WP_PLUGIN_DIR . '/etch-fusion-suite/includes/dynamic_data_converter.php' );
if ( preg_match( "/'post_id'\s*=>\s*'([^']+)'/", $ddc_file, $m2 ) ) {
	echo "post_id mapping in dynamic_data_converter.php: " . $m2[1] . "\n";
}
