<?php
/**
 * SVG Element Converter
 *
 * Converts Bricks SVG elements to etch/svg Gutenberg blocks.
 * Handles file, code, iconSet and dynamicData source types.
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

class EFS_Element_Svg extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'svg';

	/**
	 * Convert Bricks SVG element to etch/svg block.
	 *
	 * @param array $element  Bricks element.
	 * @param array $children Child elements (unused for SVG).
	 * @param array $context  Optional. Conversion context.
	 * @return string|null Gutenberg block HTML or null on skip/failure.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings = $element['settings'] ?? array();
		$source   = isset( $settings['source'] ) ? $settings['source'] : 'file';
		$svg_url  = null;

		switch ( $source ) {
			case 'file':
				$file    = $settings['file'] ?? array();
				$svg_url = isset( $file['url'] ) ? $file['url'] : null;
				break;

			case 'code':
				$code    = isset( $settings['code'] ) ? $settings['code'] : '';
				$svg_url = $this->upload_svg_code( $code );
				break;

			case 'iconSet':
				$icon_set = $settings['iconSet'] ?? array();
				$svg_data = $icon_set['svg'] ?? array();
				if ( isset( $svg_data['url'] ) ) {
					$svg_url = $svg_data['url'];
				} elseif ( ! empty( $svg_data['id'] ) && function_exists( 'wp_get_attachment_url' ) ) {
					$svg_url = wp_get_attachment_url( (int) $svg_data['id'] );
				}
				break;

			case 'dynamicData':
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs conversion warnings for unsupported source.
				error_log( 'EFS SVG: dynamicData source not supported, skipping' );
				return null;

			default:
				return null;
		}

		if ( empty( $svg_url ) ) {
			return null;
		}

		$style_ids   = $this->get_style_ids( $element );
		$css_classes = $this->get_css_classes( $style_ids );

		$etch_attributes = array(
			'src' => $svg_url,
		);

		if ( ! empty( $settings['width'] ) ) {
			$etch_attributes['width'] = $settings['width'];
		}

		if ( ! empty( $settings['height'] ) ) {
			$etch_attributes['height'] = $settings['height'];
		}

		if ( isset( $settings['fill'] ) && '' !== $settings['fill'] ) {
			$etch_attributes['fill'] = $settings['fill'];
		}

		if ( isset( $settings['stroke'] ) && '' !== $settings['stroke'] ) {
			$etch_attributes['stroke'] = $settings['stroke'];
		}

		if ( isset( $settings['strokeWidth'] ) && '' !== $settings['strokeWidth'] ) {
			$etch_attributes['stroke-width'] = $settings['strokeWidth'];
		}

		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs = array(
			'tag'        => 'svg',
			'attributes' => $etch_attributes,
			'styles'     => $style_ids,
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/svg ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/svg -->';
	}

	/**
	 * Upload SVG code as media attachment and return URL.
	 *
	 * @param string $svg_code Raw SVG markup.
	 * @return string|false Attachment URL or false on failure.
	 */
	protected function upload_svg_code( $svg_code ) {
		$svg_code = is_string( $svg_code ) ? trim( $svg_code ) : '';
		if ( '' === $svg_code ) {
			return false;
		}

		// Basic SVG validation via XML parsing.
		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$ok  = $doc->loadXML( $svg_code );
		libxml_clear_errors();

		if ( ! $ok ) {
			return false;
		}

		$tmp = wp_tempnam( 'svg-' );
		if ( false === $tmp ) {
			return false;
		}

		$written = file_put_contents( $tmp, $svg_code );
		if ( false === $written ) {
			@unlink( $tmp );
			return false;
		}

		$file_array = array(
			'name'     => 'uploaded.svg',
			'tmp_name' => $tmp,
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_handle_sideload( $file_array, 0, null, array( 'test_form' => false ) );

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		if ( is_wp_error( $id ) ) {
			return false;
		}

		$url = wp_get_attachment_url( $id );
		return $url ? $url : false;
	}
}
