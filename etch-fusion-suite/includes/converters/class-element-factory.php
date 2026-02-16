<?php
/**
 * Element Factory
 *
 * Creates the appropriate element converter based on element type
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters;

use Bricks2Etch\Converters\Elements\EFS_Element_Container;
use Bricks2Etch\Converters\Elements\EFS_Element_Section;
use Bricks2Etch\Converters\Elements\EFS_Element_Heading;
use Bricks2Etch\Converters\Elements\EFS_Element_Paragraph;
use Bricks2Etch\Converters\Elements\EFS_Element_Text;
use Bricks2Etch\Converters\Elements\EFS_Element_Image;
use Bricks2Etch\Converters\Elements\EFS_Element_Div;
use Bricks2Etch\Converters\Elements\EFS_Button_Converter;
use Bricks2Etch\Converters\Elements\EFS_Icon_Converter;
use Bricks2Etch\Converters\Elements\EFS_Element_Component;
use Bricks2Etch\Converters\Elements\EFS_Element_Svg;
use Bricks2Etch\Converters\Elements\EFS_Element_Video;
use Bricks2Etch\Converters\Elements\EFS_Element_Code;
use Bricks2Etch\Converters\Elements\EFS_Element_Html;
use Bricks2Etch\Converters\Elements\EFS_Element_Notes;
use Bricks2Etch\Converters\Elements\EFS_Element_Shortcode;
use Bricks2Etch\Converters\Elements\EFS_Element_TextLink;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Factory {

	/**
	 * Map Bricks element types to converter classes.
	 */
	private const TYPE_MAP = array(
		'container'  => EFS_Element_Container::class,
		'section'    => EFS_Element_Section::class,
		'heading'    => EFS_Element_Heading::class,
		'text-basic' => EFS_Element_Paragraph::class,
		'text'       => EFS_Element_Text::class,
		'image'      => EFS_Element_Image::class,
		'div'        => EFS_Element_Div::class,
		'block'      => EFS_Element_Div::class,
		'button'     => EFS_Button_Converter::class,
		'icon'       => EFS_Icon_Converter::class,
		'template'   => EFS_Element_Component::class,
		'svg'        => EFS_Element_Svg::class,
		'video'      => EFS_Element_Video::class,
		'code'       => EFS_Element_Code::class,
		'fr-notes'   => EFS_Element_Notes::class,
		'html'       => EFS_Element_Html::class,
		'shortcode'  => EFS_Element_Shortcode::class,
		'text-link'  => EFS_Element_TextLink::class,
	);

	/**
	 * Style map
	 */
	private $style_map;

	/**
	 * Dynamic data converter instance.
	 *
	 * @var \Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter|null
	 */
	private $dynamic_data_converter;

	/**
	 * Element converters cache
	 */
	private $converters = array();

	/**
	 * Constructor
	 *
	 * @param array                                              $style_map              Style map for CSS classes.
	 * @param \Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter|null $dynamic_data_converter Optional dynamic data converter.
	 */
	public function __construct( $style_map = array(), $dynamic_data_converter = null ) {
		$this->style_map              = $style_map;
		$this->dynamic_data_converter = $dynamic_data_converter;
		$this->load_converters();
	}

	/**
	 * Load all element converters
	 */
	private function load_converters() {
		// Converters are now autoloaded via namespace
	}

	/**
	 * Get converter for element type
	 *
	 * @param string $element_type Bricks element type
	 * @return EFS_Base_Element|null Element converter
	 */
	public function get_converter( $element_type ) {
		// Elements to skip (not shown in frontend or not supported)
		$skip_elements = array(
			'form',          // Forms (TODO)
			'map',           // Maps (TODO)
		);

		// Skip elements silently
		if ( in_array( $element_type, $skip_elements, true ) ) {
			return null;
		}

		// Get converter class
		$converter_class = self::TYPE_MAP[ $element_type ] ?? null;

		if ( ! $converter_class ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs missing converter for unsupported element types during development
			error_log( "🚨 EFS Factory: No converter found for element type: {$element_type}" );
			return null;
		}

		// Create converter instance (with caching)
		if ( ! isset( $this->converters[ $converter_class ] ) ) {
			$instance = new $converter_class( $this->style_map );

			// Inject dynamic data converter if available.
			if ( $this->dynamic_data_converter ) {
				$instance->set_dynamic_data_converter( $this->dynamic_data_converter );
			}

			$this->converters[ $converter_class ] = $instance;
		}

		return $this->converters[ $converter_class ];
	}

	/**
	 * Convert element using appropriate converter
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (already converted HTML)
	 * @param array $context Optional. Conversion context (e.g. element_map, convert_callback for slotChildren).
	 * @return string|null Gutenberg block HTML
	 */
	public function convert_element( $element, $children = array(), $context = array() ) {
		// Skip hidden elements early — produces no output.
		if ( ! empty( $element['settings']['_hidden'] ) && true === $element['settings']['_hidden'] ) {
			return null;
		}

		$element_type = $element['name'] ?? '';

		if ( empty( $element_type ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs element validation issues without error handler dependency
			error_log( '⚠️ EFS Factory: Element has no type' );
			return null;
		}

		$converter = $this->get_converter( $element_type );

		if ( ! $converter ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: highlights unsupported element types for converter coverage gaps
			error_log( "⚠️ EFS Factory: Unsupported element type: {$element_type}" );
			return null;
		}

		$result = $converter->convert( $element, $children, $context );

		if ( null === $result || '' === $result ) {
			return $result;
		}

		// Wrap in loop block if element has hasLoop setting.
		if ( ! empty( $element['settings']['hasLoop'] ) ) {
			$result = $this->wrap_in_loop_block( $result, $element );
		}

		// Wrap in condition block if element has _conditions setting.
		// Conditions wrap outside loops: "if condition, show the loop".
		if ( ! empty( $element['settings']['_conditions'] ) && is_array( $element['settings']['_conditions'] ) ) {
			$result = $this->wrap_in_condition_block( $result, $element );
		}

		return $result;
	}

	/**
	 * Wrap block output in an etch/loop block.
	 *
	 * @param string $block_html Converted block HTML.
	 * @param array  $element    Bricks element with query settings.
	 * @return string Wrapped block HTML.
	 */
	private function wrap_in_loop_block( $block_html, $element ) {
		$loop_params = $this->build_loop_params( $element );
		$attrs_json  = wp_json_encode( $loop_params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/loop ' . $attrs_json . ' -->' . "\n" .
			$block_html . "\n" .
			'<!-- /wp:etch/loop -->';
	}

	/**
	 * Build Etch loop parameters from Bricks query config.
	 *
	 * @param array $element Bricks element.
	 * @return array Etch loop params.
	 */
	private function build_loop_params( $element ) {
		$settings = $element['settings'] ?? array();
		$query    = $settings['query'] ?? array();

		$params = array();

		// Object type (post, term, user).
		if ( ! empty( $query['objectType'] ) ) {
			$params['objectType'] = (string) $query['objectType'];
		} else {
			$params['objectType'] = 'post';
		}

		// Post type.
		if ( ! empty( $query['post_type'] ) ) {
			$params['postType'] = is_array( $query['post_type'] ) ? $query['post_type'] : array( (string) $query['post_type'] );
		}

		// Posts per page.
		if ( isset( $query['posts_per_page'] ) ) {
			$params['postsPerPage'] = (int) $query['posts_per_page'];
		}

		// Order by.
		if ( ! empty( $query['orderby'] ) ) {
			$params['orderBy'] = is_array( $query['orderby'] ) ? implode( ' ', $query['orderby'] ) : (string) $query['orderby'];
		}

		// Order direction.
		if ( ! empty( $query['order'] ) ) {
			$params['order'] = is_array( $query['order'] ) ? implode( ' ', $query['order'] ) : (string) $query['order'];
		}

		// Taxonomy query.
		if ( ! empty( $query['tax_query'] ) && is_array( $query['tax_query'] ) ) {
			$params['taxQuery'] = $query['tax_query'];
		}

		return $params;
	}

	/**
	 * Wrap block output in an etch/condition block.
	 *
	 * @param string $block_html Converted block HTML.
	 * @param array  $element    Bricks element with _conditions settings.
	 * @return string Wrapped block HTML.
	 */
	private function wrap_in_condition_block( $block_html, $element ) {
		$conditions = $element['settings']['_conditions'];
		$ast        = $this->build_condition_ast( $conditions );

		if ( empty( $ast ) ) {
			return $block_html;
		}

		$attrs      = array( 'conditions' => $ast );
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<!-- wp:etch/condition ' . $attrs_json . ' -->' . "\n" .
			$block_html . "\n" .
			'<!-- /wp:etch/condition -->';
	}

	/**
	 * Build Etch condition AST from Bricks _conditions array.
	 *
	 * Supported Bricks conditions are mapped to Etch AST nodes.
	 * Unsupported/WooCommerce conditions are silently skipped.
	 *
	 * @param array $conditions Bricks conditions array.
	 * @return array Etch condition AST nodes.
	 */
	private function build_condition_ast( $conditions ) {
		$ast = array();

		$condition_map = array(
			'user_logged_in' => array(
				'leftHand'  => 'user.loggedIn',
				'operator'  => 'isTruthy',
				'rightHand' => null,
			),
			'featured_image' => array(
				'leftHand'  => 'this.featuredImage',
				'operator'  => 'isTruthy',
				'rightHand' => null,
			),
			'has_children'   => array(
				'leftHand'  => 'this.children',
				'operator'  => 'isNotEmpty',
				'rightHand' => null,
			),
			'post_parent'    => array(
				'leftHand'  => 'this.parent',
				'operator'  => 'isTruthy',
				'rightHand' => null,
			),
		);

		foreach ( $conditions as $condition ) {
			$type = $condition['type'] ?? '';

			// Skip WooCommerce conditions.
			if ( 0 === strpos( $type, 'woo_' ) ) {
				continue;
			}

			if ( isset( $condition_map[ $type ] ) ) {
				$node = $condition_map[ $type ];

				// Handle negation (compare === 'not_equal' or '!=').
				$compare = $condition['compare'] ?? '';
				if ( 'not_equal' === $compare || '!=' === $compare ) {
					$node['negate'] = true;
				}

				$ast[] = $node;
			}
		}

		return $ast;
	}
}
