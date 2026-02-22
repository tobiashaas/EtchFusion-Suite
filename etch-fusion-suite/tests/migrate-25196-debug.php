<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$mapping = array( 25196 => 927 );

$container           = etch_fusion_suite_container();
$content_parser      = $container->get( 'content_parser' );
$gutenberg_generator = $container->get( 'gutenberg_generator' );

$bricks_id = 25196;
$etch_id   = 927;

$bricks_content = $content_parser->parse_bricks_content( $bricks_id );
if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
	echo "No Bricks content\n";
	exit;
}

$gutenberg = $gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );
file_put_contents( '/tmp/post_25196_debug.txt', $gutenberg );
echo "Generated " . strlen( $gutenberg ) . " chars\n";

// Show relevant lines
$lines = explode( "\n", $gutenberg );
foreach ( $lines as $line ) {
	if ( false !== strpos( $line, 'mediaId' ) || false !== strpos( $line, 'content.strip' ) ) {
		echo "  >>> " . trim( $line ) . "\n";
	}
}
