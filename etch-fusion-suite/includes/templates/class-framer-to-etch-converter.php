<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Converters\EFS_Element_Factory;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;
use DOMElement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts sanitized Framer DOM fragments into Etch compatible block definitions.
 */
class EFS_Framer_To_Etch_Converter {
	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var EFS_Element_Factory
	 */
	protected $element_factory;

	/**
	 * @var Style_Repository_Interface
	 */
	protected $style_repository;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_Element_Factory $element_factory, Style_Repository_Interface $style_repository ) {
		$this->error_handler    = $error_handler;
		$this->element_factory  = $element_factory;
		$this->style_repository = $style_repository;
	}

	/**
	 * Converts a DOM element to an Etch block array.
	 *
	 * @param DOMElement $element
	 * @param array      $analysis_context
	 * @return array|null
	 */
	public function convert_element( DOMElement $element, array $analysis_context = array() ) {
		$type = strtolower( $element->getAttribute( 'data-framer-component-type' ) );

		try {
			switch ( $type ) {
				case 'text':
					return $this->convert_text_component( $element );
				case 'image':
					return $this->convert_image_component( $element );
				case 'button':
					return $this->convert_button_component( $element );
				default:
					$tag              = $this->get_element_tag_name( $element );
					$context_children = isset( $analysis_context['children'] ) && is_array( $analysis_context['children'] )
						? $analysis_context['children']
						: array();

					if ( 'section' === $tag ) {
						return $this->convert_section( $element, $context_children );
					}

					if ( in_array( $tag, array( 'div', 'header', 'nav', 'footer' ), true ) ) {
						return $this->convert_container( $element, $context_children );
					}
			}
		} catch ( \Throwable $exception ) {
			$this->error_handler->log_error(
				'B2E_FRAMER_CONVERTER',
				array(
					'message' => $exception->getMessage(),
					'tag'     => $this->get_element_tag_name( $element ),
					'type'    => $type,
				),
				'error'
			);
		}

		return null;
	}

	/**
	 * Converts textual components to Etch paragraph/heading blocks.
	 *
	 * @param DOMElement $element
	 * @return array
	 */
	public function convert_text_component( DOMElement $element ) {
		$text = $this->get_normalized_text_content( $element );
		if ( '' === $text ) {
			return array();
		}

		$tag = $this->get_element_tag_name( $element );
		if ( ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$tag = 'p';
		}

		$attributes = $this->extract_etch_attributes( $element );
		$styles     = $this->map_framer_classes_to_etch( $element );

		return array(
			'type'       => 'text',
			'tag'        => $tag,
			'content'    => wp_kses_post( $text ),
			'attributes' => $attributes,
			'styles'     => $styles,
		);
	}

	/**
	 * Converts image components to Etch image block definitions.
	 *
	 * @param DOMElement $element
	 * @return array
	 */
	public function convert_image_component( DOMElement $element ) {
		$src = $element->getAttribute( 'src' );
		if ( ! $src ) {
			$nested = $element->getElementsByTagName( 'img' )->item( 0 );
			$src    = $nested instanceof DOMElement ? $nested->getAttribute( 'src' ) : '';
		}

		$alt = $element->getAttribute( 'alt' );
		if ( '' === $alt ) {
			$alt = $element->getAttribute( 'data-framer-name' );
		}

		$attributes = $this->extract_etch_attributes( $element );
		$styles     = $this->map_framer_classes_to_etch( $element );

		return array(
			'type'       => 'image',
			'src'        => esc_url_raw( $src ),
			'alt'        => $alt,
			'attributes' => $attributes,
			'styles'     => $styles,
		);
	}

	/**
	 * Converts Framer buttons/links to Etch button definitions.
	 *
	 * @param DOMElement $element
	 * @return array
	 */
	public function convert_button_component( DOMElement $element ) {
		$href = $element->getAttribute( 'href' );
		if ( '' === $href || 'a' !== $this->get_element_tag_name( $element ) ) {
			$href = '#';
		}

		$label      = $this->get_normalized_text_content( $element );
		$attributes = $this->extract_etch_attributes( $element );
		$styles     = $this->map_framer_classes_to_etch( $element );

		return array(
			'type'       => 'button',
			'label'      => $label,
			'href'       => esc_url_raw( $href ),
			'attributes' => $attributes,
			'styles'     => $styles,
		);
	}

	/**
	 * Converts section containers to Etch section groups.
	 *
	 * @param DOMElement $element
	 * @param array      $children
	 * @return array
	 */
	public function convert_section( DOMElement $element, array $children ) {
		$label = $element->getAttribute( 'data-framer-name' );
		if ( '' === $label ) {
			$label = 'Section';
		}

		$attributes = $this->extract_etch_attributes( $element );
		$styles     = $this->map_framer_classes_to_etch( $element );

		return array(
			'type'       => 'section',
			'label'      => $label,
			'children'   => $children,
			'attributes' => $attributes,
			'styles'     => $styles,
			'tag'        => 'section',
		);
	}

	/**
	 * Converts general containers (div, header, nav, footer) to Etch groups.
	 *
	 * @param DOMElement $element
	 * @param array      $children
	 * @return array
	 */
	public function convert_container( DOMElement $element, array $children ) {
		$label = $element->getAttribute( 'data-framer-name' );
		if ( '' === $label ) {
			$label = ucfirst( $this->get_element_tag_name( $element ) );
		}

		$attributes = $this->extract_etch_attributes( $element );
		$styles     = $this->map_framer_classes_to_etch( $element );

		return array(
			'type'       => 'container',
			'label'      => $label,
			'children'   => $children,
			'attributes' => $attributes,
			'styles'     => $styles,
			'tag'        => $this->get_element_tag_name( $element ),
		);
	}

	/**
	 * Maps Framer classes to Etch style identifiers via the style repository.
	 *
	 * @param DOMElement $element
	 * @return array<int,string>
	 */
	public function map_framer_classes_to_etch( DOMElement $element ) {
		$classes = array();
		if ( $element->hasAttribute( 'class' ) ) {
			$classes = array_filter( preg_split( '/\s+/', $element->getAttribute( 'class' ) ) );
		}

		if ( empty( $classes ) ) {
			return array();
		}

		$style_map = $this->style_repository->get_style_map();
		$style_ids = array();

		foreach ( $classes as $class_name ) {
			foreach ( $style_map as $bricks_id => $data ) {
				if ( is_array( $data ) && ! empty( $data['selector'] ) && ltrim( $data['selector'], '.' ) === $class_name ) {
					$style_ids[] = $data['id'];
					break;
				}
			}
		}

		return array_values( array_unique( $style_ids ) );
	}

	/**
	 * Extracts Etch compatible attributes from a DOM element.
	 *
	 * @param DOMElement $element
	 * @return array<string,string>
	 */
	public function extract_etch_attributes( DOMElement $element ) {
		$attributes = array();

		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			if ( in_array( $attribute->name, array( 'class', 'id', 'role', 'aria-label' ), true ) ) {
				$attributes[ $attribute->name ] = $attribute->value;
			}
		}

		return $attributes;
	}

	/**
	 * Build block metadata structure for Etch.
	 *
	 * @param DOMElement $element      Source element.
	 * @param string     $block_type   Gutenberg block type.
	 * @param array      $attributes   Block attributes.
	 * @param array      $styles       Style definitions.
	 * @return array<string,mixed>
	 */
	public function build_block_metadata( DOMElement $element, $block_type, array $attributes, array $styles ) {
		$label = '';
		if ( isset( $attributes['label'] ) && '' !== $attributes['label'] ) {
			$label = $attributes['label'];
		} else {
			$label = $element->getAttribute( 'data-framer-name' );
			if ( '' === $label ) {
				$label = ucfirst( $block_type );
			}
		}

		$block_tag        = isset( $attributes['tag'] ) && '' !== $attributes['tag'] ? $attributes['tag'] : 'div';
		$block_attributes = isset( $attributes['attributes'] ) && is_array( $attributes['attributes'] ) ? $attributes['attributes'] : array();

		return array(
			'metadata' => array(
				'name'     => $label,
				'etchData' => array(
					'origin'     => 'etch',
					'name'       => $label,
					'styles'     => $styles,
					'attributes' => $block_attributes,
					'block'      => array(
						'type' => 'html',
						'tag'  => $block_tag,
					),
				),
			),
		);
	}

	/**
	 * Return the direct child elements of the provided element.
	 *
	 * @param DOMElement $element Parent element.
	 * @return array<int,DOMElement>
	 */
	public function get_element_children( DOMElement $element ) {
		$children = array();

		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( $child instanceof DOMElement ) {
				$children[] = $child;
			}
		}

		return $children;
	}

	/**
	 * Get a normalized text content string from a DOM element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return string
	 */
	private function get_normalized_text_content( DOMElement $element ) {
		$text_content = $element->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return trim( preg_replace( '/\s+/', ' ', $text_content ) );
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

	/**
	 * Get the owner document for the element.
	 *
	 * @param DOMElement $element Element instance.
	 * @return DOMDocument
	 */
	private function get_owner_document( DOMElement $element ) {
		return $element->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
