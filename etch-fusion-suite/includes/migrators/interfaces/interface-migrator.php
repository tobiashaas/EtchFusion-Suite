<?php
/**
 * Migrator Interface for Bricks to Etch Migration plugin
 *
 * Defines the contract for all migrator implementations, allowing the
 * migration workflow to interact with different migrators in a consistent
 * manner while supporting extensibility for third-party integrations.
 *
 * @package Bricks2Etch\Migrators\Interfaces
 */

namespace Bricks2Etch\Migrators\Interfaces;

use WP_Error;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface that all migrator implementations must follow.
 */
interface Migrator_Interface {
	/**
	 * Determines whether the migrator supports the current environment.
	 *
	 * Typical implementations should check for required plugins or
	 * dependencies (via function_exists/class_exists) and return true only
	 * when the migrator can run safely.
	 *
	 * @return bool True if the migrator can run, false otherwise.
	 */
	public function supports();

	/**
	 * Returns a human-readable name for the migrator.
	 *
	 * Example: "ACF Field Groups".
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Returns the unique type identifier for the migrator.
	 *
	 * Example: "acf", "metabox", "cpt".
	 *
	 * @return string
	 */
	public function get_type();

	/**
	 * Returns the priority used when executing migrators.
	 *
	 * Lower numbers execute earlier. Built-in migrators use priorities:
	 * - Custom Post Types: 10
	 * - ACF Field Groups: 20
	 * - MetaBox: 30
	 * - Custom Fields: 40
	 *
	 * @return int
	 */
	public function get_priority();

	/**
	 * Validates migrator preconditions before migration runs.
	 *
	 * Implementations should confirm required plugins are active, the
	 * necessary data structures exist, and return an array in the format:
	 *
	 * [
	 *     'valid'  => bool,
	 *     'errors' => string[]
	 * ]
	 *
	 * @return array
	 */
	public function validate();

	/**
	 * Exports data from the source site that the migrator handles.
	 *
	 * @return array
	 */
	public function export();

	/**
	 * Imports data into the target site.
	 *
	 * @param array $data Exported data from the source site.
	 *
	 * @return array|WP_Error
	 */
	public function import( $data );

	/**
	 * Performs the complete migration for this migrator.
	 *
	 * @param string $target_url Target site URL.
	 * @param string $jwt_token  Migration JWT for authentication.
	 *
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function migrate( $target_url, $jwt_token );

	/**
	 * Returns statistics about the data managed by this migrator.
	 *
	 * Example keys: total_items, migrated_items, skipped_items.
	 *
	 * @return array
	 */
	public function get_stats();
}
