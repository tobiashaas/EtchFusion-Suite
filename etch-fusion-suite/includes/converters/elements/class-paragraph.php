<?php
/**
 * Paragraph Element Converter
 *
 * Converts Bricks text-basic elements to Etch element blocks.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Paragraph extends EFS_Base_Element {

	protected $element_type = 'paragraph';

	/**
	 * Convert paragraph element
	 *
	 * @param array $element Bricks element
	 * @param array $children Not used for paragraphs
	 * @return string Gutenberg block HTML
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$style_ids       = $this->get_style_ids( $element );
		$css_classes     = $this->get_css_classes( $style_ids );
		$tag             = $this->get_tag( $element, 'p' );
		$label           = $this->get_label( $element );
		$text            = $element['settings']['text'] ?? '';
		$text            = is_string( $text ) ? $text : '';
		$text            = wp_kses_post( $text );
		$text            = preg_replace( '/(?:\x{00A0}|&nbsp;|&#160;)+$/u', '', $text );
		$etch_attributes = array();

		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );

		// etch/text is plain-text only. When the Bricks text field contains inline HTML
		// (e.g. <b>, <strong>, <em>), use etch/raw-html so the markup renders correctly.
		$has_html    = $text !== strip_tags( $text );
		$inner_block = $has_html
			? $this->generate_etch_raw_html_inline_block( $text, $label )
			: $this->generate_etch_text_block( $text );

		return $this->generate_etch_element_block( $attrs, $inner_block );
	}

	/**
	 * Generate an etch/raw-html inner block for text that contains inline HTML markup.
	 *
	 * @param string $html  HTML text content (e.g. "<b>Bold</b> text").
	 * @param string $label Human-readable label for the block.
	 * @return string Gutenberg block markup.
	 */
	private function generate_etch_raw_html_inline_block( $html, $label ) {
		$attrs      = array(
			'content'  => $html,
			'unsafe'   => false,
			'metadata' => array( 'name' => $label ),
		);
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}
}
