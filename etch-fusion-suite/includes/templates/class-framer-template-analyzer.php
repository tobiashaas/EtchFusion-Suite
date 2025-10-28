<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Templates\Interfaces\EFS_Template_Analyzer_Interface;
use DOMDocument;
use DOMElement;
use DOMXPath;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framer-specific template analyzer implementation.
 */
class EFS_Framer_Template_Analyzer extends EFS_Template_Analyzer implements EFS_Template_Analyzer_Interface {
	/** @var DOMXPath|null */
	protected $xpath = null;

	/**
	 * {@inheritdoc}
	 */
	public function analyze( DOMDocument $dom ) {
		$this->xpath = $this->html_parser->get_xpath( $dom );

		$sections   = $this->identify_sections( $dom );
		$components = array();
		foreach ( $sections as $section ) {
			if ( isset( $section['element'] ) && $section['element'] instanceof DOMElement ) {
				$components[] = $this->detect_components( $section['element'] );
			}
		}

		$layout     = $this->extract_layout_structure( $dom );
		$typography = $this->analyze_typography( $dom );
		$media      = $this->detect_media_elements( $dom );

		$this->complexity_score = $this->calculate_complexity_score( $layout, $components, $media );

		return array(
			'sections'         => $sections,
			'components'       => $components,
			'layout'           => $layout,
			'typography'       => $typography,
			'media'            => $media,
			'complexity_score' => $this->complexity_score,
			'warnings'         => $this->collect_warnings( $typography ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function identify_sections( DOMDocument $dom ) {
		$sections = array();
		$xpath    = $this->html_parser->get_xpath( $dom );

		$nodes = $xpath->query( '//*[self::section or self::header or self::footer or self::nav or @data-framer-name]' );

		if ( ! $nodes ) {
			return $sections;
		}

		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$type         = $this->determine_section_type( $node, $index );
			$confidence   = ( 'generic' === $type ) ? 0.5 : 0.85;
			$section_name = $node->getAttribute( 'data-framer-name' );
			if ( '' === $section_name ) {
				$section_name = ucfirst( $type );
			}

			$sections[] = array(
				'type'       => $type,
				'name'       => $section_name,
				'element'    => $node,
				'confidence' => $confidence,
				'index'      => $index,
			);

			++$index;
		}

		return $sections;
	}

	/**
	 * {@inheritdoc}
	 */
	public function detect_components( DOMElement $element ) {
		$owner_document = $this->get_owner_document( $element );
		if ( null === $owner_document ) {
			return array(
				'counts' => array(),
				'label'  => '',
			);
		}

		$xpath = new DOMXPath( $owner_document );
		$xpath->registerNamespace( 'html', 'http://www.w3.org/1999/xhtml' );

		$scope_query = sprintf( './/*[@data-framer-component-type and ancestor-or-self::%s]', $this->get_element_tag_name( $element ) );
		$nodes       = $xpath->query( $scope_query, $element );

		$counts = array(
			'heading'   => 0,
			'paragraph' => 0,
			'image'     => 0,
			'button'    => 0,
			'svg'       => 0,
		);

		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}

				$type = strtolower( $node->getAttribute( 'data-framer-component-type' ) );

				switch ( $type ) {
					case 'text':
						++$counts['paragraph'];
						if ( in_array( $this->get_element_tag_name( $node ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
							++$counts['heading'];
						}
						break;
					case 'image':
						++$counts['image'];
						break;
					case 'button':
						++$counts['button'];
						break;
					case 'svg':
						++$counts['svg'];
						break;
				}
			}
		}

		$label = $element->getAttribute( 'data-framer-name' );
		if ( '' === $label ) {
			$label = $this->get_element_tag_name( $element );
		}

		return array(
			'counts' => $counts,
			'label'  => $label,
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
		$data     = parent::analyze_typography( $dom );
		$headings = $data['heading_counts'];
		$warnings = array();
		$total_h1 = $headings['h1'] ?? 0;
		$total_h2 = $headings['h2'] ?? 0;

		if ( $total_h1 > 1 ) {
			$warnings[] = __( 'Multiple H1 tags detected.', 'etch-fusion-suite' );
		}
		if ( 0 === $total_h1 ) {
			$warnings[] = __( 'No H1 tag detected.', 'etch-fusion-suite' );
		}
		if ( $total_h2 < $total_h1 ) {
			$warnings[] = __( 'Heading hierarchy may be inconsistent.', 'etch-fusion-suite' );
		}

		$data['warnings'] = $warnings;

		return $data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function detect_media_elements( DOMDocument $dom ) {
		$media = parent::detect_media_elements( $dom );

		foreach ( $media as &$item ) {
			$attributes = $item['attributes'];
			if ( isset( $attributes['src'] ) && false !== strpos( $attributes['src'], 'framerusercontent' ) ) {
				$item['source'] = 'framerusercontent';
			}
		}

		return $media;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_complexity_score() {
		return parent::get_complexity_score();
	}

	/**
	 * Builds hierarchy snapshot limited by depth.
	 *
	 * @param DOMElement $element
	 * @param int        $depth
	 * @param int        $max_depth
	 * @return array<int,array<string,mixed>>
	 */
	protected function build_hierarchy( DOMElement $element, $depth, $max_depth ) {
		if ( $depth > $max_depth ) {
			return array();
		}

		$children = array();
		foreach ( $this->get_element_children( $element ) as $child_element ) {
			$children[] = array(
				'tag'      => $this->get_element_tag_name( $child_element ),
				'name'     => $child_element->getAttribute( 'data-framer-name' ),
				'children' => $this->build_hierarchy( $child_element, $depth + 1, $max_depth ),
			);
		}

		return $children;
	}

	/**
	 * Determines section type using heuristics.
	 *
	 * @param DOMElement $element
	 * @param int        $index
	 * @return string
	 */
	protected function determine_section_type( DOMElement $element, $index ) {
		$name = strtolower( $element->getAttribute( 'data-framer-name' ) );

		if ( false !== strpos( $name, 'hero' ) || 0 === $index ) {
			return 'hero';
		}
		if ( false !== strpos( $name, 'feature' ) ) {
			return 'features';
		}
		if ( false !== strpos( $name, 'cta' ) || $this->contains_cta_button( $element ) ) {
			return 'cta';
		}
		if ( false !== strpos( $name, 'testimonial' ) ) {
			return 'testimonials';
		}
		if ( false !== strpos( $name, 'footer' ) || 'footer' === $this->get_element_tag_name( $element ) ) {
			return 'footer';
		}

		return 'generic';
	}

	/**
	 * Checks if element subtree contains primary CTA button.
	 *
	 * @param DOMElement $element
	 * @return bool
	 */
	protected function contains_cta_button( DOMElement $element ) {
		$owner_document = $this->get_owner_document( $element );
		if ( null === $owner_document ) {
			return false;
		}

		$xpath = new DOMXPath( $owner_document );
		$nodes = $xpath->query( './/a|.//button', $element );

		if ( ! $nodes ) {
			return false;
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$text = $this->get_normalized_text_content( $node );
				if ( preg_match( '/(get started|sign up|try now|buy now|contact)/i', $text ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Collects warnings from different analysis steps.
	 *
	 * @param array<string,mixed> $typography
	 * @return array<int,string>
	 */
	protected function collect_warnings( $typography ) {
		$warnings = array();

		if ( ! empty( $typography['warnings'] ) ) {
			$warnings = array_merge( $warnings, $typography['warnings'] );
		}

		return $warnings;
	}
}
