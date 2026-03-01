<?php
/**
 * Unit Tests for Migration Progress Logger Trait
 *
 * @package Bricks2Etch\Tests
 */

namespace Bricks2Etch\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Bricks2Etch\Controllers\EFS_Migration_Progress_Logger;

/**
 * Test_EFS_Migration_Progress_Logger
 *
 * Tests the query methods in the migration progress logger trait.
 */
class Test_EFS_Migration_Progress_Logger extends \WP_UnitTestCase {

	/**
	 * Instance of a class using the trait.
	 *
	 * @var object
	 */
	private $logger;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// Create a dummy class that uses the trait.
		eval( '
			class TestLogger {
				use ' . EFS_Migration_Progress_Logger::class . ';
			}
		' );

		$this->logger = new \TestLogger();
	}

	/**
	 * Test get_migration_progress with empty migration ID.
	 */
	public function test_get_migration_progress_empty_migration_id() {
		$result = $this->logger->get_migration_progress( '' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Migration ID', $result->get_error_message() );
	}

	/**
	 * Test get_migration_progress with no logs found.
	 */
	public function test_get_migration_progress_no_logs_found() {
		$result = $this->logger->get_migration_progress( 'nonexistent-id' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'No logs', $result->get_error_message() );
	}

	/**
	 * Test get_migration_errors with empty migration ID.
	 */
	public function test_get_migration_errors_empty_id() {
		$errors = $this->logger->get_migration_errors( '' );
		$this->assertIsArray( $errors );
		$this->assertEmpty( $errors );
	}

	/**
	 * Test get_migration_logs_by_category with empty parameters.
	 */
	public function test_get_migration_logs_by_category_empty_params() {
		$logs = $this->logger->get_migration_logs_by_category( '', '' );
		$this->assertIsArray( $logs );
		$this->assertEmpty( $logs );
	}

	/**
	 * Test get_migration_logs_by_category with valid params but no results.
	 */
	public function test_get_migration_logs_by_category_no_results() {
		$logs = $this->logger->get_migration_logs_by_category( 'id-123', 'content_post_migrated' );
		$this->assertIsArray( $logs );
		$this->assertEmpty( $logs );
	}

	/**
	 * Test method names are accessible.
	 */
	public function test_trait_methods_exist() {
		$this->assertTrue( method_exists( $this->logger, 'get_migration_progress' ) );
		$this->assertTrue( method_exists( $this->logger, 'get_migration_errors' ) );
		$this->assertTrue( method_exists( $this->logger, 'get_migration_logs_by_category' ) );
	}

	/**
	 * Test that methods return correct types.
	 */
	public function test_method_return_types() {
		// Progress returns WP_Error on failure
		$progress = $this->logger->get_migration_progress( '' );
		$this->assertTrue( is_wp_error( $progress ) || is_array( $progress ) );

		// Errors returns array
		$errors = $this->logger->get_migration_errors( 'id-123' );
		$this->assertIsArray( $errors );

		// Logs returns array
		$logs = $this->logger->get_migration_logs_by_category( 'id-123', 'test' );
		$this->assertIsArray( $logs );
	}
}
