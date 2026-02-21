<?php
/**
 * Steps Manager Service
 *
 * Owns phase-order knowledge: the canonical PHASES map, step initialisation,
 * next-phase lookup, and step-state mutation for progress updates.
 * Extracted from EFS_Progress_Manager to keep progress I/O separate from
 * step-state business logic.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Plugin_Detector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Steps_Manager
 *
 * Manages the canonical phase order and step-state transitions.
 */
class EFS_Steps_Manager {

	/**
	 * Canonical phase order for dashboard progress.
	 */
	private const PHASES = array(
		'validation'    => 'Validation',
		'analyzing'     => 'Analyzing',
		'cpts'          => 'Custom Post Types',
		'acf'           => 'ACF Field Groups',
		'metabox'       => 'MetaBox Configs',
		'custom_fields' => 'Custom Fields',
		'media'         => 'Media',
		'css'           => 'CSS',
		'posts'         => 'Posts',
		'finalization'  => 'Finalization',
	);

	/** @var EFS_Plugin_Detector */
	private $plugin_detector;

	/**
	 * @param EFS_Plugin_Detector $plugin_detector
	 */
	public function __construct( EFS_Plugin_Detector $plugin_detector ) {
		$this->plugin_detector = $plugin_detector;
	}

	/**
	 * Initialize steps for progress UI and execution.
	 *
	 * @param array $options Optional. include_media (bool). Plugin presence drives ACF/MetaBox/custom_fields.
	 * @return array
	 */
	public function initialize_steps( array $options = array() ): array {
		$include_media     = ! isset( $options['include_media'] ) || ! empty( $options['include_media'] );
		$has_acf           = $this->plugin_detector->is_acf_active();
		$has_metabox       = $this->plugin_detector->is_metabox_active();
		$has_custom_fields = $has_acf || $has_metabox;

		$phases_to_include = array(
			'validation'    => true,
			'analyzing'     => true,
			'cpts'          => true,
			'acf'           => $has_acf,
			'metabox'       => $has_metabox,
			'custom_fields' => $has_custom_fields,
			'media'         => $include_media,
			'css'           => true,
			'posts'         => true,
			'finalization'  => true,
		);

		$steps     = array();
		$index     = 0;
		$timestamp = current_time( 'mysql' );
		foreach ( self::PHASES as $phase_key => $label ) {
			if ( empty( $phases_to_include[ $phase_key ] ) ) {
				continue;
			}
			$steps[ $phase_key ] = array(
				'label'           => $label,
				'status'          => 0 === $index ? 'active' : 'pending',
				'active'          => 0 === $index,
				'completed'       => false,
				'failed'          => false,
				'items_processed' => 0,
				'items_total'     => 0,
				'updated_at'      => $timestamp,
			);
			++$index;
		}

		return $steps;
	}

	/**
	 * Get next phase key in canonical order.
	 *
	 * @param string $phase Current phase.
	 * @param array  $steps Optional. If provided, next key must be in this set.
	 * @return string
	 */
	public function get_next_phase_key( string $phase, array $steps = array() ): string {
		$keys  = array_keys( self::PHASES );
		$index = array_search( $phase, $keys, true );

		if ( false === $index ) {
			return '';
		}

		$start = $index + 1;
		for ( $i = $start; $i < count( $keys ); $i++ ) {
			$key = $keys[ $i ];
			if ( empty( $steps ) || isset( $steps[ $key ] ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Return the human-readable label for a phase key.
	 *
	 * @param string $phase Phase key.
	 * @return string Empty string if unknown.
	 */
	public function get_phase_name( string $phase ): string {
		return self::PHASES[ $phase ] ?? '';
	}

	/**
	 * Whether the given key is a known phase.
	 *
	 * @param string $phase Phase key.
	 * @return bool
	 */
	public function is_known_phase( string $phase ): bool {
		return isset( self::PHASES[ $phase ] );
	}

	/**
	 * Apply step-state transitions for a progress update.
	 *
	 * Mutates the step entries inside $steps according to the current $step and
	 * $step_truly_finished flag, then returns the updated array.
	 *
	 * @param string $step               Phase key being updated.
	 * @param bool   $step_truly_finished Whether the step is fully done (not just active).
	 * @param array  $steps              Current step-state map.
	 * @return array Updated step-state map.
	 */
	public function update_steps_for_progress( string $step, bool $step_truly_finished, array $steps ): array {
		foreach ( $steps as $key => &$step_data ) {
			if ( ! is_array( $step_data ) ) {
				continue;
			}

			if ( 'error' === $step ) {
				if ( ! empty( $step_data['active'] ) ) {
					$step_data['status'] = 'failed';
					$step_data['active'] = false;
				}
			} elseif ( $key === $step ) {
				if ( $step_truly_finished ) {
					$step_data['status']    = 'completed';
					$step_data['active']    = false;
					$step_data['completed'] = true;
				} else {
					$step_data['status']    = 'active';
					$step_data['active']    = true;
					$step_data['completed'] = false;
				}
			} elseif ( empty( $step_data['completed'] ) && ! empty( $step_data['active'] ) ) {
				$step_data['status']    = 'completed';
				$step_data['active']    = false;
				$step_data['completed'] = true;
			} elseif ( empty( $step_data['completed'] ) && 'pending' === ( $step_data['status'] ?? 'pending' ) ) {
				$step_data['status'] = 'pending';
			}

			$step_data['updated_at'] = current_time( 'mysql' );
		}
		unset( $step_data );

		if ( 'completed' !== $step && 'error' !== $step && $step_truly_finished ) {
			$next_phase = $this->get_next_phase_key( $step, $steps );
			if ( $next_phase && isset( $steps[ $next_phase ] ) && empty( $steps[ $next_phase ]['completed'] ) ) {
				$steps[ $next_phase ]['status']     = 'active';
				$steps[ $next_phase ]['active']     = true;
				$steps[ $next_phase ]['updated_at'] = current_time( 'mysql' );
			}
		}

		return $steps;
	}
}
