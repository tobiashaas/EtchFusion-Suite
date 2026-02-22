<?php
/**
 * Pre-Flight Checker Service
 *
 * Runs environment checks before migration.
 *
 * @package Etch_Fusion_Suite
 */

namespace Bricks2Etch\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Pre_Flight_Checker
 */
class EFS_Pre_Flight_Checker {

	/**
	 * Transient key for caching results.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'efs_preflight_cache';

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Run all environment checks.
	 *
	 * @param string $target_url Target site URL.
	 * @param string $mode       Migration mode ('browser' or 'headless').
	 * @return array{checks: array, has_hard_block: bool, has_soft_block: bool, checked_at: int}
	 */
	public function run_checks( string $target_url, string $mode ): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$checks = array();

		// Memory checks.
		$memory_bytes = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_bytes > 0 && $memory_bytes < 67108864 ) {
			$checks[] = array(
				'id'      => 'memory',
				'status'  => 'error',
				'value'   => ini_get( 'memory_limit' ),
				'message' => sprintf(
					/* translators: %s: current memory limit */
					__( 'PHP memory_limit is %s (minimum 64 MB required).', 'etch-fusion-suite' ),
					ini_get( 'memory_limit' )
				),
			);
		} elseif ( $memory_bytes > 0 && $memory_bytes < 268435456 ) {
			$checks[] = array(
				'id'      => 'memory_warning',
				'status'  => 'warning',
				'value'   => ini_get( 'memory_limit' ),
				'message' => sprintf(
					/* translators: %s: current memory limit */
					__( 'PHP memory_limit is %s. Large migrations may fail (recommended: 256 MB).', 'etch-fusion-suite' ),
					ini_get( 'memory_limit' )
				),
			);
		} else {
			$checks[] = array(
				'id'      => 'memory',
				'status'  => 'ok',
				'value'   => ini_get( 'memory_limit' ),
				'message' => sprintf(
					/* translators: %s: current memory limit */
					__( 'PHP memory_limit is %s.', 'etch-fusion-suite' ),
					ini_get( 'memory_limit' )
				),
			);
		}

		// Execution time check.
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 30 ) {
			$checks[] = array(
				'id'      => 'execution_time',
				'status'  => 'warning',
				'value'   => $max_exec,
				'message' => sprintf(
					/* translators: %d: max execution time in seconds */
					__( 'max_execution_time is %d seconds. Batch size will be reduced automatically.', 'etch-fusion-suite' ),
					$max_exec
				),
			);
		} else {
			$checks[] = array(
				'id'      => 'execution_time',
				'status'  => 'ok',
				'value'   => $max_exec,
				'message' => $max_exec === 0
					? __( 'max_execution_time is unlimited.', 'etch-fusion-suite' )
					: sprintf(
						/* translators: %d: max execution time in seconds */
						__( 'max_execution_time is %d seconds.', 'etch-fusion-suite' ),
						$max_exec
					),
			);
		}

		// WP Cron check.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && 'headless' === $mode ) {
			$checks[] = array(
				'id'      => 'wp_cron',
				'status'  => 'error',
				'value'   => 'disabled',
				'message' => __( 'WP Cron is disabled (DISABLE_WP_CRON) and migration mode is headless. Switch to Browser Mode or enable WP Cron.', 'etch-fusion-suite' ),
			);
		} else {
			$checks[] = array(
				'id'      => 'wp_cron',
				'status'  => 'ok',
				'value'   => 'enabled',
				'message' => __( 'WP Cron is available.', 'etch-fusion-suite' ),
			);
		}

		// WP Cron delay check.
		$cron_array = get_option( 'cron' );
		if ( is_array( $cron_array ) ) {
			$next_run = null;
			$now      = time();
			foreach ( $cron_array as $timestamp => $hook_entries ) {
				if ( ! is_numeric( $timestamp ) ) {
					continue;
				}
				$timestamp = (int) $timestamp;
				if ( $timestamp > $now ) {
					if ( null === $next_run || $timestamp < $next_run ) {
						$next_run = $timestamp;
					}
				}
			}
			if ( null !== $next_run && ( $now - $next_run ) > 300 ) {
				$checks[] = array(
					'id'      => 'wp_cron_delay',
					'status'  => 'info',
					'value'   => $next_run,
					'message' => __( 'WP Cron appears to be delayed. Some scheduled tasks may not have run.', 'etch-fusion-suite' ),
				);
			} else {
				$checks[] = array(
					'id'      => 'wp_cron_delay',
					'status'  => 'ok',
					'value'   => $next_run,
					'message' => __( 'WP Cron timing looks normal.', 'etch-fusion-suite' ),
				);
			}
		}

		// Target URL checks.
		if ( empty( $target_url ) || ! filter_var( $target_url, FILTER_VALIDATE_URL ) ) {
			$checks[] = array(
				'id'      => 'target_reachable',
				'status'  => 'ok',
				'value'   => '',
				'message' => __( 'Skipped – no target URL.', 'etch-fusion-suite' ),
			);
			$checks[] = array(
				'id'      => 'disk_space',
				'status'  => 'ok',
				'value'   => '',
				'message' => __( 'Skipped – no target URL.', 'etch-fusion-suite' ),
			);
		} else {
			// Target reachability check.
			$response = wp_remote_get(
				$target_url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				$checks[] = array(
					'id'      => 'target_reachable',
					'status'  => 'error',
					'value'   => '',
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Target site is not reachable: %s', 'etch-fusion-suite' ),
						$response->get_error_message()
					),
				);
			} else {
				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code >= 500 ) {
					$checks[] = array(
						'id'      => 'target_reachable',
						'status'  => 'error',
						'value'   => $status_code,
						'message' => sprintf(
							/* translators: %d: HTTP status code */
							__( 'Target site returned HTTP %d. The site may be down or misconfigured.', 'etch-fusion-suite' ),
							$status_code
						),
					);
				} else {
					$checks[] = array(
						'id'      => 'target_reachable',
						'status'  => 'ok',
						'value'   => $status_code,
						'message' => __( 'Target site is reachable.', 'etch-fusion-suite' ),
					);
				}
			}

			// Disk space check.
			$disk_response = wp_remote_get(
				trailingslashit( $target_url ) . 'wp-json/efs/v1/disk-space',
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);

			if ( is_wp_error( $disk_response ) || 200 !== wp_remote_retrieve_response_code( $disk_response ) ) {
				$checks[] = array(
					'id'      => 'disk_space',
					'status'  => 'info',
					'value'   => '',
					'message' => __( 'Disk space check skipped.', 'etch-fusion-suite' ),
				);
			} else {
				$body = json_decode( wp_remote_retrieve_body( $disk_response ), true );
				if ( isset( $body['free_bytes'] ) && is_numeric( $body['free_bytes'] ) ) {
					$free_bytes = (int) $body['free_bytes'];
					if ( $free_bytes < 524288000 ) {
						$checks[] = array(
							'id'      => 'disk_space',
							'status'  => 'warning',
							'value'   => $free_bytes,
							'message' => sprintf(
								/* translators: %s: free disk space formatted */
								__( 'Target site has less than 500 MB free disk space (%s free).', 'etch-fusion-suite' ),
								size_format( $free_bytes )
							),
						);
					} else {
						$checks[] = array(
							'id'      => 'disk_space',
							'status'  => 'ok',
							'value'   => $free_bytes,
							'message' => sprintf(
								/* translators: %s: free disk space formatted */
								__( 'Target site has %s free disk space.', 'etch-fusion-suite' ),
								size_format( $free_bytes )
							),
						);
					}
				} else {
					$checks[] = array(
						'id'      => 'disk_space',
						'status'  => 'info',
						'value'   => '',
						'message' => __( 'Disk space check skipped.', 'etch-fusion-suite' ),
					);
				}
			}
		}

		$has_hard_block = false;
		$has_soft_block = false;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$has_hard_block = true;
			} elseif ( 'warning' === $check['status'] ) {
				$has_soft_block = true;
			}
		}

		$result = array(
			'checks'         => $checks,
			'has_hard_block' => $has_hard_block,
			'has_soft_block' => $has_soft_block,
			'checked_at'     => time(),
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Invalidate the pre-flight check cache.
	 *
	 * @return void
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
