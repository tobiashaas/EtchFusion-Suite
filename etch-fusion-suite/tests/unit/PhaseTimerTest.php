<?php
/**
 * Unit tests for EFS_Phase_Timer
 *
 * @package Bricks2Etch\Tests\Unit
 */

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Services\EFS_Phase_Timer;

class PhaseTimerTest extends \WP_UnitTestCase {

	/**
	 * Test phase timer instantiation.
	 */
	public function test_phase_timer_instantiation() {
		$timer = new EFS_Phase_Timer();
		$this->assertInstanceOf( EFS_Phase_Timer::class, $timer );
	}

	/**
	 * Test starting and ending a single phase.
	 */
	public function test_start_and_end_single_phase() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		sleep( 1 );
		$timer->end_phase();

		$timing = $timer->get_timing();

		$this->assertArrayHasKey( 'validation', $timing );
		$this->assertArrayHasKey( 'start', $timing['validation'] );
		$this->assertArrayHasKey( 'end', $timing['validation'] );
		$this->assertArrayHasKey( 'duration', $timing['validation'] );
		$this->assertGreaterThanOrEqual( 1, $timing['validation']['duration'] );
	}

	/**
	 * Test that duration is calculated correctly.
	 */
	public function test_duration_calculation() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'posts' );
		sleep( 2 );
		$timer->end_phase();

		$timing = $timer->get_timing();

		$this->assertGreaterThanOrEqual( 2, $timing['posts']['duration'] );
		$this->assertLessThan( 3, $timing['posts']['duration'] );
	}

	/**
	 * Test timestamps are in ISO 8601 format.
	 */
	public function test_timestamps_are_iso8601() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'media' );
		$timer->end_phase();

		$timing = $timer->get_timing();

		// ISO 8601 format check: YYYY-MM-DDTHH:MM:SSZ
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z?/',
			$timing['media']['start']
		);
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z?/',
			$timing['media']['end']
		);
	}

	/**
	 * Test multiple phases tracking.
	 */
	public function test_multiple_phases() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'posts' );
		sleep( 1 );
		$timer->end_phase();

		$timer->start_phase( 'media' );
		sleep( 1 );
		$timer->end_phase();

		$timing = $timer->get_timing();

		$this->assertCount( 3, $timing );
		$this->assertArrayHasKey( 'validation', $timing );
		$this->assertArrayHasKey( 'posts', $timing );
		$this->assertArrayHasKey( 'media', $timing );
	}

	/**
	 * Test all five migration phases.
	 */
	public function test_all_five_phases() {
		$timer = new EFS_Phase_Timer();

		$phases = array( 'validation', 'posts', 'media', 'templates', 'finalization' );

		foreach ( $phases as $phase ) {
			$timer->start_phase( $phase );
			sleep( 1 );
			$timer->end_phase();
		}

		$timing = $timer->get_timing();

		$this->assertCount( 5, $timing );
		foreach ( $phases as $phase ) {
			$this->assertArrayHasKey( $phase, $timing );
			$this->assertArrayHasKey( 'start', $timing[ $phase ] );
			$this->assertArrayHasKey( 'end', $timing[ $phase ] );
			$this->assertArrayHasKey( 'duration', $timing[ $phase ] );
		}
	}

	/**
	 * Test starting a new phase automatically ends the previous one.
	 */
	public function test_auto_end_previous_phase() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		sleep( 1 );
		$timer->start_phase( 'posts' );

		$timing = $timer->get_timing();

		$this->assertNotNull( $timing['validation']['end'] );
		$this->assertNotNull( $timing['validation']['duration'] );
	}

	/**
	 * Test has_phase method.
	 */
	public function test_has_phase() {
		$timer = new EFS_Phase_Timer();

		$this->assertFalse( $timer->has_phase( 'validation' ) );

		$timer->start_phase( 'validation' );
		$this->assertTrue( $timer->has_phase( 'validation' ) );

		$timer->end_phase();
		$this->assertTrue( $timer->has_phase( 'validation' ) );
	}

	/**
	 * Test add_phase_timing method adds timing to run data.
	 */
	public function test_add_phase_timing_to_run_data() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		sleep( 1 );
		$timer->end_phase();

		$run_data = array(
			'migrationId' => 'test-123',
			'status'      => 'success',
		);

		$timer->add_phase_timing( $run_data );

		$this->assertArrayHasKey( 'phase_timing', $run_data );
		$this->assertArrayHasKey( 'validation', $run_data['phase_timing'] );
	}

	/**
	 * Test that get_timing automatically ends active phase.
	 */
	public function test_get_timing_auto_ends_active_phase() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'posts' );
		sleep( 1 );

		// Call get_timing without explicitly ending the phase.
		$timing = $timer->get_timing();

		$this->assertNotNull( $timing['posts']['end'] );
		$this->assertNotNull( $timing['posts']['duration'] );
	}

	/**
	 * Test ending a phase when no phase is active does nothing.
	 */
	public function test_end_phase_when_none_active() {
		$timer = new EFS_Phase_Timer();

		// Should not throw any errors.
		$timer->end_phase();

		$timing = $timer->get_timing();
		$this->assertCount( 0, $timing );
	}

	/**
	 * Test phase data structure format.
	 */
	public function test_phase_data_structure() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		$timer->end_phase();

		$timing = $timer->get_timing();
		$phase  = $timing['validation'];

		$this->assertIsString( $phase['start'] );
		$this->assertIsString( $phase['end'] );
		$this->assertIsInt( $phase['duration'] );
	}

	/**
	 * Test duration is always zero or positive.
	 */
	public function test_duration_is_non_negative() {
		$timer = new EFS_Phase_Timer();

		$timer->start_phase( 'validation' );
		$timer->end_phase();

		$timing = $timer->get_timing();

		$this->assertGreaterThanOrEqual( 0, $timing['validation']['duration'] );
	}

	/**
	 * Test run data structure after phase timing integration.
	 */
	public function test_run_data_integration() {
		$timer = new EFS_Phase_Timer();

		// Simulate a full migration with all phases.
		$phases = array( 'validation', 'posts', 'media', 'templates', 'finalization' );

		foreach ( $phases as $phase ) {
			$timer->start_phase( $phase );
			usleep( 100 );
			$timer->end_phase();
		}

		$run_data = array(
			'migrationId'       => 'test-migration-001',
			'status'            => 'success',
			'duration_sec'      => 5,
			'counts_by_post_type' => array(
				'post' => array( 'total' => 10, 'success' => 10, 'failed' => 0 ),
			),
		);

		$timer->add_phase_timing( $run_data );

		$this->assertArrayHasKey( 'phase_timing', $run_data );
		$phase_timing = $run_data['phase_timing'];

		// Verify all phases are present.
		foreach ( $phases as $phase ) {
			$this->assertArrayHasKey( $phase, $phase_timing );
			$this->assertArrayHasKey( 'start', $phase_timing[ $phase ] );
			$this->assertArrayHasKey( 'end', $phase_timing[ $phase ] );
			$this->assertArrayHasKey( 'duration', $phase_timing[ $phase ] );
		}

		// Verify other run data is preserved.
		$this->assertEquals( 'test-migration-001', $run_data['migrationId'] );
		$this->assertEquals( 'success', $run_data['status'] );
	}
}
