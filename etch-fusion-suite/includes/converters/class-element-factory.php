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
use Bricks2Etch\Converters\Elements\EFS_Element_Condition;
use Bricks2Etch\Converters\Elements\EFS_Element_Heading;
use Bricks2Etch\Converters\Interfaces\Needs_Error_Handler;
use Bricks2Etch\Converters\EFS_Converter_Registry;
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
use Bricks2Etch\Core\EFS_Error_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Factory {

	/**
	 * Built-in map of Bricks element types to converter classes.
	 * Public so EFS_Converter_Discovery can read it without reflection.
	 */
	public const BUILT_IN_CONVERTERS = array(
		'container'  => EFS_Element_Container::class,
		'section'    => EFS_Element_Section::class,
		'condition'  => EFS_Element_Condition::class,
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
	 * Element converters cache
	 */
	private $converters = array();

	/**
	 * Optional error handler for converter-specific warning logging.
	 *
	 * @var EFS_Error_Handler|null
	 */
	private $error_handler;

	/**
	 * Converter registry for extensible type lookup.
	 *
	 * @var EFS_Converter_Registry
	 */
	private $registry;

	/**
	 * Constructor
	 *
	 * @param EFS_Converter_Registry $registry      Converter registry (required; no nullable fallback).
	 * @param array                  $style_map     Style map for CSS classes.
	 * @param EFS_Error_Handler|null $error_handler Optional error handler instance.
	 */
	public function __construct( EFS_Converter_Registry $registry, $style_map = array(), ?EFS_Error_Handler $error_handler = null ) {
		$this->registry      = $registry;
		$this->style_map     = $style_map;
		$this->error_handler = $error_handler;
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

		// Registry is always non-null (invariant set in constructor).
		// If the registry doesn't have the type, fall back to the built-in map.
		$converter_class = $this->registry->get( $element_type ) ?? ( self::BUILT_IN_CONVERTERS[ $element_type ] ?? null );

		if ( ! $converter_class ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs missing converter for unsupported element types during development
			error_log( "🚨 EFS Factory: No converter found for element type: {$element_type}" );
			return null;
		}

		// Create converter instance (with caching)
		if ( ! isset( $this->converters[ $converter_class ] ) ) {
			if ( is_a( $converter_class, Needs_Error_Handler::class, true ) ) {
				$this->converters[ $converter_class ] = new $converter_class( $this->style_map, $this->error_handler );
			} else {
				$this->converters[ $converter_class ] = new $converter_class( $this->style_map );
			}
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

		return $converter->convert( $element, $children, $context );
	}
}
