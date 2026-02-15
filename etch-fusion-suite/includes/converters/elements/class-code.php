<?php
/**
 * Code Element Converter
 *
 * Converts Bricks code elements to etch/raw-html Gutenberg blocks.
 * Handles executeCode (unsafe) flag and optional CSS/JS code.
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

class EFS_Element_Code extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'code';

	/**
	 * Convert Bricks code element to etch/raw-html block.
	 *
	 * @param array $element  Bricks element.
	 * @param array $children Child elements (unused for code).
	 * @param array $context  Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings = $element['settings'] ?? array();

		$code = isset( $settings['code'] ) ? $settings['code'] : '';
		$code = is_string( $code ) ? $code : '';

		if ( false !== strpos( $code, '<?' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs conversion warnings for unsupported PHP in code block.
			error_log( 'EFS Code: PHP tags detected, not supported in Etch' );
		}

		$css_code = isset( $settings['cssCode'] ) ? $settings['cssCode'] : '';
		$css_code = is_string( $css_code ) ? trim( $css_code ) : '';
		$js_code  = isset( $settings['javascriptCode'] ) ? $settings['javascriptCode'] : '';
		$js_code  = is_string( $js_code ) ? trim( $js_code ) : '';

		$parts = array();
		if ( '' !== $css_code ) {
			$parts[] = '<style>' . $css_code . '</style>';
		}

		$parts[] = $code;

		if ( '' !== $js_code ) {
			$parts[] = '<script>' . $js_code . '</script>';
		}

		$content      = implode( "\n", $parts );
		$execute_code = isset( $settings['executeCode'] ) && true === $settings['executeCode'];
		$label        = $this->get_label( $element );

		$attrs = array(
			'content'  => $content,
			'unsafe'   => $execute_code,
			'metadata' => array(
				'name' => $label,
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}
}
