<?php
/**
 * Migration Progress Logging Helper
 *
 * Provides static methods for retrieving real-time migration progress logs.
 * Can be used by any controller or service class.
 *
 * @package Bricks2Etch\Controllers
 */

namespace Bricks2Etch\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migration_Progress_Logger
 *
 * Static methods for retrieving migration logs by category, errors, and progress.
 */
class EFS_Migration_Progress_Logger {

	/**
	 * Get real-time migration progress with item details.
	 *
	 * @param string $migration_id The migration ID to fetch logs for.
	 * @return array|\WP_Error Array with current item and recent logs, or error.
	 */
	public static function get_migration_progress( $migration_id ) {
		if ( empty( $migration_id ) ) {
			return new \WP_Error( 'missing_migration_id', __( 'Migration ID is required.', 'etch-fusion-suite' ) );
		}

		$container = etch_fusion_suite_container();
		if ( ! $container->has( 'db_migration_persistence' ) ) {
			return new \WP_Error( 'persistence_unavailable', __( 'Database persistence not available.', 'etch-fusion-suite' ) );
		}

		$db_persist = $container->get( 'db_migration_persistence' );
		$trail      = $db_persist->get_audit_trail( $migration_id );

		if ( ! is_array( $trail ) ) {
			return new \WP_Error( 'no_logs', __( 'No logs found for this migration.', 'etch-fusion-suite' ) );
		}

		// Get summary statistics.
		$stats = array(
			'total_events'      => count( $trail ),
			'posts_migrated'    => 0,
			'posts_failed'      => 0,
			'media_processed'   => 0,
			'css_classes'       => 0,
			'total_duration_ms' => 0,
		);

		// Process logs and extract current state.
		$current_item = null;
		$recent_logs  = array();

		foreach ( $trail as $log ) {
			$category = $log['category'] ?? '';

			// Tally statistics.
			if ( 'content_post_migrated' === $category ) {
				++$stats['posts_migrated'];
			} elseif ( 'content_post_failed' === $category ) {
				++$stats['posts_failed'];
			} elseif ( strpos( $category, 'media' ) === 0 ) {
				++$stats['media_processed'];
			} elseif ( strpos( $category, 'css' ) === 0 ) {
				++$stats['css_classes'];
			}

			// Extract duration from context.
			$ctx = json_decode( $log['context'], true );
			if ( isset( $ctx['duration_ms'] ) ) {
				$stats['total_duration_ms'] += (int) $ctx['duration_ms'];
			}

			// Keep last 10 logs for display.
			if ( count( $recent_logs ) < 10 ) {
				$recent_logs[] = array(
					'timestamp'  => $log['timestamp'],
					'level'      => $log['level'],
					'category'   => $category,
					'message'    => $log['message'],
					'context'    => $ctx,
				);
			}

			// Current item is the last event.
			$current_item = array(
				'timestamp' => $log['timestamp'],
				'category'  => $category,
				'message'   => $log['message'],
				'context'   => $ctx,
			);
		}

		return array(
			'migration_id'  => $migration_id,
			'current_item'  => $current_item,
			'recent_logs'   => array_reverse( $recent_logs ), // Newest first
			'statistics'    => $stats,
		);
	}

	/**
	 * Get error logs for a migration (failures only).
	 *
	 * @param string $migration_id The migration ID.
	 * @return array Array of error log entries.
	 */
	public static function get_migration_errors( $migration_id ) {
		if ( empty( $migration_id ) ) {
			return array();
		}

		$container = etch_fusion_suite_container();
		if ( ! $container->has( 'db_migration_persistence' ) ) {
			return array();
		}

		$db_persist = $container->get( 'db_migration_persistence' );
		$trail      = $db_persist->get_audit_trail( $migration_id );

		if ( ! is_array( $trail ) ) {
			return array();
		}

		$errors = array();
		foreach ( $trail as $log ) {
			if ( 'error' === $log['level'] ) {
				$ctx = json_decode( $log['context'], true );
				$errors[] = array(
					'timestamp' => $log['timestamp'],
					'message'   => $log['message'],
					'category'  => $log['category'],
					'context'   => $ctx,
				);
			}
		}

		return $errors;
	}

	/**
	 * Get logs filtered by category (e.g., all post migrations).
	 *
	 * @param string $migration_id The migration ID.
	 * @param string $category     Log category to filter (e.g., 'content_post_migrated').
	 * @return array Filtered log entries.
	 */
	public static function get_migration_logs_by_category( $migration_id, $category ) {
		if ( empty( $migration_id ) || empty( $category ) ) {
			return array();
		}

		$container = etch_fusion_suite_container();
		if ( ! $container->has( 'db_migration_persistence' ) ) {
			return array();
		}

		$db_persist = $container->get( 'db_migration_persistence' );
		$trail      = $db_persist->get_audit_trail( $migration_id );

		if ( ! is_array( $trail ) ) {
			return array();
		}

		$filtered = array();
		foreach ( $trail as $log ) {
			if ( $category === ( $log['category'] ?? '' ) ) {
				$ctx       = json_decode( $log['context'], true );
				$filtered[] = array(
					'timestamp' => $log['timestamp'],
					'level'     => $log['level'],
					'message'   => $log['message'],
					'context'   => $ctx,
				);
			}
		}

		return $filtered;
	}
}
