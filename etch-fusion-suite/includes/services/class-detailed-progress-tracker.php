<?php
/**
 * Detailed Progress Tracker Service
 *
 * Logs detailed information about each item being migrated (posts, media, CSS classes).
 * Provides centralized API for all services to report progress with detail.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Repositories\EFS_DB_Migration_Persistence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Detailed_Progress_Tracker
 *
 * Tracks migration progress with item-level detail:
 * - Per-post information (title, blocks, fields, duration)
 * - Per-media information (filename, size, status)
 * - Per-class information (CSS class conversions, conflicts)
 * - Real-time current item state for dashboard
 */
class EFS_Detailed_Progress_Tracker {

	/** @var string */
	private $migration_id;

	/** @var EFS_DB_Migration_Persistence */
	private $db_persist;

	/** @var array */
	private $current_item = array();

	/** @var int */
	private $start_time;

	/**
	 * @param string                      $migration_id Migration ID.
	 * @param EFS_DB_Migration_Persistence $db_persist    Database persistence service.
	 */
	public function __construct( $migration_id, EFS_DB_Migration_Persistence $db_persist = null ) {
		$this->migration_id = $migration_id;
		$this->db_persist   = $db_persist;
		$this->start_time   = microtime( true );
	}

	/**
	 * Log a post migration event.
	 *
	 * @param int     $post_id         Post ID.
	 * @param string  $post_title      Post title.
	 * @param string  $status          'success' or 'failed'.
	 * @param array   $metadata        Additional metadata (blocks, fields, duration, error, etc).
	 * @return void
	 */
	public function log_post_migration( $post_id, $post_title, $status = 'success', $metadata = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$level    = 'success' === $status ? 'info' : 'error';
		$category = 'success' === $status ? 'content_post_migrated' : 'content_post_failed';
		$message  = 'success' === $status
			? sprintf( __( 'Post migrated: "%s"', 'etch-fusion-suite' ), $post_title )
			: sprintf( __( 'Post failed: "%s"', 'etch-fusion-suite' ), $post_title );

		$context = array(
			'post_id'   => $post_id,
			'title'     => $post_title,
			'status'    => $status,
		);

		// Merge in metadata (blocks_converted, fields_migrated, duration_ms, error, etc)
		$context = array_merge( $context, $metadata );

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			$category,
			$context
		);

		// Update current item for dashboard
		$this->set_current_item( 'post', $post_id, $post_title, $status );
	}

	/**
	 * Log a media migration event.
	 *
	 * @param string  $url             Media URL.
	 * @param string  $filename        Filename.
	 * @param string  $status          'success', 'skipped', or 'failed'.
	 * @param array   $metadata        Additional metadata (size_bytes, mime_type, error, etc).
	 * @return void
	 */
	public function log_media_migration( $url, $filename, $status = 'success', $metadata = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$level    = 'success' === $status ? 'info' : ( 'skipped' === $status ? 'warning' : 'error' );
		$category = 'media_' . $status;
		$message  = sprintf( __( 'Media %s: %s', 'etch-fusion-suite' ), $status, $filename );

		$context = array(
			'url'      => $url,
			'filename' => $filename,
			'status'   => $status,
		);

		$context = array_merge( $context, $metadata );

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			'media',
			$context
		);

		$this->set_current_item( 'media', $filename, $filename, $status );
	}

	/**
	 * Log CSS class migration event.
	 *
	 * @param string  $class_name      CSS class name.
	 * @param string  $status          'converted', 'skipped', or 'conflict'.
	 * @param array   $metadata        Additional metadata (conflict_count, suggestions, etc).
	 * @return void
	 */
	public function log_css_migration( $class_name, $status = 'converted', $metadata = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$level    = 'converted' === $status ? 'info' : 'warning';
		$category = 'css_' . $status;
		$message  = sprintf( __( 'CSS class %s: %s', 'etch-fusion-suite' ), $status, $class_name );

		$context = array(
			'class_name' => $class_name,
			'status'     => $status,
		);

		$context = array_merge( $context, $metadata );

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			'css',
			$context
		);

		$this->set_current_item( 'css_class', $class_name, $class_name, $status );
	}

	/**
	 * Log custom field migration event.
	 *
	 * @param string  $field_type      Field type (ACF, metabox, etc).
	 * @param int     $count           Count of fields/values migrated.
	 * @param string  $status          'success' or 'partial'.
	 * @param array   $metadata        Additional metadata (failed_count, etc).
	 * @return void
	 */
	public function log_custom_fields_migration( $field_type, $count, $status = 'success', $metadata = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$level    = 'success' === $status ? 'info' : 'warning';
		$category = 'custom_fields_' . $field_type;
		$message  = sprintf(
			__( '%s: %d fields migrated (%s)', 'etch-fusion-suite' ),
			ucfirst( $field_type ),
			$count,
			$status
		);

		$context = array(
			'field_type' => $field_type,
			'count'      => $count,
			'status'     => $status,
		);

		$context = array_merge( $context, $metadata );

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			'custom_fields',
			$context
		);

		$this->set_current_item( 'custom_fields', $field_type, $field_type, $status );
	}

	/**
	 * Log batch completion event.
	 *
	 * @param string  $batch_type      Type of batch (posts, media, css_classes, etc).
	 * @param int     $completed       Number completed in this batch.
	 * @param int     $total           Total in batch.
	 * @param int     $errors          Number of errors in batch.
	 * @param array   $metadata        Additional metadata (duration_ms, avg_duration, etc).
	 * @return void
	 */
	public function log_batch_completion( $batch_type, $completed, $total, $errors = 0, $metadata = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$level   = 0 === $errors ? 'info' : 'warning';
		$message = sprintf(
			__( '%s batch completed: %d/%d (%d errors)', 'etch-fusion-suite' ),
			ucfirst( str_replace( '_', ' ', $batch_type ) ),
			$completed,
			$total,
			$errors
		);

		$context = array(
			'batch_type' => $batch_type,
			'completed'  => $completed,
			'total'      => $total,
			'errors'     => $errors,
		);

		$context = array_merge( $context, $metadata );

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			'batch_completion',
			$context
		);
	}

	/**
	 * Set current item being processed (for dashboard display).
	 *
	 * @param string $type     Item type (post, media, css_class, etc).
	 * @param string $id       Item ID/key.
	 * @param string $title    Item title/name.
	 * @param string $status   Current status (processing, completed, failed, etc).
	 * @return void
	 */
	public function set_current_item( $type, $id, $title, $status = 'processing' ) {
		$this->current_item = array(
			'type'      => $type,
			'id'        => $id,
			'title'     => $title,
			'status'    => $status,
			'timestamp' => current_time( 'mysql', true ),
			'elapsed'   => microtime( true ) - $this->start_time,
		);
	}

	/**
	 * Get current item being processed.
	 *
	 * @return array Current item or empty array.
	 */
	public function get_current_item() {
		return $this->current_item;
	}

	/**
	 * Log custom event (for flexible logging of other events).
	 *
	 * @param string  $level       Log level (info, warning, error).
	 * @param string  $message     Event message.
	 * @param string  $category    Event category.
	 * @param array   $context     Event context as array.
	 * @return void
	 */
	public function log_event( $level, $message, $category, $context = array() ) {
		if ( ! $this->db_persist ) {
			return;
		}

		$this->db_persist->log_event(
			$this->migration_id,
			$level,
			$message,
			$category,
			$context
		);
	}
}
