<?php
/**
 * HTML Element Converter
 *
 * Converts Bricks HTML elements to etch/raw-html Gutenberg blocks.
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

class EFS_Element_Html extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'html';

	/**
	 * Convert Bricks HTML element to etch/raw-html block.
	 *
	 * @param array $element  Bricks element.
	 * @param array $children Child elements (unused for html).
	 * @param array $context  Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings = $element['settings'] ?? array();

		$html = isset( $settings['html'] ) ? $settings['html'] : '';
		$html = is_string( $html ) ? $html : '';

		$label = $this->get_label( $element );

		$attrs = array(
			'content'  => $html,
			'unsafe'   => false,
			'metadata' => array(
				'name' => $label,
			),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}
}
