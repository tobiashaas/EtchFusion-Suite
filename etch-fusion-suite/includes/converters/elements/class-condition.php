<?php
/**
 * Condition Element Converter
 *
 * Converts Bricks condition wrappers to Etch condition blocks when possible.
 *
 * @package Bricks_Etch_Migration
 * @since 0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;
use Bricks2Etch\Converters\Interfaces\Needs_Error_Handler;
use Bricks2Etch\Core\EFS_Error_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Condition extends EFS_Base_Element implements Needs_Error_Handler {

	protected $element_type = 'condition';

	/**
	 * Optional error handler for structured warning logging.
	 *
	 * @var EFS_Error_Handler|null
	 */
	private $error_handler;

	/**
	 * Constructor.
	 *
	 * @param array                  $style_map Style map for CSS classes.
	 * @param EFS_Error_Handler|null $error_handler Optional warning logger.
	 */
	public function __construct( $style_map = array(), ?EFS_Error_Handler $error_handler = null ) {
		parent::__construct( $style_map );
		$this->error_handler = $error_handler;
	}

	/**
	 * Convert condition element.
	 *
	 * @param array $element Bricks element.
	 * @param array $children Child elements (already converted HTML).
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$children_html = is_array( $children ) ? implode( "\n", $children ) : (string) $children;
		$settings      = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$conditions    = array();

		if ( isset( $settings['_conditions'] ) && is_array( $settings['_conditions'] ) ) {
			$conditions = $settings['_conditions'];
		} elseif ( isset( $settings['conditions'] ) && is_array( $settings['conditions'] ) ) {
			$conditions = $settings['conditions'];
		}

		if ( empty( $conditions ) ) {
			return $children_html;
		}

		$supported_types      = array( 'logged_in', 'user_role', 'post_type', 'page_template', 'custom_field' );
		$unsupported_comments = array();
		$supported_conditions = array();
		$element_id           = isset( $element['id'] ) ? (string) $element['id'] : '';

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$type = isset( $condition['type'] ) ? strtolower( trim( (string) $condition['type'] ) ) : '';
			if ( '' === $type && isset( $condition['condition'] ) ) {
				$type = strtolower( trim( (string) $condition['condition'] ) );
			}

			$is_supported        = in_array( $type, $supported_types, true );
			$is_unsupported_type = ( '' === $type )
				|| 0 === strpos( $type, 'woocommerce_' )
				|| 0 === strpos( $type, 'acf_' )
				|| 'php' === $type;

			if ( $is_supported && ! $is_unsupported_type ) {
				$supported_conditions[] = $condition;
				continue;
			}

			$reported_type          = '' !== $type ? $type : 'unknown';
			$unsupported_comments[] = '<!-- [EFS] Condition not converted: ' . $reported_type . ' -->';
			$this->log_condition_warning( $reported_type, $element_id );
		}

		if ( ! empty( $unsupported_comments ) ) {
			return implode( "\n", $unsupported_comments ) . "\n" . $children_html;
		}

		$style_ids = $this->get_style_ids( $element );
		$tag       = $this->get_tag( $element, 'div' );
		$label     = $this->get_label( $element );

		$etch_attributes = array(
			'data-etch-element' => 'condition',
		);

		$css_classes = $this->get_css_classes( $style_ids );
		if ( ! empty( $css_classes ) ) {
			$etch_attributes['class'] = $css_classes;
		}

		$attrs                  = $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );
		$attrs['conditionType'] = 'bricks';
		$attrs['conditions']    = $supported_conditions;

		return $this->generate_etch_element_block( $attrs, $children_html );
	}

	/**
	 * Log warning for unsupported condition type.
	 *
	 * @param string $type Unsupported condition type.
	 * @param string $element_id Bricks element ID.
	 * @return void
	 */
	private function log_condition_warning( $type, $element_id ) {
		if ( $this->error_handler instanceof EFS_Error_Handler ) {
			$this->error_handler->log_warning(
				'W008',
				array(
					'type'       => $type,
					'element_id' => $element_id,
				)
			);
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: fallback warning path when no error handler is available in converter factory
		error_log( 'W008: Condition not converted: ' . $type . ' (element_id: ' . $element_id . ')' );
	}
}
