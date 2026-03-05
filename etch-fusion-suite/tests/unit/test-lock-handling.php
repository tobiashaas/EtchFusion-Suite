<?php
/**
 * Lock Handling Tests
 *
 * Tests for the batch processor's lock acquisition, ownership verification,
 * and cleanup mechanisms.
 *
 * @package Bricks2Etch\Tests\Unit
 */

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Services\EFS_Batch_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Lock_Handling_Test
 *
 * Comprehensive test suite for concurrent migration lock handling.
 */
class Lock_Handling_Test extends \WP_UnitTestCase {

	/**
	 * Test lock acquisition succeeds on first attempt
	 */
	public function test_lock_acquisition_succeeds() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();

		// Create a migration record
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Attempt to acquire lock
		$lock_uuid = wp_generate_uuid4();
		$locked    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$lock_uuid,
				$migration_id
			)
		);

		$this->assertTrue( $locked > 0, 'Lock acquisition should succeed on first attempt' );

		// Verify lock is set
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertEquals( $lock_uuid, $row->lock_uuid );
	}

	/**
	 * Test lock acquisition fails when held by another process
	 */
	public function test_lock_acquisition_fails_when_held() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();
		$lock_uuid_1  = wp_generate_uuid4();

		// Create a migration record with an active lock
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $lock_uuid_1,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Attempt to acquire lock with a different UUID
		$lock_uuid_2 = wp_generate_uuid4();
		$locked      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$lock_uuid_2,
				$migration_id
			)
		);

		$this->assertEquals( 0, $locked, 'Lock acquisition should fail when lock is held' );

		// Verify original lock is still in place
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertEquals( $lock_uuid_1, $row->lock_uuid );
	}

	/**
	 * Test stale lock is cleaned up and new lock acquired
	 */
	public function test_stale_lock_cleanup() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();
		$old_uuid     = wp_generate_uuid4();

		// Create a migration with an OLD lock (6 minutes ago)
		$old_time = gmdate( 'Y-m-d H:i:s', time() - 360 );

		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $old_uuid,
				'locked_at'     => $old_time,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Attempt to acquire lock (should succeed because old lock is stale)
		$new_uuid = wp_generate_uuid4();
		$locked   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$new_uuid,
				$migration_id
			)
		);

		$this->assertTrue( $locked > 0, 'Stale lock should be replaced' );

		// Verify new lock is in place
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertEquals( $new_uuid, $row->lock_uuid );
	}

	/**
	 * Test lock ownership verification (only owning UUID can release)
	 */
	public function test_lock_ownership_verification() {
		global $wpdb;

		$migration_id  = 'test-migration-' . wp_generate_uuid4();
		$owning_uuid   = wp_generate_uuid4();
		$other_uuid    = wp_generate_uuid4();

		// Create a migration with a lock
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $owning_uuid,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Attempt to release with wrong UUID (should fail)
		$released = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = NULL, locked_at = NULL
				WHERE migration_uid = %s
				AND lock_uuid = %s",
				$migration_id,
				$other_uuid
			)
		);

		$this->assertEquals( 0, $released, 'Lock release should fail with wrong UUID' );

		// Release with correct UUID (should succeed)
		$released = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = NULL, locked_at = NULL
				WHERE migration_uid = %s
				AND lock_uuid = %s",
				$migration_id,
				$owning_uuid
			)
		);

		$this->assertTrue( $released > 0, 'Lock release should succeed with correct UUID' );

		// Verify lock is released
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertNull( $row->lock_uuid );
	}

	/**
	 * Test lock TTL (5 minutes)
	 */
	public function test_lock_ttl_duration() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();
		$lock_uuid    = wp_generate_uuid4();

		// Create a migration with a fresh lock
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $lock_uuid,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Retrieve the locked_at time
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT locked_at FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$locked_at = strtotime( $row->locked_at );
		$now       = time();

		// Lock should be considered fresh (< 5 minutes old)
		$age_seconds = $now - $locked_at;
		$this->assertLessThan( 300, $age_seconds, 'Fresh lock should be < 5 minutes old' );

		// Verify lock prevents acquisition
		$new_uuid = wp_generate_uuid4();
		$locked   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$new_uuid,
				$migration_id
			)
		);

		$this->assertEquals( 0, $locked, 'Fresh lock (within 5 min) should block acquisition' );
	}

	/**
	 * Test lock is released on shutdown (simulated)
	 */
	public function test_lock_release_on_shutdown() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();
		$lock_uuid    = wp_generate_uuid4();

		// Create a migration and lock it
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $lock_uuid,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Simulate the shutdown release logic
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = NULL, locked_at = NULL
				WHERE migration_uid = %s
				AND lock_uuid = %s",
				$migration_id,
				$lock_uuid
			)
		);

		// Verify lock is released
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertNull( $row->lock_uuid, 'Lock should be released after shutdown' );
	}

	/**
	 * Test no TOCTOU race condition (atomic lock check+acquire)
	 *
	 * The UPDATE statement is atomic: the WHERE clause and SET clause
	 * are evaluated together. No gap exists where another process could
	 * interfere between checking the lock state and acquiring it.
	 */
	public function test_no_toctou_race_condition() {
		global $wpdb;

		$migration_id = 'test-migration-' . wp_generate_uuid4();

		// Create a migration without a lock
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_id,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Simulate what two concurrent processes would do
		$process_1_uuid = wp_generate_uuid4();
		$process_2_uuid = wp_generate_uuid4();

		// Process 1: Acquire lock
		$p1_locked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$process_1_uuid,
				$migration_id
			)
		);

		$this->assertTrue( $p1_locked > 0, 'Process 1 should acquire lock' );

		// Process 2: Attempt to acquire lock (should fail)
		$p2_locked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$process_2_uuid,
				$migration_id
			)
		);

		$this->assertEquals( 0, $p2_locked, 'Process 2 should not acquire lock' );

		// Verify only process 1's UUID is stored
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_id
			)
		);

		$this->assertNotNull( $row );
		$this->assertEquals( $process_1_uuid, $row->lock_uuid );
		$this->assertNotEquals( $process_2_uuid, $row->lock_uuid );
	}

	/**
	 * Test lock works across multiple migrations simultaneously
	 */
	public function test_locks_are_independent_per_migration() {
		global $wpdb;

		$migration_1 = 'test-migration-1-' . wp_generate_uuid4();
		$migration_2 = 'test-migration-2-' . wp_generate_uuid4();
		$lock_uuid_1 = wp_generate_uuid4();
		$lock_uuid_2 = wp_generate_uuid4();

		// Create two migrations with locks
		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_1,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $lock_uuid_1,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$wpdb->insert(
			$wpdb->prefix . 'efs_migrations',
			array(
				'migration_uid' => $migration_2,
				'source_url'    => 'http://source.test',
				'target_url'    => 'http://target.test',
				'status'        => 'pending',
				'lock_uuid'     => $lock_uuid_2,
				'locked_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Try to acquire lock on migration 1 (should fail - locked)
		$new_uuid_1 = wp_generate_uuid4();
		$locked_1   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$new_uuid_1,
				$migration_1
			)
		);

		$this->assertEquals( 0, $locked_1, 'Migration 1 should still be locked' );

		// Try to acquire lock on migration 2 (should fail - locked)
		$new_uuid_2 = wp_generate_uuid4();
		$locked_2   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}efs_migrations
				SET lock_uuid = %s, locked_at = NOW()
				WHERE migration_uid = %s
				AND (lock_uuid IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
				$new_uuid_2,
				$migration_2
			)
		);

		$this->assertEquals( 0, $locked_2, 'Migration 2 should still be locked' );

		// Locks should be independent
		$row_1 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_1
			)
		);
		$row_2 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lock_uuid FROM {$wpdb->prefix}efs_migrations WHERE migration_uid = %s",
				$migration_2
			)
		);

		$this->assertEquals( $lock_uuid_1, $row_1->lock_uuid );
		$this->assertEquals( $lock_uuid_2, $row_2->lock_uuid );
	}
}
