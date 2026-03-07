<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Media_Migrator;
use WP_Error;
use WP_UnitTestCase;

class MediaMigratorTest extends WP_UnitTestCase {
	/** @var int[] */
	private $attachment_ids = array();

	protected function tearDown(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		delete_option( 'efs_media_mappings' );
		delete_option( 'b2e_media_mappings' );

		parent::tearDown();
	}

	public function test_migrate_media_by_id_returns_wp_error_when_target_upload_fails(): void {
		$upload = wp_upload_bits( 'media-migrator-test.txt', null, 'target upload failure' );
		$this->assertIsArray( $upload );
		$this->assertEmpty( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Media Migrator Test',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		$this->assertIsInt( $attachment_id );
		update_attached_file( $attachment_id, $upload['file'] );
		$this->attachment_ids[] = $attachment_id;

		$api_client = $this->getMockBuilder( EFS_API_Client::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'send_media_data' ) )
			->getMock();

		$api_client->expects( $this->once() )
			->method( 'send_media_data' )
			->willReturn( new WP_Error( 'api_error', 'Target upload failed.' ) );

		$migrator = new EFS_Media_Migrator( new EFS_Error_Handler(), $api_client );
		$result   = $migrator->migrate_media_by_id( $attachment_id, 'https://target.test', 'token' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_error', $result->get_error_code() );
		$this->assertSame( array(), get_option( 'efs_media_mappings', array() ) );
	}
}
