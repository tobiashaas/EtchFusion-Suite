<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Models\EFS_Migration_Config;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migration_Job_Runner {
	private const CRON_HOOK     = 'efs_execute_migration_batch';
	private const DEFAULT_DELAY = 5;
	private const ACTION_PAUSE  = 'pause';
	private const ACTION_CANCEL = 'cancel';

	/** @var string[] */
	private array $phase_sequence = array(
		'validation',
		'analyzing',
		'cpts',
		'acf_field_groups',
		'metabox_configs',
		'custom_fields',
		'media',
		'css_classes',
		'posts',
		'finalization',
	);

	/** @var array<string,string> */
	private array $phase_migrator_map = array(
		'cpts'             => 'cpt',
		'acf_field_groups' => 'acf',
		'metabox_configs'  => 'metabox',
		'custom_fields'    => 'custom_fields',
	);

	/** @var EFS_Migration_Service */
	private $migration_service;

	/** @var Migration_Repository_Interface */
	private $migration_repository;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/** @var EFS_Migrator_Registry */
	private $migrator_registry;

	public function __construct(
		EFS_Migration_Service $migration_service,
		Migration_Repository_Interface $migration_repository,
		EFS_Error_Handler $error_handler,
		EFS_Migrator_Registry $migrator_registry
	) {
		$this->migration_service    = $migration_service;
		$this->migration_repository = $migration_repository;
		$this->error_handler         = $error_handler;
		$this->migrator_registry    = $migrator_registry;
	}

	/**
	 * Initialize job state and schedule first execution.
	 */
	public function initialize_job( string $job_id, EFS_Migration_Config $config ) {
		$active_migration = $this->migration_repository->get_active_migration();
		$target_url       = $active_migration['target_url'] ?? '';
		$migration_key    = $active_migration['migration_key'] ?? '';

		if ( empty( $target_url ) || empty( $migration_key ) ) {
			return new \WP_Error( 'missing_metadata', __( 'Migration metadata is not available for job execution.', 'etch-fusion-suite' ) );
		}

		$post_stats       = $this->calculate_post_batches( $config );
		$total_posts      = $post_stats['total_posts'];
		$total_batches    = $post_stats['total_batches'];

		$state = array(
			'job_id'        => $job_id,
			'status'        => 'running',
			'current_phase' => 'validation',
			'current_batch' => 0,
			'total_batches' => $total_batches,
			'total_posts'   => $total_posts,
			'migration_key' => $migration_key,
			'target_url'    => $target_url,
			'metadata'      => array(),
			'started_at'    => current_time( 'timestamp' ),
			'updated_at'    => current_time( 'timestamp' ),
			'completed_at'  => null,
			'pending_action' => '',
		);

		$this->migration_repository->save_migration_config( $job_id, $config->to_array() );
		$this->migration_repository->save_job_state( $job_id, $state );

		$this->schedule_next_batch( $job_id );

		return array(
			'job_id'        => $job_id,
			'scheduled'     => true,
			'total_posts'   => $total_posts,
			'total_batches' => $total_batches,
		);
	}

	/**
	 * Execute the next scheduled phase/batch.
	 */
	public function execute_next_batch( string $job_id ) {
		$state = $this->migration_repository->get_job_state( $job_id );

		if ( empty( $state ) ) {
			return new \WP_Error( 'job_not_found', __( 'Migration job not found.', 'etch-fusion-suite' ) );
		}

		$status = $state['status'] ?? 'running';
		if ( in_array( $status, array( 'completed', 'cancelled', 'failed', 'paused' ), true ) ) {
			return $state;
		}

		$phase         = $state['current_phase'] ?? $this->phase_sequence[0];
		$batch_index   = max( 0, (int) ( $state['current_batch'] ?? 0 ) );
		$total_batches = max( 0, (int) ( $state['total_batches'] ?? 0 ) );
		$pending       = $state['pending_action'] ?? '';

		if ( $pending && $this->is_safe_boundary( $phase, $batch_index, $total_batches ) ) {
			$state = $this->apply_pending_action( $job_id, $state );
			$status = $state['status'] ?? 'running';
			if ( in_array( $status, array( 'completed', 'cancelled', 'failed', 'paused' ), true ) ) {
				return $state;
			}
		}

		$config        = $this->load_config( $job_id );

		switch ( $phase ) {
			case 'validation':
				$this->perform_validation_phase( $job_id );
				break;

			case 'analyzing':
				$this->perform_analysis_phase( $job_id );
				break;

			case 'cpts':
			case 'acf_field_groups':
			case 'metabox_configs':
			case 'custom_fields':
				$this->perform_migrator_phase( $job_id, $phase, $config );
				break;

			case 'media':
				$this->perform_media_phase( $job_id, $config );
				break;

			case 'css_classes':
				$this->perform_css_phase( $job_id );
				break;

			case 'posts':
				$this->perform_posts_phase( $job_id, $config, $batch_index );
				break;

			case 'finalization':
				$this->finalize_job( $job_id );
				break;
		}

		$state = $this->migration_repository->get_job_state( $job_id );
		if ( ! empty( $state['pending_action'] ) ) {
			$current_phase   = $state['current_phase'] ?? $phase;
			$current_batch   = max( 0, (int) ( $state['current_batch'] ?? 0 ) );
			$current_total   = max( 0, (int) ( $state['total_batches'] ?? 0 ) );
			$state['status'] = 'running';

			if ( $this->is_safe_boundary( $current_phase, $current_batch, $current_total ) ) {
				$state = $this->apply_pending_action( $job_id, $state );
			} else {
				$this->migration_repository->save_job_state( $job_id, $state );
			}
		}

		$status = $state['status'] ?? 'running';
		if ( in_array( $status, array( 'completed', 'cancelled', 'failed', 'paused' ), true ) ) {
			return $state;
		}

		if ( $this->is_running( $job_id ) ) {
			$this->schedule_next_batch( $job_id );
		}

		return $this->get_job_status( $job_id );
	}

	public function pause_job( string $job_id ): bool {
		$state = $this->migration_repository->get_job_state( $job_id );
		if ( empty( $state ) ) {
			return false;
		}

		$state['status']         = 'running';
		$state['pending_action'] = self::ACTION_PAUSE;
		$state['updated_at']     = current_time( 'timestamp' );
		$this->migration_repository->save_job_state( $job_id, $state );
		$this->schedule_next_batch( $job_id, 0 );

		return true;
	}

	public function resume_job( string $job_id ): bool {
		$state = $this->migration_repository->get_job_state( $job_id );
		if ( empty( $state ) ) {
			return false;
		}

		$state['status']         = 'running';
		$state['pending_action'] = '';
		$state['updated_at']     = current_time( 'timestamp' );
		$this->migration_repository->save_job_state( $job_id, $state );
		$this->schedule_next_batch( $job_id );

		return true;
	}

	public function cancel_job( string $job_id ): bool {
		$state = $this->migration_repository->get_job_state( $job_id );
		if ( empty( $state ) ) {
			return false;
		}

		$state['status']         = 'running';
		$state['pending_action'] = self::ACTION_CANCEL;
		$state['updated_at']     = current_time( 'timestamp' );
		$this->migration_repository->save_job_state( $job_id, $state );
		$this->unschedule_job( $job_id );
		$this->schedule_next_batch( $job_id, 0 );

		return true;
	}

	public function get_job_status( string $job_id ): array {
		$state = $this->migration_repository->get_job_state( $job_id );

		if ( empty( $state ) ) {
			return array(
				'status' => 'unknown',
			);
		}

		$state['progress'] = $this->calculate_progress( $state );

		return $state;
	}

	private function perform_validation_phase( string $job_id ) {
		$result = $this->migration_service->validate_target_site_requirements();

		if ( ! $result['valid'] ) {
			$this->fail_job( $job_id, new \Exception( implode( ', ', $result['errors'] ?? array() ) ) );
			return;
		}

		$this->migration_repository->update_job_progress( $job_id, 'validation', 0, array( 'message' => 'Validation succeeded' ) );
		$this->transition_to_phase( $job_id, 'analyzing' );
	}

	private function perform_analysis_phase( string $job_id ) {
		$analysis = $this->migration_service->analyze_content_for_job();

		$this->migration_repository->update_job_progress( $job_id, 'analyzing', 0, array( 'analysis' => $analysis ) );
		$this->transition_to_phase( $job_id, 'cpts' );
	}

	private function perform_migrator_phase( string $job_id, string $phase, EFS_Migration_Config $config ) {
		$state      = $this->migration_repository->get_job_state( $job_id );
		$target_url = $state['target_url'] ?? '';
		$jwt_token  = $state['migration_key'] ?? '';
		$type       = $this->phase_migrator_map[ $phase ] ?? '';

		if ( empty( $type ) ) {
			$this->transition_to_phase( $job_id, $this->get_next_phase( $phase ) );
			return;
		}

		if ( ! $this->migrator_registry->has( $type ) ) {
			$this->transition_to_phase( $job_id, $this->get_next_phase( $phase ) );
			return;
		}

		$result = $this->migration_service->run_migrator_by_type( $type, $target_url, $jwt_token, $config );

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, new \Exception( $result->get_error_message() ) );
			return;
		}

		$this->migration_repository->update_job_progress( $job_id, $phase, 0, array( 'result' => $result ) );
		$next_phase = $this->get_next_phase( $phase );
		if ( $next_phase ) {
			$this->transition_to_phase( $job_id, $next_phase );
		}
	}

	private function perform_media_phase( string $job_id, EFS_Migration_Config $config ) {
		$state       = $this->migration_repository->get_job_state( $job_id );
		$target_url  = $state['target_url'] ?? '';
		$jwt_token   = $state['migration_key'] ?? '';
		$should_run  = $config->should_include_media();

		if ( ! $should_run ) {
			$this->migration_repository->update_job_progress( $job_id, 'media', 0, array( 'skipped' => true ) );
			$this->transition_to_phase( $job_id, 'css_classes' );
			return;
		}

		$result = $this->migration_service->run_media_phase( $target_url, $jwt_token, $config );

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, new \Exception( $result->get_error_message() ) );
			return;
		}

		$this->migration_repository->update_job_progress( $job_id, 'media', 0, array( 'media' => $result ) );
		$this->transition_to_phase( $job_id, 'css_classes' );
	}

	private function perform_css_phase( string $job_id ) {
		$state      = $this->migration_repository->get_job_state( $job_id );
		$target_url = $state['target_url'] ?? '';
		$jwt_token  = $state['migration_key'] ?? '';

		$result = $this->migration_service->run_css_phase( $target_url, $jwt_token );

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, new \Exception( $result->get_error_message() ) );
			return;
		}

		$this->migration_repository->update_job_progress( $job_id, 'css_classes', 0, array( 'css' => $result ) );
		$this->transition_to_phase( $job_id, 'posts' );
	}

	private function perform_posts_phase( string $job_id, EFS_Migration_Config $config, int $batch_index ) {
		$state          = $this->migration_repository->get_job_state( $job_id );
		$target_url     = $state['target_url'] ?? '';
		$jwt_token      = $state['migration_key'] ?? '';
		$posts          = $this->migration_service->get_migration_posts( $config );
		$batch_size     = max( 1, $config->get_batch_size() );
		$batches        = array_chunk( $posts, $batch_size );
		$total_batches  = count( $batches );
		$batch_index    = min( $batch_index, $total_batches );

		if ( $total_batches === 0 || $batch_index >= $total_batches ) {
			$this->transition_to_phase( $job_id, 'finalization' );
			return;
		}

		$current_batch = $batches[ $batch_index ];
		$result        = $this->migration_service->migrate_posts(
			$target_url,
			$jwt_token,
			$this->migration_service->get_api_client(),
			$config,
			$current_batch
		);

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, new \Exception( $result->get_error_message() ) );
			return;
		}

		$metadata = array(
			'summary'       => $result,
			'batch_index'   => $batch_index,
			'total_batches' => $total_batches,
		);

		if ( $batch_index + 1 >= $total_batches ) {
			$this->migration_repository->update_job_progress( $job_id, 'posts', $batch_index, $metadata );
			$this->transition_to_phase( $job_id, 'finalization', $metadata );
		} else {
			$this->migration_repository->update_job_progress( $job_id, 'posts', $batch_index + 1, $metadata );
		}
	}

	private function finalize_job( string $job_id ) {
		$state = $this->migration_repository->get_job_state( $job_id );
		$result = $this->migration_service->finalize_migration( $state['metadata']['summary'] ?? array() );

		if ( is_wp_error( $result ) ) {
			$this->fail_job( $job_id, new \Exception( $result->get_error_message() ) );
			return;
		}

		$state['status']       = 'completed';
		$state['completed_at'] = current_time( 'timestamp' );
		$state['updated_at']   = current_time( 'timestamp' );
		$this->migration_repository->save_job_state( $job_id, $state );
	}

	private function transition_to_phase( string $job_id, string $phase, array $metadata = array() ) {
		$this->migration_repository->update_job_progress( $job_id, $phase, 0, $metadata );
	}

	private function schedule_next_batch( string $job_id, int $delay = self::DEFAULT_DELAY ) {
		if ( ! $this->is_running( $job_id ) ) {
			return;
		}

		$this->unschedule_job( $job_id );
		wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $job_id ) );
	}

	private function is_safe_boundary( string $phase, int $batch_index, int $total_batches ): bool {
		if ( 'posts' === $phase ) {
			if ( $total_batches <= 0 ) {
				return true;
			}

			return $batch_index > 0 && $batch_index <= $total_batches;
		}

		return true;
	}

	private function apply_pending_action( string $job_id, array $state ): array {
		$action = $state['pending_action'] ?? '';
		if ( empty( $action ) ) {
			return $state;
		}

		switch ( $action ) {
			case self::ACTION_PAUSE:
				$state['status']         = 'paused';
				$state['pending_action'] = '';
				$state['updated_at']     = current_time( 'timestamp' );
				$this->migration_repository->save_job_state( $job_id, $state );
				$this->unschedule_job( $job_id );
				break;

			case self::ACTION_CANCEL:
				$state['status']         = 'cancelled';
				$state['completed_at']   = current_time( 'timestamp' );
				$state['pending_action'] = '';
				$state['updated_at']     = current_time( 'timestamp' );
				$this->migration_repository->save_job_state( $job_id, $state );
				$this->unschedule_job( $job_id );
				break;
		}

		return $state;
	}

	private function unschedule_job( string $job_id ) {
		while ( $timestamp = wp_next_scheduled( self::CRON_HOOK, array( $job_id ) ) ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK, array( $job_id ) );
		}
	}

	private function calculate_backlog_batches( int $total_posts, int $batch_size ): int {
		return $batch_size > 0 ? (int) ceil( $total_posts / $batch_size ) : 0;
	}

	private function calculate_post_batches( EFS_Migration_Config $config ): array {
		$posts = $this->migration_service->get_migration_posts( $config );
		$total = count( $posts );
		$batches = $this->calculate_backlog_batches( $total, max( 1, $config->get_batch_size() ) );

		return array(
			'total_posts'   => $total,
			'total_batches' => $batches,
		);
	}

	private function calculate_progress( array $state ): int {
		$phase      = $state['current_phase'] ?? 'validation';
		$phase_index = $this->get_phase_index( $phase );
		$phase_count = max( 1, count( $this->phase_sequence ) - 1 );
		$progress = $phase_index > 0 ? (int) floor( ( $phase_index / $phase_count ) * 100 ) : 0;

		if ( 'posts' === $phase && isset( $state['total_batches'] ) && $state['total_batches'] > 0 ) {
			$current_batch = (int) ( $state['current_batch'] ?? 0 );
			$batch_progress = (int) floor( ( $current_batch / $state['total_batches'] ) * ( 100 / $phase_count ) );
			$progress += $batch_progress;
		}

		return min( 100, $progress );
	}

	private function load_config( string $job_id ): EFS_Migration_Config {
		$data = $this->migration_repository->get_migration_config( $job_id );
		return ! empty( $data ) ? EFS_Migration_Config::from_array( $data ) : EFS_Migration_Config::get_default();
	}

	private function fail_job( string $job_id, \Exception $exception ) {
		$this->error_handler->log_error(
			'E501',
			array(
				'job_id'  => $job_id,
				'message' => $exception->getMessage(),
			)
		);

		$state = $this->migration_repository->get_job_state( $job_id );
		$state['status']       = 'failed';
		$state['updated_at']   = current_time( 'timestamp' );
		$state['completed_at'] = current_time( 'timestamp' );
		$this->migration_repository->save_job_state( $job_id, $state );

		return $state;
	}

	private function is_running( string $job_id ): bool {
		$state = $this->migration_repository->get_job_state( $job_id );
		return isset( $state['status'] ) && 'running' === $state['status'];
	}

	private function get_phase_index( string $phase ): int {
		$index = array_search( $phase, $this->phase_sequence, true );
		return $index !== false ? $index : 0;
	}

	private function get_next_phase( string $current ): ?string {
		$index = $this->get_phase_index( $current );
		$next  = $this->phase_sequence[ $index + 1 ] ?? null;
		return $next;
	}
}
