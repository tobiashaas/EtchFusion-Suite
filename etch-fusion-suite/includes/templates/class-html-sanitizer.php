<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Templates\Interfaces\EFS_HTML_Sanitizer_Interface;
use DOMDocument;
use DOMElement;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base sanitizer that coordinates DOM sanitization steps for template extraction.
 */
class EFS_HTML_Sanitizer implements EFS_HTML_Sanitizer_Interface {
	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var EFS_Input_Validator
	 */
	protected $input_validator;

	/**
	 * Last extracted CSS variables during sanitization.
	 *
	 * @var array<string,string>
	 */
	protected $css_variables = array();

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_Input_Validator $input_validator ) {
		$this->error_handler   = $error_handler;
		$this->input_validator = $input_validator;
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize( DOMDocument $dom ) {
		$root_element = $this->get_document_element( $dom );
		if ( ! $root_element instanceof DOMElement ) {
			$this->error_handler->log_error( 'B2E_SANITIZER_DOM_MISSING', array(), 'error' );
			return $dom;
		}

		// Default no-op implementations expected to be overridden in specialized sanitizers.
		$this->remove_framer_scripts( $dom );
		$this->traverse_and_clean( $root_element );
		$this->semanticize_elements( $dom );

		$this->css_variables = $this->extract_inline_styles( $dom );

		return $dom;
	}

	/**
	 * Removes Framer related scripts. Subclasses may override.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return void
	 */
	protected function remove_framer_scripts( DOMDocument $dom ) {
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//script[contains(@src, "framer.com") or contains(@src, "framerusercontent.com")]' );
		if ( empty( $nodes ) ) {
			return;
		}

		foreach ( $nodes as $node ) {
			$parent_node = $this->get_parent_node( $node );
			if ( $parent_node instanceof \DOMNode ) {
				$parent_node->removeChild( $node );
			}
		}
	}

	/**
	 * Recursively traverses the DOM tree to clean attributes and unwrap wrappers.
	 *
	 * @param DOMElement $element Element to process.
	 * @return void
	 */
	protected function traverse_and_clean( DOMElement $element ) {
		$this->clean_attributes( $element );
		$this->normalize_class_names( $element );
		$this->remove_unnecessary_wrappers( $element );

		foreach ( $this->get_element_children( $element ) as $child_element ) {
			$this->traverse_and_clean( $child_element );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function remove_unnecessary_wrappers( DOMElement $element ) {
		// Base implementation only handles simple div wrappers.
		if ( 'div' !== strtolower( $this->get_element_tag_name( $element ) ) ) {
			return;
		}

		$child_elements = $this->get_element_children( $element );
		if ( 1 !== count( $child_elements ) ) {
			return;
		}

		$child = $child_elements[0];

		if ( $element->hasAttributes() ) {
			return;
		}

		$parent = $this->get_parent_node( $element );
		if ( $parent instanceof \DOMNode ) {
			$parent->replaceChild( $child, $element );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function semanticize_elements( DOMDocument $dom ) {
		// Base implementation leaves DOM untouched. Specialized sanitizers should override.
	}

	/**
	 * {@inheritdoc}
	 */
	public function clean_attributes( DOMElement $element ) {
		// Remove empty attributes.
		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			if ( '' === trim( $attribute->value ) ) {
				$element->removeAttribute( $attribute->name );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function extract_inline_styles( DOMDocument $dom ) {
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function normalize_class_names( DOMElement $element ) {
		// Default does nothing, subclasses can override.
	}

	/**
	 * Returns the CSS variables collected during the last sanitize() invocation.
	 *
	 * @return array<string,string>
	 */
	public function get_css_variables() {
		return $this->css_variables;
	}

	/**
	 * Retrieve the document root element, if available.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return DOMElement|null
	 */
	private function get_document_element( DOMDocument $dom ) {
		$root = $dom->getElementsByTagName( 'html' )->item( 0 );
		if ( $root instanceof DOMElement ) {
			return $root;
		}

		// Fallback to DOMDocument::documentElement when <html> tag is missing.
		$dom_document_element = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $dom_document_element instanceof DOMElement ? $dom_document_element : null;
	}

	/**
	 * Get direct child elements of a DOM element.
	 *
	 * @param DOMElement $element Parent element.
	 * @return array<int,DOMElement>
	 */
	private function get_element_children( DOMElement $element ) {
		$children = array();

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Safely retrieve an element's parent node.
	 *
	 * @param \DOMNode $node Child node.
	 * @return \DOMNode|null
	 */
	private function get_parent_node( \DOMNode $node ) {
		return $node->parentNode instanceof \DOMNode ? $node->parentNode : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get the lowercase tag name for the element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return string
	 */
	private function get_element_tag_name( DOMElement $element ) {
		return strtolower( $element->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
