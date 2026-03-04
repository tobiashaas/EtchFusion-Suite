<?php
/**
 * Migrator Executor Service
 *
 * Handles registry dispatch: iterates over all registered migrators, validates each,
 * and executes them. Progress callbacks are injected by the orchestrator.
 *
 * @package Bricks2Etch\Services
 */

namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Migrators\EFS_Migrator_Registry;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Migrator_Executor
 *
 * Dispatches execution to each registered migrator in the registry.
 */
class EFS_Migrator_Executor {

	/** @var EFS_Migrator_Registry */
	private $migrator_registry;

	/** @var EFS_Error_Handler */
	private $error_handler;

	/**
	 * @param EFS_Migrator_Registry $migrator_registry
	 * @param EFS_Error_Handler     $error_handler
	 */
	public function __construct(
		EFS_Migrator_Registry $migrator_registry,
		EFS_Error_Handler $error_handler
	) {
		$this->migrator_registry = $migrator_registry;
		$this->error_handler     = $error_handler;
	}

	/**
	 * Execute all registered migrators that have a matching step in the current steps state.
	 *
	 * @param string        $target_url   Target site URL.
	 * @param string        $jwt_token    Migration key / JWT token.
	 * @param array         $steps        Current steps state used to determine which migrators are enabled.
	 * @param callable|null $on_progress  Optional. Called before each migrator: fn(string $step_key, int $pct, string $name): void.
	 *
	 * @return array|\WP_Error Array with keys 'success' and 'warnings' on success, WP_Error on fatal failure.
	 */
	public function execute_migrators( string $target_url, string $jwt_token, array $steps, ?callable $on_progress = null ) {
		$supported_migrators = $this->migrator_registry->get_supported();

		if ( empty( $supported_migrators ) ) {
			$this->error_handler->log_warning(
				'W002',
				array(
					'message' => 'No migrators available to execute.',
					'action'  => 'Migrator execution skipped',
				)
			);

			return array(
				'success'  => true,
				'warnings' => array(),
			);
		}

		$enabled_migrators = array();
		foreach ( $supported_migrators as $migrator ) {
			$step_key = $this->get_migrator_step_key( $migrator );
			if ( isset( $steps[ $step_key ] ) ) {
				$enabled_migrators[] = $migrator;
			}
		}

		$count             = count( $enabled_migrators );
		$base_percentage   = 30;
		$end_percentage    = 60;
		$range             = max( 1, $end_percentage - $base_percentage );
		$increment         = $count > 0 ? $range / $count : 0;
		$index             = 0;
		$migrator_warnings = array();

		foreach ( $enabled_migrators as $migrator ) {
			$progress = (int) floor( $base_percentage + ( $increment * $index ) );
			$step_key = $this->get_migrator_step_key( $migrator );

			if ( null !== $on_progress ) {
				$on_progress( $step_key, $progress, $migrator->get_name() );
			}

			$validation = $migrator->validate();
			if ( isset( $validation['valid'] ) && ! $validation['valid'] ) {
				$this->error_handler->log_warning(
					'W002',
					array(
						'migrator' => $migrator->get_type(),
						'errors'   => $validation['errors'] ?? array(),
						'action'   => 'Migrator validation failed',
					)
				);
				++$index;
				continue;
			}

			$result = $migrator->migrate( $target_url, $jwt_token );

			if ( is_wp_error( $result ) ) {
				if ( $migrator->is_required() ) {
					$this->error_handler->log_error(
						'E201',
						array(
							'migrator' => $migrator->get_type(),
							'error'    => $result->get_error_message(),
							'action'   => 'Migrator execution failed',
						)
					);

					return $result;
				} else {
					$this->error_handler->log_warning(
						'W014',
						array(
							'migrator' => $migrator->get_type(),
							'error'    => $result->get_error_message(),
							'action'   => 'Optional migrator failed; migration continues',
						)
					);
					$migrator_warnings[] = $migrator->get_type();
					++$index;
					continue;
				}
			}

			++$index;
		}

		return array(
			'success'  => true,
			'warnings' => $migrator_warnings,
		);
	}

	/**
	 * Derive progress step key from migrator type.
	 *
	 * @param Migrator_Interface $migrator
	 * @return string
	 */
	private function get_migrator_step_key( Migrator_Interface $migrator ): string {
		$type = $migrator->get_type();

		$mapping = array(
			'cpt'           => 'cpts',
			'acf'           => 'acf',
			'metabox'       => 'metabox',
			'custom_fields' => 'custom_fields',
			'css_classes'   => 'css',
		);

		return $mapping[ $type ] ?? $type;
	}
}
