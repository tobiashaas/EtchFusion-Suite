<?php
/**
 * Unit tests for Video Element Converter.
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
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'youtube.com/embed/dQw4w9WgXcQ', $result );
		$this->assertStringContainsString( '"tag":"iframe"', $result );
		$this->assertStringContainsString( '"data-etch-element":"iframe"', $result );
		$this->assertStringContainsString( 'Video (YouTube)', $result );
		$this->assertStringNotContainsString( '"tag":"figure"', $result );
	}

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
		$this->assertStringContainsString( 'wp:etch/element', $result );
		$this->assertStringContainsString( 'player.vimeo.com/video/123456789', $result );
		$this->assertStringContainsString( '"tag":"iframe"', $result );
		$this->assertStringContainsString( '"data-etch-element":"iframe"', $result );
		$this->assertStringContainsString( 'Video (Vimeo)', $result );
		$this->assertStringNotContainsString( '"tag":"figure"', $result );
	}

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
		$this->assertStringContainsString( '"width":"800"', $result );
		$this->assertStringContainsString( '"height":"450"', $result );
	}

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
		$this->assertStringContainsString( '"styles"', $result );
		$this->assertStringContainsString( 'etch-video-style', $result );
		$this->assertStringContainsString( 'etch-iframe-style', $result );
		$this->assertStringContainsString( '"class":"video-wrapper"', $result );
	}

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

	public function test_html5_video_empty_figcaption(): void {
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
		$this->assertStringContainsString( '"tag":"figcaption"', $result );
		$this->assertStringContainsString( '"content":""', $result );
	}

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
}
