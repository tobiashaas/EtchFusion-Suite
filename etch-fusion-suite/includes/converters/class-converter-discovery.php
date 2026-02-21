<?php
/**
 * Converter Discovery
 *
 * Populates an EFS_Converter_Registry with built-in converters and fires WordPress
 * hooks so third-party plugins can register additional converters.
 *
 * @package Bricks_Etch_Migration
 * @since 0.13.0
 */

namespace Bricks2Etch\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Converter_Discovery {

	/**
	 * Discover and register all converters into the given registry.
	 *
	 * @param EFS_Converter_Registry $registry Registry instance to populate.
	 * @return void
	 */
	public static function discover_converters( EFS_Converter_Registry $registry ): void {
		// Register built-in converters (idempotent — registry ignores duplicates).
		foreach ( EFS_Element_Factory::BUILT_IN_CONVERTERS as $type => $class ) {
			$registry->register( $type, $class );
		}

		// Allow third-party plugins to register additional converters.
		do_action( 'etch_fusion_suite_register_converters', $registry );

		// Allow filtering/replacing the registry object itself.
		$filtered = apply_filters( 'etch_fusion_suite_converter_registry', $registry );
		if ( $filtered instanceof EFS_Converter_Registry && $filtered !== $registry ) {
			foreach ( $filtered->get_all() as $type => $class ) {
				$registry->register( $type, $class );
			}
		}
	}
}
