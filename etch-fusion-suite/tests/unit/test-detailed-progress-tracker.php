<?php
/**
 * Unit Test: EFS_Detailed_Progress_Tracker
 *
 * Tests the detailed progress tracker functionality.
 *
 * @package Bricks2Etch\Tests
 */

require_once dirname( dirname( __FILE__ ) ) . '/vendor/autoload.php';

use Bricks2Etch\Services\EFS_Detailed_Progress_Tracker;
use Bricks2Etch\Repositories\EFS_DB_Migration_Persistence;

class Test_Detailed_Progress_Tracker extends \PHPUnit\Framework\TestCase {

	private $db_persistence;
	private $migration_id;

	public function setUp(): void {
		parent::setUp();
		$this->db_persistence = new EFS_DB_Migration_Persistence();
		$this->migration_id    = 'test-tracker-' . uniqid();

		// Create migration record.
		$this->db_persistence->create_migration(
			array(
				'migration_uid' => $this->migration_id,
				'source_url'   => 'http://localhost:8888',
				'target_url'   => 'http://localhost:8889',
				'initiated_by' => 'test_user',
				'state'        => 'in_progress',
			)
		);
	}

	public function test_log_post_migration_success() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->log_post_migration(
			42,
			'Test Post',
			'success',
			array(
				'blocks_converted' => 5,
				'fields_migrated'  => 2,
				'duration_ms'      => 450,
			)
		);

		$trail = $this->db_persistence->get_audit_trail( $this->migration_id );
		$this->assertNotEmpty( $trail );

		$post_logs = array_filter(
			$trail,
			function ( $entry ) {
				return 'content_post_migrated' === ( $entry['category'] ?? '' );
			}
		);

		$this->assertCount( 1, $post_logs );
		$log = reset( $post_logs );
		$ctx = json_decode( $log['context'], true );

		$this->assertEquals( 42, $ctx['post_id'] );
		$this->assertEquals( 'Test Post', $ctx['title'] );
		$this->assertEquals( 'success', $ctx['status'] );
		$this->assertEquals( 5, $ctx['blocks_converted'] );
	}

	public function test_log_post_migration_failed() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->log_post_migration(
			43,
			'Failed Post',
			'failed',
			array(
				'error'                => 'Unsupported block type',
				'unsupported_blocks'   => array( 'bricks-form' ),
				'duration_ms'          => 200,
			)
		);

		$trail = $this->db_persistence->get_audit_trail( $this->migration_id );

		$fail_logs = array_filter(
			$trail,
			function ( $entry ) {
				return 'content_post_failed' === ( $entry['category'] ?? '' );
			}
		);

		$this->assertCount( 1, $fail_logs );
		$log = reset( $fail_logs );
		$this->assertEquals( 'error', $log['level'] );

		$ctx = json_decode( $log['context'], true );
		$this->assertEquals( 'failed', $ctx['status'] );
		$this->assertStringContainsString( 'Unsupported', $ctx['error'] );
	}

	public function test_log_media_migration() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->log_media_migration(
			'https://example.com/image.jpg',
			'image.jpg',
			'success',
			array(
				'size_bytes' => 245000,
				'mime_type'  => 'image/jpeg',
				'duration_ms' => 1200,
			)
		);

		$trail = $this->db_persistence->get_audit_trail( $this->migration_id );

		$media_logs = array_filter(
			$trail,
			function ( $entry ) {
				return 'media' === ( $entry['category'] ?? '' );
			}
		);

		$this->assertCount( 1, $media_logs );
		$log = reset( $media_logs );
		$ctx = json_decode( $log['context'], true );

		$this->assertEquals( 'https://example.com/image.jpg', $ctx['url'] );
		$this->assertEquals( 'image.jpg', $ctx['filename'] );
		$this->assertEquals( 245000, $ctx['size_bytes'] );
	}

	public function test_log_css_migration() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->log_css_migration(
			'button-primary',
			'converted',
			array(
				'new_class_name' => 'etch-btn-primary',
				'conflicts'      => 0,
			)
		);

		$trail = $this->db_persistence->get_audit_trail( $this->migration_id );

		$css_logs = array_filter(
			$trail,
			function ( $entry ) {
				return 'css' === ( $entry['category'] ?? '' );
			}
		);

		$this->assertCount( 1, $css_logs );
	}

	public function test_set_current_item() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->set_current_item( 'post', 42, 'Test Post', 'processing' );
		$current = $tracker->get_current_item();

		$this->assertEquals( 'post', $current['type'] );
		$this->assertEquals( 42, $current['id'] );
		$this->assertEquals( 'Test Post', $current['title'] );
		$this->assertEquals( 'processing', $current['status'] );
	}

	public function test_batch_completion_logging() {
		$tracker = new EFS_Detailed_Progress_Tracker( $this->migration_id, $this->db_persistence );

		$tracker->log_batch_completion(
			'posts',
			50,
			100,
			2,
			array(
				'avg_duration_ms' => 1250,
			)
		);

		$trail = $this->db_persistence->get_audit_trail( $this->migration_id );

		$batch_logs = array_filter(
			$trail,
			function ( $entry ) {
				return 'batch_completion' === ( $entry['category'] ?? '' );
			}
		);

		$this->assertCount( 1, $batch_logs );
		$log = reset( $batch_logs );
		$ctx = json_decode( $log['context'], true );

		$this->assertEquals( 50, $ctx['completed'] );
		$this->assertEquals( 100, $ctx['total'] );
		$this->assertEquals( 2, $ctx['errors'] );
	}
}
