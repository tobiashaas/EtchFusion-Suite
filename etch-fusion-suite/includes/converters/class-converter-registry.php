<?php
/**
 * Converter Registry
 *
 * Non-singleton registry that maps Bricks element type strings to converter class names.
 * Injected via the DI container and populated by EFS_Converter_Discovery.
 *
 * @package Bricks_Etch_Migration
 * @since 0.13.0
 */

namespace Bricks2Etch\Converters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Converter_Registry {

	/**
	 * Map of element type => converter class.
	 *
	 * @var array<string, string>
	 */
	private $converters = array();

	/**
	 * Register a converter class for an element type.
	 *
	 * Silently ignores duplicate registrations (first-one-wins).
	 *
	 * @param string $type  Bricks element type identifier.
	 * @param string $class Fully-qualified converter class name.
	 * @return void
	 */
	public function register( string $type, string $class ): void {
		if ( isset( $this->converters[ $type ] ) ) {
			return;
		}
		$this->converters[ $type ] = $class;
	}

	/**
	 * Retrieve the converter class for an element type.
	 *
	 * @param string $type Bricks element type identifier.
	 * @return string|null Converter class name or null if not registered.
	 */
	public function get( string $type ): ?string {
		return $this->converters[ $type ] ?? null;
	}

	/**
	 * Check whether a converter is registered for an element type.
	 *
	 * @param string $type Bricks element type identifier.
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->converters[ $type ] );
	}

	/**
	 * Return the full converters map.
	 *
	 * @return array<string, string>
	 */
	public function get_all(): array {
		return $this->converters;
	}

	/**
	 * Remove a converter registration.
	 *
	 * @param string $type Bricks element type identifier.
	 * @return void
	 */
	public function unregister( string $type ): void {
		unset( $this->converters[ $type ] );
	}
}
