#!/usr/bin/env php
<?php
/**
 * Test: Migrate YouTube (15446) + Vimeo (9963) videos
 */

// Load WordPress
$found = false;
$paths = array(
	'/var/www/html/wp-load.php',
	dirname( __FILE__ ) . '/../../../wp-load.php',
);

foreach ( $paths as $path ) {
	if ( file_exists( $path ) ) {
		require_once $path;
		$found = true;
		break;
	}
}

if ( ! $found ) {
	echo "ERROR: Could not find wp-load.php\n";
	exit( 1 );
}

// Load plugin
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/autoloader.php';

use Bricks2Etch\Converters\Elements\EFS_Element_Video;

echo "==============================================\n";
echo "VIDEO CONVERTER MIGRATION TEST\n";
echo "==============================================\n\n";

$converter = new EFS_Element_Video( array() );
$tests    = array(
	15446 => 'YouTube (Hero Papa)',
	9963  => 'Vimeo (Squeeze Hero Charlie)',
);

foreach ( $tests as $post_id => $label ) {
	echo "TEST: $label (Post $post_id)\n";
	echo "----------------------------------------------\n";

	$bricks_data = unserialize( get_post_meta( $post_id, '_bricks_page_content', true ) );

	if ( ! is_array( $bricks_data ) ) {
		echo "ERROR: No Bricks data\n\n";
		continue;
	}

	$video_element = null;
	foreach ( $bricks_data as $element ) {
		if ( isset( $element['name'] ) && 'video' === $element['name'] ) {
			$video_element = $element;
			break;
		}
	}

	if ( ! $video_element ) {
		echo "ERROR: No video element\n\n";
		continue;
	}

	$type = $video_element['settings']['videoType'];
	echo "Video Type: $type\n";

	if ( 'youtube' === $type ) {
		echo "YouTube ID: " . $video_element['settings']['youTubeId'] . "\n";
	} elseif ( 'vimeo' === $type ) {
		echo "Vimeo ID: " . $video_element['settings']['vimeoId'] . "\n";
	}

	$result = $converter->convert( $video_element );

	if ( ! $result ) {
		echo "Conversion: FAILED\n\n";
		continue;
	}

	echo "Conversion: OK\n";
	echo "Output size: " . strlen( $result ) . " bytes\n";

	// Check structure
	$checks = array(
		'youtube-play-button'   => 'Play button',
		'img.youtube.com'       => 'YouTube poster',
		'i.vimeocdn.com'        => 'Vimeo poster',
		'youtube-nocookie.com'  => 'Privacy URL',
		'player.vimeo.com'      => 'Vimeo player',
		'"tag":"div"'           => 'Wrapper div',
		'etch-lazy-iframe'      => 'Lazy loader',
	);

	foreach ( $checks as $search => $label ) {
		if ( strpos( $result, $search ) !== false ) {
			echo "  ✓ $label\n";
		}
	}

	echo "\n";
}

echo "==============================================\n";
echo "ALL TESTS COMPLETED\n";
echo "==============================================\n";
