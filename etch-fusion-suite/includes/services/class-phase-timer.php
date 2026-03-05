<?php
/**
 * Phase Timer Utility
 *
 * Tracks timing for individual migration phases (validation, posts, media, templates, finalization).
 * Records start time, end time, and calculates duration for each phase.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Phase_Timer
 *
 * Manages phase timing with ISO 8601 timestamps and automatic duration calculation.
 */
class EFS_Phase_Timer {

	/**
	 * Stores phase timing data.
	 *
	 * @var array
	 */
	private $phases = array();

	/**
	 * Currently active phase name.
	 *
	 * @var string|null
	 */
	private $current_phase = null;

	/**
	 * Start timing a migration phase.
	 *
	 * If another phase is already running, it will be ended before starting the new one.
	 *
	 * @param string $phase_name Name of the phase to start (e.g., 'validation', 'posts', 'media', 'templates', 'finalization').
	 * @return void
	 */
	public function start_phase( string $phase_name ): void {
		// End current phase if one is active.
		if ( $this->current_phase ) {
			$this->end_phase();
		}

		$this->current_phase         = $phase_name;
		$this->phases[ $phase_name ] = array(
			'start'    => gmdate( 'c' ),
			'end'      => null,
			'duration' => null,
		);
	}

	/**
	 * End timing the current migration phase.
	 *
	 * @return void
	 */
	public function end_phase(): void {
		if ( ! $this->current_phase || ! isset( $this->phases[ $this->current_phase ] ) ) {
			return;
		}

		$phase_name = $this->current_phase;
		$start_time = strtotime( $this->phases[ $phase_name ]['start'] );
		$end_time   = time();

		$this->phases[ $phase_name ]['end']      = gmdate( 'c', $end_time );
		$this->phases[ $phase_name ]['duration'] = max( 0, $end_time - $start_time );

		$this->current_phase = null;
	}

	/**
	 * Get the timing data for all phases.
	 *
	 * If a phase is still running, it will be ended automatically.
	 *
	 * @return array Associative array of phase timings with start, end, and duration.
	 */
	public function get_timing(): array {
		// End current phase if one is still running.
		if ( $this->current_phase ) {
			$this->end_phase();
		}

		return $this->phases;
	}

	/**
	 * Add phase timing data to a migration run record.
	 *
	 * Automatically ends the current phase if one is still running.
	 *
	 * @param array $run_data Reference to the run data array to update.
	 * @return void
	 */
	public function add_phase_timing( array &$run_data ): void {
		$run_data['phase_timing'] = $this->get_timing();
	}

	/**
	 * Check if a specific phase has been timed.
	 *
	 * @param string $phase_name Name of the phase to check.
	 * @return bool True if the phase has been tracked, false otherwise.
	 */
	public function has_phase( string $phase_name ): bool {
		return isset( $this->phases[ $phase_name ] );
	}
}
