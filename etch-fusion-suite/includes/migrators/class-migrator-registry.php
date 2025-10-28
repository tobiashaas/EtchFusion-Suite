<?php
/**
 * Migrator registry for Bricks to Etch migration plugin
 *
 * Stores all migrators and provides helper methods for registration,
 * retrieval, and filtering based on support and priority.
 *
 * @package Bricks2Etch\Migrators
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use InvalidArgumentException;
use RuntimeException;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry for migrators.
 */
class EFS_Migrator_Registry {
	/**
	 * @var B2E_Migrator_Registry|null
	 */
	private static $instance;

	/**
	 * @var Migrator_Interface[] Migrators keyed by type.
	 */
	private $migrators = array();

	/**
	 * Private constructor to enforce singleton usage.
	 */
	private function __construct() {}

	/**
	 * Returns singleton instance.
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers a migrator.
	 */
	public function register( Migrator_Interface $migrator ) {
		$type = $migrator->get_type();

		if ( ! $type ) {
			throw new InvalidArgumentException( 'Migrator type must be defined.' );
		}

		if ( isset( $this->migrators[ $type ] ) ) {
			throw new RuntimeException( sprintf( 'Migrator type "%s" is already registered.', \esc_html( (string) $type ) ) );
		}

		$this->migrators[ $type ] = $migrator;
	}

	/**
	 * Unregisters a migrator by type.
	 */
	public function unregister( $type ) {
		unset( $this->migrators[ $type ] );
	}

	/**
	 * Returns migrator by type or null if not registered.
	 */
	public function get( $type ) {
		return isset( $this->migrators[ $type ] ) ? $this->migrators[ $type ] : null;
	}

	/**
	 * Checks if migrator is registered.
	 */
	public function has( $type ) {
		return isset( $this->migrators[ $type ] );
	}

	/**
	 * Returns all migrators sorted by priority (ascending).
	 */
	public function get_all() {
		$migrators = $this->migrators;

		uasort(
			$migrators,
			function ( Migrator_Interface $a, Migrator_Interface $b ) {
				return $a->get_priority() <=> $b->get_priority();
			}
		);

		return $migrators;
	}

	/**
	 * Returns migrators whose supports() check returns true.
	 */
	public function get_supported() {
		return array_filter(
			$this->get_all(),
			function ( Migrator_Interface $migrator ) {
				return (bool) $migrator->supports();
			}
		);
	}

	/**
	 * Returns array of registered migrator types.
	 */
	public function get_types() {
		return array_keys( $this->migrators );
	}

	/**
	 * Returns number of registered migrators.
	 */
	public function count() {
		return count( $this->migrators );
	}

	/**
	 * Clears registry (primarily for testing).
	 */
	public function clear() {
		$this->migrators = array();
	}
}
