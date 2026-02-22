<?php
// Temporary: migrate Bricks post 25195 → save converted content to /tmp/efs_migrated_post.txt
if ( ! defined( 'ABSPATH' ) ) { exit; }
$bricks_post_id = 25195;
$container = etch_fusion_suite_container();
$content_parser = $container->get( 'content_parser' );
$gutenberg_generator = $container->get( 'gutenberg_generator' );
$bricks_content = $content_parser->parse_bricks_content( $bricks_post_id );
if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
	echo "NO BRICKS CONTENT\n";
	exit;
}
echo 'Elements: ' . count( $bricks_content['elements'] ) . "\n";
$gutenberg = $gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );
file_put_contents( '/tmp/efs_migrated_post.txt', $gutenberg );
echo 'Done. Length: ' . strlen( $gutenberg ) . "\n";
echo substr( $gutenberg, 0, 400 ) . "\n";
