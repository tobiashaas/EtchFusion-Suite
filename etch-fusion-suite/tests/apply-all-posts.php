<?php
/**
 * Apply all pre-converted post files from /tmp/efs_posts/ to the Etch site.
 * Runs on etch-cli.
 *
 * Usage: wp --allow-root --path=/var/www/html eval-file .../apply-all-posts.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$out_dir = '/tmp/efs_posts';
$files   = glob( "$out_dir/*_to_*.txt" );

if ( empty( $files ) ) {
	echo "No files found in $out_dir\n";
	exit;
}

$ok   = 0;
$fail = 0;

foreach ( $files as $file ) {
	$basename = basename( $file, '.txt' );
	if ( ! preg_match( '/^(\d+)_to_(\d+)$/', $basename, $m ) ) {
		continue;
	}
	$bricks_id = (int) $m[1];
	$etch_id   = (int) $m[2];

	$content = file_get_contents( $file );
	if ( ! $content ) {
		echo "SKIP  $file (empty)\n";
		++$fail;
		continue;
	}

	$result = wp_update_post( array(
		'ID'           => $etch_id,
		'post_content' => $content,
	), true );

	if ( is_wp_error( $result ) ) {
		echo "FAIL  $bricks_id → $etch_id: " . $result->get_error_message() . "\n";
		++$fail;
	} else {
		echo "OK    $bricks_id → $etch_id\n";
		++$ok;
	}
}

echo "\nDone: $ok updated, $fail failed.\n";
