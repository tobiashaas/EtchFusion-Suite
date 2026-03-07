<?php
/**
 * API Migration Endpoints for Etch Fusion Suite
 *
 * Handles REST API endpoints for migration operations
 */

namespace Bricks2Etch\Api;

use Bricks2Etch\Api\EFS_API_Rate_Limiting;
use Bricks2Etch\Api\EFS_API_CORS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-rate-limiting.php';
require_once __DIR__ . '/class-cors.php';
require_once __DIR__ . '/class-permissions.php';

/**
 * API Migration Endpoints class.
 *
 * Provides REST API endpoints for migration operations.
 *
 * @package Bricks2Etch\Api
 */
class EFS_API_Migration_Endpoints {

	/**
	 * Export list of post types available on this site (for mapping dropdown on source).
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function export_post_types( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'export_post_types', 60, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		$exclude = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'acf-field-group', 'acf-field' );
		$list    = array();

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, $exclude, true ) ) {
				continue;
			}
			$list[] = array(
				'slug'  => $post_type->name,
				'label' => $post_type->label,
			);
		}

		return new \WP_REST_Response(
			array(
				'post_types' => $list,
			),
			200
		);
	}

	/**
	 * Import custom post types.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_cpts( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_cpts', 60, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$cpt_migrator = EFS_API_Endpoints::resolve( 'cpt_migrator' );
		if ( ! $cpt_migrator || ! method_exists( $cpt_migrator, 'import_custom_post_types' ) ) {
			return new \WP_Error( 'cpt_import_unavailable', __( 'CPT import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $cpt_migrator->import_custom_post_types( $data );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'cpts', 1, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import ACF field groups.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_acf_field_groups( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_acf_field_groups', 60, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$acf_migrator = EFS_API_Endpoints::resolve( 'acf_migrator' );
		if ( ! $acf_migrator || ! method_exists( $acf_migrator, 'import_field_groups' ) ) {
			return new \WP_Error( 'acf_import_unavailable', __( 'ACF import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $acf_migrator->import_field_groups( $data );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'acf', 1, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import MetaBox configurations.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_metabox_configs( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_metabox_configs', 60, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$metabox_migrator = EFS_API_Endpoints::resolve( 'metabox_migrator' );
		if ( ! $metabox_migrator || ! method_exists( $metabox_migrator, 'import_metabox_configs' ) ) {
			return new \WP_Error( 'metabox_import_unavailable', __( 'MetaBox import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $metabox_migrator->import_metabox_configs( $data );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'metabox', 1, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import Bricks global CSS into Etch's global stylesheets option.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_global_css( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_global_css', 10, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$id  = isset( $data['id'] ) ? \sanitize_key( $data['id'] ) : 'bricks-global';
		$css = isset( $data['css'] ) ? \wp_unslash( $data['css'] ) : '';
		$css = '' !== $css ? $css : '';

		if ( '' === $css ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'skipped' => true,
				),
				200
			);
		}

		$style_repository = EFS_API_Endpoints::resolve( 'style_repository' );
		if ( ! $style_repository ) {
			return new \WP_Error( 'style_repo_unavailable', __( 'Style repository unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$stylesheets = $style_repository->get_global_stylesheets();
		if ( ! is_array( $stylesheets ) ) {
			$stylesheets = array();
		}

		$stylesheets[ $id ] = array(
			'name' => isset( $data['name'] ) ? \sanitize_text_field( $data['name'] ) : __( 'Migrated from Bricks', 'etch-fusion-suite' ),
			'css'  => $css,
			'type' => isset( $data['type'] ) ? \sanitize_key( $data['type'] ) : 'custom',
		);

		$saved = $style_repository->save_global_stylesheets( $stylesheets );
		if ( ! $saved ) {
			return new \WP_Error( 'style_save_failed', __( 'Failed to save global stylesheets.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'css', 1, $request );

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Import initial migration totals from source Bricks site.
	 * Sets up receiving_state with all phase totals upfront so UI can show 0/X for all phases.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_init_totals( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_init_totals', 10, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$totals = $request->get_json_params();
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		EFS_API_Endpoints::init_receiving_state_totals( $token, $totals, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'totals'  => $totals,
			),
			200
		);
	}

	/**
	 * Import CSS classes from source Bricks site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_css_classes( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_css_classes', 120, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();

		$css_converter = EFS_API_Endpoints::resolve( 'css_converter' );
		if ( ! $css_converter || ! method_exists( $css_converter, 'import_etch_styles' ) ) {
			return new \WP_Error( 'css_import_unavailable', __( 'CSS import service unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$result = $css_converter->import_etch_styles( $data );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'css', 1, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Import post content into target site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_post( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_post', 240, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$post    = isset( $payload['post'] ) && is_array( $payload['post'] ) ? $payload['post'] : array();

		if ( empty( $post ) ) {
			return new \WP_Error( 'invalid_post_payload', __( 'Invalid post payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$post_type = isset( $post['post_type'] ) ? \sanitize_key( $post['post_type'] ) : 'post';
		if ( ! \post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$post_title  = isset( $post['post_title'] ) ? \sanitize_text_field( $post['post_title'] ) : '';
		$post_slug   = isset( $post['post_name'] ) ? \sanitize_title( $post['post_name'] ) : \sanitize_title( $post_title );
		$post_date   = isset( $post['post_date'] ) ? \sanitize_text_field( $post['post_date'] ) : \current_time( 'mysql' );
		$post_status = isset( $post['post_status'] ) ? \sanitize_key( $post['post_status'] ) : 'draft';
		if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			$post_status = 'draft';
		}

		$etch_content = isset( $payload['etch_content'] ) ? (string) $payload['etch_content'] : '';
		if ( '' === trim( $etch_content ) ) {
			$etch_content = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
		}

		$is_block_content = false !== strpos( $etch_content, '<!-- wp:' );
		$post_content     = $is_block_content ? $etch_content : \wp_kses_post( $etch_content );
		self::sync_etch_loops_from_payload( isset( $payload['etch_loops'] ) && is_array( $payload['etch_loops'] ) ? $payload['etch_loops'] : array() );

		$source_post_id  = isset( $post['ID'] ) ? \absint( $post['ID'] ) : 0;
		$existing        = null;
		$resolution_path = 'new';

		if ( $source_post_id > 0 ) {
			$meta_matches = \get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'   => '_efs_original_post_id',
							'value' => $source_post_id,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => '_b2e_original_post_id',
							'value' => $source_post_id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);
			if ( ! empty( $meta_matches ) ) {
				$existing        = \get_post( $meta_matches[0] );
				$resolution_path = 'meta';
			}
		}

		if ( null === $existing && $source_post_id > 0 ) {
			$mappings = \get_option( 'efs_post_mappings', \get_option( 'b2e_post_mappings', array() ) );
			$mappings = is_array( $mappings ) ? $mappings : array();
			if ( isset( $mappings[ $source_post_id ] ) ) {
				$mapped_post = \get_post( $mappings[ $source_post_id ] );
				if ( $mapped_post instanceof \WP_Post ) {
					$existing        = $mapped_post;
					$resolution_path = 'option';
				}
			}
		}

		if ( null === $existing ) {
			$existing = $post_slug ? \get_page_by_path( $post_slug, OBJECT, $post_type ) : null;
			if ( $existing instanceof \WP_Post ) {
				$resolution_path = 'slug';
			}
		}

		$postarr = array(
			'post_title'   => $post_title,
			'post_name'    => $post_slug,
			'post_type'    => $post_type,
			'post_status'  => $post_status,
			'post_date'    => $post_date,
			'post_content' => $post_content,
		);

		if ( $existing instanceof \WP_Post ) {
			$postarr['ID'] = $existing->ID;

			if ( in_array( $resolution_path, array( 'meta', 'option' ), true ) && $existing->post_type !== $post_type ) {
				if ( \post_type_exists( $post_type ) ) {
					$postarr['post_type'] = $post_type;
				} else {
					unset( $postarr['post_type'] );
					EFS_API_Endpoints::resolve( 'error_handler' )->log_warning(
						'W009',
						array(
							'source_id'           => $source_post_id,
							'target_id'           => $existing->ID,
							'requested_post_type' => $post_type,
							'kept_post_type'      => $existing->post_type,
						)
					);
				}
			}
		}

		if ( $is_block_content ) {
			\kses_remove_filters();
		}
		$post_id = \wp_insert_post( $postarr, true );
		if ( $is_block_content ) {
			\kses_init_filters();
		}
		if ( \is_wp_error( $post_id ) ) {
			return new \WP_Error( 'post_import_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}

		if ( $source_post_id > 0 ) {
			$mappings                    = \get_option( 'efs_post_mappings', \get_option( 'b2e_post_mappings', array() ) );
			$mappings                    = is_array( $mappings ) ? $mappings : array();
			$mappings[ $source_post_id ] = (int) $post_id;
			\update_option( 'efs_post_mappings', $mappings );

			\update_post_meta( $post_id, '_efs_original_post_id', $source_post_id );
		}

		if ( in_array( $resolution_path, array( 'meta', 'option', 'slug' ), true ) ) {
			EFS_API_Endpoints::resolve( 'error_handler' )->log_info(
				'Idempotent upsert: existing post resolved',
				array(
					'source_id'       => $source_post_id,
					'target_id'       => (int) $post_id,
					'resolution_path' => $resolution_path,
				)
			);
		}

		\update_post_meta( $post_id, '_efs_migrated_from_bricks', 1 );
		\update_post_meta( $post_id, '_efs_migration_date', \current_time( 'mysql' ) );

		EFS_API_Endpoints::touch_receiving_state( $token, 'posts', 1, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'post_id' => (int) $post_id,
			),
			200
		);
	}

	/**
	 * Import multiple posts in batch.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_posts( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_posts', 30, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$items   = isset( $payload['posts'] ) && is_array( $payload['posts'] ) ? $payload['posts'] : array();

		if ( empty( $items ) ) {
			return new \WP_Error( 'invalid_batch_payload', __( 'No posts in batch payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$type_counts = array();
		foreach ( $items as $batch_item ) {
			$post_raw  = isset( $batch_item['post'] ) && is_array( $batch_item['post'] ) ? $batch_item['post'] : array();
			$post_type = isset( $post_raw['post_type'] ) ? \sanitize_key( (string) $post_raw['post_type'] ) : 'post';
			if ( \post_type_exists( $post_type ) ) {
				$type_counts[ $post_type ] = ( $type_counts[ $post_type ] ?? 0 ) + 1;
			}
		}

		$all_loops = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['etch_loops'] ) && is_array( $item['etch_loops'] ) ) {
				$all_loops = array_merge( $all_loops, $item['etch_loops'] );
			}
		}
		if ( ! empty( $all_loops ) ) {
			self::sync_etch_loops_from_payload( $all_loops );
		}

		$mappings = \get_option( 'efs_post_mappings', \get_option( 'b2e_post_mappings', array() ) );
		$mappings = is_array( $mappings ) ? $mappings : array();

		$results = array();

		foreach ( $items as $item ) {
			$post_raw = isset( $item['post'] ) && is_array( $item['post'] ) ? $item['post'] : array();
			if ( empty( $post_raw ) ) {
				$results[] = array(
					'source_id' => 0,
					'status'    => 'failed',
					'error'     => 'Missing post data in batch item.',
				);
				continue;
			}

			$etch_content   = isset( $item['etch_content'] ) ? (string) $item['etch_content'] : '';
			$source_post_id = isset( $post_raw['ID'] ) ? \absint( $post_raw['ID'] ) : 0;
			$post_type      = isset( $post_raw['post_type'] ) ? \sanitize_key( $post_raw['post_type'] ) : 'post';
			if ( ! \post_type_exists( $post_type ) ) {
				$post_type = 'post';
			}
			$post_title  = isset( $post_raw['post_title'] ) ? \sanitize_text_field( $post_raw['post_title'] ) : '';
			$post_slug   = isset( $post_raw['post_name'] ) ? \sanitize_title( $post_raw['post_name'] ) : \sanitize_title( $post_title );
			$post_date   = isset( $post_raw['post_date'] ) ? \sanitize_text_field( $post_raw['post_date'] ) : \current_time( 'mysql' );
			$post_status = isset( $post_raw['post_status'] ) ? \sanitize_key( $post_raw['post_status'] ) : 'draft';
			if ( ! in_array( $post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
				$post_status = 'draft';
			}

			if ( '' === trim( $etch_content ) ) {
				$etch_content = '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
			}
			$is_block_content = false !== strpos( $etch_content, '<!-- wp:' );
			$post_content     = $is_block_content ? $etch_content : \wp_kses_post( $etch_content );

			$existing        = null;
			$resolution_path = 'new';

			if ( $source_post_id > 0 ) {
				$meta_matches = \get_posts(
					array(
						'post_type'      => 'any',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							'relation' => 'OR',
							array(
								'key'   => '_efs_original_post_id',
								'value' => $source_post_id,
								'type'  => 'NUMERIC',
							),
							array(
								'key'   => '_b2e_original_post_id',
								'value' => $source_post_id,
								'type'  => 'NUMERIC',
							),
						),
					)
				);
				if ( ! empty( $meta_matches ) ) {
						$existing        = \get_post( $meta_matches[0] );
						$resolution_path = 'meta';
				}
			}

			if ( null === $existing && $source_post_id > 0 && isset( $mappings[ $source_post_id ] ) ) {
				$mapped_post = \get_post( $mappings[ $source_post_id ] );
				if ( $mapped_post instanceof \WP_Post ) {
					$existing        = $mapped_post;
					$resolution_path = 'option';
				}
			}

			if ( null === $existing ) {
				$existing = $post_slug ? \get_page_by_path( $post_slug, OBJECT, $post_type ) : null;
				if ( $existing instanceof \WP_Post ) {
					$resolution_path = 'slug';
				}
			}

			$postarr = array(
				'post_title'   => $post_title,
				'post_name'    => $post_slug,
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				'post_date'    => $post_date,
				'post_content' => $post_content,
			);

			if ( $existing instanceof \WP_Post ) {
				$postarr['ID'] = $existing->ID;
				if ( in_array( $resolution_path, array( 'meta', 'option' ), true ) && $existing->post_type !== $post_type ) {
					if ( \post_type_exists( $post_type ) ) {
						$postarr['post_type'] = $post_type;
					} else {
						unset( $postarr['post_type'] );
					}
				}
			}

			if ( $is_block_content ) {
				\kses_remove_filters();
			}
			$post_id = \wp_insert_post( $postarr, true );
			if ( $is_block_content ) {
				\kses_init_filters();
			}

			if ( \is_wp_error( $post_id ) ) {
				$results[] = array(
					'source_id' => $source_post_id,
					'status'    => 'failed',
					'error'     => $post_id->get_error_message(),
				);
				continue;
			}

			if ( $source_post_id > 0 ) {
				$mappings[ $source_post_id ] = (int) $post_id;
				\update_post_meta( $post_id, '_efs_original_post_id', $source_post_id );
			}

			\update_post_meta( $post_id, '_efs_migrated_from_bricks', 1 );
			\update_post_meta( $post_id, '_efs_migration_date', \current_time( 'mysql' ) );

			$results[] = array(
				'source_id' => $source_post_id,
				'post_id'   => (int) $post_id,
				'status'    => 'ok',
			);
		}

		\update_option( 'efs_post_mappings', $mappings );

		$successful_counts = array();
		foreach ( $results as $result ) {
			if ( isset( $result['status'] ) && 'success' === $result['status'] && isset( $result['post_type'] ) ) {
				$pt = $result['post_type'];
				$successful_counts[ $pt ] = ( $successful_counts[ $pt ] ?? 0 ) + 1;
			}
		}
		if ( ! empty( $successful_counts ) ) {
			EFS_API_Endpoints::touch_receiving_state_by_types( $token, 'posts', $successful_counts, $request );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'count'   => count( $results ),
				'results' => $results,
			),
			200
		);
	}

	/**
	 * Import post meta.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import_post_meta( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'import_post_meta', 240, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

		if ( empty( $meta ) ) {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'updated' => 0,
				),
				200
			);
		}

		$source_post_id = isset( $payload['post_id'] ) ? \absint( $payload['post_id'] ) : 0;
		$target_post_id = isset( $payload['target_post_id'] ) ? \absint( $payload['target_post_id'] ) : 0;

		if ( $target_post_id <= 0 && $source_post_id > 0 ) {
			$mappings = \get_option( 'efs_post_mappings', \get_option( 'b2e_post_mappings', array() ) );
			if ( is_array( $mappings ) && isset( $mappings[ $source_post_id ] ) ) {
				$target_post_id = \absint( $mappings[ $source_post_id ] );
			}
		}

		if ( $target_post_id <= 0 && $source_post_id > 0 ) {
			$target_post_id = $source_post_id;
		}

		if ( $target_post_id <= 0 || ! \get_post( $target_post_id ) ) {
			return new \WP_Error( 'target_post_not_found', __( 'Target post not found for meta import.', 'etch-fusion-suite' ), array( 'status' => 404 ) );
		}

		$updated = 0;
		foreach ( $meta as $meta_key => $meta_value ) {
			$key = \sanitize_key( (string) $meta_key );
			if ( '' === $key ) {
				continue;
			}
			\update_post_meta( $target_post_id, $key, $meta_value );
			++$updated;
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'posts', $updated, $request );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'updated' => $updated,
				'post_id' => (int) $target_post_id,
			),
			200
		);
	}

	/**
	 * Receive media files from source site.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive_media( $request ) {
		$rate = EFS_API_Rate_Limiting::check_rate_limit( 'receive_media', 120, 60 );
		if ( \is_wp_error( $rate ) ) {
			return $rate;
		}

		$cors = EFS_API_CORS::check_cors_origin();
		if ( \is_wp_error( $cors ) ) {
			return $cors;
		}

		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}
		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();

		$filename     = isset( $payload['filename'] ) ? \sanitize_file_name( $payload['filename'] ) : '';
		$title        = isset( $payload['title'] ) ? \sanitize_text_field( $payload['title'] ) : '';
		$mime_type    = isset( $payload['mime_type'] ) ? \sanitize_text_field( $payload['mime_type'] ) : '';
		$file_content = isset( $payload['file_content'] ) ? \base64_decode( (string) $payload['file_content'], true ) : false;

		if ( empty( $filename ) || false === $file_content ) {
			return new \WP_Error( 'invalid_media_payload', __( 'Invalid media payload.', 'etch-fusion-suite' ), array( 'status' => 400 ) );
		}

		$upload = \wp_upload_bits( $filename, null, $file_content );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'media_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => ! empty( $title ) ? $title : \preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = \wp_insert_attachment( $attachment, $upload['file'] );
		if ( \is_wp_error( $attachment_id ) ) {
			return new \WP_Error( 'media_insert_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
		}

		include_once \ABSPATH . 'wp-admin/includes/image.php';
		$metadata = \wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		\wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( ! empty( $payload['alt_text'] ) ) {
			\update_post_meta( $attachment_id, '_wp_attachment_image_alt', \sanitize_text_field( $payload['alt_text'] ) );
		}

		EFS_API_Endpoints::touch_receiving_state( $token, 'media', 1, $request );

		if ( ! empty( $payload['alt_text'] ) ) {
			\update_post_meta( $attachment_id, '_wp_attachment_image_alt', \sanitize_text_field( $payload['alt_text'] ) );
		}

		return new \WP_REST_Response(
			array(
				'success'  => true,
				'media_id' => (int) $attachment_id,
			),
			200
		);
	}

	/**
	 * Complete migration and cleanup.
	 *
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function complete_migration( $request ) {
		$token = EFS_API_Endpoints::validate_bearer_migration_token( $request );
		if ( \is_wp_error( $token ) ) {
			return $token;
		}

		$source_check = EFS_API_Endpoints::check_source_origin( $request, $token );
		if ( \is_wp_error( $source_check ) ) {
			return $source_check;
		}

		$repository = EFS_API_Endpoints::resolve_migration_repository();
		if ( ! $repository || ! method_exists( $repository, 'get_receiving_state' ) || ! method_exists( $repository, 'save_receiving_state' ) ) {
			return new \WP_Error( 'service_unavailable', __( 'Migration repository unavailable.', 'etch-fusion-suite' ), array( 'status' => 500 ) );
		}

		$current = $repository->get_receiving_state();
		$current = is_array( $current ) ? $current : array();

		$items_received = isset( $current['items_received'] ) ? (int) $current['items_received'] : 0;
		$items_total    = isset( $current['items_total'] ) ? (int) $current['items_total'] : 0;
		$items_by_type  = isset( $current['items_by_type'] ) && is_array( $current['items_by_type'] ) ? $current['items_by_type'] : array();

		$incomplete_types = array();
		foreach ( $items_by_type as $type => $counts ) {
			if ( ! isset( $counts['total'] ) || ! isset( $counts['received'] ) ) {
				continue;
			}
			if ( $counts['total'] > 0 && $counts['received'] < $counts['total'] ) {
				$incomplete_types[] = $type . ' (' . $counts['received'] . '/' . $counts['total'] . ')';
			}
		}

		if ( ! empty( $incomplete_types ) ) {
			return new \WP_Error(
				'migration_incomplete',
				sprintf(
					__( 'Migration not yet complete. Missing: %s', 'etch-fusion-suite' ),
					implode( ', ', $incomplete_types )
				),
				array( 'status' => 400 )
			);
		}

		if ( $items_total > 0 && $items_received < $items_total ) {
			return new \WP_Error(
				'migration_incomplete',
				sprintf(
					__( 'Migration not yet complete. Received %d of %d items.', 'etch-fusion-suite' ),
					$items_received,
					$items_total
				),
				array( 'status' => 400 )
			);
		}

		$repository->save_receiving_state(
			array_merge(
				$current,
				array(
					'status'       => 'completed',
					'last_updated' => \current_time( 'mysql' ),
					'is_stale'     => false,
				)
			)
		);

		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Sync Etch loops from incoming payload.
	 *
	 * @param array $incoming_loops Array of loop presets.
	 */
	public static function sync_etch_loops_from_payload( $incoming_loops ) {
		if ( empty( $incoming_loops ) || ! is_array( $incoming_loops ) ) {
			return;
		}

		$current_loops = \get_option( 'etch_loops', array() );
		$current_loops = is_array( $current_loops ) ? $current_loops : array();

		foreach ( $incoming_loops as $loop_id => $preset ) {
			$id = \sanitize_key( (string) $loop_id );
			if ( '' === $id || ! is_array( $preset ) ) {
				continue;
			}

			$config = isset( $preset['config'] ) && is_array( $preset['config'] ) ? $preset['config'] : array();
			if ( empty( $config ) ) {
				continue;
			}

			$current_loops[ $id ] = array(
				'name'   => isset( $preset['name'] ) ? \sanitize_text_field( (string) $preset['name'] ) : $id,
				'key'    => isset( $preset['key'] ) ? \sanitize_key( (string) $preset['key'] ) : $id,
				'global' => isset( $preset['global'] ) ? (bool) $preset['global'] : true,
				'config' => $config,
			);
		}

		\update_option( 'etch_loops', $current_loops );
	}
}
