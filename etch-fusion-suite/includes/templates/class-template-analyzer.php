<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Templates\Interfaces\EFS_Template_Analyzer_Interface;
use DOMDocument;
use DOMElement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base template analyzer providing shared helpers for concrete analyzers.
 */
class EFS_Template_Analyzer implements EFS_Template_Analyzer_Interface {
	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var EFS_HTML_Parser
	 */
	protected $html_parser;

	/**
	 * @var int
	 */
	protected $complexity_score = 0;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_HTML_Parser $html_parser, EFS_Error_Handler $error_handler ) {
		$this->html_parser   = $html_parser;
		$this->error_handler = $error_handler;
	}

	/**
	 * {@inheritdoc}
	 */
	public function analyze( DOMDocument $dom ) {
		$sections   = $this->identify_sections( $dom );
		$layout     = $this->extract_layout_structure( $dom );
		$typography = $this->analyze_typography( $dom );
		$media      = $this->detect_media_elements( $dom );

		$components = array();
		foreach ( $sections as $section ) {
			if ( isset( $section['element'] ) && $section['element'] instanceof DOMElement ) {
				$components[] = $this->detect_components( $section['element'] );
			}
		}

		$this->complexity_score = $this->calculate_complexity_score(
			$layout,
			$components,
			$media
		);

		return array(
			'sections'         => $sections,
			'components'       => $components,
			'layout'           => $layout,
			'typography'       => $typography,
			'media'            => $media,
			'complexity_score' => $this->complexity_score,
			'warnings'         => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function identify_sections( DOMDocument $dom ) {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function detect_components( DOMElement $element ) {
		return array(
			'counts' => array(),
			'label'  => $element->getAttribute( 'data-framer-name' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function extract_layout_structure( DOMDocument $dom ) {
		$xpath = $this->html_parser->get_xpath( $dom );

		$grid_count = $xpath->query( "//*[@style[contains(., 'display:grid')]]" )->length;
		$flex_count = $xpath->query( "//*[@style[contains(., 'display:flex')]]" )->length;

		$root_element = $this->get_document_root( $dom );
		$depth        = $this->calculate_dom_depth( $root_element );
		$hierarchy    = $root_element instanceof DOMElement ? $this->build_hierarchy( $root_element, 0, 3 ) : array();

		return array(
			'depth'      => $depth,
			'grid_count' => $grid_count,
			'flex_count' => $flex_count,
			'hierarchy'  => $hierarchy,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function analyze_typography( DOMDocument $dom ) {
		$xpath = $this->html_parser->get_xpath( $dom );

		$headings = array();
		for ( $level = 1; $level <= 6; $level++ ) {
			$query                    = sprintf( '//h%d', $level );
			$nodes                    = $xpath->query( $query );
			$headings[ 'h' . $level ] = $nodes ? $nodes->length : 0;
		}

		$paragraphs      = $xpath->query( '//p' );
		$paragraph_count = $paragraphs ? $paragraphs->length : 0;

		return array(
			'heading_counts'  => $headings,
			'paragraph_count' => $paragraph_count,
			'warnings'        => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function detect_media_elements( DOMDocument $dom ) {
		$xpath = $this->html_parser->get_xpath( $dom );
		$media = array();

		foreach ( array( 'img', 'picture', 'video', 'svg' ) as $tag ) {
			$nodes = $xpath->query( '//' . $tag );
			if ( ! $nodes ) {
				continue;
			}

			foreach ( $nodes as $node ) {
				if ( $node instanceof DOMElement ) {
					$media[] = array(
						'tag'        => $tag,
						'attributes' => $this->html_parser->get_element_attributes( $node ),
					);
				}
			}
		}

		return $media;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_complexity_score() {
		return $this->complexity_score;
	}

	/**
	 * Calculates a basic DOM depth.
	 *
	 * @param DOMElement|null $element Root element.
	 * @return int
	 */
	protected function calculate_dom_depth( $element ) {
		if ( ! $element instanceof DOMElement ) {
			return 0;
		}

		$max_depth = 0;
		foreach ( $this->get_element_children( $element ) as $child ) {
			$max_depth = max( $max_depth, $this->calculate_dom_depth( $child ) );
		}

		return $max_depth + 1;
	}

	/**
	 * Computes a complexity score based on layout, components and media presence.
	 *
	 * @param array<string,mixed> $layout Layout data.
	 * @param array<int,array<string,mixed>> $components Component information.
	 * @param array<int,array<string,mixed>> $media Media list.
	 * @return int
	 */
	protected function calculate_complexity_score( $layout, $components, $media ) {
		$depth_score       = ! empty( $layout['depth'] ) ? min( (int) $layout['depth'] * 5, 10 ) : 0;
		$component_count   = 0;
		$component_divisor = 1;

		foreach ( $components as $component_data ) {
			if ( ! empty( $component_data['counts'] ) && is_array( $component_data['counts'] ) ) {
				$component_count += array_sum( $component_data['counts'] );
				++$component_divisor;
			}
		}

		$component_score = min( (int) ( $component_count / $component_divisor ) * 5, 30 );
		$layout_score    = ! empty( $layout['grid_count'] ) || ! empty( $layout['flex_count'] ) ? 20 : 5;
		$media_score     = min( count( $media ) * 4, 20 );

		return (int) min( 100, $depth_score + $component_score + $layout_score + $media_score + 10 );
	}

	/**
	 * Retrieve the root DOM element for the provided document.
	 *
	 * @param DOMDocument $dom DOM document instance.
	 * @return DOMElement|null
	 */
	protected function get_document_root( DOMDocument $dom ) {
		$root_element = $dom->getElementsByTagName( 'html' )->item( 0 );
		if ( $root_element instanceof DOMElement ) {
			return $root_element;
		}

		$document_element = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $document_element instanceof DOMElement ? $document_element : null;
	}

	/**
	 * Return the child elements of a DOM element.
	 *
	 * @param DOMElement $element Parent element.
	 * @return array<int,DOMElement>
	 */
	protected function get_element_children( DOMElement $element ) {
		$children = array();

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Retrieve the lowercase tag name for the element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return string
	 */
	protected function get_element_tag_name( DOMElement $element ) {
		return strtolower( $element->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Retrieve normalized text content from the element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return string
	 */
	protected function get_normalized_text_content( DOMElement $element ) {
		$text_content = $element->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return trim( preg_replace( '/\s+/', ' ', $text_content ) );
	}

	/**
	 * Retrieve owner document for an element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return DOMDocument|null
	 */
	protected function get_owner_document( DOMElement $element ) {
		return $element->ownerDocument instanceof DOMDocument ? $element->ownerDocument : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
