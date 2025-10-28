<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Templates\Interfaces\EFS_HTML_Sanitizer_Interface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framer specific HTML sanitizer implementation.
 */
class EFS_Framer_HTML_Sanitizer extends EFS_HTML_Sanitizer implements EFS_HTML_Sanitizer_Interface {
	/** @var array<string,string> */
	protected $css_variables = array();

	/**
	 * {@inheritdoc}
	 */
	public function sanitize( DOMDocument $dom ) {
		$root_element = $this->get_document_root( $dom );
		if ( ! $root_element instanceof DOMElement ) {
			return $dom;
		}

		$this->remove_framer_scripts( $dom );
		$this->clean_dom_attributes( $root_element );
		$this->semanticize_elements( $dom );
		$this->remove_unnecessary_wrappers( $root_element );
		$this->normalize_class_names( $root_element );

		$this->css_variables = $this->extract_inline_styles( $dom );

		return $dom;
	}

	/**
	 * Iterates through DOM and cleans attributes on each element.
	 *
	 * @param DOMElement $element
	 * @return void
	 */
	protected function clean_dom_attributes( DOMElement $element ) {
		$this->clean_attributes( $element );

		foreach ( $this->get_element_children( $element ) as $child_element ) {
			$this->clean_dom_attributes( $child_element );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function remove_unnecessary_wrappers( DOMElement $element ) {
		foreach ( $this->get_element_children( $element ) as $child_element ) {
			$this->remove_unnecessary_wrappers( $child_element );
		}

		if ( 'div' !== $this->get_element_tag_name( $element ) ) {
			return;
		}

		$child_elements = $this->get_element_children( $element );
		if ( 1 !== count( $child_elements ) ) {
			return;
		}

		$child = $child_elements[0];

		if ( $element->hasAttributes() ) {
			// Ignore aria and semantic attributes that are meaningful.
			$attributes = array();
			foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
				$attributes[] = $attribute->name;
			}
			// Allow data-framer-name for semantic hints only if the child has none.
			if ( count( $attributes ) > 1 ) {
				return;
			}
		}

		$parent_node = $this->get_parent_node( $element );
		if ( $parent_node instanceof DOMElement || $parent_node instanceof \DOMNode ) {
			$parent_node->replaceChild( $child, $element );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	protected function remove_framer_scripts( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( "//script[contains(@src, 'framer.com') or contains(@src, 'framerusercontent.com')]" );

		if ( ! $nodes ) {
			return;
		}

		foreach ( iterator_to_array( $nodes ) as $node ) {
			$parent_node = $this->get_parent_node( $node );
			if ( null !== $parent_node ) {
				$parent_node->removeChild( $node );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function clean_attributes( DOMElement $element ) {
		$allowed_data_attributes = array( 'data-framer-component-type', 'data-framer-name', 'data-framer-image-url' );

		$classes = array();
		if ( $element->hasAttribute( 'class' ) ) {
			$class_parts = preg_split( '/\s+/', $element->getAttribute( 'class' ) );
			foreach ( $class_parts as $class_name ) {
				if ( empty( $class_name ) ) {
					continue;
				}
				if ( preg_match( '/^framer-[a-z0-9]{5,}$/i', $class_name ) ) {
					continue;
				}
				$classes[] = $class_name;
			}
			if ( empty( $classes ) ) {
				$element->removeAttribute( 'class' );
			} else {
				$element->setAttribute( 'class', implode( ' ', array_unique( $classes ) ) );
			}
		}

		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			$name  = $attribute->name;
			$value = $attribute->value;

			if ( 0 === strpos( $name, 'data-framer-' ) && ! in_array( $name, $allowed_data_attributes, true ) ) {
				$element->removeAttribute( $name );
				continue;
			}

			if ( in_array( $name, array( 'style', 'onclick', 'onmouseover', 'onmouseout' ), true ) ) {
				if ( 'style' === $name ) {
					$filtered_style = $this->filter_style_attribute( $value );
					if ( '' === $filtered_style ) {
						$element->removeAttribute( 'style' );
					} else {
						$element->setAttribute( 'style', $filtered_style );
					}
				} else {
					$element->removeAttribute( $name );
				}
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function semanticize_elements( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );

		// Convert text components first, before sections change their tags
		$this->convert_text_components( $xpath );

		// Header / Nav / Footer semantics.
		$this->convert_by_data_name( $xpath, 'Header', 'header' );
		$this->convert_by_data_name( $xpath, 'Nav', 'nav' );
		$this->convert_by_data_name( $xpath, 'Footer', 'footer' );
		$this->convert_sections( $xpath );
		$this->convert_media_components( $xpath );
		$this->convert_button_components( $xpath );
	}

	/**
	 * {@inheritdoc}
	 */
	public function extract_inline_styles( DOMDocument $dom ) {
		$xpath    = new DOMXPath( $dom );
		$nodes    = $xpath->query( '//*[@style]' );
		$vars_map = array();

		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( ! $node instanceof DOMElement ) {
					continue;
				}
				$style = $node->getAttribute( 'style' );
				preg_match_all( '/--framer-([a-z0-9\-]+)\s*:\s*([^;]+);?/', $style, $matches, PREG_SET_ORDER );
				if ( ! empty( $matches ) ) {
					foreach ( $matches as $match ) {
						$vars_map[ $match[1] ] = trim( $match[2] );
					}
				}
			}
		}

		return $vars_map;
	}

	/**
	 * {@inheritdoc}
	 */
	public function normalize_class_names( DOMElement $element ) {
		if ( $element->hasAttribute( 'data-framer-name' ) ) {
			$slug = sanitize_title( $element->getAttribute( 'data-framer-name' ) );
			if ( $slug ) {
				$current   = $element->hasAttribute( 'class' ) ? explode( ' ', $element->getAttribute( 'class' ) ) : array();
				$current[] = $slug;
				$element->setAttribute( 'class', implode( ' ', array_unique( array_filter( $current ) ) ) );
			}
		}

		foreach ( $this->get_element_children( $element ) as $child_element ) {
			$this->normalize_class_names( $child_element );
		}
	}

	/**
	 * Returns collected CSS variable definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_css_variables() {
		return $this->css_variables;
	}

	/**
	 * Helper to convert elements by data-framer-name keyword.
	 *
	 * @param DOMXPath $xpath
	 * @param string   $keyword
	 * @param string   $tag
	 * @return void
	 */
	protected function convert_by_data_name( DOMXPath $xpath, $keyword, $tag ) {
		$nodes = $xpath->query( sprintf( "//div[contains(translate(@data-framer-name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '%s')]", strtolower( $keyword ) ) );

		if ( ! $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( $node instanceof DOMElement ) {
				$this->rename_element( $node, $tag );
			}
		}
	}

	/**
	 * Converts section-like elements to semantic section tags with extra classes.
	 *
	 * @param DOMXPath $xpath
	 * @return void
	 */
	protected function convert_sections( DOMXPath $xpath ) {
		$nodes = $xpath->query( '//div[@data-framer-name]' );
		if ( ! $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$name = strtolower( $node->getAttribute( 'data-framer-name' ) );
			if ( preg_match( '/hero|feature|cta|testimonial|about|contact/', $name ) ) {
				$this->rename_element( $node, 'section' );
			}
		}
	}

	/**
	 * Converts text components based on data-framer-component-type.
	 *
	 * @param DOMXPath $xpath
	 * @return void
	 */
	protected function convert_text_components( DOMXPath $xpath ) {
		$nodes = $xpath->query( "//*[@data-framer-component-type='Text']" );
		if ( ! $nodes || 0 === $nodes->length ) {
			return;
		}

		$has_h1 = ! ! $xpath->query( '//h1' )->length;

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$tag_name = $this->get_element_tag_name( $node );
			if ( 'div' !== $tag_name ) {
				continue;
			}
			$text = $this->get_normalized_text_content( $node );
			if ( '' === $text ) {
				continue;
			}

			$word_count = str_word_count( $text );
			$tag        = 'p';

			if ( ! $has_h1 && $word_count <= 12 ) {
				$tag    = 'h1';
				$has_h1 = true;
			} elseif ( $word_count <= 16 && preg_match( '/hero|heading|title/', strtolower( (string) $node->getAttribute( 'data-framer-name' ) ) ) ) {
				$tag = 'h2';
			}

			$this->rename_element( $node, $tag );
		}
	}

	/**
	 * Converts image related components into img tags.
	 *
	 * @param DOMXPath $xpath
	 * @return void
	 */
	protected function convert_media_components( DOMXPath $xpath ) {
		$nodes = $xpath->query( "//*[@data-framer-component-type='Image']" );
		if ( ! $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$src = $node->getAttribute( 'data-framer-image-url' );
			if ( ! $src ) {
				$img = $node->getElementsByTagName( 'img' )->item( 0 );
				$src = $img instanceof DOMElement ? $img->getAttribute( 'src' ) : '';
			}

			if ( '' === $src ) {
				continue;
			}

			$img = $this->get_owner_document( $node )->createElement( 'img' );
			$img->setAttribute( 'src', esc_url_raw( $src ) );

			if ( $node->hasAttribute( 'data-framer-name' ) ) {
				$img->setAttribute( 'alt', $node->getAttribute( 'data-framer-name' ) );
			}

			foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
				if ( in_array( $attribute->name, array( 'class', 'style' ), true ) && '' !== $attribute->value ) {
					$img->setAttribute( $attribute->name, $attribute->value );
				}
			}

			$parent_node = $this->get_parent_node( $node );
			if ( $parent_node instanceof DOMElement || $parent_node instanceof \DOMNode ) {
				$parent_node->replaceChild( $img, $node );
			}
		}
	}

	/**
	 * Converts button like components into button elements.
	 *
	 * @param DOMXPath $xpath
	 * @return void
	 */
	protected function convert_button_components( DOMXPath $xpath ) {
		$nodes = $xpath->query( "//*[@data-framer-component-type='Button' or @role='button' or @onclick]" );
		if ( ! $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}

			$tag = $this->get_element_tag_name( $node );
			if ( 'a' === $tag ) {
				$node->setAttribute( 'role', 'button' );
				continue;
			}

			$this->rename_element( $node, 'button' );
			$node->removeAttribute( 'onclick' );
		}
	}

	/**
	 * Renames an element preserving attributes and children.
	 *
	 * @param DOMElement $element
	 * @param string     $new_tag
	 * @return void
	 */
	protected function rename_element( DOMElement $element, $new_tag ) {
		if ( $this->get_element_tag_name( $element ) === strtolower( $new_tag ) ) {
			return;
		}

		$document    = $this->get_owner_document( $element );
		$new_element = $document->createElement( $new_tag );

		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			$new_element->setAttribute( $attribute->name, $attribute->value );
		}

		while ( $this->has_first_child( $element ) ) {
			$new_element->appendChild( $this->extract_first_child( $element ) );
		}

		$parent_node = $this->get_parent_node( $element );
		if ( $parent_node instanceof DOMElement || $parent_node instanceof \DOMNode ) {
			$parent_node->replaceChild( $new_element, $element );
		}
	}

	/**
	 * Retrieve the root DOM element for a document.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return DOMElement|null
	 */
	private function get_document_root( DOMDocument $dom ) {
		$root_element = $dom->getElementsByTagName( 'html' )->item( 0 );
		if ( $root_element instanceof DOMElement ) {
			return $root_element;
		}

		$document_element = $dom->documentElement; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $document_element instanceof DOMElement ? $document_element : null;
	}

	/**
	 * Return the children of a DOM element.
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
	 * Fetch the parent node for a DOM node.
	 *
	 * @param \DOMNode $node DOM node.
	 * @return \DOMNode|null
	 */
	private function get_parent_node( \DOMNode $node ) {
		return $node->parentNode instanceof \DOMNode ? $node->parentNode : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get lowercase tag name for element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return string
	 */
	private function get_element_tag_name( DOMElement $element ) {
		return strtolower( $element->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Get the owner document for an element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return DOMDocument
	 */
	private function get_owner_document( DOMElement $element ) {
		return $element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Check whether an element has a first child.
	 *
	 * @param DOMElement $element Element instance.
	 * @return bool
	 */
	private function has_first_child( DOMElement $element ) {
		return null !== $element->firstChild; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Extract first child from an element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return \DOMNode|null
	 */
	private function extract_first_child( DOMElement $element ) {
		$first_child = $element->firstChild; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $first_child instanceof \DOMNode ? $first_child : null;
	}

	/**
	 * Return trimmed text content for a DOM node.
	 *
	 * @param DOMElement $element Element to read.
	 * @return string
	 */
	private function get_normalized_text_content( DOMElement $element ) {
		$text_content = $element->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return trim( preg_replace( '/\s+/', ' ', $text_content ) );
	}

	/**
	 * Filters style attribute keeping CSS variables only.
	 *
	 * @param string $style
	 * @return string
	 */
	protected function filter_style_attribute( $style ) {
		$variables = array();
		preg_match_all( '/--framer-[a-z0-9\-]+\s*:\s*[^;]+;?/', $style, $matches );
		if ( ! empty( $matches[0] ) ) {
			foreach ( $matches[0] as $match ) {
				$variables[] = trim( $match, '; ' ) . ';';
			}
		}

		return implode( ' ', $variables );
	}
}
