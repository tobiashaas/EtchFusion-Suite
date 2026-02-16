<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Core\EFS_Plugin_Detector;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning,WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Discovery_Service {

	/**
	 * Default sample size for dynamic-data analysis.
	 */
	private const DEFAULT_SAMPLE_SIZE = 25;

	/**
	 * Maximum allowed sample size for on-demand analysis.
	 */
	private const MAX_SAMPLE_SIZE = 100;

	/**
	 * @var EFS_Plugin_Detector
	 */
	private $plugin_detector;

	/**
	 * @var EFS_Dynamic_Data_Converter
	 */
	private $dynamic_data_converter;

	/**
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	public function __construct(
		EFS_Plugin_Detector $plugin_detector,
		EFS_Dynamic_Data_Converter $dynamic_data_converter,
		EFS_Error_Handler $error_handler
	) {
		$this->plugin_detector        = $plugin_detector;
		$this->dynamic_data_converter = $dynamic_data_converter;
		$this->error_handler          = $error_handler;
	}

	/**
	 * Discover public post types and counts on the current site.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function discover_post_types(): array {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$results = array();

		foreach ( $post_types as $slug => $post_type ) {
			$supports = get_all_post_type_supports( $slug );
			$results[] = array(
				'slug'            => $slug,
				'label'           => isset( $post_type->labels->name ) ? (string) $post_type->labels->name : (string) $post_type->label,
				'count'           => $this->count_posts_for_type( $slug ),
				'supports'        => array_values( array_keys( is_array( $supports ) ? $supports : array() ) ),
				'has_bricks_data' => $this->has_bricks_data( $slug ),
			);
		}

		usort(
			$results,
			function ( array $a, array $b ): int {
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);

		return $results;
	}

	/**
	 * Discover custom fields grouped by provider.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	public function discover_custom_fields(): array {
		$acf_fields       = $this->discover_acf_fields();
		$metabox_fields   = $this->discover_metabox_fields();
		$jetengine_fields = $this->discover_jetengine_fields();

		return array(
			'acf'       => array(
				'active' => $this->plugin_detector->is_acf_active(),
				'total'  => count( $acf_fields ),
				'fields' => $acf_fields,
			),
			'metabox'   => array(
				'active' => $this->plugin_detector->is_metabox_active(),
				'total'  => count( $metabox_fields ),
				'fields' => $metabox_fields,
			),
			'jetengine' => array(
				'active' => $this->plugin_detector->is_jetengine_active(),
				'total'  => count( $jetengine_fields ),
				'fields' => $jetengine_fields,
			),
		);
	}

	/**
	 * Discover public taxonomies and associated post types.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function discover_taxonomies(): array {
		$taxonomies = get_taxonomies(
			array(
				'public' => true,
			),
			'objects'
		);

		$results = array();

		foreach ( $taxonomies as $taxonomy ) {
			$results[] = array(
				'slug'       => $taxonomy->name,
				'label'      => isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : (string) $taxonomy->label,
				'post_types' => isset( $taxonomy->object_type ) && is_array( $taxonomy->object_type ) ? array_values( $taxonomy->object_type ) : array(),
			);
		}

		usort(
			$results,
			function ( array $a, array $b ): int {
				return strnatcasecmp( $a['label'], $b['label'] );
			}
		);

		return $results;
	}

	/**
	 * Discover media library stats.
	 *
	 * @return array<string,mixed>
	 */
	public function discover_media_stats(): array {
		$attachment_ids = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$attachment_ids = is_array( $attachment_ids ) ? array_map( 'absint', $attachment_ids ) : array();

		$by_type = array(
			'image'    => 0,
			'video'    => 0,
			'audio'    => 0,
			'document' => 0,
			'other'    => 0,
		);

		$total_size = 0;
		foreach ( $attachment_ids as $attachment_id ) {
			$mime_type = (string) get_post_mime_type( $attachment_id );
			$type_key  = $this->map_mime_type_to_bucket( $mime_type );

			if ( ! isset( $by_type[ $type_key ] ) ) {
				$type_key = 'other';
			}

			++$by_type[ $type_key ];
			$total_size += $this->resolve_attachment_size( $attachment_id );
		}

		return array(
			'total_files'          => count( $attachment_ids ),
			'total_size'           => $total_size,
			'by_type'              => $by_type,
			'max_upload_size'      => wp_max_upload_size(),
			'supported_mime_types' => array_values( array_keys( get_allowed_mime_types() ) ),
		);
	}

	/**
	 * Analyze dynamic-data usage on posts.
	 *
	 * @param array<int,string> $post_types
	 * @param int               $sample_size
	 * @param bool              $full_scan
	 * @return array<string,mixed>
	 */
	public function analyze_dynamic_data( array $post_types = array(), int $sample_size = self::DEFAULT_SAMPLE_SIZE, bool $full_scan = false ): array {
		$post_types = $this->normalize_post_types( $post_types );
		if ( empty( $post_types ) ) {
			return $this->empty_dynamic_data_response( $sample_size, $full_scan );
		}

		$sample_size = $this->normalize_sample_size( $sample_size );
		$providers   = array(
			'acf_tags'       => $this->build_provider_summary(),
			'metabox_tags'   => $this->build_provider_summary(),
			'jetengine_tags' => $this->build_provider_summary(),
			'other_tags'     => $this->build_provider_summary(),
		);

		$convertibility = array(
			'green'  => 0,
			'yellow' => 0,
			'red'    => 0,
		);

		$analyzed_posts     = 0;
		$unconvertible_list = array();
		$deferred_list      = array();

		foreach ( $post_types as $post_type ) {
			$post_ids = $this->get_post_ids_for_analysis( $post_type, $sample_size, $full_scan );
			$analyzed_posts += count( $post_ids );

			foreach ( $post_ids as $post_id ) {
				$content = $this->get_post_content_for_analysis( $post_id );
				$tags    = $this->dynamic_data_converter->extract_dynamic_tags( $content );

				foreach ( $tags as $tag ) {
					$classification = $this->dynamic_data_converter->classify_dynamic_tag( $tag );
					$provider_key   = $this->resolve_provider_key( $tag );

					if ( ! isset( $providers[ $provider_key ] ) ) {
						$provider_key = 'other_tags';
					}

					++$providers[ $provider_key ]['total'];

					if ( 'green' === $classification ) {
						++$providers[ $provider_key ]['convertible'];
						++$convertibility['green'];
						continue;
					}

					if ( 'yellow' === $classification ) {
						++$providers[ $provider_key ]['deferred'];
						++$convertibility['yellow'];
						$deferred_list[] = $tag;
						continue;
					}

					++$providers[ $provider_key ]['unconvertible'];
					++$convertibility['red'];
					$unconvertible_list[] = $tag;
				}
			}
		}

		foreach ( $providers as $key => $provider_data ) {
			$providers[ $key ]['sample_size'] = $full_scan ? $analyzed_posts : min( $sample_size, $analyzed_posts );
		}

		return array(
			'acf_tags'          => $providers['acf_tags'],
			'metabox_tags'      => $providers['metabox_tags'],
			'jetengine_tags'    => $providers['jetengine_tags'],
			'other_tags'        => $providers['other_tags'],
			'convertibility'    => $convertibility,
			'unconvertible_list' => array_values( array_unique( $unconvertible_list ) ),
			'deferred_list'     => array_values( array_unique( $deferred_list ) ),
			'sample_size'       => $full_scan ? null : $sample_size,
			'analyzed_posts'    => $analyzed_posts,
			'is_full_scan'      => $full_scan,
		);
	}

	/**
	 * Build complete discovery payload.
	 *
	 * @param int  $sample_size
	 * @param bool $full_scan
	 * @return array<string,mixed>
	 */
	public function generate_discovery_response( int $sample_size = self::DEFAULT_SAMPLE_SIZE, bool $full_scan = false ): array {
		return array(
			'post_types'    => $this->discover_post_types(),
			'custom_fields' => $this->discover_custom_fields(),
			'taxonomies'    => $this->discover_taxonomies(),
			'media_stats'   => $this->discover_media_stats(),
			'dynamic_data'  => $this->analyze_dynamic_data( array(), $sample_size, $full_scan ),
			'capabilities'  => array(
				'max_upload_size'      => wp_max_upload_size(),
				'supported_mime_types' => array_values( array_keys( get_allowed_mime_types() ) ),
			),
			'generated_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * @param string $post_type
	 * @return int
	 */
	private function count_posts_for_type( string $post_type ): int {
		$count_object = wp_count_posts( $post_type );
		if ( ! is_object( $count_object ) ) {
			return 0;
		}

		$total = 0;
		foreach ( get_object_vars( $count_object ) as $status => $count ) {
			if ( in_array( $status, array( 'auto-draft', 'trash' ), true ) ) {
				continue;
			}
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * @param string $post_type
	 * @return bool
	 */
	private function has_bricks_data( string $post_type ): bool {
		$post_ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_bricks_editor_mode',
				'meta_value'             => 'bricks',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $post_ids );
	}

	/**
	 * @return array<int, string>
	 */
	private function discover_acf_fields(): array {
		if ( ! $this->plugin_detector->is_acf_active() || ! function_exists( 'acf_get_field_groups' ) ) {
			return array();
		}

		$field_names  = array();
		$field_groups = acf_get_field_groups();

		if ( ! is_array( $field_groups ) ) {
			return array();
		}

		foreach ( $field_groups as $group ) {
			if ( ! isset( $group['key'] ) || ! function_exists( 'acf_get_fields' ) ) {
				continue;
			}

			$fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( ! isset( $field['name'] ) ) {
					continue;
				}
				$field_names[] = sanitize_key( (string) $field['name'] );
			}
		}

		$field_names = array_filter( array_unique( $field_names ) );
		sort( $field_names );

		return array_values( $field_names );
	}

	/**
	 * @return array<int, string>
	 */
	private function discover_metabox_fields(): array {
		if ( ! $this->plugin_detector->is_metabox_active() ) {
			return array();
		}

		return $this->discover_meta_keys_by_prefix(
			array(
				'mb_',
				'rwmb_',
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private function discover_jetengine_fields(): array {
		if ( ! $this->plugin_detector->is_jetengine_active() ) {
			return array();
		}

		return $this->discover_meta_keys_by_prefix(
			array(
				'jet_',
				'_jet_',
			)
		);
	}

	/**
	 * @param array<int,string> $prefixes
	 * @return array<int,string>
	 */
	private function discover_meta_keys_by_prefix( array $prefixes ): array {
		global $wpdb;

		if ( empty( $prefixes ) ) {
			return array();
		}

		$likes = array();
		$args  = array();
		foreach ( $prefixes as $prefix ) {
			$likes[] = 'meta_key LIKE %s';
			$args[]  = $wpdb->esc_like( $prefix ) . '%';
		}

		$sql = "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE " . implode( ' OR ', $likes ) . ' ORDER BY meta_key ASC LIMIT 500';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are safely assembled and prepared below.
		$query = $wpdb->prepare( $sql, $args );
		$rows  = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above via $wpdb->prepare().

		$rows = is_array( $rows ) ? array_map( 'sanitize_key', $rows ) : array();
		$rows = array_filter( $rows );

		return array_values( array_unique( $rows ) );
	}

	/**
	 * @param string $mime_type
	 * @return string
	 */
	private function map_mime_type_to_bucket( string $mime_type ): string {
		if ( str_starts_with( $mime_type, 'image/' ) ) {
			return 'image';
		}

		if ( str_starts_with( $mime_type, 'video/' ) ) {
			return 'video';
		}

		if ( str_starts_with( $mime_type, 'audio/' ) ) {
			return 'audio';
		}

		if ( str_starts_with( $mime_type, 'application/' ) || str_starts_with( $mime_type, 'text/' ) ) {
			return 'document';
		}

		return 'other';
	}

	/**
	 * @param int $attachment_id
	 * @return int
	 */
	private function resolve_attachment_size( int $attachment_id ): int {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) && isset( $metadata['filesize'] ) ) {
			return max( 0, (int) $metadata['filesize'] );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
			return 0;
		}

		$size = filesize( $file_path );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * @param array<int,string> $post_types
	 * @return array<int,string>
	 */
	private function normalize_post_types( array $post_types ): array {
		$resolved = array();

		if ( empty( $post_types ) ) {
			$resolved = array_map(
				static function ( array $item ): string {
					return sanitize_key( (string) ( $item['slug'] ?? '' ) );
				},
				$this->discover_post_types()
			);
		} else {
			foreach ( $post_types as $post_type ) {
				$resolved[] = sanitize_key( (string) $post_type );
			}
		}

		$resolved = array_filter( array_unique( $resolved ) );

		return array_values(
			array_filter(
				$resolved,
				static function ( string $post_type ): bool {
					return post_type_exists( $post_type );
				}
			)
		);
	}

	/**
	 * @param int $sample_size
	 * @return int
	 */
	private function normalize_sample_size( int $sample_size ): int {
		if ( $sample_size <= 0 ) {
			return self::DEFAULT_SAMPLE_SIZE;
		}

		return min( self::MAX_SAMPLE_SIZE, $sample_size );
	}

	/**
	 * @return array<string,int>
	 */
	private function build_provider_summary(): array {
		return array(
			'total'         => 0,
			'convertible'   => 0,
			'deferred'      => 0,
			'unconvertible' => 0,
			'sample_size'   => 0,
		);
	}

	/**
	 * @param array<int,string> $post_types
	 * @param int               $sample_size
	 * @param bool              $full_scan
	 * @return array<string,mixed>
	 */
	private function empty_dynamic_data_response( int $sample_size, bool $full_scan ): array {
		return array(
			'acf_tags'          => $this->build_provider_summary(),
			'metabox_tags'      => $this->build_provider_summary(),
			'jetengine_tags'    => $this->build_provider_summary(),
			'other_tags'        => $this->build_provider_summary(),
			'convertibility'    => array(
				'green'  => 0,
				'yellow' => 0,
				'red'    => 0,
			),
			'unconvertible_list' => array(),
			'deferred_list'     => array(),
			'sample_size'       => $full_scan ? null : $this->normalize_sample_size( $sample_size ),
			'analyzed_posts'    => 0,
			'is_full_scan'      => $full_scan,
		);
	}

	/**
	 * @param string $post_type
	 * @param int    $sample_size
	 * @param bool   $full_scan
	 * @return array<int,int>
	 */
	private function get_post_ids_for_analysis( string $post_type, int $sample_size, bool $full_scan ): array {
		$post_ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $full_scan ? -1 : $sample_size,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $post_ids ) ? array_map( 'absint', $post_ids ) : array();
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	private function get_post_content_for_analysis( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$content_parts = array( (string) $post->post_content );
		$bricks_keys   = array( '_bricks_data', '_bricks_page_content_2', '_bricks_page_content' );

		foreach ( $bricks_keys as $meta_key ) {
			$meta_value = get_post_meta( $post_id, $meta_key, true );

			if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
				$content_parts[] = (string) wp_json_encode( $meta_value );
				continue;
			}

			if ( is_string( $meta_value ) && '' !== $meta_value ) {
				$content_parts[] = $meta_value;
			}
		}

		return implode( "\n", $content_parts );
	}

	/**
	 * @param string $tag
	 * @return string
	 */
	private function resolve_provider_key( string $tag ): string {
		$normalized = trim( $tag );
		$normalized = trim( $normalized, '{}' );

		$base = $normalized;
		if ( false !== strpos( $base, '|' ) ) {
			$base = explode( '|', $base )[0];
		}
		if ( false !== strpos( $base, ':' ) ) {
			$base = explode( ':', $base )[0];
		}
		$base = strtolower( trim( $base ) );

		if ( str_starts_with( $base, 'acf' ) ) {
			return 'acf_tags';
		}

		if ( str_starts_with( $base, 'mb' ) || str_starts_with( $base, 'metabox' ) ) {
			return 'metabox_tags';
		}

		if ( str_starts_with( $base, 'jet' ) ) {
			return 'jetengine_tags';
		}

		return 'other_tags';
	}
}
