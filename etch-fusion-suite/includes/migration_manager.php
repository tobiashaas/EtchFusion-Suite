<?php
/**
 * Migration Manager for Etch Fusion Suite
 *
 * Orchestrates the entire migration process
 */

namespace Bricks2Etch\Core;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Migration_Token_Manager;
use Bricks2Etch\Migrators\EFS_ACF_Field_Groups_Migrator;
use Bricks2Etch\Migrators\EFS_CPT_Migrator;
use Bricks2Etch\Migrators\EFS_Custom_Fields_Migrator;
use Bricks2Etch\Migrators\EFS_Media_Migrator;
use Bricks2Etch\Migrators\EFS_MetaBox_Migrator;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Parsers\EFS_Content_Parser;
use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;
use Bricks2Etch\Services\EFS_Content_Service;
use Bricks2Etch\Services\EFS_CSS_Service;
use Bricks2Etch\Services\EFS_Media_Service;
use Bricks2Etch\Services\EFS_Migration_Service;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Manager {

	/**
	 * Migration service instance.
	 */
	private $migration_service;

	/**
	 * Constructor
	 *
	 * @param EFS_Migration_Service|null $migration_service
	 * @param Migration_Repository_Interface|null $migration_repository
	 */
	public function __construct( EFS_Migration_Service $migration_service = null, Migration_Repository_Interface $migration_repository = null ) {
		if ( $migration_service ) {
			$this->migration_service = $migration_service;

			return;
		}

		// Instantiate migration repository if not provided
		if ( ! $migration_repository ) {
			$migration_repository = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
		}

		$error_handler          = new EFS_Error_Handler();
		$plugin_detector        = new EFS_Plugin_Detector( $error_handler );
		$api_client             = new EFS_API_Client( $error_handler );
		$content_parser         = new EFS_Content_Parser( $error_handler );
		$dynamic_data_converter = new EFS_Dynamic_Data_Converter( $error_handler );
		$gutenberg_generator    = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data_converter, $content_parser );

		// Instantiate style repository for CSS converter
		$style_repository = new \Bricks2Etch\Repositories\EFS_WordPress_Style_Repository();
		$css_converter    = new EFS_CSS_Converter( $error_handler, $style_repository );

		$media_migrator   = new EFS_Media_Migrator( $error_handler, $api_client );
		$cpt_migrator     = new EFS_CPT_Migrator( $error_handler, $api_client );
		$acf_migrator     = new EFS_ACF_Field_Groups_Migrator( $error_handler, $api_client );
		$metabox_migrator = new EFS_MetaBox_Migrator( $error_handler, $api_client );

		$css_service     = new EFS_CSS_Service( $css_converter, $api_client, $error_handler );
		$media_service   = new EFS_Media_Service( $media_migrator, $error_handler );
		$content_service = new EFS_Content_Service( $content_parser, $gutenberg_generator, $error_handler );
		$token_manager   = new EFS_Migration_Token_Manager( $migration_repository );

		$migrator_registry = EFS_Migrator_Registry::instance();
		$migrator_registry->clear();
		$migrator_registry->register( $cpt_migrator );
		$migrator_registry->register( $acf_migrator );
		$migrator_registry->register( $metabox_migrator );
		$migrator_registry->register( new EFS_Custom_Fields_Migrator( $error_handler, $api_client ) );

		$this->migration_service = new EFS_Migration_Service(
			$error_handler,
			$plugin_detector,
			$content_parser,
			$css_service,
			$media_service,
			$content_service,
			$api_client,
			$migrator_registry,
			$migration_repository,
			$token_manager
		);
	}

	/**
	 * Start migration process
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null ) {
		return $this->migration_service->start_migration( $migration_key, $target_url, $batch_size );
	}

	/**
	 * Retrieve current progress data
	 */
	public function get_progress( $migration_id = '' ) {
		return $this->migration_service->get_progress( $migration_id );
	}

	/**
	 * Process batch placeholder for async migrations
	 */
	public function process_batch( $migration_id, $batch ) {
		return $this->migration_service->process_batch( $migration_id, $batch );
	}

	/**
	 * Cancel migration and reset progress
	 */
	public function cancel_migration( $migration_id = '' ) {
		return $this->migration_service->cancel_migration( $migration_id );
	}

	/**
	 * Get migration status
	 */
	public function get_migration_status() {
		return $this->migration_service->get_migration_status();
	}

	/**
	 * Resume migration
	 */
	public function resume_migration( $migration_key, $target_url = null, $batch_size = null ) {
		return $this->migration_service->resume_migration( $migration_key, $target_url, $batch_size );
	}

	/**
	 * Validate target site requirements only
	 * This runs on the TARGET site (Etch), not the source site (Bricks)
	 */
	public function validate_target_site_requirements() {
		return $this->migration_service->validate_target_site_requirements();
	}

	/**
	 * Start import process (runs on TARGET site - Etch)
	 * This method receives data from the source site and imports it
	 */
	/**
	 * Migrate a single post (for batch processing)
	 * Uses same logic as migrate_posts() but for one post at a time
	 */
	public function migrate_single_post( $post, $migration_key, $target_url = null ) {
		return $this->migration_service->migrate_single_post( $post, $migration_key, $target_url );
	}

	/**
	 * Migrate Gutenberg/Classic post via WordPress REST API
	 */
	private function migrate_gutenberg_post( $post, $migration_key, $target_url = null ) {
		return $this->migration_service->migrate_single_post( $post, $migration_key, $target_url );
	}
}
