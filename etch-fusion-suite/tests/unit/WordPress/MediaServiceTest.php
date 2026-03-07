<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Media_Migrator;
use Bricks2Etch\Services\EFS_Media_Service;
use WP_UnitTestCase;

class MediaServiceTest extends WP_UnitTestCase {
	/** @var array<int> */
	private $attachment_ids = array();

	/** @var array<string> */
	private $uploaded_files = array();

	protected function tearDown(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( $this->uploaded_files as $uploaded_file ) {
			if ( is_string( $uploaded_file ) && '' !== $uploaded_file && file_exists( $uploaded_file ) ) {
				unlink( $uploaded_file );
			}
		}

		delete_option( 'efs_media_mappings' );
		delete_option( 'b2e_media_mappings' );

		parent::tearDown();
	}

	public function test_get_transferable_media_ids_excludes_existing_mappings_and_missing_local_files(): void {
		$available_attachment_id = $this->create_attachment( 'media-service-available.txt', 'available' );
		$mapped_attachment_id    = $this->create_attachment( 'media-service-mapped.txt', 'mapped' );
		$missing_attachment_id   = $this->create_attachment( 'media-service-missing.txt', 'missing', true );

		$media_migrator = $this->getMockBuilder( EFS_Media_Migrator::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_media_ids', 'get_media_mapping' ) )
			->getMock();

		$media_migrator->expects( $this->exactly( 2 ) )
			->method( 'get_media_ids' )
			->with( array( 'bricks_template' ) )
			->willReturn( array( $available_attachment_id, $mapped_attachment_id, $missing_attachment_id ) );

		$media_migrator->method( 'get_media_mapping' )
			->willReturnCallback( static function ( int $media_id ) use ( $mapped_attachment_id ) {
				return $mapped_attachment_id === $media_id ? 9002 : null;
			} );

		$service = new EFS_Media_Service( $media_migrator, new EFS_Error_Handler() );

		$this->assertSame( array( $available_attachment_id ), $service->get_transferable_media_ids( array( 'bricks_template' ) ) );
		$this->assertSame( 1, $service->get_transferable_media_count( array( 'bricks_template' ) ) );
	}

	private function create_attachment( string $filename, string $contents, bool $delete_file = false ): int {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertIsArray( $upload );
		$this->assertEmpty( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => $filename,
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		$this->assertIsInt( $attachment_id );
		update_attached_file( $attachment_id, $upload['file'] );

		$this->attachment_ids[] = $attachment_id;
		$this->uploaded_files[] = $upload['file'];

		if ( $delete_file ) {
			unlink( $upload['file'] );
			clearstatcache();
		}

		return $attachment_id;
	}
}
