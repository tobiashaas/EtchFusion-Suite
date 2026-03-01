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
					'youtube',
					$youtube_id,
					$this->build_youtube_embed_url_nocookie( $youtube_id, $settings ),
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
					'vimeo',
					$vimeo_id,
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
	 * Convert YouTube/Vimeo to privacy-friendly HTML with poster + play button pattern.
	 *
	 * Instead of embedding the iframe directly (which loads tracking immediately),
	 * this creates a poster image with a play button overlay. The iframe only loads
	 * when the user clicks the button (youtube-nocookie.com for privacy).
	 *
	 * @param string $label Block label.
	 * @param string $video_type youtube|vimeo
	 * @param string $video_id Video ID.
	 * @param string $embed_url Embed URL (youtube-nocookie.com or vimeo.com).
	 * @param array  $style_ids Style IDs.
	 * @param string $css_classes CSS class list.
	 * @param string $width Frame width.
	 * @param string $height Frame height.
	 * @param array  $element Original Bricks element.
	 * @return string Etch block HTML.
	 */
	private function convert_embed_video( $label, $video_type, $video_id, $embed_url, $style_ids, $css_classes, $width, $height, $element = array() ) {
		// Get poster URL for the video type.
		$poster_url = $this->get_embed_poster_url( $video_type, $video_id );

		if ( '' === $video_id || '' === $poster_url ) {
			// Fallback to old iframe-only approach if we can't get poster.
			return $this->convert_embed_video_fallback( $label, $embed_url, $style_ids, $css_classes, $width, $height, $element );
		}

		// Convert to privacy-friendly format: poster + play button + hidden iframe.
		return $this->build_privacy_video_container( $label, $video_type, $video_id, $poster_url, $embed_url, $style_ids, $css_classes, $width, $height, $element );
	}

	/**
	 * Fallback: Old iframe-only approach if privacy conversion fails.
	 *
	 * @param string $label Block label.
	 * @param string $embed_url Embed URL.
	 * @param array  $style_ids Style IDs.
	 * @param string $css_classes CSS class list.
	 * @param string $width Frame width.
	 * @param string $height Frame height.
	 * @param array  $element Original Bricks element.
	 * @return string
	 */
	private function convert_embed_video_fallback( $label, $embed_url, $style_ids, $css_classes, $width, $height, $element = array() ) {
		$iframe_attrs = array(
			'data-etch-element' => 'iframe',
			'src'               => $embed_url,
			'width'             => $width,
			'height'            => $height,
			'frameborder'       => '0',
			'loading'           => 'lazy',
			'referrerpolicy'    => 'strict-origin-when-cross-origin',
			'allow'             => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
			'allowfullscreen'   => 'true',
		);

		$iframe_styles = $style_ids;
		array_unshift( $iframe_styles, 'etch-iframe-style' );
		$iframe_styles = array_values( array_unique( $iframe_styles ) );

		$attrs = $this->build_attributes( $label, $iframe_styles, $iframe_attrs, 'iframe', $element );

		return $this->generate_etch_element_block( $attrs );
	}

	/**
	 * Build privacy-friendly video container with poster, play button, and hidden iframe.
	 *
	 * @param string $label Block label.
	 * @param string $video_type youtube|vimeo
	 * @param string $video_id Video ID.
	 * @param string $poster_url Poster image URL.
	 * @param string $embed_url iframe src (youtube-nocookie.com for privacy).
	 * @param array  $style_ids Style IDs.
	 * @param string $css_classes CSS class list.
	 * @param string $width Frame width.
	 * @param string $height Frame height.
	 * @param array  $element Original Bricks element.
	 * @return string
	 */
	private function build_privacy_video_container( $label, $video_type, $video_id, $poster_url, $embed_url, $style_ids, $css_classes, $width, $height, $element = array() ) {
		// Build the poster image block (lazy-loaded).
		$poster_attrs = array(
			'src'     => $poster_url,
			'alt'     => $label,
			'loading' => 'lazy',
			'style'   => 'width: 100%; height: 100%; object-fit: cover; display: block;',
		);
		$poster_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Video Poster', array(), $poster_attrs, 'img' )
		);

		// Build the play button (SVG overlay).
		$play_button_attrs = array(
			'class'             => 'youtube-play-button',
			'data-video-id'     => $video_id,
			'data-video-type'   => $video_type,
			'data-privacy-mode' => 'nocookie',
			'aria-label'        => __( 'Play video', 'etch-fusion-suite' ),
			'style'             => 'position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80px; height: 80px; background: rgba(255, 0, 0, 0.8); border: none; border-radius: 50%; cursor: pointer; z-index: 10;',
		);
		$play_svg          = '<svg viewBox="0 0 24 24" style="width: 100%; height: 100%;"><path fill="white" d="M8 5v14l11-7z" /></svg>';
		$play_button_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Video Play Button', array(), $play_button_attrs, 'button' ),
			array( $this->generate_etch_text_block( $play_svg ) )
		);

		// Build the hidden iframe (loads on click via JavaScript).
		$iframe_attrs = array(
			'class'          => 'youtube-iframe etch-lazy-iframe',
			'data-src'       => $embed_url,
			'style'          => 'display: none; position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0;',
			'allow'          => 'accelerometer; autoplay; clipboard-write; encrypted-media; fullscreen; gyroscope; picture-in-picture; web-share',
			'referrerpolicy' => 'strict-origin-when-cross-origin',
			'loading'        => 'lazy',
		);
		$iframe_block = $this->generate_etch_element_block(
			$this->build_attributes( 'Video IFrame', array(), $iframe_attrs, 'iframe' )
		);

		// Build wrapper container with position: relative for absolute positioning.
		$wrapper_attrs = array(
			'class' => trim( 'video-privacy-container ' . $css_classes ),
			'style' => 'aspect-ratio: 16/9; position: relative; background: #000; overflow: hidden;',
		);

		// Combine all inner blocks.
		$inner_blocks = array( $poster_block, $play_button_block, $iframe_block );

		// Build final container with styles from Bricks.
		$container_styles = $style_ids;
		array_unshift( $container_styles, 'etch-video-style' );
		$container_styles = array_values( array_unique( $container_styles ) );

		$attrs = $this->build_attributes( $label, $container_styles, $wrapper_attrs, 'div', $element );

		return $this->generate_etch_element_block( $attrs, $inner_blocks );
	}

	/**
	 * Detect embed type from URL.
	 *
	 * @param string $url Embed URL.
	 * @return string youtube|vimeo
	 */
	private function detect_embed_type( $url ) {
		if ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) {
			return 'youtube';
		}
		if ( strpos( $url, 'vimeo.com' ) !== false ) {
			return 'vimeo';
		}
		return 'unknown';
	}

	/**
	 * Extract video ID from embed URL.
	 *
	 * @param string $url Embed or standard video URL.
	 * @return string Video ID or empty string.
	 */
	private function extract_video_id_from_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		// YouTube embed format: /embed/{ID}
		if ( strpos( $url, '/embed/' ) !== false && ! empty( $parts['path'] ) ) {
			$path_parts = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
			if ( count( $path_parts ) >= 2 ) {
				return (string) $path_parts[1];
			}
		}

		// YouTube query format: v={ID}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			if ( isset( $query['v'] ) && is_string( $query['v'] ) && '' !== $query['v'] ) {
				return $query['v'];
			}
		}

		// Vimeo format: /video/{ID} or just /{ID}
		if ( strpos( $url, 'vimeo.com' ) !== false && ! empty( $parts['path'] ) ) {
			$path_parts = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
			if ( ! empty( $path_parts ) ) {
				return (string) end( $path_parts );
			}
		}

		return '';
	}

	/**
	 * Get poster image URL for embed service.
	 *
	 * @param string $video_type youtube|vimeo
	 * @param string $video_id Video ID.
	 * @return string Poster URL or empty string.
	 */
	private function get_embed_poster_url( $video_type, $video_id ) {
		if ( '' === $video_id ) {
			return '';
		}

		if ( 'youtube' === $video_type ) {
			// Try highest quality first, fall back to lower qualities.
			return 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
		}

		if ( 'vimeo' === $video_type ) {
			// Vimeo requires API call to get poster, use a generic fallback for now.
			// In production, this could call Vimeo's oEmbed API.
			return 'https://i.vimeocdn.com/video/' . $video_id . '.jpg';
		}

		return '';
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

		$video_block  = $this->generate_etch_element_block(
			$this->build_attributes( 'Video Element', array(), $video_attrs, 'video' ),
			array( $source_block )
		);
		$inner_blocks = array( $video_block );

		if ( '' !== trim( $description ) ) {
			$inner_blocks[] = $this->generate_etch_element_block(
				$this->build_attributes( 'Video Caption', array(), array( 'class' => 'hidden-accessible' ), 'figcaption' ),
				$this->generate_etch_text_block( wp_kses_post( $description ) )
			);
		}

		return $this->generate_etch_element_block(
			$this->build_attributes( $label, $style_ids, $figure_attrs, 'figure', $element ),
			$inner_blocks
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
	 * Build HTML5 video attributes.
	 *
	 * @param array  $settings Bricks settings.
	 * @param string $width Width value.
	 * @param string $height Height value.
	 * @return array<string,string>
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
	 * Handles multiple naming conventions from different Bricks settings:
	 * - YouTube: $settings['youtube'] or $settings['youTubeId'] or $settings['videoId']
	 * - Vimeo: $settings['vimeo'] or $settings['vimeoId']
	 *
	 * @param array  $settings Bricks settings.
	 * @param string $provider youtube|vimeo
	 * @return string
	 */
	private function get_video_id( $settings, $provider ) {
		// Try multiple key variations for YouTube and Vimeo
		$candidates = array();

		if ( 'youtube' === $provider ) {
			$candidates = array( 'youTubeId', 'youtube', 'videoId' );
		} elseif ( 'vimeo' === $provider ) {
			$candidates = array( 'vimeoId', 'vimeo', 'videoId' );
		}

		foreach ( $candidates as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
				$raw = $settings[ $key ];
				break;
			}
		}

		if ( ! isset( $raw ) ) {
			return '';
		}

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
	 * Build YouTube embed URL with youtube-nocookie.com for privacy.
	 *
	 * @param string $video_id YouTube video ID.
	 * @param array  $settings Bricks element settings.
	 * @return string Embed URL (privacy-friendly).
	 */
	protected function build_youtube_embed_url_nocookie( $video_id, $settings ) {
		$params             = array();
		$params['autoplay'] = ! empty( $settings['autoplay'] ) ? '1' : '0';
		$params['loop']     = ! empty( $settings['loop'] ) ? '1' : '0';
		$params['muted']    = ! empty( $settings['muted'] ) ? '1' : '0';
		$params['controls'] = isset( $settings['controls'] ) && $settings['controls'] ? '1' : '0';
		$query              = http_build_query( $params );
		return 'https://www.youtube-nocookie.com/embed/' . $video_id . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Build YouTube embed URL with youtube.com (old, with tracking).
	 * Kept for reference/fallback only.
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
		// Direct src URL (modern Bricks format)
		if ( isset( $settings['src'] ) && is_string( $settings['src'] ) && '' !== trim( $settings['src'] ) ) {
			return trim( $settings['src'] );
		}

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
