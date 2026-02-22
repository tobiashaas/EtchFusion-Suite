<?php
/**
 * Single-post re-migration helper.
 *
 * Usage (on bricks-cli):
 *   wp --allow-root --path=/var/www/html eval-file \
 *       /var/www/html/wp-content/plugins/etch-fusion-suite/tests/migrate-single-post.php \
 *       -- <bricks_post_id> [<etch_post_id>]
 *
 * The converted Gutenberg content is printed to stdout and simultaneously
 * written to /tmp/efs_migrated_post.txt so it can be piped to the etch-cli.
 *
 * Example full round-trip:
 *   # 1. Convert on Bricks side → save to shared tmp file
 *   docker exec bricks-cli wp --allow-root --path=/var/www/html \
 *       eval-file /var/www/html/wp-content/plugins/etch-fusion-suite/tests/migrate-single-post.php \
 *       -- 25195
 *
 *   # 2. Update on Etch side using the saved file
 *   docker exec etch-cli wp --allow-root --path=/var/www/html \
 *       post update 929 \
 *       --post_content="$(cat /tmp/efs_migrated_post.txt)"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Args ────────────────────────────────────────────────────────────────────
$bricks_post_id = isset( $args[0] ) ? (int) $args[0] : 0;

if ( $bricks_post_id <= 0 ) {
	WP_CLI::error( 'Usage: migrate-single-post.php -- <bricks_post_id>' );
}

WP_CLI::log( "Converting Bricks post ID: $bricks_post_id …" );

// ── Bootstrap converter ──────────────────────────────────────────────────────
$plugin_dir = WP_CONTENT_DIR . '/plugins/etch-fusion-suite/';

// Make sure the container / autoloader is ready (plugin already loaded by WP).
$container = function_exists( 'etch_fusion_suite_container' )
	? etch_fusion_suite_container()
	: null;

if ( ! $container ) {
	WP_CLI::error( 'Plugin container not available. Is etch-fusion-suite active?' );
}

/** @var \Bricks2Etch\Parsers\EFS_Content_Parser $content_parser */
$content_parser = $container->get( 'content_parser' );

/** @var \Bricks2Etch\Parsers\EFS_Gutenberg_Generator $gutenberg_generator */
$gutenberg_generator = $container->get( 'gutenberg_generator' );

// ── Convert ──────────────────────────────────────────────────────────────────
$bricks_content = $content_parser->parse_bricks_content( $bricks_post_id );

if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
	WP_CLI::warning( "No Bricks content found for post $bricks_post_id – using raw post_content." );
	$post    = get_post( $bricks_post_id );
	$gutenberg = $post ? (string) $post->post_content : '';
} else {
	WP_CLI::log( 'Elements found: ' . count( $bricks_content['elements'] ) );
	$gutenberg = $gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );
}

if ( empty( $gutenberg ) ) {
	WP_CLI::error( 'Conversion produced empty output.' );
}

// ── Output ───────────────────────────────────────────────────────────────────
$tmp = '/tmp/efs_migrated_post.txt';
file_put_contents( $tmp, $gutenberg );

WP_CLI::success( "Converted. Content saved to $tmp" );
WP_CLI::log( '' );
WP_CLI::log( '--- CONTENT PREVIEW (first 500 chars) ---' );
WP_CLI::log( substr( $gutenberg, 0, 500 ) );
WP_CLI::log( '--- END PREVIEW ---' );
WP_CLI::log( '' );
WP_CLI::log( "To apply to Etch, run on etch-cli:" );
WP_CLI::log( "  wp --allow-root --path=/var/www/html post update <etch_post_id> --post_content=\"\$(cat $tmp)\"" );
