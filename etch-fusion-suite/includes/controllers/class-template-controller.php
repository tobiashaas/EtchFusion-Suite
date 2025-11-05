<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller handling template extraction requests.
 */
class EFS_Template_Controller {
	/**
	 * @var EFS_Template_Extractor_Service
	 */
	protected $extractor_service;

	/**
	 * @var EFS_Input_Validator
	 */
	protected $input_validator;

	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Template_Extractor_Service $extractor_service, EFS_Input_Validator $input_validator, EFS_Error_Handler $error_handler ) {
		$this->extractor_service = $extractor_service;
		$this->input_validator   = $input_validator;
		$this->error_handler     = $error_handler;
	}

	/**
	 * Handles template extraction request.
	 *
	 * @param string $source
	 * @param string $source_type
	 * @return array|WP_Error
	 */
	public function extract_template( $source, $source_type = 'url' ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return $this->extractor_service->extract_template( $source, $source_type );
	}

	/**
	 * Persists extracted template.
	 *
	 * @param array  $template_data
	 * @param string $name
	 * @return int|WP_Error
	 */
	public function save_template( array $template_data, $name ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return $this->extractor_service->save_template( $template_data, $name );
	}

	/**
	 * Imports a template payload and persists it as a draft Etch template.
	 *
	 * @param array<string,mixed> $template_payload Template payload expected from extractor output.
	 * @param string|null         $name Optional explicit template name.
	 * @return int|WP_Error Post ID on success.
	 */
	public function import_template( array $template_payload, $name = null ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $template_payload['blocks'] ) || ! is_array( $template_payload['blocks'] ) ) {
			return new WP_Error( 'b2e_template_import_invalid', __( 'Template payload is missing blocks data.', 'etch-fusion-suite' ) );
		}

		$resolved_name = $name ? sanitize_text_field( $name ) : ( $template_payload['metadata']['title'] ?? '' );
		if ( '' === $resolved_name ) {
			$resolved_name = __( 'Imported Framer Template', 'etch-fusion-suite' );
		}

		return $this->save_template( $template_payload, $resolved_name );
	}

	/**
	 * Returns saved templates list.
	 *
	 * @return array
	 */
	public function get_saved_templates() {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return $this->extractor_service->get_saved_templates();
	}

	/**
	 * Returns current extraction progress snapshot.
	 *
	 * @return array<string,mixed>
	 */
	public function get_extraction_progress() {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		return $this->extractor_service->get_extraction_progress();
	}

	/**
	 * Deletes stored template.
	 *
	 * @param int $template_id
	 * @return bool|WP_Error
	 */
	public function delete_template( $template_id ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		$deleted = wp_delete_post( (int) $template_id, true );

		if ( ! $deleted ) {
			return new WP_Error( 'b2e_template_delete_failed', __( 'Failed to delete the template.', 'etch-fusion-suite' ) );
		}

		return true;
	}

	/**
	 * Returns single template preview including metadata.
	 *
	 * @param int $template_id
	 * @return array|WP_Error
	 */
	public function preview_template( $template_id ) {
		if ( ! \efs_is_framer_enabled() ) {
			return new WP_Error(
				'framer_disabled',
				__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( (int) $template_id );

		if ( ! $post || 'etch_template' !== $post->post_type ) {
			return new WP_Error( 'b2e_template_not_found', __( 'Template not found.', 'etch-fusion-suite' ) );
		}

		return array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'content'  => maybe_unserialize( $post->post_content ),
			'styles'   => get_post_meta( $post->ID, '_b2e_template_styles', true ),
			'metadata' => get_post_meta( $post->ID, '_b2e_template_metadata', true ),
			'stats'    => get_post_meta( $post->ID, '_b2e_template_stats', true ),
		);
	}
}
