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
use Bricks2Etch\Services\EFS_Media_Phase_Handler;
use Bricks2Etch\Services\EFS_Media_Service;
use Bricks2Etch\Services\EFS_Migration_Service;
use Bricks2Etch\Services\EFS_Posts_Phase_Handler;
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
	public function __construct( ?EFS_Migration_Service $migration_service = null, ?Migration_Repository_Interface $migration_repository = null ) {
		if ( $migration_service ) {
			$this->migration_service = $migration_service;
			return;
		}

		// Prefer the service container (always available in a properly bootstrapped plugin).
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			$this->migration_service = etch_fusion_suite_container()->get( 'migration_service' );
			return;
		}

		// Fallback for non-container contexts (legacy / test paths).
		if ( ! $migration_repository ) {
			$migration_repository = new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
		}

		$error_handler          = new EFS_Error_Handler();
		$plugin_detector        = new EFS_Plugin_Detector( $error_handler );
		$api_client             = new EFS_API_Client( $error_handler );
		$content_parser         = new EFS_Content_Parser( $error_handler );
		$dynamic_data_converter = new EFS_Dynamic_Data_Converter( $error_handler );
		$gutenberg_generator    = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data_converter, $content_parser );

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

		$migrator_executor   = new \Bricks2Etch\Services\EFS_Migrator_Executor( $migrator_registry, $error_handler );
		$steps_manager       = new \Bricks2Etch\Services\EFS_Steps_Manager( $plugin_detector );
		$progress_manager    = new \Bricks2Etch\Services\EFS_Progress_Manager( $migration_repository, $steps_manager );
		$run_finalizer       = new \Bricks2Etch\Services\EFS_Migration_Run_Finalizer(
			$progress_manager,
			$migration_repository,
			null,
			$error_handler,
			$content_service
		);
		$migration_logger    = new \Bricks2Etch\Services\EFS_Migration_Logger();
		$async_runner        = new \Bricks2Etch\Services\EFS_Async_Migration_Runner(
			$migrator_executor,
			$progress_manager,
			$run_finalizer,
			$css_service,
			$media_service,
			$content_service,
			$content_parser,
			$api_client,
			$migration_repository,
			$error_handler,
			$plugin_detector,
			$migration_logger
		);
		$batch_phase_runner  = new \Bricks2Etch\Services\EFS_Batch_Phase_Runner(
			$error_handler,
			$run_finalizer,
			$progress_manager,
			$migration_repository,
			$api_client,
			$migration_logger
		);
		$media_phase_handler = new EFS_Media_Phase_Handler( $media_service, $error_handler );
		$posts_phase_handler = new EFS_Posts_Phase_Handler( $content_service, $error_handler );
		$batch_processor     = new \Bricks2Etch\Services\EFS_Batch_Processor(
			array( $media_phase_handler, $posts_phase_handler ),
			$migration_repository,
			$progress_manager,
			$api_client,
			$error_handler,
			null,
			null,
			$batch_phase_runner
		);
		$headless_job        = new \Bricks2Etch\Services\EFS_Headless_Migration_Job(
			$async_runner,
			$batch_processor,
			$progress_manager,
			$migration_logger,
			$migration_repository
		);
		$starter             = new \Bricks2Etch\Services\EFS_Migration_Starter(
			$token_manager,
			$progress_manager,
			$run_finalizer,
			$migrator_executor,
			$css_service,
			$media_service,
			$content_service,
			$api_client,
			$error_handler,
			$plugin_detector,
			$migration_repository,
			$migration_logger,
			$headless_job
		);
		$orchestrator        = new \Bricks2Etch\Services\EFS_Migration_Orchestrator(
			$starter,
			$async_runner,
			$progress_manager,
			$migration_repository,
			$plugin_detector,
			$token_manager,
			$content_service,
			$api_client,
			$error_handler
		);

		$this->migration_service = new EFS_Migration_Service(
			$orchestrator,
			$batch_processor,
			$migration_repository
		);
	}

	/**
	 * Start migration process
	 *
	 * @param string       $migration_key Migration key (JWT).
	 * @param string|null  $target_url    Target site URL.
	 * @param int|null     $batch_size    Batch size.
	 * @param array        $options       Optional. selected_post_types, post_type_mappings, etc.
	 * @return array|\WP_Error
	 */
	public function start_migration( $migration_key, $target_url = null, $batch_size = null, $options = array() ) {
		return $this->migration_service->start_migration( $migration_key, $target_url, $batch_size, $options );
	}

	/**
	 * Start migration asynchronously (returns immediately; execution runs in background).
	 *
	 * @param string       $migration_key Migration key (JWT).
	 * @param string|null  $target_url    Target site URL.
	 * @param int|null     $batch_size    Batch size.
	 * @param array        $options       selected_post_types, post_type_mappings, etc.
	 * @param string       $nonce         Nonce for spawning background request (optional).
	 * @return array|\WP_Error
	 */
	public function start_migration_async( $migration_key, $target_url = null, $batch_size = null, $options = array(), $nonce = '' ) {
		return $this->migration_service->start_migration_async( $migration_key, $target_url, $batch_size, $options, $nonce );
	}

	/**
	 * Run the long-running migration steps (called by background AJAX).
	 *
	 * @param string $migration_id
	 * @return array|\WP_Error
	 */
	public function run_migration_execution( $migration_id = '' ) {
		return $this->migration_service->run_migration_execution( $migration_id );
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
	 * Resume JS-driven batch loop after timeout or error.
	 *
	 * @param string $migration_id Migration ID.
	 * @return array|\WP_Error
	 */
	public function resume_migration_execution( $migration_id ) {
		return $this->migration_service->resume_migration_execution( $migration_id );
	}

	/**
	 * Cancel migration and reset progress
	 */
	public function cancel_migration( $migration_id = '' ) {
		return $this->migration_service->cancel_migration( $migration_id );
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
}
