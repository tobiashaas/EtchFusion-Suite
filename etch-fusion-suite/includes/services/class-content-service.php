<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Models\EFS_Migration_Config;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;

class EFS_Content_Service {

	/** @var EFS_Content_Parser */
	private $content_parser;

	/** @var EFS_Gutenberg_Generator */
	private $gutenberg_generator;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var array<string, string[]> */
	private array $target_slug_cache = array();

	public function __construct(
		EFS_Content_Parser $content_parser,
		EFS_Gutenberg_Generator $gutenberg_generator,
		EFS_Error_Handler $error_handler
	) {
		$this->content_parser      = $content_parser;
		$this->gutenberg_generator = $gutenberg_generator;
		$this->error_handler       = $error_handler;
	}

	/**
	 * Analyze content statistics.
	 *
	 * @return array
	 */
	public function analyze_content() {
		$bricks_posts    = $this->content_parser->get_bricks_posts();
		$gutenberg_posts = $this->content_parser->get_gutenberg_posts();
		$media           = $this->content_parser->get_media();

		return array(
			'bricks_posts'    => is_array( $bricks_posts ) ? count( $bricks_posts ) : 0,
			'gutenberg_posts' => is_array( $gutenberg_posts ) ? count( $gutenberg_posts ) : 0,
			'media'           => is_array( $media ) ? count( $media ) : 0,
			'total'           => ( ( is_array( $bricks_posts ) ? count( $bricks_posts ) : 0 )
				+ ( is_array( $gutenberg_posts ) ? count( $gutenberg_posts ) : 0 )
				+ ( is_array( $media ) ? count( $media ) : 0 ) ),
		);
	}

	/**
	 * @param string                $target_url
	 * @param string                $jwt_token
	 * @param EFS_API_Client        $api_client
	 * @param EFS_Migration_Config|null $config
	 * @param array                 $posts Explicit posts to migrate (optional).
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_posts( $target_url, $jwt_token, EFS_API_Client $api_client, EFS_Migration_Config $config = null, array $posts = array() ) {
		$posts_to_migrate = $this->prepare_posts_for_migration( $config, $posts );

		if ( empty( $posts_to_migrate ) ) {
			return array(
				'migrated_posts' => 0,
				'message'        => __( 'No posts found for migration.', 'etch-fusion-suite' ),
			);
		}

		$batch_size = max( 1, $config ? $config->get_batch_size() : 10 );
		$batches    = array_chunk( $posts_to_migrate, $batch_size );

		$summary = array(
			'total'    => count( $posts_to_migrate ),
			'migrated' => 0,
			'failed'   => 0,
			'skipped'  => 0,
		);

		foreach ( $batches as $batch ) {
			foreach ( $batch as $post ) {
				$result = $this->convert_bricks_to_gutenberg( $post->ID, $api_client, $target_url, $jwt_token, $config );

				if ( is_wp_error( $result ) ) {
					++$summary['failed'];
					continue;
				}

				if ( isset( $result['skipped'] ) && $result['skipped'] ) {
					++$summary['skipped'];
					continue;
				}

				++$summary['migrated'];
			}
		}

		$summary['message'] = __( 'Posts migrated successfully.', 'etch-fusion-suite' );

		return $summary;
	}

	/**
	 * Prepare posts list for migration respecting config selection.
	 *
	 * @param EFS_Migration_Config|null $config
	 * @param array $posts
	 *
	 * @return array
	 */
	private function prepare_posts_for_migration( EFS_Migration_Config $config = null, array $posts = array() ): array {
		if ( ! empty( $posts ) ) {
			$post_list = $posts;
		} else {
			$bricks_posts    = $this->content_parser->get_bricks_posts();
			$gutenberg_posts = $this->content_parser->get_gutenberg_posts();

			$post_list = array_merge(
				is_array( $bricks_posts ) ? $bricks_posts : array(),
				is_array( $gutenberg_posts ) ? $gutenberg_posts : array()
			);
		}

		$post_list = array_filter(
			$post_list,
			function ( $post ) {
				return $post instanceof \WP_Post;
			}
		);

		$selected_post_types = array();
		if ( $config ) {
			$selected_post_types = $config->get_selected_post_types();
		}

		if ( ! empty( $selected_post_types ) ) {
			$selected_post_types = array_map( 'sanitize_key', $selected_post_types );
			$post_list            = array_filter(
				$post_list,
				function ( $post ) use ( $selected_post_types ) {
					return in_array( $post->post_type, $selected_post_types, true );
				}
			);
		}

		usort(
			$post_list,
			function ( \WP_Post $a, \WP_Post $b ) {
				return $a->ID <=> $b->ID;
			}
		);

		return array_values( $post_list );
	}

	/**
	 * Public helper to expose filtered post list for job runners.
	 */
	public function get_posts_for_migration( EFS_Migration_Config $config ): array {
		return $this->prepare_posts_for_migration( $config );
	}

	/**
	 * @param int             $post_id
	 * @param EFS_API_Client   $api_client
	 * @param string|null      $target_url
	 * @param string|null      $jwt_token
	 * @param EFS_Migration_Config|null $config
	 *
	 * @return array|\WP_Error
	 */
	public function convert_bricks_to_gutenberg( $post_id, EFS_API_Client $api_client, $target_url = null, $jwt_token = null, EFS_Migration_Config $config = null ) {
		$bricks_content = $this->content_parser->parse_bricks_content( $post_id );

		if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
			$post    = get_post( $post_id );
			$content = ! empty( $post->post_content )
				? $post->post_content
				: '<!-- wp:paragraph --><p>Empty content</p><!-- /wp:paragraph -->';

			return $this->send_post_to_target( $post, $content, $api_client, $target_url, $jwt_token, $config );
		}

		$etch_content = $this->gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );

		if ( empty( $etch_content ) ) {
			$etch_content = '<!-- wp:paragraph --><p>Content migrated from Bricks (conversion pending)</p><!-- /wp:paragraph -->';
			$this->error_handler->log_error(
				'W002',
				array(
					'post_id' => $post_id,
					'action'  => 'Bricks content found but conversion produced empty output - using placeholder',
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %d is the numeric post ID. */
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post with ID %d not found.', 'etch-fusion-suite' ), $post_id ) );
		}

		return $this->send_post_to_target( $post, $etch_content, $api_client, $target_url, $jwt_token, $config );
	}

	/**
	 * @return array
	 */
	public function get_bricks_posts() {
		return $this->content_parser->get_bricks_posts();
	}

	/**
	 * @return array
	 */
	public function get_gutenberg_posts() {
		return $this->content_parser->get_gutenberg_posts();
	}

	/**
	 * @return array
	 */
	public function get_all_content() {
		return array(
			'bricks_posts'    => $this->get_bricks_posts(),
			'gutenberg_posts' => $this->get_gutenberg_posts(),
			'media'           => $this->content_parser->get_media(),
		);
	}

	/**
	 * @param \WP_Post       $post
	 * @param EFS_API_Client  $api_client
	 * @param string          $target_url
	 * @param string          $jwt_token
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_existing_content( $post, EFS_API_Client $api_client, $target_url, $jwt_token, EFS_Migration_Config $config = null ) {
		$content = ! empty( $post->post_content )
			? $post->post_content
			: '<!-- wp:paragraph --><p>Empty content</p><!-- /wp:paragraph -->';

		return $this->send_post_to_target( $post, $content, $api_client, $target_url, $jwt_token, $config );
	}

	/**
	 * @return array|\WP_Error
	 */
	public function validate_bricks_installation() {
		if ( method_exists( $this->content_parser, 'validate_bricks_installation' ) ) {
			return $this->content_parser->validate_bricks_installation();
		}

		return array(
			'valid'   => true,
			'message' => __( 'Validation not implemented for this version.', 'etch-fusion-suite' ),
		);
	}

	/**
	 * @param \WP_Post              $post
	 * @param string                $content
	 * @param EFS_API_Client        $api_client
	 * @param string|null           $target_url
	 * @param string|null           $jwt_token
	 * @param EFS_Migration_Config|null $config
	 *
	 * @return array|\WP_Error
	 */
	private function send_post_to_target( $post, $content, EFS_API_Client $api_client, $target_url = null, $jwt_token = null, EFS_Migration_Config $config = null, $post_slug = '' ) {
		if ( ! $target_url || ! $jwt_token ) {
			return array(
				'post_id' => $post->ID,
				'content' => $content,
			);
		}

		if ( empty( $post_slug ) ) {
			$post_slug = $this->resolve_unique_slug( $post, $api_client, $target_url, $jwt_token );
		}

		$post_type = $post->post_type;
		if ( $config ) {
			$mappings = $config->get_post_type_mappings();
			if ( isset( $mappings[ $post_type ] ) ) {
				$post_type = $mappings[ $post_type ];
			}
		}

		$post_data = array(
			'title'   => $post->post_title,
			'content' => $content,
			'excerpt' => $post->post_excerpt,
			'status'  => $post->post_status,
			'post'    => array(
				'ID'          => $post->ID,
				'post_title'  => $post->post_title,
				'post_name'   => $post_slug,
				'post_type'   => $post_type,
				'post_date'   => $post->post_date,
				'post_status' => $post->post_status,
			),
			'meta'    => array(
				'_b2e_migrated_from_bricks' => true,
				'_b2e_original_post_id'     => $post->ID,
				'_b2e_migration_date'       => current_time( 'mysql' ),
			),
		);

		$response = $api_client->send_post( $target_url, $jwt_token, (object) $post_data['post'], $content );

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_error(
				'E107',
				array(
					'post_id' => $post->ID,
					'error'   => $response->get_error_message(),
					'action'  => 'Failed to send post to target site',
				)
			);

			return $response;
		}

		return array(
			'post_id'   => $post->ID,
			'target_id' => $response['post_id'] ?? null,
			'status'    => 'migrated',
		);
	}

	/**
	 * Resolve a unique slug for the target site.
	 */
	private function resolve_unique_slug( \WP_Post $post, EFS_API_Client $api_client, $target_url, $jwt_token ): string {
		$cache_key = $this->get_target_cache_key( $target_url, $jwt_token );

		if ( ! isset( $this->target_slug_cache[ $cache_key ] ) ) {
			$this->target_slug_cache[ $cache_key ] = $this->fetch_existing_slugs( $api_client, $target_url, $jwt_token );
		}

		$existing_slugs = $this->target_slug_cache[ $cache_key ];
		$base_slug      = ! empty( $post->post_name ) ? sanitize_title_with_dashes( $post->post_name ) : sanitize_title_with_dashes( $post->post_title );
		if ( ! $base_slug ) {
			$base_slug = 'post-' . $post->ID;
		}

		$slug    = $base_slug;
		$counter = 1;
		while ( in_array( $slug, $existing_slugs, true ) ) {
			++$counter;
			$slug = sprintf( '%s-%d', $base_slug, $counter );
		}

		$existing_slugs[] = $slug;
		$this->target_slug_cache[ $cache_key ] = $existing_slugs;

		return $slug;
	}

	/**
	 * Fetch slugs already present on the target site.
	 */
	private function fetch_existing_slugs( EFS_API_Client $api_client, $target_url, $jwt_token ): array {
		$slugs = array();

		if ( ! $target_url || ! $jwt_token ) {
			return $slugs;
		}

		$response = $api_client->get_posts_list( $target_url, $jwt_token );

		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_warning(
				'W103',
				array(
					'target_url' => $target_url,
					'error'      => $response->get_error_message(),
					'action'     => 'Failed to fetch existing post slugs for collision detection',
				)
			);

			return $slugs;
		}

		foreach ( (array) $response as $item ) {
			if ( is_array( $item ) && ! empty( $item['post_name'] ) ) {
				$slugs[] = $item['post_name'];
			} elseif ( is_object( $item ) && property_exists( $item, 'post_name' ) ) {
				$slugs[] = $item->post_name;
			}
		}

		return array_unique( $slugs );
	}

	/**
	 * Build a cache key for slug lookups.
	 */
	private function get_target_cache_key( $target_url, $jwt_token ): string {
		return md5( rtrim( $target_url, '/' ) . '|' . (string) $jwt_token );
	}
}
