<?php
use Bricks2Etch\Services\EFS_Content_Service;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Models\EFS_Migration_Config;
use Bricks2Etch\Api\EFS_API_Client;

class TestSelectiveMigration extends \PHPUnit\Framework\TestCase {
	/** @var EFS_Content_Service */
	private $content_service;

	/** @var \PHPUnit\Framework\MockObject\MockObject|EFS_Content_Parser */
	private $content_parser;

	protected function setUp(): void {
		$this->content_parser = $this->getMockBuilder( EFS_Content_Parser::class )
			->disableOriginalConstructor()
			->getMock();

		$generator = $this->getMockBuilder( EFS_Gutenberg_Generator::class )
			->disableOriginalConstructor()
			->getMock();

		$this->content_service = new EFS_Content_Service(
			$this->content_parser,
			$generator,
			new EFS_Error_Handler()
		);
	}

	public function test_selected_post_types_filter_posts(): void {
		$posts = array(
			(object) array( 'ID' => 1, 'post_type' => 'post', 'post_title' => 'One' ),
			(object) array( 'ID' => 2, 'post_type' => 'custom_type', 'post_title' => 'Two' ),
			(object) array( 'ID' => 3, 'post_type' => 'another_type', 'post_title' => 'Three' ),
		);

		$this->content_parser->method( 'get_bricks_posts' )->willReturn( $posts );
		$this->content_parser->method( 'get_gutenberg_posts' )->willReturn( array() );

		$config = new EFS_Migration_Config(
			array( 'post', 'custom_type' ),
			array(),
			true,
			10
		);

		$filtered = $this->content_service->get_posts_for_migration( $config );
		$this->assertCount( 2, $filtered );
		$post_types = array_map( static fn( $item ) => $item->post_type, $filtered );
		$this->assertSame( array( 'post', 'custom_type' ), array_values( array_unique( $post_types ) ) );
	}

	public function test_post_type_mapping_applies_to_payload(): void {
		$api_client = new RecordingApiClient();

		$config = new EFS_Migration_Config(
			array( 'bricks_portfolio' ),
			array( 'bricks_portfolio' => 'etch_portfolio' ),
			true,
			10
		);

		$post = (object) array(
			'ID'          => 42,
			'post_title'  => 'Mapped Post',
			'post_excerpt'=> 'Excerpt',
			'post_status' => 'publish',
			'post_name'   => 'mapped-post',
			'post_type'   => 'bricks_portfolio',
			'post_content'=> 'Body',
		);

		$method = new ReflectionMethod( EFS_Content_Service::class, 'send_post_to_target' );
		$method->setAccessible( true );
		$method->invoke(
			$this->content_service,
			$post,
			'<!-- content -->',
			$api_client,
			'https://example.test',
			'jwt-token',
			$config,
			'mapped-post'
		);

		$this->assertSame( 'etch_portfolio', $api_client->last_sent['post']['post_type'] );
	}

	public function test_unique_slug_generation_appends_suffix_on_collision(): void {
		$api_client = new RecordingApiClient( array( 'collision', 'collision-2' ) );

		$post = (object) array(
			'ID'        => 77,
			'post_title'=> 'Collision',
			'post_name' => 'collision',
		);

		$method = new ReflectionMethod( EFS_Content_Service::class, 'resolve_unique_slug' );
		$method->setAccessible( true );

		$slug = $method->invoke(
			$this->content_service,
			$post,
			$api_client,
			'https://example.test',
			'jwt-token'
		);

		$this->assertSame( 'collision-3', $slug );
	}
}

class RecordingApiClient extends EFS_API_Client {
	/** @var array<string> */
	private array $existing_slugs;

	/** @var array */
	public array $last_sent = array();

	/**
	 * @param array<string> $existing_slugs
	 */
	public function __construct( array $existing_slugs = array() ) {
		parent::__construct( new EFS_Error_Handler() );
		$this->existing_slugs = $existing_slugs;
	}

	public function send_post( $url, $jwt_token, $post, $etch_content = null ) {
		$this->last_sent = array(
			'url'     => $url,
			'jwt'     => $jwt_token,
			'post'    => (array) $post,
			'content' => $etch_content,
		);

		return array( 'post_id' => 999 );
	}

	public function get_posts_list( $url, $jwt_token ) {
		return array_map(
			function ( $slug ) {
				return array( 'post_name' => $slug );
			},
			$this->existing_slugs
		);
	}
}
