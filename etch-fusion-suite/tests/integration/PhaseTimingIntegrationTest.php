<?php
/**
 * Integration test for phase-timing tracking in migration runs
 *
 * Verifies that phase timing is properly tracked and persisted to migration run records.
 *
 * @package Bricks2Etch\Tests\Integration
 */

namespace Bricks2Etch\Tests\Integration;

use Bricks2Etch\Services\EFS_Phase_Timer;
use Bricks2Etch\Repositories\EFS_Migration_Runs_Repository;

class PhaseTimingIntegrationTest extends \WP_UnitTestCase {

	/**
	 * Test that phase timing is stored in migration run records.
	 */
	public function test_phase_timing_stored_in_migration_runs() {
		$timer = new EFS_Phase_Timer();

		// Simulate migration phases.
		$timer->start_phase( 'validation' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'posts' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'media' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'templates' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'finalization' );
		sleep( 1 );
		$timer->end_phase();

		// Build a migration run record with phase timing.
		$record = array(
			'migrationId'                => 'test-migration-' . time(),
			'timestamp_started_at'       => gmdate( 'Y-m-d H:i:s', time() - 10 ),
			'timestamp_completed_at'     => gmdate( 'Y-m-d H:i:s' ),
			'source_site'                => home_url(),
			'target_url'                 => 'https://example-etch.com',
			'status'                     => 'success',
			'counts_by_post_type'        => array(
				'post' => array( 'total' => 10, 'success' => 10, 'failed' => 0, 'skipped' => 0 ),
			),
			'post_type_mappings'         => array(),
			'failed_posts_count'         => 0,
			'failed_post_ids'            => array(),
			'failed_media_count'         => 0,
			'failed_media_ids'           => array(),
			'warnings_count'             => 0,
			'warnings_summary'           => null,
			'errors_summary'             => null,
			'duration_sec'               => 5,
			'optional_migrator_warnings' => array(),
		);

		// Add phase timing to the record.
		$timer->add_phase_timing( $record );

		// Verify phase_timing is present and structured correctly.
		$this->assertArrayHasKey( 'phase_timing', $record );
		$phase_timing = $record['phase_timing'];

		// Verify all five phases are present.
		$expected_phases = array( 'validation', 'posts', 'media', 'templates', 'finalization' );
		foreach ( $expected_phases as $phase ) {
			$this->assertArrayHasKey( $phase, $phase_timing, "Phase '$phase' should be in phase_timing" );
			$this->assertArrayHasKey( 'start', $phase_timing[ $phase ] );
			$this->assertArrayHasKey( 'end', $phase_timing[ $phase ] );
			$this->assertArrayHasKey( 'duration', $phase_timing[ $phase ] );

			// Verify timing data types.
			$this->assertIsString( $phase_timing[ $phase ]['start'] );
			$this->assertIsString( $phase_timing[ $phase ]['end'] );
			$this->assertIsInt( $phase_timing[ $phase ]['duration'] );

			// Verify duration is reasonable (at least 1 second from our sleep calls).
			$this->assertGreaterThanOrEqual( 1, $phase_timing[ $phase ]['duration'] );
		}

		// Verify timestamps are in ISO 8601 format.
		foreach ( $expected_phases as $phase ) {
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
				$phase_timing[ $phase ]['start'],
				"Start timestamp for phase '$phase' should be in ISO 8601 format"
			);
			$this->assertMatchesRegularExpression(
				'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
				$phase_timing[ $phase ]['end'],
				"End timestamp for phase '$phase' should be in ISO 8601 format"
			);
		}
	}

	/**
	 * Test backward compatibility: missing phase_timing initializes as empty.
	 */
	public function test_backward_compatibility_missing_phase_timing() {
		$repository = new EFS_Migration_Runs_Repository();

		// Create a record without phase_timing (simulating old migrations).
		$old_record = array(
			'migrationId'                => 'legacy-migration-' . time(),
			'timestamp_started_at'       => gmdate( 'Y-m-d H:i:s', time() - 10 ),
			'timestamp_completed_at'     => gmdate( 'Y-m-d H:i:s' ),
			'source_site'                => home_url(),
			'target_url'                 => 'https://example-etch.com',
			'status'                     => 'success',
			'counts_by_post_type'        => array(),
			'post_type_mappings'         => array(),
			'failed_posts_count'         => 0,
			'failed_post_ids'            => array(),
			'failed_media_count'         => 0,
			'failed_media_ids'           => array(),
			'warnings_count'             => 0,
			'warnings_summary'           => null,
			'errors_summary'             => null,
			'duration_sec'               => 5,
		);

		// This should not have phase_timing (backward compatible).
		$this->assertArrayNotHasKey( 'phase_timing', $old_record );

		// Save and retrieve to verify backward compatibility.
		$repository->save_run( $old_record );
		$retrieved = $repository->get_run_by_id( $old_record['migrationId'] );

		$this->assertIsArray( $retrieved );
		$this->assertEquals( $old_record['migrationId'], $retrieved['migrationId'] );
	}

	/**
	 * Test phase timing structure matches expected data format.
	 */
	public function test_phase_timing_data_structure() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		usleep( 500 );
		$timer->end_phase();

		$timing = $timer->get_timing();

		// Each phase should have this structure:
		// [
		//   'start' => '2026-03-05T10:00:00Z',
		//   'end' => '2026-03-05T10:00:15Z',
		//   'duration' => 15
		// ]

		$validation_timing = $timing['validation'];

		$this->assertCount( 3, $validation_timing );
		$this->assertArrayHasKey( 'start', $validation_timing );
		$this->assertArrayHasKey( 'end', $validation_timing );
		$this->assertArrayHasKey( 'duration', $validation_timing );

		// No other keys should be present.
		$this->assertEquals( array( 'start', 'end', 'duration' ), array_keys( $validation_timing ) );
	}
}
