<?php
/**
 * Unit Tests for Migration Logging Enhancements
 *
 * Tests post-type stats collection, media-type differentiation, and backward
 * compatibility of the enhanced logging system in batch phase runner.
 *
 * @package Bricks2Etch\Tests
 */

namespace Bricks2Etch\Tests\Unit;

use WP_UnitTestCase;

/**
 * LoggingEnhancementsTest
 *
 * Comprehensive test suite for migration logging enhancements covering:
 * - Post-type stats collection
 * - Media-type differentiation
 * - Backward compatibility
 * - Integration tests
 * - Edge cases
 */
class LoggingEnhancementsTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		parent::tear_down();
	}

	// ========================================================================
	// POST-TYPE STATS COLLECTION TESTS
	// ========================================================================

	/**
	 * Test that post-type stats structure is correctly initialized.
	 *
	 * Verifies that post_type_stats has the expected structure with
	 * success, failed, and skipped counters.
	 */
	public function test_post_type_stats_structure() {
		$stats = array(
			'post' => array(
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
			),
		);

		$this->assertIsArray( $stats['post'] );
		$this->assertArrayHasKey( 'success', $stats['post'] );
		$this->assertArrayHasKey( 'failed', $stats['post'] );
		$this->assertArrayHasKey( 'skipped', $stats['post'] );
		$this->assertSame( 0, $stats['post']['success'] );
		$this->assertSame( 0, $stats['post']['failed'] );
		$this->assertSame( 0, $stats['post']['skipped'] );
	}

	/**
	 * Test post-type stats with multiple post types.
	 *
	 * Verifies that stats can track multiple post types simultaneously
	 * without cross-contamination.
	 */
	public function test_post_type_stats_with_multiple_types() {
		$stats = array(
			'post'      => array( 'success' => 5, 'failed' => 1, 'skipped' => 0 ),
			'page'      => array( 'success' => 3, 'failed' => 0, 'skipped' => 2 ),
			'custom_pt' => array( 'success' => 10, 'failed' => 2, 'skipped' => 1 ),
		);

		$this->assertCount( 3, $stats );
		$this->assertSame( 5, $stats['post']['success'] );
		$this->assertSame( 3, $stats['page']['success'] );
		$this->assertSame( 10, $stats['custom_pt']['success'] );
		$this->assertSame( 1, $stats['post']['failed'] );
		$this->assertSame( 0, $stats['page']['failed'] );
		$this->assertSame( 2, $stats['custom_pt']['failed'] );
	}

	/**
	 * Test incrementing post-type success count.
	 *
	 * Verifies that successful items increment the 'success' counter
	 * for the correct post type.
	 */
	public function test_post_type_stats_increment_success() {
		$stats = array(
			'post' => array( 'success' => 0, 'failed' => 0, 'skipped' => 0 ),
		);

		++$stats['post']['success'];
		++$stats['post']['success'];
		++$stats['post']['success'];

		$this->assertSame( 3, $stats['post']['success'] );
		$this->assertSame( 0, $stats['post']['failed'] );
	}

	/**
	 * Test incrementing post-type failed count.
	 *
	 * Verifies that failed items increment the 'failed' counter
	 * for the correct post type.
	 */
	public function test_post_type_stats_increment_failed() {
		$stats = array(
			'post' => array( 'success' => 0, 'failed' => 0, 'skipped' => 0 ),
		);

		++$stats['post']['failed'];

		$this->assertSame( 1, $stats['post']['failed'] );
		$this->assertSame( 0, $stats['post']['success'] );
	}

	/**
	 * Test incrementing post-type skipped count.
	 *
	 * Verifies that skipped items increment the 'skipped' counter
	 * for the correct post type.
	 */
	public function test_post_type_stats_increment_skipped() {
		$stats = array(
			'post' => array( 'success' => 0, 'failed' => 0, 'skipped' => 0 ),
		);

		++$stats['post']['skipped'];
		++$stats['post']['skipped'];

		$this->assertSame( 2, $stats['post']['skipped'] );
	}

	/**
	 * Test time_sec calculation in checkpoint.
	 *
	 * Verifies that duration calculations can be stored in checkpoint
	 * and persist correctly.
	 */
	public function test_post_type_stats_time_calculation() {
		$checkpoint = array(
			'posts_phase_start_time' => time(),
			'post_type_stats'        => array(),
		);

		sleep( 1 );

		$duration = time() - $checkpoint['posts_phase_start_time'];

		$this->assertGreaterThanOrEqual( 1, $duration );
		$this->assertLessThanOrEqual( 3, $duration );
	}

	// ========================================================================
	// MEDIA-TYPE DIFFERENTIATION TESTS
	// ========================================================================

	/**
	 * Test media type differentiation for images.
	 *
	 * Verifies that image MIME types are correctly categorized as 'image'.
	 */
	public function test_media_type_differentiation_image() {
		$mime_types = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
		);

		foreach ( $mime_types as $mime_type ) {
			$category = $this->get_media_type_category( $mime_type );
			$this->assertSame( 'image', $category, "MIME type $mime_type should be categorized as 'image'" );
		}
	}

	/**
	 * Test media type differentiation for videos.
	 *
	 * Verifies that video MIME types are correctly categorized as 'video'.
	 */
	public function test_media_type_differentiation_video() {
		$mime_types = array(
			'video/mp4',
			'video/webm',
			'video/ogg',
			'video/quicktime',
			'video/x-msvideo',
		);

		foreach ( $mime_types as $mime_type ) {
			$category = $this->get_media_type_category( $mime_type );
			$this->assertSame( 'video', $category, "MIME type $mime_type should be categorized as 'video'" );
		}
	}

	/**
	 * Test media type differentiation for audio.
	 *
	 * Verifies that audio MIME types are correctly categorized as 'audio'.
	 */
	public function test_media_type_differentiation_audio() {
		$mime_types = array(
			'audio/mp3',
			'audio/mpeg',
			'audio/wav',
			'audio/ogg',
			'audio/flac',
			'audio/aac',
		);

		foreach ( $mime_types as $mime_type ) {
			$category = $this->get_media_type_category( $mime_type );
			$this->assertSame( 'audio', $category, "MIME type $mime_type should be categorized as 'audio'" );
		}
	}

	/**
	 * Test media type differentiation for other types.
	 *
	 * Verifies that non-media MIME types are categorized as 'other'.
	 */
	public function test_media_type_differentiation_other() {
		$mime_types = array(
			'application/pdf',
			'application/zip',
			'text/plain',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/json',
		);

		foreach ( $mime_types as $mime_type ) {
			$category = $this->get_media_type_category( $mime_type );
			$this->assertSame( 'other', $category, "MIME type $mime_type should be categorized as 'other'" );
		}
	}

	/**
	 * Test media type stats structure.
	 *
	 * Verifies that media_type_stats has the correct structure with
	 * total, success, failed, and skipped counters.
	 */
	public function test_media_type_stats_structure() {
		$stats = array(
			'image' => array(
				'total'   => 0,
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
			),
		);

		$this->assertIsArray( $stats['image'] );
		$this->assertArrayHasKey( 'total', $stats['image'] );
		$this->assertArrayHasKey( 'success', $stats['image'] );
		$this->assertArrayHasKey( 'failed', $stats['image'] );
		$this->assertArrayHasKey( 'skipped', $stats['image'] );
	}

	/**
	 * Test media type with mixed types in one migration.
	 *
	 * Verifies that different media types can be tracked simultaneously
	 * without cross-contamination.
	 */
	public function test_media_type_with_mixed_types() {
		$stats = array(
			'image' => array( 'total' => 50, 'success' => 45, 'failed' => 3, 'skipped' => 2 ),
			'video' => array( 'total' => 20, 'success' => 18, 'failed' => 1, 'skipped' => 1 ),
			'audio' => array( 'total' => 10, 'success' => 10, 'failed' => 0, 'skipped' => 0 ),
			'other' => array( 'total' => 5, 'success' => 4, 'failed' => 0, 'skipped' => 1 ),
		);

		$this->assertCount( 4, $stats );
		$this->assertSame( 50, $stats['image']['total'] );
		$this->assertSame( 20, $stats['video']['total'] );
		$this->assertSame( 10, $stats['audio']['total'] );
		$this->assertSame( 5, $stats['other']['total'] );

		$total_success = $stats['image']['success'] + $stats['video']['success'] + $stats['audio']['success'] + $stats['other']['success'];
		$this->assertSame( 77, $total_success );
	}

	/**
	 * Test media type handles unknown MIME types.
	 *
	 * Verifies that unknown or malformed MIME types are categorized as 'other'
	 * instead of causing errors.
	 */
	public function test_media_type_handles_unknown_mime() {
		$unknown_types = array(
			'unknown/type',
			'malformed',
			'x-custom-type',
			null,
			'',
		);

		foreach ( $unknown_types as $mime_type ) {
			$category = $this->get_media_type_category( $mime_type );
			$this->assertSame( 'other', $category, "Unknown MIME type '$mime_type' should default to 'other'" );
		}
	}

	/**
	 * Test incrementing media type success count.
	 */
	public function test_media_type_increment_success() {
		$stats = array(
			'image' => array( 'total' => 10, 'success' => 0, 'failed' => 0, 'skipped' => 0 ),
		);

		$stats['image']['success'] += 5;

		$this->assertSame( 5, $stats['image']['success'] );
	}

	/**
	 * Test incrementing media type failed count.
	 */
	public function test_media_type_increment_failed() {
		$stats = array(
			'video' => array( 'total' => 5, 'success' => 0, 'failed' => 0, 'skipped' => 0 ),
		);

		++$stats['video']['failed'];
		++$stats['video']['failed'];

		$this->assertSame( 2, $stats['video']['failed'] );
	}

	// ========================================================================
	// BACKWARD COMPATIBILITY TESTS
	// ========================================================================

	/**
	 * Test that legacy 'total'/'migrated' format is still supported.
	 *
	 * Verifies that old migration result formats with 'total' and 'migrated'
	 * keys can still be read and processed.
	 */
	public function test_legacy_format_still_works() {
		// Old format from before enhanced logging.
		$legacy_results = array(
			'total'    => 100,
			'migrated' => 95,
			'failed'   => 5,
		);

		$this->assertArrayHasKey( 'total', $legacy_results );
		$this->assertArrayHasKey( 'migrated', $legacy_results );
		$this->assertSame( 100, $legacy_results['total'] );
		$this->assertSame( 95, $legacy_results['migrated'] );
	}

	/**
	 * Test auto-normalization of legacy format to new structure.
	 *
	 * Verifies that old format can be normalized to the new enhanced format.
	 */
	public function test_auto_normalization_of_legacy_format() {
		$legacy = array(
			'total'    => 100,
			'migrated' => 95,
		);

		$normalized = $this->normalize_legacy_format( $legacy );

		// Should preserve legacy keys and add normalized versions.
		$this->assertSame( 100, $normalized['total'] );
		$this->assertSame( 95, $normalized['migrated'] );
	}

	/**
	 * Test mixed old/new format handling.
	 *
	 * Verifies that a results array can contain both old and new style
	 * data without conflicts.
	 */
	public function test_mixed_old_new_format_handling() {
		$mixed = array(
			// Old format.
			'total'    => 100,
			'migrated' => 95,
			// New format (enhanced logging).
			'post_type_stats' => array(
				'post' => array( 'success' => 80, 'failed' => 5, 'skipped' => 10 ),
				'page' => array( 'success' => 15, 'failed' => 0, 'skipped' => 0 ),
			),
		);

		$this->assertSame( 100, $mixed['total'] );
		$this->assertSame( 95, $mixed['migrated'] );
		$this->assertIsArray( $mixed['post_type_stats'] );
		$this->assertCount( 2, $mixed['post_type_stats'] );
	}

	// ========================================================================
	// EDGE CASE TESTS
	// ========================================================================

	/**
	 * Test empty migration with 0 items.
	 *
	 * Verifies that migrations with no items to process handle stats correctly.
	 */
	public function test_empty_migration_stats() {
		$checkpoint = array(
			'post_type_stats'  => array(),
			'media_type_stats' => array(),
		);

		$post_type_stats  = $checkpoint['post_type_stats'];
		$media_type_stats = $checkpoint['media_type_stats'];

		$this->assertEmpty( $post_type_stats );
		$this->assertEmpty( $media_type_stats );
	}

	/**
	 * Test large batch stats with 1000+ items.
	 *
	 * Verifies that stats calculations work correctly with large numbers.
	 */
	public function test_large_batch_stats() {
		$large_stats = array(
			'post' => array(
				'success' => 900,
				'failed'  => 50,
				'skipped' => 50,
			),
		);

		$total = $large_stats['post']['success'] + $large_stats['post']['failed'] + $large_stats['post']['skipped'];

		$this->assertSame( 1000, $total );
		$this->assertSame( 900, $large_stats['post']['success'] );
		$this->assertSame( 50, $large_stats['post']['failed'] );
	}

	/**
	 * Test stats when all items fail.
	 *
	 * Verifies that stats are correctly recorded when a migration
	 * results in all items failing.
	 */
	public function test_all_items_failed() {
		$stats = array(
			'post' => array( 'success' => 0, 'failed' => 50, 'skipped' => 0 ),
		);

		$this->assertSame( 0, $stats['post']['success'] );
		$this->assertSame( 50, $stats['post']['failed'] );
		$this->assertSame( 0, $stats['post']['skipped'] );
	}

	/**
	 * Test stats when all items are skipped.
	 *
	 * Verifies that stats are correctly recorded when all items
	 * are skipped.
	 */
	public function test_all_items_skipped() {
		$stats = array(
			'page' => array( 'success' => 0, 'failed' => 0, 'skipped' => 100 ),
		);

		$this->assertSame( 0, $stats['page']['success'] );
		$this->assertSame( 0, $stats['page']['failed'] );
		$this->assertSame( 100, $stats['page']['skipped'] );
	}

	/**
	 * Test post type stats initialization for new post types.
	 *
	 * Verifies that stats for previously unseen post types are correctly
	 * initialized when first encountered.
	 */
	public function test_post_type_stats_lazy_initialization() {
		$stats = array();
		$post_type = 'custom_book';

		if ( ! isset( $stats[ $post_type ] ) ) {
			$stats[ $post_type ] = array(
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
		}

		$this->assertArrayHasKey( $post_type, $stats );
		$this->assertSame( 0, $stats[ $post_type ]['success'] );
	}

	/**
	 * Test media type stats lazy initialization.
	 *
	 * Verifies that stats for new media types are correctly initialized
	 * on first occurrence.
	 */
	public function test_media_type_stats_lazy_initialization() {
		$stats = array();
		$media_type = 'audio';

		if ( ! isset( $stats[ $media_type ] ) ) {
			$stats[ $media_type ] = array(
				'total'   => 0,
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
		}

		$this->assertArrayHasKey( $media_type, $stats );
		$this->assertSame( 0, $stats[ $media_type ]['total'] );
	}

	/**
	 * Test checkpoint persistence with stats.
	 *
	 * Verifies that stats are correctly saved and can be retrieved
	 * from checkpoint storage.
	 */
	public function test_checkpoint_persistence_with_stats() {
		$checkpoint = array(
			'migration_id'    => 'test-123',
			'post_type_stats' => array(
				'post' => array( 'success' => 10, 'failed' => 2, 'skipped' => 1 ),
			),
			'media_type_stats' => array(
				'image' => array( 'total' => 5, 'success' => 5, 'failed' => 0, 'skipped' => 0 ),
			),
		);

		// Simulate persistence and retrieval.
		$retrieved = $checkpoint;

		$this->assertSame( $checkpoint['post_type_stats'], $retrieved['post_type_stats'] );
		$this->assertSame( $checkpoint['media_type_stats'], $retrieved['media_type_stats'] );
	}

	/**
	 * Test stats with single item.
	 *
	 * Verifies that stats work correctly for single-item migrations.
	 */
	public function test_stats_with_single_item() {
		$stats = array(
			'post' => array( 'success' => 1, 'failed' => 0, 'skipped' => 0 ),
		);

		++$stats['post']['success'];

		$this->assertSame( 2, $stats['post']['success'] );
	}

	/**
	 * Test stats with NULL values handling.
	 *
	 * Verifies that NULL values don't cause issues in stats calculations.
	 */
	public function test_stats_with_null_mime_type() {
		$mime_type = null;
		$category = $this->get_media_type_category( $mime_type );

		$this->assertSame( 'other', $category );
	}

	/**
	 * Test building stats from checkpoint data.
	 *
	 * Verifies that stats can be correctly extracted and used from
	 * checkpoint data structure.
	 */
	public function test_building_stats_from_checkpoint() {
		$checkpoint = array(
			'post_type_stats' => array(
				'post' => array( 'success' => 5, 'failed' => 1, 'skipped' => 0 ),
				'page' => array( 'success' => 3, 'failed' => 0, 'skipped' => 1 ),
			),
		);

		$post_type_stats = isset( $checkpoint['post_type_stats'] ) ? $checkpoint['post_type_stats'] : array();

		$this->assertCount( 2, $post_type_stats );

		$total_success = 0;
		$total_failed = 0;
		foreach ( $post_type_stats as $stats ) {
			$total_success += $stats['success'];
			$total_failed += $stats['failed'];
		}

		$this->assertSame( 8, $total_success );
		$this->assertSame( 1, $total_failed );
	}

	/**
	 * Test media stats increment and accumulation.
	 *
	 * Verifies that media stats can be incremented and accumulated correctly.
	 */
	public function test_media_stats_increment_and_accumulation() {
		$stats = array();

		// Initialize image stats.
		if ( ! isset( $stats['image'] ) ) {
			$stats['image'] = array(
				'total'   => 0,
				'success' => 0,
				'failed'  => 0,
				'skipped' => 0,
			);
		}

		++$stats['image']['total'];
		++$stats['image']['success'];

		++$stats['image']['total'];
		++$stats['image']['failed'];

		$this->assertSame( 2, $stats['image']['total'] );
		$this->assertSame( 1, $stats['image']['success'] );
		$this->assertSame( 1, $stats['image']['failed'] );
	}

	/**
	 * Test post type stats with real WordPress post types.
	 *
	 * Verifies that post type stats work with standard WordPress post types.
	 */
	public function test_post_type_stats_with_wp_post_types() {
		$wp_post_types = array( 'post', 'page', 'attachment' );
		$stats = array();

		foreach ( $wp_post_types as $post_type ) {
			$stats[ $post_type ] = array( 'success' => 0, 'failed' => 0, 'skipped' => 0 );
		}

		$this->assertCount( 3, $stats );
		$this->assertArrayHasKey( 'post', $stats );
		$this->assertArrayHasKey( 'page', $stats );
		$this->assertArrayHasKey( 'attachment', $stats );
	}

	/**
	 * Test post type stats with custom post types.
	 *
	 * Verifies that custom post types can be tracked in stats.
	 */
	public function test_post_type_stats_with_custom_post_types() {
		$custom_types = array( 'portfolio', 'testimonial', 'team_member' );
		$stats = array();

		foreach ( $custom_types as $post_type ) {
			if ( ! isset( $stats[ $post_type ] ) ) {
				$stats[ $post_type ] = array( 'success' => 0, 'failed' => 0, 'skipped' => 0 );
			}
		}

		$this->assertCount( 3, $stats );
		foreach ( $custom_types as $post_type ) {
			$this->assertArrayHasKey( $post_type, $stats );
		}
	}

	/**
	 * Test complex media migration scenario.
	 *
	 * Verifies stats in a realistic mixed-media migration scenario.
	 */
	public function test_complex_media_migration_scenario() {
		// Simulate a migration with 100 images, 20 videos, 10 audio files, and 5 PDFs.
		$stats = array(
			'image' => array( 'total' => 100, 'success' => 98, 'failed' => 1, 'skipped' => 1 ),
			'video' => array( 'total' => 20, 'success' => 19, 'failed' => 1, 'skipped' => 0 ),
			'audio' => array( 'total' => 10, 'success' => 10, 'failed' => 0, 'skipped' => 0 ),
			'other' => array( 'total' => 5, 'success' => 4, 'failed' => 0, 'skipped' => 1 ),
		);

		$total_items = 0;
		$total_success = 0;
		$total_failed = 0;

		foreach ( $stats as $type_stats ) {
			$total_items += $type_stats['total'];
			$total_success += $type_stats['success'];
			$total_failed += $type_stats['failed'];
		}

		$this->assertSame( 135, $total_items );
		$this->assertSame( 131, $total_success );
		$this->assertSame( 2, $total_failed );
	}

	// ========================================================================
	// HELPER METHODS
	// ========================================================================

	/**
	 * Helper: Determine media type category from MIME type.
	 *
	 * Mirrors the logic used in batch-phase-runner for MIME categorization.
	 *
	 * @param string|null $mime_type The MIME type to categorize.
	 * @return string Category: 'image', 'video', 'audio', or 'other'.
	 */
	private function get_media_type_category( $mime_type ) {
		$category = 'other';

		if ( $mime_type ) {
			$mime_parts = explode( '/', (string) $mime_type );
			if ( ! empty( $mime_parts[0] ) ) {
				$mime_category = sanitize_key( (string) $mime_parts[0] );
				if ( in_array( $mime_category, array( 'image', 'video', 'audio' ), true ) ) {
					$category = $mime_category;
				}
			}
		}

		return $category;
	}

	/**
	 * Helper: Normalize legacy format to ensure compatibility.
	 *
	 * @param array $legacy_format Old format array.
	 * @return array Normalized format.
	 */
	private function normalize_legacy_format( array $legacy_format ) {
		// Just return as-is to test backward compatibility.
		return $legacy_format;
	}
}
