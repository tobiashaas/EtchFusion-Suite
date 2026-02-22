<?php
/**
 * Re-migrate ALL Bricks posts using fresh converter code.
 * Runs on bricks-cli, saves individual files to /tmp/efs_posts/
 *
 * Usage: wp --allow-root --path=/var/www/html eval-file .../remigrate-all-posts.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Bricks ID => Etch ID mapping (from _b2e_original_post_id meta on etch site)
$mapping = array(
	25199 => 953,
	25197 => 925,
	25196 => 927,
	25195 => 929,
	25194 => 933,
	25192 => 958,
	21749 => 935,
	19772 => 898,
	16790 => 937,
	10083 => 899,
	10084 => 900,
	8296  => 944,
	4042  => 946,
	3085  => 948,
	2577  => 47,
	2487  => 901,
	180   => 902,
	178   => 903,
	175   => 904,
);

$container           = etch_fusion_suite_container();
$content_parser      = $container->get( 'content_parser' );
$gutenberg_generator = $container->get( 'gutenberg_generator' );

$out_dir = '/tmp/efs_posts';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0755, true );
}

$ok   = 0;
$fail = 0;

foreach ( $mapping as $bricks_id => $etch_id ) {
	$bricks_content = $content_parser->parse_bricks_content( $bricks_id );

	if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
		$post      = get_post( $bricks_id );
		$gutenberg = $post ? (string) $post->post_content : '';
		if ( empty( $gutenberg ) ) {
			echo "SKIP  $bricks_id → $etch_id (no content)\n";
			++$fail;
			continue;
		}
	} else {
		$gutenberg = $gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );
	}

	if ( empty( $gutenberg ) ) {
		echo "FAIL  $bricks_id → $etch_id (empty output)\n";
		++$fail;
		continue;
	}

	file_put_contents( "$out_dir/{$bricks_id}_to_{$etch_id}.txt", $gutenberg );
	echo "OK    $bricks_id → $etch_id (" . strlen( $gutenberg ) . " chars)\n";
	++$ok;
}

echo "\nDone: $ok converted, $fail failed.\n";
echo "Files saved to $out_dir\n";
