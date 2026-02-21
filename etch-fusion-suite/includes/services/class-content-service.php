<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;

class EFS_Content_Service {

	/** @var EFS_Content_Parser */
	private $content_parser;

	/** @var EFS_Gutenberg_Generator */
	private $gutenberg_generator;

	/** @var EFS_Error_Handler */
	private $error_handler;

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
	 * @param array|null $post_types Optional. Post types to include in counts (passed to parser). When null/empty, default post types are used.
	 * @return array
	 */
	public function analyze_content( $post_types = null ) {
		$bricks_posts    = $this->content_parser->get_bricks_posts( $post_types );
		$gutenberg_posts = $this->content_parser->get_gutenberg_posts( $post_types );
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
	 * @param string          $target_url
	 * @param string          $jwt_token
	 * @param EFS_API_Client  $api_client
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_posts( $target_url, $jwt_token, EFS_API_Client $api_client, $post_type_mappings = array(), $selected_post_types = array() ) {
		$bricks_posts    = $this->content_parser->get_bricks_posts( $selected_post_types );
		$gutenberg_posts = $this->content_parser->get_gutenberg_posts( $selected_post_types );
		$media           = $this->content_parser->get_media();

		$all_posts = array_merge(
			is_array( $bricks_posts ) ? $bricks_posts : array(),
			is_array( $gutenberg_posts ) ? $gutenberg_posts : array()
		);

		if ( empty( $all_posts ) ) {
			return array(
				'migrated_posts' => 0,
				'message'        => __( 'No posts found for migration.', 'etch-fusion-suite' ),
			);
		}

		$batch_size = 10;
		$batches    = array_chunk( array_values( $all_posts ), $batch_size );
		$summary    = array(
			'total'    => count( $all_posts ),
			'migrated' => 0,
			'failed'   => 0,
			'skipped'  => 0,
		);

		foreach ( $batches as $batch_index => $batch ) {
			foreach ( $batch as $post ) {
				$result = $this->convert_bricks_to_gutenberg( $post->ID, $api_client, $target_url, $jwt_token, $post_type_mappings );

				if ( is_wp_error( $result ) ) {
					++$summary['failed'];
					continue;
				}

				++$summary['migrated'];
			}
		}

		$summary['message'] = __( 'Posts migrated successfully.', 'etch-fusion-suite' );

		return $summary;
	}

	/**
	 * @param int             $post_id
	 * @param EFS_API_Client  $api_client
	 * @param string|null     $target_url
	 * @param string|null     $jwt_token
	 * @param array           $post_type_mappings Source post type => target post type (from wizard).
	 *
	 * @return array|\WP_Error
	 */
	public function convert_bricks_to_gutenberg( $post_id, EFS_API_Client $api_client, $target_url = null, $jwt_token = null, $post_type_mappings = array() ) {
		$bricks_content = $this->content_parser->parse_bricks_content( $post_id );

		if ( ! $bricks_content || ! isset( $bricks_content['elements'] ) ) {
			$post    = get_post( $post_id );
			$content = ! empty( $post->post_content )
				? $post->post_content
				: '<!-- wp:paragraph --><p>Empty content</p><!-- /wp:paragraph -->';

			return $this->send_post_to_target( $post, $content, $api_client, $target_url, $jwt_token, $post_type_mappings );
		}

		$etch_content = $this->gutenberg_generator->generate_gutenberg_blocks( $bricks_content['elements'] );

		if ( empty( $etch_content ) ) {
			$etch_content = '<!-- wp:paragraph --><p>Content migrated from Bricks (conversion pending)</p><!-- /wp:paragraph -->';
			$this->error_handler->log_info(
				'Bricks content found but conversion produced empty output — placeholder inserted',
				array(
					'post_id' => $post_id,
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %d is the numeric post ID. */
			return new \WP_Error( 'post_not_found', sprintf( __( 'Post with ID %d not found.', 'etch-fusion-suite' ), $post_id ) );
		}

		return $this->send_post_to_target( $post, $etch_content, $api_client, $target_url, $jwt_token, $post_type_mappings );
	}

	/**
	 * @param array|null $post_types Optional. Post types to query (passed to parser).
	 * @return array
	 */
	public function get_bricks_posts( $post_types = null ) {
		return $this->content_parser->get_bricks_posts( $post_types );
	}

	/**
	 * @param array|null $post_types Optional. Post types to query (passed to parser).
	 * @return array
	 */
	public function get_gutenberg_posts( $post_types = null ) {
		return $this->content_parser->get_gutenberg_posts( $post_types );
	}

	/**
	 * @param array|null $post_types Optional. Post types to include (passed to parser).
	 * @return array
	 */
	public function get_all_content( $post_types = null ) {
		return array(
			'bricks_posts'    => $this->get_bricks_posts( $post_types ),
			'gutenberg_posts' => $this->get_gutenberg_posts( $post_types ),
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
	public function migrate_existing_content( $post, EFS_API_Client $api_client, $target_url, $jwt_token ) {
		$content = ! empty( $post->post_content )
			? $post->post_content
			: '<!-- wp:paragraph --><p>Empty content</p><!-- /wp:paragraph -->';

		return $this->send_post_to_target( $post, $content, $api_client, $target_url, $jwt_token );
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
	 * @param \WP_Post       $post
	 * @param string          $content
	 * @param EFS_API_Client  $api_client
	 * @param string|null     $target_url
	 * @param string|null     $jwt_token
	 * @param array           $post_type_mappings Source => target post type (from wizard).
	 *
	 * @return array|\WP_Error
	 */
	private function send_post_to_target( $post, $content, EFS_API_Client $api_client, $target_url = null, $jwt_token = null, $post_type_mappings = array() ) {
		if ( ! $target_url || ! $jwt_token ) {
			return array(
				'post_id' => $post->ID,
				'content' => $content,
			);
		}

		if ( ! isset( $post_type_mappings[ $post->post_type ] ) || '' === $post_type_mappings[ $post->post_type ] ) {
			$this->error_handler->log_error(
				'E108',
				array(
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
					'action'    => 'Missing post type mapping - migration cannot proceed',
				)
			);
			/* translators: %s is the post type slug */
			return new \WP_Error( 'missing_post_type_mapping', sprintf( __( 'No mapping found for post type: %s', 'etch-fusion-suite' ), $post->post_type ) );
		}

		$target_post_type = $post_type_mappings[ $post->post_type ];

		$loop_presets = $this->extract_loop_presets_from_content( (string) $content );
		$response     = $api_client->send_post( $target_url, $jwt_token, $post, $content, $target_post_type, $loop_presets );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'post_id'   => $post->ID,
			'target_id' => $response['post_id'] ?? null,
			'status'    => 'migrated',
		);
	}

	/**
	 * Extract loop presets referenced by etch/loop blocks in content.
	 *
	 * @param string $content Block content.
	 * @return array<string, array<string, mixed>>
	 */
	private function extract_loop_presets_from_content( $content ) {
		$presets = array();
		if ( '' === trim( $content ) ) {
			return $presets;
		}

		$loops = get_option( 'etch_loops', array() );
		$loops = is_array( $loops ) ? $loops : array();
		if ( empty( $loops ) ) {
			return $presets;
		}

		if ( ! preg_match_all( '/<!--\s+wp:etch\/loop\s+(\{.*?\})\s+-->/s', $content, $matches ) ) {
			return $presets;
		}

		$json_chunks = isset( $matches[1] ) && is_array( $matches[1] ) ? $matches[1] : array();
		foreach ( $json_chunks as $json_chunk ) {
			$attrs = json_decode( (string) $json_chunk, true );
			if ( ! is_array( $attrs ) || empty( $attrs['loopId'] ) ) {
				continue;
			}

			$loop_id = sanitize_key( (string) $attrs['loopId'] );
			if ( '' === $loop_id || ! isset( $loops[ $loop_id ] ) || ! is_array( $loops[ $loop_id ] ) ) {
				continue;
			}

			$presets[ $loop_id ] = $loops[ $loop_id ];
		}

		return $presets;
	}
}
