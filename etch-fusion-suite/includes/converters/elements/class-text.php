<?php
/**
 * Rich Text Element Converter
 *
 * Converts Bricks rich text elements to Etch element/text blocks.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;
use DOMDocument;
use DOMElement;
use DOMNode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Text extends EFS_Base_Element {

	protected $element_type = 'text';

	/**
	 * Block-level element tag names.
	 *
	 * @var array<string>
	 */
	private static $block_level_tags = array(
		'p',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'ul',
		'ol',
		'div',
		'section',
		'article',
		'aside',
		'header',
		'footer',
		'nav',
		'blockquote',
		'pre',
		'hr',
		'table',
	);

	/**
	 * Convert rich text element to Gutenberg blocks.
	 *
	 * @param array $element Bricks element.
	 * @param array $children Child elements (unused).
	 * @param array $context Optional conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$html = isset( $element['settings']['text'] ) && is_string( $element['settings']['text'] )
			? $element['settings']['text']
			: '';

		if ( '' === trim( $html ) ) {
			return '';
		}

		libxml_use_internal_errors( true );
		$dom    = new DOMDocument();
		$loaded = $dom->loadHTML(
			'<?xml encoding="UTF-8"><body>' . $html . '</body>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		if ( ! $loaded ) {
			return $this->build_single_block_fallback( $html, $element );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $this->build_single_block_fallback( $html, $element );
		}

		$blocks        = array();
		$inline_buffer = '';

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM extension exposes camelCase properties.
		foreach ( $body->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( '' !== trim( $node->textContent ) ) {
					$inline_buffer .= esc_html( $node->textContent );
				}
				continue;
			}

			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}

			$tag = strtolower( $node->nodeName );
			if ( in_array( $tag, self::$block_level_tags, true ) ) {
				$this->flush_inline_buffer( $inline_buffer, $element, $blocks );
				$block = $this->generate_block_from_node( $node, $element, $tag );
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
				continue;
			}

			$inline_buffer .= $node->ownerDocument->saveHTML( $node );
		}
		// phpcs:enable

		$this->flush_inline_buffer( $inline_buffer, $element, $blocks );

		if ( empty( $blocks ) ) {
			return $this->build_single_block_fallback( $html, $element );
		}

		$wrapper_style_ids = $this->get_style_ids( $element );
		$wrapper_classes   = array_filter( preg_split( '/\s+/', trim( (string) $this->get_css_classes( $wrapper_style_ids ) ) ) );
		$wrapper_classes[] = 'smart-spacing';
		$wrapper_classes   = array_values( array_unique( array_filter( $wrapper_classes ) ) );
		$wrapper_attrs     = array();

		if ( ! empty( $wrapper_classes ) ) {
			$wrapper_attrs['class'] = implode( ' ', $wrapper_classes );
		}

		$wrapper = $this->build_attributes(
			$this->get_label( $element ),
			$wrapper_style_ids,
			$wrapper_attrs,
			'div',
			$element
		);

		return $this->generate_etch_element_block( $wrapper, implode( "\n", $blocks ) );
	}

	/**
	 * Fallback when DOM parsing fails or truncates: output full HTML as a single paragraph block.
	 * Prevents content loss (e.g. text after "cutout" or malformed HTML).
	 *
	 * @param string $html    Raw HTML from Bricks settings.
	 * @param array  $element Bricks element.
	 * @return string Gutenberg block HTML.
	 */
	private function build_single_block_fallback( $html, $element ) {
		$sanitized = wp_kses_post( $html );
		$sanitized = preg_replace( '/(?:\x{00A0}|&nbsp;|&#160;)+$/u', '', (string) $sanitized );
		$block     = $this->generate_block( 'p', $sanitized, $element );

		$wrapper_style_ids = $this->get_style_ids( $element );
		$wrapper_classes   = array_filter( preg_split( '/\s+/', trim( (string) $this->get_css_classes( $wrapper_style_ids ) ) ) );
		$wrapper_classes[] = 'smart-spacing';
		$wrapper_classes   = array_values( array_unique( array_filter( $wrapper_classes ) ) );
		$wrapper_attrs     = array();
		if ( ! empty( $wrapper_classes ) ) {
			$wrapper_attrs['class'] = implode( ' ', $wrapper_classes );
		}

		$wrapper = $this->build_attributes(
			$this->get_label( $element ),
			$wrapper_style_ids,
			$wrapper_attrs,
			'div',
			$element
		);

		return $this->generate_etch_element_block( $wrapper, $block );
	}

	/**
	 * Flush inline HTML buffer into a paragraph block.
	 *
	 * @param string $inline_buffer Inline buffer (by reference).
	 * @param array  $element Bricks element.
	 * @param array  $blocks Block collection (by reference).
	 * @return void
	 */
	private function flush_inline_buffer( &$inline_buffer, $element, &$blocks ) {
		if ( '' === trim( wp_strip_all_tags( $inline_buffer, true ) ) ) {
			$inline_buffer = '';
			return;
		}

		$sanitized = wp_kses_post( $inline_buffer );
		$block     = $this->generate_block( 'p', $sanitized, $element );
		if ( '' !== $block ) {
			$blocks[] = $block;
		}

		$inline_buffer = '';
	}

	/**
	 * Generate a block from a DOM element.
	 *
	 * @param DOMNode $node DOM node.
	 * @param array   $element Bricks element.
	 * @param string  $tag Output HTML tag.
	 * @return string
	 */
	private function generate_block_from_node( DOMNode $node, $element, $tag ) {
		$content = wp_kses_post( $this->get_inner_html( $node ) );
		return $this->generate_block( $tag, $content, $element, $node );
	}

	/**
	 * Generate an Etch element block with an optional etch/text inner block.
	 *
	 * @param string       $tag HTML tag.
	 * @param string       $content Inner content for etch/text.
	 * @param array        $element Bricks element.
	 * @param DOMNode|null $node Optional source node for class merging.
	 * @return string
	 */
	private function generate_block( $tag, $content, $element, $node = null ) {
		$content        = preg_replace( '/(?:\x{00A0}|&nbsp;|&#160;)+$/u', '', (string) $content );
		$style_ids      = array();
		$merged_classes = $this->merge_classes( '', $node );
		$etch_attrs     = array();

		if ( '' !== $merged_classes ) {
			$etch_attrs['class'] = $merged_classes;
		}

		$attrs = $this->build_attributes( $this->get_label( $element ), $style_ids, $etch_attrs, $tag, $element );

		if ( in_array( $tag, array( 'hr' ), true ) || '' === trim( $content ) ) {
			return $this->generate_etch_element_block( $attrs );
		}

		// etch/text is plain-text only — use etch/raw-html when content contains markup.
		$inner = strip_tags( $content ) !== $content
			? $this->generate_etch_raw_html_block( $content )
			: $this->generate_etch_text_block( $content );

		return $this->generate_etch_element_block( $attrs, $inner );
	}

	/**
	 * Get inner HTML of a DOM node.
	 *
	 * @param DOMNode $node DOM node.
	 * @return string
	 */
	private function get_inner_html( DOMNode $node ) {
		$html = '';
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM extension exposes camelCase properties.
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		// phpcs:enable
		return trim( $html );
	}

	/**
	 * Merge classes from mapped style IDs and node classes.
	 *
	 * @param string       $style_classes Classes from mapped style IDs.
	 * @param DOMNode|null $node Optional source DOM node.
	 * @return string
	 */
	private function merge_classes( $style_classes, $node = null ) {
		$list = array_filter( preg_split( '/\s+/', trim( (string) $style_classes ) ) );

		if ( $node instanceof DOMElement && $node->hasAttribute( 'class' ) ) {
			$node_classes = array_filter( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) ) );
			$list         = array_merge( $list, $node_classes );
		}

		if ( empty( $list ) ) {
			return '';
		}

		return implode( ' ', array_values( array_unique( $list ) ) );
	}
}
