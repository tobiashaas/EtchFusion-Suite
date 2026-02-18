<?php
/**
 * Video Element Converter
 *
 * Converts Bricks video elements to Etch element blocks.
 * Supports YouTube, Vimeo, and HTML5 (media/file) videos.
 *
 * @package Bricks_Etch_Migration
 * @subpackage Converters\Elements
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Video extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'video';

	/**
	 * Convert Bricks video element to Etch block HTML.
	 *
	 * @param array $element Bricks element.
	 * @param array $children Child elements (unused).
	 * @param array $context Optional conversion context.
	 * @return string|null Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings    = $element['settings'] ?? array();
		$video_type  = isset( $settings['videoType'] ) ? (string) $settings['videoType'] : 'media';
		$style_ids   = $this->get_style_ids( $element );
		$css_classes = $this->get_css_classes( $style_ids );
		$width       = isset( $settings['width'] ) ? (string) $settings['width'] : '640';
		$height      = isset( $settings['height'] ) ? (string) $settings['height'] : '360';

		switch ( $video_type ) {
			case 'youtube':
				$youtube_id = $this->get_video_id( $settings, 'youtube' );
				if ( '' === $youtube_id ) {
					return null;
				}

				return $this->convert_embed_video(
					'Video (YouTube)',
					$this->build_youtube_embed_url( $youtube_id, $settings ),
					$style_ids,
					$css_classes,
					$width,
					$height,
					$element
				);

			case 'vimeo':
				$vimeo_id = $this->get_video_id( $settings, 'vimeo' );
				if ( '' === $vimeo_id ) {
					return null;
				}

				return $this->convert_embed_video(
					'Video (Vimeo)',
					$this->build_vimeo_embed_url( $vimeo_id, $settings ),
					$style_ids,
					$css_classes,
					$width,
					$height,
					$element
				);

			case 'media':
			case 'file':
				return $this->convert_html5_video( $element, $settings, $style_ids, $css_classes, $width, $height );

			default:
				return null;
		}
	}

	/**
	 * Convert YouTube/Vimeo to an iframe Etch element block.
	 *
	 * @param string $label Block label.
	 * @param string $embed_url Embed URL.
	 * @param array  $style_ids Style IDs.
	 * @param string $css_classes CSS class list.
	 * @param string $width Frame width.
	 * @param string $height Frame height.
	 * @return string
	 */
	private function convert_embed_video( $label, $embed_url, $style_ids, $css_classes, $width, $height, $element = array() ) {
		$iframe_attrs = array(
			'data-etch-element' => 'iframe',
			'src'               => $embed_url,
			'width'             => $width,
			'height'            => $height,
			'frameborder'       => '0',
			'allow'             => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
			'allowfullscreen'   => 'true',
		);

		if ( '' !== trim( $css_classes ) ) {
			$iframe_attrs['class'] = $css_classes;
		}

		$iframe_styles = $style_ids;
		array_unshift( $iframe_styles, 'etch-iframe-style' );
		$iframe_styles = array_values( array_unique( $iframe_styles ) );

		$attrs = $this->build_attributes( $label, $iframe_styles, $iframe_attrs, 'iframe', $element );

		return $this->generate_etch_element_block( $attrs );
	}

	/**
	 * Convert HTML5 videos to figure/figcaption/video/source inner blocks.
	 *
	 * @param array  $element Bricks element.
	 * @param array  $settings Video settings.
	 * @param array  $style_ids Style IDs.
	 * @param string $css_classes CSS class list.
	 * @param string $width Width value.
	 * @param string $height Height value.
	 * @return string|null
	 */
	private function convert_html5_video( $element, $settings, $style_ids, $css_classes, $width, $height ) {
		$video_url = $this->get_video_url( $settings );
		if ( '' === $video_url ) {
			return null;
		}

		$description    = $this->get_video_description( $settings );
		$video_mime     = $this->get_video_mime_type( $video_url );
		$figure_classes = trim( 'media ' . $css_classes );
		$figure_attrs   = array(
			'class' => '' !== $figure_classes ? $figure_classes : 'media',
		);

		$label = $this->get_label( $element );
		if ( '' === trim( $label ) ) {
			$label = 'Video';
		}

		$figcaption_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Video Caption', array(), array( 'class' => 'hidden-accessible' ), 'figcaption' ),
			$this->generate_etch_text_block( wp_kses_post( $description ) )
		);

		$video_attrs  = $this->build_html5_video_attributes( $settings, $width, $height );
		$source_block = $this->generate_etch_element_block(
			$this->build_attributes(
				'Video Source',
				array(),
				array(
					'src'  => $video_url,
					'type' => $video_mime,
				),
				'source'
			)
		);

		$video_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Video Element', array(), $video_attrs, 'video' ),
			array( $source_block )
		);

		return $this->generate_etch_element_block(
			$this->build_attributes( $label, $style_ids, $figure_attrs, 'figure', $element ),
			array( $figcaption_block, $video_block )
		);
	}

	/**
	 * Build HTML5 video attributes.
	 *
	 * @param array  $settings Bricks settings.
	 * @param string $width Width value.
	 * @param string $height Height value.
	 * @return array<string,string>
	 */
	private function build_html5_video_attributes( $settings, $width, $height ) {
		$attrs = array(
			'width'       => $width,
			'height'      => $height,
			'preload'     => 'metadata',
			'playsinline' => 'true',
		);

		if ( ! empty( $settings['autoplay'] ) ) {
			$attrs['autoplay'] = 'true';
		}
		if ( ! empty( $settings['loop'] ) ) {
			$attrs['loop'] = 'true';
		}
		if ( ! empty( $settings['muted'] ) ) {
			$attrs['muted'] = 'true';
		}
		if ( ! isset( $settings['controls'] ) || (bool) $settings['controls'] ) {
			$attrs['controls'] = 'true';
		}
		if ( isset( $settings['poster']['url'] ) && is_string( $settings['poster']['url'] ) && '' !== trim( $settings['poster']['url'] ) ) {
			$attrs['poster'] = $settings['poster']['url'];
		}

		return $attrs;
	}

	/**
	 * Get a human-readable description for figcaption.
	 *
	 * @param array $settings Bricks settings.
	 * @return string
	 */
	private function get_video_description( $settings ) {
		$fields = array(
			$settings['alt'] ?? '',
			$settings['caption'] ?? '',
			$settings['title'] ?? '',
			$settings['overlay']['content'] ?? '',
		);

		foreach ( $fields as $field ) {
			if ( is_string( $field ) && '' !== trim( $field ) ) {
				return $field;
			}
		}

		return '';
	}

	/**
	 * Resolve video ID from settings for embed providers.
	 *
	 * @param array  $settings Bricks settings.
	 * @param string $provider youtube|vimeo
	 * @return string
	 */
	private function get_video_id( $settings, $provider ) {
		$raw = $settings[ $provider ] ?? ( $settings['videoId'] ?? '' );

		if ( is_array( $raw ) ) {
			$raw = $raw['id'] ?? ( $raw['videoId'] ?? '' );
		}

		if ( ! is_string( $raw ) ) {
			return '';
		}

		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		if ( strpos( $raw, 'http://' ) === 0 || strpos( $raw, 'https://' ) === 0 ) {
			return $this->extract_id_from_url( $raw, $provider );
		}

		return $raw;
	}

	/**
	 * Extract provider video ID from URL.
	 *
	 * @param string $url Provider URL.
	 * @param string $provider youtube|vimeo.
	 * @return string
	 */
	private function extract_id_from_url( $url, $provider ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		if ( 'youtube' === $provider ) {
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
				if ( isset( $query['v'] ) && is_string( $query['v'] ) && '' !== $query['v'] ) {
					return $query['v'];
				}
			}

			if ( ! empty( $parts['path'] ) ) {
				$path_parts = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
				if ( ! empty( $path_parts ) ) {
					return (string) end( $path_parts );
				}
			}
		}

		if ( 'vimeo' === $provider && ! empty( $parts['path'] ) ) {
			$path_parts = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
			if ( ! empty( $path_parts ) ) {
				return (string) end( $path_parts );
			}
		}

		return '';
	}

	/**
	 * Build YouTube embed URL with query parameters.
	 *
	 * @param string $video_id YouTube video ID.
	 * @param array  $settings Bricks element settings.
	 * @return string Embed URL.
	 */
	protected function build_youtube_embed_url( $video_id, $settings ) {
		$params             = array();
		$params['autoplay'] = ! empty( $settings['autoplay'] ) ? '1' : '0';
		$params['loop']     = ! empty( $settings['loop'] ) ? '1' : '0';
		$params['muted']    = ! empty( $settings['muted'] ) ? '1' : '0';
		$params['controls'] = isset( $settings['controls'] ) && $settings['controls'] ? '1' : '0';
		$query              = http_build_query( $params );
		return 'https://www.youtube.com/embed/' . $video_id . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Build Vimeo embed URL with query parameters.
	 *
	 * @param string $video_id Vimeo video ID.
	 * @param array  $settings Bricks element settings.
	 * @return string Embed URL.
	 */
	protected function build_vimeo_embed_url( $video_id, $settings ) {
		$params             = array();
		$params['autoplay'] = ! empty( $settings['autoplay'] ) ? '1' : '0';
		$params['loop']     = ! empty( $settings['loop'] ) ? '1' : '0';
		$params['muted']    = ! empty( $settings['muted'] ) ? '1' : '0';
		$query              = http_build_query( $params );
		return 'https://player.vimeo.com/video/' . $video_id . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Get video URL from media or file settings.
	 *
	 * @param array $settings Bricks element settings.
	 * @return string Video URL or empty string.
	 */
	protected function get_video_url( $settings ) {
		$video_type = isset( $settings['videoType'] ) ? $settings['videoType'] : 'media';

		if ( 'file' === $video_type ) {
			return isset( $settings['url'] ) ? (string) $settings['url'] : '';
		}

		$media = isset( $settings['media'] ) && is_array( $settings['media'] ) ? $settings['media'] : array();
		$id    = isset( $media['id'] ) ? (int) $media['id'] : 0;
		if ( $id > 0 && function_exists( 'wp_get_attachment_url' ) ) {
			$url = wp_get_attachment_url( $id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		if ( isset( $media['url'] ) && is_string( $media['url'] ) ) {
			return $media['url'];
		}
		return '';
	}

	/**
	 * Detect video MIME type from URL extension.
	 *
	 * @param string $url Video URL.
	 * @return string MIME type.
	 */
	protected function get_video_mime_type( $url ) {
		$extension  = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );
		$mime_types = array(
			'mp4'  => 'video/mp4',
			'webm' => 'video/webm',
			'ogg'  => 'video/ogg',
			'ogv'  => 'video/ogg',
		);
		return $mime_types[ $extension ] ?? 'video/mp4';
	}
}
