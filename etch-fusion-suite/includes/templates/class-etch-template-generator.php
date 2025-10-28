<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;
use DOMDocument;
use DOMElement;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates Etch template payloads from sanitized DOM and analysis metadata.
 */
class EFS_Etch_Template_Generator {
	/**
	 * @var EFS_Framer_To_Etch_Converter
	 */
	protected $converter;

	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var Style_Repository_Interface
	 */
	protected $style_repository;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Framer_To_Etch_Converter $converter, EFS_Error_Handler $error_handler, Style_Repository_Interface $style_repository ) {
		$this->converter        = $converter;
		$this->error_handler    = $error_handler;
		$this->style_repository = $style_repository;
	}

	/**
	 * Generates a complete template payload from sanitized DOM and analysis data.
	 *
	 * @param DOMDocument $sanitized_dom Sanitized DOM document.
	 * @param array       $analysis      Analysis metadata array.
	 * @param array       $css_variables Optional CSS variable map collected during sanitization.
	 * @return array|WP_Error Template payload or error.
	 */
	public function generate( DOMDocument $sanitized_dom, array $analysis, array $css_variables = array() ) {
		if ( empty( $analysis['sections'] ) ) {
			return new WP_Error( 'b2e_template_generator_no_sections', __( 'No sections were detected in the sanitized DOM.', 'etch-fusion-suite' ) );
		}

		$blocks   = $this->generate_blocks_from_sections( $analysis['sections'], $sanitized_dom );
		$styles   = $this->generate_style_definitions( $css_variables );
		$metadata = $this->get_template_metadata( $analysis );
		$stats    = array(
			'generated_at'     => current_time( 'mysql' ),
			'block_count'      => count( $blocks ),
			'complexity_score' => isset( $analysis['complexity_score'] ) ? (int) $analysis['complexity_score'] : 0,
			'section_count'    => count( $analysis['sections'] ),
			'media_count'      => isset( $analysis['media'] ) ? count( $analysis['media'] ) : 0,
		);

		$validation = $this->validate_generated_template( $blocks );
		if ( ! $validation['valid'] ) {
			return new WP_Error( 'b2e_template_generator_invalid_output', __( 'Generated template failed validation.', 'etch-fusion-suite' ), $validation['errors'] );
		}

		return array(
			'blocks'   => $blocks,
			'styles'   => $styles,
			'metadata' => $metadata,
			'stats'    => $stats,
		);
	}

	/**
	 * Builds Etch block HTML strings from analyzed sections.
	 *
	 * @param array<int,array<string,mixed>> $sections Analysis sections.
	 * @param DOMDocument                    $dom      Sanitized DOM.
	 * @return array<int,string>
	 */
	public function generate_blocks_from_sections( array $sections, DOMDocument $dom ) {
		$blocks = array();

		foreach ( $sections as $section ) {
			if ( empty( $section['element'] ) || ! $section['element'] instanceof DOMElement ) {
				continue;
			}

			$children_context = $this->convert_children( $section['element'] );
			$block_data       = $this->converter->convert_section(
				$section['element'],
				$children_context
			);

			$blocks[] = $this->build_etch_block(
				$section['element'],
				'group',
				$block_data,
				$children_context
			);
		}

		return array_values( array_filter( $blocks ) );
	}

	/**
	 * Builds Gutenberg block HTML string for Etch.
	 *
	 * @param DOMElement $element
	 * @param string     $block_type Gutenberg block type (group, paragraph, etc.).
	 * @param array      $attributes Block metadata and attributes.
	 * @param array      $children   Child blocks data.
	 * @return string
	 */
	public function build_etch_block( DOMElement $element, $block_type, array $attributes, array $children ) {
		$styles   = isset( $attributes['styles'] ) && is_array( $attributes['styles'] ) ? $attributes['styles'] : array();
		$metadata = $this->converter->build_block_metadata( $element, $block_type, $attributes, $styles );

		$attrs_json = wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$child_html = '';

		foreach ( $children as $child ) {
			if ( isset( $child['__html'] ) ) {
				$child_html .= $child['__html'];
			}
		}

		return sprintf(
			'<!-- wp:%1$s %2$s -->%3$s<!-- /wp:%1$s -->',
			esc_attr( $block_type ),
			$attrs_json,
			"\n<div class=\"wp-block-group\">\n" . $child_html . "</div>\n"
		);
	}

	/**
	 * Converts child nodes recursively into Etch block arrays and captures HTML.
	 *
	 * @param DOMElement $element
	 * @return array<int,array<string,mixed>>
	 */
	protected function convert_children( DOMElement $element ) {
		$children       = array();
		$child_elements = $this->converter->get_element_children( $element );

		foreach ( $child_elements as $child ) {
			$converted = $this->converter->convert_element( $child, array() );

			if ( empty( $converted ) ) {
				$converted = array(
					'type'    => 'html',
					'content' => $this->save_node_html( $child ),
				);
			}

			$children[] = $converted;
			$children[] = array( '__html' => $this->render_child_block( $child, $converted ) );
		}

		return $children;
	}

	/**
	 * Renders child block to Gutenberg HTML snippet.
	 *
	 * @param DOMElement $element
	 * @param array      $data
	 * @return string
	 */
	protected function render_child_block( DOMElement $element, array $data ) {
		$type = isset( $data['type'] ) ? $data['type'] : 'html';

		switch ( $type ) {
			case 'text':
				$content = isset( $data['content'] ) ? $data['content'] : '';
				return sprintf( '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->', $content );
			case 'image':
				$src = isset( $data['src'] ) ? esc_url( $data['src'] ) : '';
				$alt = isset( $data['alt'] ) ? esc_attr( $data['alt'] ) : '';
				return sprintf( '<!-- wp:image --><figure class="wp-block-image"><img src="%s" alt="%s"/></figure><!-- /wp:image -->', $src, $alt );
			case 'button':
				$href  = isset( $data['href'] ) ? esc_url( $data['href'] ) : '#';
				$label = isset( $data['label'] ) ? esc_html( $data['label'] ) : '';
				return sprintf( '<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="%s">%s</a></div><!-- /wp:button -->', $href, $label );
			default:
				return '<!-- wp:html -->' . $this->save_node_html( $element ) . '<!-- /wp:html -->';
		}
	}

	/**
	 * Generates Etch style definitions from CSS variables.
	 *
	 * @param array<string,string> $css_variables
	 * @return array<string,mixed>
	 */
	public function generate_style_definitions( array $css_variables ) {
		if ( empty( $css_variables ) ) {
			return array();
		}

		$styles = array();

		foreach ( $css_variables as $name => $value ) {
			$styles[] = array(
				'name'   => $name,
				'value'  => $value,
				'origin' => 'framer-inline',
			);
		}

		return $styles;
	}

	/**
	 * Validates generated blocks.
	 *
	 * @param array<int,string> $blocks
	 * @return array{valid:bool,errors:array<int,string>}
	 */
	public function validate_generated_template( array $blocks ) {
		$errors = array();

		if ( empty( $blocks ) ) {
			$errors[] = __( 'Template contains no blocks.', 'etch-fusion-suite' );
		}

		foreach ( $blocks as $index => $block ) {
			if ( false === strpos( $block, '<!-- wp:' ) ) {
				/* translators: %d: Block index in the generated template. */
				$errors[] = sprintf( __( 'Block %d is missing Gutenberg block comment wrappers.', 'etch-fusion-suite' ), $index );
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	/**
	 * Builds template metadata array.
	 *
	 * @param array<string,mixed> $analysis
	 * @return array<string,mixed>
	 */
	public function get_template_metadata( array $analysis ) {
		$sections = isset( $analysis['sections'] ) && is_array( $analysis['sections'] ) ? $analysis['sections'] : array();
		$title    = __( 'Imported Template', 'etch-fusion-suite' );
		if ( ! empty( $sections ) && ! empty( $sections[0]['name'] ) ) {
			$title = sanitize_text_field( $sections[0]['name'] );
		}

		return array(
			'title'            => $title,
			'description'      => __( 'Template generated from Framer HTML import.', 'etch-fusion-suite' ),
			'complexity_score' => isset( $analysis['complexity_score'] ) ? (int) $analysis['complexity_score'] : 0,
			'section_overview' => wp_list_pluck( $sections, 'type' ),
			'warnings'         => isset( $analysis['warnings'] ) && is_array( $analysis['warnings'] ) ? $analysis['warnings'] : array(),
		);
	}

	/**
	 * Render DOM node HTML safely.
	 *
	 * @param DOMElement $node DOM element to export.
	 * @return string
	 */
	private function save_node_html( DOMElement $node ) {
		$owner_document = $node->ownerDocument; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $owner_document instanceof DOMDocument ? $owner_document->saveHTML( $node ) : '';
	}
}
