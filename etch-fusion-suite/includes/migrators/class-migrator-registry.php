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
use Throwable;
use function esc_html;
use function error_log;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry for migrators.
 */
class EFS_Migrator_Registry {
	/**
	 * @var EFS_Migrator_Registry|null
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
			throw new RuntimeException( sprintf( 'Migrator type "%s" is already registered.', esc_html( (string) $type ) ) );
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
	 *
	 * Migrators that throw during get_priority() are sorted to the end (priority PHP_INT_MAX)
	 * and the error is logged, so a single buggy migrator cannot break the entire sort.
	 */
	public function get_all() {
		$migrators = $this->migrators;

		uasort(
			$migrators,
			function ( Migrator_Interface $a, Migrator_Interface $b ) {
				try {
					$pa = $a->get_priority();
				} catch ( Throwable $e ) {
					error_log( 'EFS_Migrator_Registry: get_priority() threw on ' . get_class( $a ) . ': ' . $e->getMessage() );
					$pa = PHP_INT_MAX;
				}
				try {
					$pb = $b->get_priority();
				} catch ( Throwable $e ) {
					error_log( 'EFS_Migrator_Registry: get_priority() threw on ' . get_class( $b ) . ': ' . $e->getMessage() );
					$pb = PHP_INT_MAX;
				}
				return $pa <=> $pb;
			}
		);

		return $migrators;
	}

	/**
	 * Returns migrators whose supports() check returns true.
	 *
	 * A migrator that throws from supports() is treated as unsupported and its
	 * error is logged, so a single broken migrator cannot prevent others from running.
	 */
	public function get_supported() {
		return array_filter(
			$this->get_all(),
			function ( Migrator_Interface $migrator ) {
				try {
					return (bool) $migrator->supports();
				} catch ( Throwable $e ) {
					error_log( 'EFS_Migrator_Registry: supports() threw on ' . get_class( $migrator ) . ': ' . $e->getMessage() );
					return false;
				}
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
