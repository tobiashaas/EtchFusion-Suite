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
use Bricks2Etch\Converters\Elements\EFS_Element_Image;
use Bricks2Etch\Converters\Elements\EFS_Element_Div;
use Bricks2Etch\Converters\Elements\EFS_Button_Converter;
use Bricks2Etch\Converters\Elements\EFS_Icon_Converter;

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
		'text'       => EFS_Element_Paragraph::class,
		'image'      => EFS_Element_Image::class,
		'div'        => EFS_Element_Div::class,
		'block'      => EFS_Element_Div::class,
		'button'     => EFS_Button_Converter::class,
		'icon'       => EFS_Icon_Converter::class,
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
	 * Constructor
	 *
	 * @param array $style_map Style map for CSS classes
	 */
	public function __construct( $style_map = array() ) {
		$this->style_map = $style_map;
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
			'fr-notes',      // Bricks Builder notes (not frontend)
			'code',          // Code blocks (TODO)
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
			$this->converters[ $converter_class ] = new $converter_class( $this->style_map );
		}

		return $this->converters[ $converter_class ];
	}

	/**
	 * Convert element using appropriate converter
	 *
	 * @param array $element Bricks element
	 * @param array $children Child elements (already converted HTML)
	 * @return string|null Gutenberg block HTML
	 */
	public function convert_element( $element, $children = array() ) {
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

		return $converter->convert( $element, $children );
	}
}
