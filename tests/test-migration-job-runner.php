<?php
use Bricks2Etch\Services\EFS_Migration_Job_Runner;
use Bricks2Etch\Services\EFS_Migration_Service;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Models\EFS_Migration_Config;
use Bricks2Etch\Repositories\Interfaces\Migration_Repository_Interface;

class TestMigrationJobRunner extends \PHPUnit\Framework\TestCase {
	/** @var SimpleMigrationRepository */
	private $repository;

	/** @var \PHPUnit\Framework\MockObject\MockObject|EFS_Migration_Service */
	private $service;

	/** @var EFS_Migration_Job_Runner */
	private $runner;

	protected function setUp(): void {
		$this->repository = new SimpleMigrationRepository();
		$this->service    = $this->getMockBuilder( EFS_Migration_Service::class )
			->disableOriginalConstructor()
			->getMock();
		$this->service->method( 'get_migration_posts' )->willReturn( array() );
		$this->service->method( 'validate_target_site_requirements' )->willReturn( array( 'valid' => true ) );

		$registry = $this->createMock( EFS_Migrator_Registry::class );
		$registry->method( 'has' )->willReturn( false );

		$this->repository->save_active_migration(
			array(
				'target_url'    => 'https://example.com',
				'migration_key' => 'test-token',
			)
		);

		$this->runner = new EFS_Migration_Job_Runner(
			$this->service,
			$this->repository,
			new EFS_Error_Handler(),
			$registry
		);
	}

	public function test_initialize_job_records_state(): void {
		$result = $this->runner->initialize_job( 'job-1', EFS_Migration_Config::get_default() );

		$this->assertArrayHasKey( 'job_id', $result );
		$state = $this->repository->get_job_state( 'job-1' );

		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 'validation', $state['current_phase'] );
		$this->assertSame( 0, $state['current_batch'] );
	}

	public function test_execute_next_batch_advances_from_validation(): void {
		$this->runner->initialize_job( 'job-2', EFS_Migration_Config::get_default() );
		$state = $this->runner->execute_next_batch( 'job-2' );

		$this->assertSame( 'analyzing', $state['current_phase'] );
	}

	public function test_pause_requests_apply_at_boundary(): void {
		$this->runner->initialize_job( 'job-3', EFS_Migration_Config::get_default() );
		$this->runner->pause_job( 'job-3' );

		$state = $this->runner->execute_next_batch( 'job-3' );
		$this->assertSame( 'paused', $state['status'] );
	}

	public function test_cancel_requests_apply_at_boundary(): void {
		$this->runner->initialize_job( 'job-4', EFS_Migration_Config::get_default() );
		$this->runner->cancel_job( 'job-4' );

		$state = $this->runner->execute_next_batch( 'job-4' );
		$this->assertSame( 'cancelled', $state['status'] );
		$this->assertNotEmpty( $state['completed_at'] );
	}
}

class SimpleMigrationRepository implements Migration_Repository_Interface {
	private array $progress = array();
	private array $steps = array();
	private array $stats = array();
	private array $token_data = array();
	private string $token_value = '';
	private array $active_migration = array();
	private array $imported = array();
	private array $job_states = array();
	private array $job_configs = array();

	public function get_progress(): array {
		return $this->progress;
	}

	public function save_progress( array $progress ): bool {
		$this->progress = $progress;
		return true;
	}

	public function delete_progress(): bool {
		$this->progress = array();
		return true;
	}

	public function get_steps(): array {
		return $this->steps;
	}

	public function save_steps( array $steps ): bool {
		$this->steps = $steps;
		return true;
	}

	public function delete_steps(): bool {
		$this->steps = array();
		return true;
	}

	public function get_stats(): array {
		return $this->stats;
	}

	public function save_stats( array $stats ): bool {
		$this->stats = $stats;
		return true;
	}

	public function get_token_data(): array {
		return $this->token_data;
	}

	public function save_token_data( array $token_data ): bool {
		$this->token_data = $token_data;
		return true;
	}

	public function save_active_migration( array $data ): bool {
		$this->active_migration = $data;
		return true;
	}

	public function get_active_migration(): array {
		return $this->active_migration;
	}

	public function get_token_value(): string {
		return $this->token_value;
	}

	public function save_token_value( string $token ): bool {
		$this->token_value = $token;
		return true;
	}

	public function delete_token_data(): bool {
		$this->token_data = array();
		$this->token_value = '';
		return true;
	}

	public function get_imported_data( string $type ): array {
		return $this->imported[ $type ] ?? array();
	}

	public function save_imported_data( string $type, array $data ): bool {
		$this->imported[ $type ] = $data;
		return true;
	}

	public function cleanup_expired_tokens(): int {
		return 0;
	}

	public function save_job_state( string $job_id, array $state ): bool {
		$this->job_states[ $job_id ] = $state;
		return true;
	}

	public function get_job_state( string $job_id ): array {
		return $this->job_states[ $job_id ] ?? array();
	}

	public function update_job_progress( string $job_id, string $phase, int $batch_index, array $metadata ): bool {
		$state = $this->get_job_state( $job_id );
		$state['current_phase'] = $phase;
		$state['current_batch'] = $batch_index;
		$state['metadata'] = $metadata;

		if ( isset( $metadata['total_batches'] ) ) {
			$state['total_batches'] = (int) $metadata['total_batches'];
		}

		return $this->save_job_state( $job_id, $state );
	}

	public function get_safe_boundaries( string $job_id ): array {
		return $this->get_job_state( $job_id );
	}

	public function save_migration_config( string $job_id, array $config ): bool {
		$this->job_configs[ $job_id ] = $config;
		return true;
	}

	public function get_migration_config( string $job_id ): array {
		return $this->job_configs[ $job_id ] ?? array();
	}

	public function cleanup_old_jobs( int $days = 7 ): int {
		return 0;
	}
}
