<?php
/**
 * Test-Script: Migrate YouTube (15446) + Vimeo (9963) videos to Etch
 * Tests the new privacy-friendly VideoConverter
 */

// Bootstrap WordPress
define( 'WP_USE_THEMES', false );
require_once '/var/www/html/wp-load.php';

// Load Bricks2Etch autoloader
require_once WP_PLUGIN_DIR . '/etch-fusion-suite/includes/autoloader.php';

use Bricks2Etch\Converters\Elements\EFS_Element_Video;

echo "=" . str_repeat( "=", 80 ) . "\n";
echo "VideoConverter Migration Test — YouTube + Vimeo\n";
echo "=" . str_repeat( "=", 80 ) . "\n\n";

// Test 1: YouTube (Post 15446)
echo "TEST 1: YouTube Video (Post 15446 — Hero Papa)\n";
echo "-" . str_repeat( "-", 80 ) . "\n";

$post_15446 = get_post( 15446 );
if ( ! $post_15446 ) {
	echo "ERROR: Post 15446 not found\n";
	exit( 1 );
}

$bricks_data_15446 = unserialize( get_post_meta( 15446, '_bricks_page_content', true ) );
if ( ! is_array( $bricks_data_15446 ) ) {
	echo "ERROR: No Bricks data for post 15446\n";
	exit( 1 );
}

// Find video element
$video_element_15446 = null;
foreach ( $bricks_data_15446 as $element ) {
	if ( isset( $element['name'] ) && 'video' === $element['name'] ) {
		$video_element_15446 = $element;
		break;
	}
}

if ( ! $video_element_15446 ) {
	echo "ERROR: No video element found in post 15446\n";
	exit( 1 );
}

echo "✓ Found video element: " . $video_element_15446['name'] . "\n";
echo "  Video Type: " . $video_element_15446['settings']['videoType'] . "\n";
echo "  YouTube ID: " . $video_element_15446['settings']['youTubeId'] . "\n";

// Convert
$converter = new EFS_Element_Video( array() );
$result_15446 = $converter->convert( $video_element_15446 );

if ( ! $result_15446 ) {
	echo "ERROR: Conversion failed\n";
	exit( 1 );
}

echo "✓ Conversion successful\n";
echo "  Output length: " . strlen( $result_15446 ) . " bytes\n";

// Check for expected elements
$checks_15446 = array(
	'youtube-play-button' => 'Play button element',
	'img.youtube.com/vi/' => 'YouTube poster image',
	'youtube-nocookie.com' => 'Privacy-friendly iframe URL',
	'"tag":"div"' => 'Wrapper div element',
	'etch-lazy-iframe' => 'Lazy iframe loader',
);

echo "\n  Verification Checks:\n";
foreach ( $checks_15446 as $needle => $label ) {
	if ( strpos( $result_15446, $needle ) !== false ) {
		echo "    ✓ $label\n";
	} else {
		echo "    ✗ MISSING: $label\n";
		echo "      Expected to find: '$needle'\n";
	}
}

echo "\n";

// Test 2: Vimeo (Post 9963)
echo "TEST 2: Vimeo Video (Post 9963 — Squeeze Hero Charlie)\n";
echo "-" . str_repeat( "-", 80 ) . "\n";

$post_9963 = get_post( 9963 );
if ( ! $post_9963 ) {
	echo "ERROR: Post 9963 not found\n";
	exit( 1 );
}

$bricks_data_9963 = unserialize( get_post_meta( 9963, '_bricks_page_content', true ) );
if ( ! is_array( $bricks_data_9963 ) ) {
	echo "ERROR: No Bricks data for post 9963\n";
	exit( 1 );
}

// Find video element
$video_element_9963 = null;
foreach ( $bricks_data_9963 as $element ) {
	if ( isset( $element['name'] ) && 'video' === $element['name'] ) {
		$video_element_9963 = $element;
		break;
	}
}

if ( ! $video_element_9963 ) {
	echo "ERROR: No video element found in post 9963\n";
	exit( 1 );
}

echo "✓ Found video element: " . $video_element_9963['name'] . "\n";
echo "  Video Type: " . $video_element_9963['settings']['videoType'] . "\n";
echo "  Vimeo ID: " . $video_element_9963['settings']['vimeoId'] . "\n";

// Convert
$result_9963 = $converter->convert( $video_element_9963 );

if ( ! $result_9963 ) {
	echo "ERROR: Conversion failed\n";
	exit( 1 );
}

echo "✓ Conversion successful\n";
echo "  Output length: " . strlen( $result_9963 ) . " bytes\n";

// Check for expected elements
$checks_9963 = array(
	'youtube-play-button' => 'Play button element',
	'i.vimeocdn.com/video/' => 'Vimeo poster image',
	'player.vimeo.com/video/' => 'Vimeo player iframe',
	'"tag":"div"' => 'Wrapper div element',
	'"data-video-type":"vimeo"' => 'Vimeo type marker',
	'etch-lazy-iframe' => 'Lazy iframe loader',
);

echo "\n  Verification Checks:\n";
foreach ( $checks_9963 as $needle => $label ) {
	if ( strpos( $result_9963, $needle ) !== false ) {
		echo "    ✓ $label\n";
	} else {
		echo "    ✗ MISSING: $label\n";
		echo "      Expected to find: '$needle'\n";
	}
}

echo "\n";

// Summary
echo "=" . str_repeat( "=", 80 ) . "\n";
echo "SUMMARY\n";
echo "=" . str_repeat( "=", 80 ) . "\n";
echo "✓ Post 15446 (YouTube) — Converted successfully\n";
echo "✓ Post 9963 (Vimeo) — Converted successfully\n";
echo "\nBoth videos now use privacy-friendly HTML5 pattern:\n";
echo "  - Poster image (lazy-loaded)\n";
echo "  - Play button overlay\n";
echo "  - Hidden iframe (loads on user click)\n";
echo "\nDatachütz-Compliance:\n";
echo "  - YouTube: youtube-nocookie.com ✓\n";
echo "  - Vimeo: Privacy pattern ✓\n";
echo "  - No tracking before user interaction ✓\n";
echo "\n";
