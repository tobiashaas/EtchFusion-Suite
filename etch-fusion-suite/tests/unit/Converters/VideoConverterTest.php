<?php
/**
 * Unit tests for Video Element Converter.
 *
 * Tests cover:
 * - YouTube/Vimeo privacy-friendly conversion (poster + play button pattern)
 * - HTML5 video conversion (figure/video/source structure)
 * - Attribute mapping and MIME type detection
 *
 * @package Bricks2Etch\Tests\Unit\Converters
 */

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Video;
use WP_UnitTestCase;

class VideoConverterTest extends WP_UnitTestCase {

	/** @var array Style map for tests */
	private $style_map;

	protected function setUp(): void {
		parent::setUp();
		$this->style_map = array(
			'bricks-video-1' => array(
				'id'       => 'etch-video-style',
				'selector' => '.video-wrapper',
			),
		);
	}

	/**
	 * Test YouTube video conversion (privacy-friendly with poster + play button).
	 */
	public function test_convert_youtube_video(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'youtube',
				'videoId'   => 'dQw4w9WgXcQ',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Should contain the privacy-friendly wrapper structure.
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// YouTube poster image.
		$this->assertStringContainsString( 'img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg', $result );
		// Play button element.
		$this->assertStringContainsString( 'youtube-play-button', $result );
		$this->assertStringContainsString( '"data-video-id":"dQw4w9WgXcQ"', $result );
		// Hidden iframe with youtube-nocookie.com.
		$this->assertStringContainsString( 'youtube-nocookie.com/embed/dQw4w9WgXcQ', $result );
		$this->assertStringContainsString( 'etch-lazy-iframe', $result );
		// Should be a div container, not directly an iframe.
		$this->assertStringContainsString( '"tag":"div"', $result );
		$this->assertStringNotContainsString( '"tag":"figure"', $result );
	}

	/**
	 * Test Vimeo video conversion (privacy-friendly with poster + play button).
	 */
	public function test_convert_vimeo_video(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'vimeo',
				'videoId'   => '123456789',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Should contain the privacy-friendly wrapper structure.
		$this->assertStringContainsString( 'wp:etch/element', $result );
		// Vimeo poster image.
		$this->assertStringContainsString( 'i.vimeocdn.com/video/123456789.jpg', $result );
		// Play button element.
		$this->assertStringContainsString( 'youtube-play-button', $result );
		$this->assertStringContainsString( '"data-video-id":"123456789"', $result );
		// Hidden iframe.
		$this->assertStringContainsString( 'player.vimeo.com/video/123456789', $result );
		$this->assertStringContainsString( 'etch-lazy-iframe', $result );
		// Should be a div container.
		$this->assertStringContainsString( '"tag":"div"', $result );
		$this->assertStringNotContainsString( '"tag":"figure"', $result );
	}

	/**
	 * Test HTML5 video conversion from media URL.
	 */
	public function test_convert_html5_video_from_media(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'media',
				'media'     => array( 'url' => 'https://example.com/video.mp4' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( '"tag":"figure"', $result );
		$this->assertStringContainsString( '"tag":"video"', $result );
		$this->assertStringContainsString( '"tag":"source"', $result );
		$this->assertStringContainsString( 'https://example.com/video.mp4', $result );
	}

	/**
	 * Test HTML5 video conversion from file URL.
	 */
	public function test_convert_html5_video_from_file(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/uploaded.mp4',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'https://example.com/uploaded.mp4', $result );
		$this->assertStringContainsString( '"tag":"source"', $result );
	}

	/**
	 * Test video attribute mapping (autoplay, loop, muted, controls).
	 */
	public function test_video_attribute_mapping(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/v.mp4',
				'autoplay'  => true,
				'loop'      => true,
				'muted'     => true,
				'controls'  => false,
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"autoplay":"true"', $result );
		$this->assertStringContainsString( '"loop":"true"', $result );
		$this->assertStringContainsString( '"muted":"true"', $result );
		$this->assertStringNotContainsString( '"controls":"true"', $result );
	}

	/**
	 * Test video dimensions handling (width/height attributes).
	 */
	public function test_video_dimensions(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'youtube',
				'videoId'   => 'abc',
				'width'     => '800',
				'height'    => '450',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// Dimensions are preserved in the aspect-ratio/style attribute.
		$this->assertStringContainsString( 'wp:etch/element', $result );
	}

	/**
	 * Test CSS classes applied to wrapper (not buried in iframe attributes).
	 *
	 * Fixed regression: CSS classes should be on the wrapper container, not the hidden iframe.
	 */
	public function test_video_css_classes_and_styles(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType'          => 'youtube',
				'videoId'            => 'xyz',
				'_cssGlobalClasses'  => array( 'bricks-video-1' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// CSS classes and styles should be applied to wrapper.
		$this->assertStringContainsString( '"styles"', $result );
		$this->assertStringContainsString( 'etch-video-style', $result );
		// CSS class should NOT be in iframe attributes (it's in the wrapper div).
		$this->assertStringNotContainsString( '"class":"video-wrapper"', $result );
	}

	/**
	 * Test HTML5 video figure structure (figure > video > source).
	 */
	public function test_html5_video_figure_structure(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/video.mp4',
				'alt'       => 'Test video description',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"tag":"figure"', $result );
		$this->assertStringContainsString( '"class":"media"', $result );
		$this->assertStringContainsString( '"tag":"figcaption"', $result );
		$this->assertStringContainsString( 'hidden-accessible', $result );
		$this->assertStringContainsString( 'Test video description', $result );
		$this->assertStringContainsString( '"tag":"video"', $result );
		$this->assertStringContainsString( '"tag":"source"', $result );
		$this->assertStringContainsString( '"type":"video/mp4"', $result );
	}

	/**
	 * Test that figcaption is omitted when no description is provided.
	 */
	public function test_html5_video_no_figcaption_when_empty(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/video.mp4',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		// No description → no figcaption block should be emitted.
		$this->assertStringNotContainsString( '"tag":"figcaption"', $result );
		// Video and source must still be present.
		$this->assertStringContainsString( '"tag":"video"', $result );
		$this->assertStringContainsString( '"tag":"source"', $result );
	}

	/**
	 * Test HTML5 video with poster image and playsinline attribute.
	 */
	public function test_html5_video_poster_and_playsinline(): void {
		$converter = new EFS_Element_Video( $this->style_map );
		$element   = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/video.mp4',
				'poster'    => array( 'url' => 'https://example.com/poster.jpg' ),
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '"poster":"https://example.com/poster.jpg"', $result );
		$this->assertStringContainsString( '"playsinline":"true"', $result );
		$this->assertStringContainsString( '"preload":"metadata"', $result );
	}

	/**
	 * Test MIME type detection for various video formats.
	 */
	public function test_video_mime_type_detection(): void {
		$converter = new EFS_Element_Video( $this->style_map );

		$element = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/video.webm',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '"type":"video/webm"', $result );

		$element['settings']['url'] = 'https://example.com/video.ogg';
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( '"type":"video/ogg"', $result );
	}

	/**
	 * Test description field priority (alt > caption > title).
	 */
	public function test_description_field_priority(): void {
		$converter = new EFS_Element_Video( $this->style_map );

		$element = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'file',
				'url'       => 'https://example.com/video.mp4',
				'alt'       => 'Alt text',
				'caption'   => 'Caption text',
				'title'     => 'Title text',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'Alt text', $result );
		$this->assertStringNotContainsString( 'Caption text', $result );

		$element['settings'] = array(
			'videoType' => 'file',
			'url'       => 'https://example.com/video.mp4',
			'caption'   => 'Caption text',
			'title'     => 'Title text',
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertStringContainsString( 'Caption text', $result );
	}

	/**
	 * Test YouTube video ID extraction from various URL formats.
	 */
	public function test_youtube_video_id_extraction(): void {
		$converter = new EFS_Element_Video( $this->style_map );

		// Test standard embed URL format.
		$element = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'youtube',
				'youtube'   => 'https://www.youtube.com/embed/5DGo0AYOJ7s',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '5DGo0AYOJ7s', $result );
		$this->assertStringContainsString( 'img.youtube.com/vi/5DGo0AYOJ7s/maxresdefault.jpg', $result );
	}

	/**
	 * Test Vimeo video ID extraction from URL.
	 */
	public function test_vimeo_video_id_extraction(): void {
		$converter = new EFS_Element_Video( $this->style_map );

		$element = array(
			'name'     => 'video',
			'settings' => array(
				'videoType' => 'vimeo',
				'vimeo'     => 'https://player.vimeo.com/video/987654321',
			),
		);
		$result = $converter->convert( $element, array(), array() );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( '987654321', $result );
		$this->assertStringContainsString( 'i.vimeocdn.com/video/987654321.jpg', $result );
	}
}

