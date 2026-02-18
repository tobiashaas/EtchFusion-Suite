<?php
/**
 * Icon Element Converter
 *
 * Converts Bricks icon elements to Etch icon blocks.
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

class EFS_Icon_Converter extends EFS_Base_Element {

	protected $element_type = 'icon';

	/**
	 * Convert Bricks icon to Etch icon element.
	 *
	 * @param array $element Bricks element.
	 * @param array $children Child elements (unused for icon).
	 * @param array $context Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$style_ids = $this->get_style_ids( $element );

		// Intentionally emit empty etch/svg blocks for font-icon based Bricks icons.
		// This avoids carrying icon-font specific attributes/classes (e.g. data-icon-library).
		$attrs = array(
			'tag'        => 'svg',
			'attributes' => array(),
			'styles'     => $this->normalize_style_ids( $style_ids ),
		);

		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/svg ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/svg -->';
	}
}
