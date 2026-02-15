<?php
/**
 * Notes Element Converter
 *
 * Converts Frames fr-notes elements to HTML comments for migration tracking.
 * Notes are builder-only and not rendered in frontend.
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

class EFS_Element_Notes extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'notes';

	/**
	 * Convert notes element to HTML comment.
	 *
	 * @param array $element  Bricks/Frames element with settings.notesContent.
	 * @param array $children Not used for notes.
	 * @param array $context  Optional. Conversion context.
	 * @return string HTML comment or empty string.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$raw = isset( $element['settings']['notesContent'] ) ? $element['settings']['notesContent'] : '';
		$raw = is_string( $raw ) ? $raw : '';

		$text = wp_strip_all_tags( $raw );
		$text = trim( $text );

		// Avoid breaking HTML comment with "--" inside content.
		$text = str_replace( '--', "\u{2013}", $text );

		if ( '' === $text ) {
			return '<!-- MIGRATION NOTE: (empty) -->';
		}

		return '<!-- MIGRATION NOTE: ' . $text . ' -->';
	}
}
