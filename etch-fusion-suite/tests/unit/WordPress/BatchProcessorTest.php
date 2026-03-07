<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Services\EFS_Batch_Processor;
use WP_UnitTestCase;

class BatchProcessorTest extends WP_UnitTestCase {
	public function test_validate_checkpoint_allows_media_phase_with_total_media_count_only(): void {
		$validation = EFS_Batch_Processor::validate_checkpoint(
			array(
				'migrationId'       => 'media-only-migration',
				'phase'             => 'media',
				'total_count'       => 0,
				'total_media_count' => 3,
			),
			'media-only-migration'
		);

		$this->assertNull( $validation );
	}

	public function test_validate_checkpoint_keeps_posts_phase_total_count_requirement(): void {
		$validation = EFS_Batch_Processor::validate_checkpoint(
			array(
				'migrationId'       => 'posts-migration',
				'phase'             => 'posts',
				'total_count'       => 0,
				'total_media_count' => 3,
			),
			'posts-migration'
		);

		$this->assertInstanceOf( \WP_Error::class, $validation );
		$this->assertSame( 'invalid_checkpoint_count', $validation->get_error_code() );
	}
}
