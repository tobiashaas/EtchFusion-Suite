<?php
namespace Bricks2Etch\Controllers;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Security\EFS_Input_Validator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller handling template extraction requests.
 *
 * @deprecated Framer feature is disabled. All methods return an error.
 */
class EFS_Template_Controller {

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
	public function __construct( EFS_Input_Validator $input_validator, EFS_Error_Handler $error_handler ) {
		$this->input_validator = $input_validator;
		$this->error_handler   = $error_handler;
	}

	/**
	 * Handles template extraction request.
	 *
	 * @deprecated Framer feature is disabled.
	 * @param string $source
	 * @param string $source_type
	 * @return array|WP_Error
	 */
	public function extract_template( $source, $source_type = 'url' ) {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Persists extracted template.
	 *
	 * @deprecated Framer feature is disabled.
	 * @param array  $template_data
	 * @param string $name
	 * @return int|WP_Error
	 */
	public function save_template( array $template_data, $name ) {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Imports a template payload and persists it as a draft Etch template.
	 *
	 * @deprecated Framer feature is disabled.
	 * @param array<string,mixed> $template_payload Template payload expected from extractor output.
	 * @param string|null         $name Optional explicit template name.
	 * @return int|WP_Error Post ID on success.
	 */
	public function import_template( array $template_payload, $name = null ) {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Returns saved templates list.
	 *
	 * @deprecated Framer feature is disabled.
	 * @return array
	 */
	public function get_saved_templates() {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Returns current extraction progress snapshot.
	 *
	 * @deprecated Framer feature is disabled.
	 * @return array<string,mixed>
	 */
	public function get_extraction_progress() {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Deletes stored template.
	 *
	 * @deprecated Framer feature is disabled.
	 * @param int $template_id
	 * @return bool|WP_Error
	 */
	public function delete_template( $template_id ) {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Returns single template preview including metadata.
	 *
	 * @deprecated Framer feature is disabled.
	 * @param int $template_id
	 * @return array|WP_Error
	 */
	public function preview_template( $template_id ) {
		return new WP_Error(
			'framer_disabled',
			__( 'Framer template extraction is not enabled.', 'etch-fusion-suite' ),
			array( 'status' => 403 )
		);
	}
}
