<?php
/**
 * Abstract Migrator base class
 *
 * Provides common functionality for all migrators, including error handling,
 * logging helpers, plugin detection, and API communication.
 *
 * @package Bricks2Etch\Migrators
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use WP_Error;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for all migrator implementations.
 */
abstract class Abstract_Migrator implements Migrator_Interface {
	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var EFS_API_Client|null
	 */
	protected $api_client;

	/**
	 * @var string
	 */
	protected $name = '';

	/**
	 * @var string
	 */
	protected $type = '';

	/**
	 * @var int
	 */
	protected $priority = 10;

	/**
	 * Constructor.
	 */
	public function __construct( EFS_Error_Handler $error_handler, EFS_API_Client $api_client = null ) {
		$this->error_handler = $error_handler;
		$this->api_client    = $api_client;
	}

	/** @inheritDoc */
	public function supports() {
		return true;
	}

	/** @inheritDoc */
	public function get_name() {
		return $this->name;
	}

	/** @inheritDoc */
	public function get_type() {
		return $this->type;
	}

	/** @inheritDoc */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Helper to determine plugin availability by function/class name.
	 *
	 * @param string $function_or_class
	 *
	 * @return bool
	 */
	protected function check_plugin_active( $function_or_class ) {
		return function_exists( $function_or_class ) || class_exists( $function_or_class );
	}

	/**
	 * Logs an info/debug message.
	 */
	protected function log_info( $message, $context = array() ) {
		$this->error_handler->debug_log( $message, $context, 'B2E_MIGRATOR' );
	}

	/**
	 * Logs a warning via error handler.
	 */
	protected function log_warning( $code, $context = array() ) {
		$this->error_handler->log_warning( $code, $context );
	}

	/**
	 * Logs an error via error handler.
	 */
	protected function log_error( $code, $context = array() ) {
		$this->error_handler->log_error( $code, $context );
	}

	/** @inheritDoc */
	abstract public function export();

	/** @inheritDoc */
	abstract public function import( $data );

	/** @inheritDoc */
	abstract public function migrate( $target_url, $jwt_token );

	/** @inheritDoc */
	abstract public function validate();

	/** @inheritDoc */
	abstract public function get_stats();

	/**
	 * Sends data to Etch target endpoint via API client.
	 *
	 * @param string $endpoint
	 * @param array  $data
	 * @param string $target_url
	 * @param string $jwt_token
	 *
	 * @return array|WP_Error
	 */
	protected function send_to_target( $endpoint, array $data, $target_url, $jwt_token ) {
		if ( ! $this->api_client instanceof EFS_API_Client ) {
			$this->api_client = new EFS_API_Client( $this->error_handler );
		}

		return $this->api_client->send_authorized_request( $target_url, $jwt_token, $endpoint, 'POST', $data );
	}
}
