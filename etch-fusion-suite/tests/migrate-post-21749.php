<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$bricks_post_id = 21749;
$container = etch_fusion_suite_container();
$content_parser = $container->get( 'content_parser' );
$gutenberg_generator = $container->get( 'gutenberg_generator' );
$bricks_content = $content_parser->parse_bricks_content( $bricks_post_id );
if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) { echo "NO BRICKS CONTENT\n"; exit; }
echo 'Elements: ' . count( $bricks_content['elements'] ) . "\n";
$gutenberg = $gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );
file_put_contents( '/tmp/efs_migrated_post.txt', $gutenberg );
echo 'Done. Length: ' . strlen( $gutenberg ) . "\n";

// Verify the fix
if ( strpos( $gutenberg, 'hidden-accessible' ) !== false ) {
	$pos     = strpos( $gutenberg, '"style":"position: absolute' );
	if ( $pos !== false ) {
		$excerpt = substr( $gutenberg, $pos, 120 );
		echo "\nStyle preview: " . $excerpt . "\n";
	}
}
