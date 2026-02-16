<?php
use Bricks2Etch\Models\EFS_Migration_Config;

class TestMigrationConfig extends \PHPUnit\Framework\TestCase {
	public function test_default_config_is_valid() {
		$config = EFS_Migration_Config::get_default();
		$this->assertSame( 50, $config->get_batch_size() );
		$result = $config->validate();
		$this->assertTrue( $result['valid'] );
	}

	public function test_serialization_round_trip() {
		$config = new EFS_Migration_Config(
			array( 'post' => 'blog' ),
			array( 'post' => 'blog-copy' ),
			false,
			25
		);

		$serialized = $config->to_array();
		$restored   = EFS_Migration_Config::from_array( $serialized );

		$this->assertSame( $config->get_selected_post_types(), $restored->get_selected_post_types() );
		$this->assertSame( $config->get_post_type_mappings(), $restored->get_post_type_mappings() );
		$this->assertSame( $config->should_include_media(), $restored->should_include_media() );
		$this->assertSame( $config->get_batch_size(), $restored->get_batch_size() );
	}

	public function test_validation_fails_for_missing_post_types() {
		$config = new EFS_Migration_Config( array( 'invalid-post-type' ), array(), true, 10 );
		$result = $config->validate();

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}
}
