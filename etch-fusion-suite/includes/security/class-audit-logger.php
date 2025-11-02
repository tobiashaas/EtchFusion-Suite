<?php
/**
 * Audit Logger
 *
 * Structured logging for security events including authentication, authorization,
 * rate limiting, and suspicious activity.
 *
 * @package    Bricks2Etch
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Audit Logger Class
 *
 * Provides structured security event logging with severity levels and context.
 */
class EFS_Audit_Logger {

	/**
	 * Error Handler instance
	 *
	 * @var \EFS_Error_Handler|null
	 */
	private $error_handler;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Maximum number of security events to keep
	 *
	 * @var int
	 */
	private $max_events = 1000;

	/**
	 * Allowed severity levels.
	 *
	 * @var array<string>
	 */
	private $allowed_severities = array( 'low', 'medium', 'high', 'critical' );

	/**
	 * Keys whose values should be masked in logs.
	 *
	 * @var array<string>
	 */
	private $sensitive_keys = array(
		'api_key',
		'authorization',
		'client_secret',
		'key',
		'migration_key',
		'nonce',
		'password',
		'private_key',
		'secret',
		'token',
	);

	/**
	 * Security log option name
	 *
	 * @var string
	 */
	private $log_option = 'efs_security_log';

	/**
	 * Legacy option keys we used in previous releases.
	 *
	 * @var array<string>
	 */
	private $legacy_log_options = array( 'b2e_security_log' );

	/**
	 * Constructor
	 *
	 * @param \EFS_Error_Handler|null $error_handler Error handler instance (optional).
	 */
	public function __construct( $error_handler = null ) {
		$this->error_handler = $error_handler;
		$this->maybe_migrate_legacy_logs();
	}

	/**
	 * Retrieve singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Log security event
	 *
	 * @param string $event_type Event type (auth_success, auth_failure, etc.).
	 * @param string $severity   Severity level (low, medium, high, critical).
	 * @param string $message    Human-readable message.
	 * @param array  $context    Additional context data (optional).
	 * @return bool True on success, false on failure.
	 */
	public function log_security_event( $event_type, $severity, $message, $context = array() ) {
		$event_type = $this->sanitize_event_type( $event_type );
		$severity   = $this->sanitize_severity( $severity );
		$message    = $this->sanitize_message( $message );
		$context    = $this->build_context( $context );

		$entry = array(
			'timestamp'  => current_time( 'mysql' ),
			'event_type' => $event_type,
			'severity'   => $severity,
			'message'    => $message,
			'context'    => $context,
		);

		$max_events = $this->get_max_events();
		$logs       = $this->get_security_logs( $max_events );

		array_unshift( $logs, $entry );
		$logs = array_slice( $logs, 0, $max_events );

		$saved = update_option( $this->log_option, $logs, false );

		if ( in_array( $severity, array( 'high', 'critical' ), true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: mirrors high/critical security events to WordPress debug.log for real-time alerting
			error_log(
				sprintf(
					'[EFS Security] %s - %s: %s',
					strtoupper( $severity ),
					$event_type,
					$message
				)
			);

			if ( $this->error_handler ) {
				$this->error_handler->log_warning(
					$message,
					array(
						'event_type' => $event_type,
						'severity'   => $severity,
						'context'    => $context,
					)
				);
			}
		}

		return $saved;
	}

	/**
	 * Log authentication attempt
	 *
	 * @param bool   $success  Whether authentication was successful.
	 * @param string $username Username or identifier.
	 * @param string $method   Authentication method (api_key, token, etc.).
	 * @return bool True on success, false on failure.
	 */
	public function log_authentication_attempt( $success, $username, $method ) {
		$event_type = $success ? 'auth_success' : 'auth_failure';
		$severity   = $success ? 'low' : 'medium';
		$message    = sprintf(
			'Authentication %s for %s via %s',
			$success ? 'succeeded' : 'failed',
			$username,
			$method
		);

		return $this->log_security_event(
			$event_type,
			$severity,
			$message,
			array(
				'username' => $username,
				'method'   => $method,
			)
		);
	}

	/**
	 * Log authorization failure
	 *
	 * @param int    $user_id     User ID.
	 * @param string $action      Action attempted.
	 * @param string $resource_id Resource accessed.
	 * @return bool True on success, false on failure.
	 */
	public function log_authorization_failure( $user_id, $action, $resource_id ) {
		$message = sprintf(
			'Authorization failed for user %d attempting %s on %s',
			$user_id,
			$action,
			$resource_id
		);

		return $this->log_security_event(
			'authorization_failure',
			'medium',
			$message,
			array(
				'user_id'  => $user_id,
				'action'   => $action,
				'resource' => $resource_id,
			)
		);
	}

	/**
	 * Log rate limit exceeded
	 *
	 * @param string $identifier Identifier (IP, user ID).
	 * @param string $action     Action that was rate limited.
	 * @return bool True on success, false on failure.
	 */
	public function log_rate_limit_exceeded( $identifier, $action ) {
		$message = sprintf(
			'Rate limit exceeded for %s on action %s',
			$identifier,
			$action
		);

		return $this->log_security_event(
			'rate_limit_exceeded',
			'medium',
			$message,
			array(
				'identifier' => $identifier,
				'action'     => $action,
			)
		);
	}

	/**
	 * Log suspicious activity
	 *
	 * @param string $type    Activity type.
	 * @param array  $details Activity details.
	 * @return bool True on success, false on failure.
	 */
	public function log_suspicious_activity( $type, $details ) {
		$message = sprintf( 'Suspicious activity detected: %s', $type );

		return $this->log_security_event( 'suspicious_activity', 'high', $message, $details );
	}

	/**
	 * Get security logs
	 *
	 * @param int         $limit    Maximum number of logs to retrieve (default: 100).
	 * @param string|null $severity Filter by severity level (optional).
	 * @return array Array of log entries.
	 */
	public function get_security_logs( $limit = 100, $severity = null ) {
		$logs = get_option( $this->log_option, array() );

		// Filter by severity if specified
		if ( null !== $severity ) {
			$logs = array_filter(
				$logs,
				function ( $log ) use ( $severity ) {
					return isset( $log['severity'] ) && $severity === $log['severity'];
				}
			);
		}

		// Limit results
		$logs = array_slice( $logs, 0, $limit );

		return $logs;
	}

	/**
	 * Resolve maximum number of events to retain.
	 *
	 * @return int
	 */
	protected function get_max_events() {
		$max = apply_filters( 'etch_fusion_suite_audit_logger_max_events', $this->max_events );
		$max = (int) $max;

		return $max > 0 ? $max : $this->max_events;
	}

	/**
	 * Sanitize event type identifier.
	 *
	 * @param string $event_type Raw event type.
	 * @return string
	 */
	private function sanitize_event_type( $event_type ) {
		$sanitized = sanitize_key( (string) $event_type );

		return $sanitized ? $sanitized : 'unknown_event';
	}

	/**
	 * Sanitize severity and fall back to low when invalid.
	 *
	 * @param string $severity Raw severity.
	 * @return string
	 */
	private function sanitize_severity( $severity ) {
		$sanitized = strtolower( sanitize_key( (string) $severity ) );

		return in_array( $sanitized, $this->allowed_severities, true ) ? $sanitized : 'low';
	}

	/**
	 * Sanitize log message and enforce a maximum length.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	private function sanitize_message( $message ) {
		$sanitized = sanitize_text_field( (string) $message );

		return $this->limit_string_length( $sanitized, 300 );
	}

	/**
	 * Recursively sanitize context payload.
	 *
	 * @param mixed       $value Context value.
	 * @param string|null $key   Context key.
	 * @param int         $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_context( $value, $key = null, $depth = 0 ) {
		if ( $depth > 5 ) {
			return '[truncated]';
		}

		if ( null !== $key && $this->is_sensitive_key( $key ) ) {
			return '***redacted***';
		}

		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
			if ( null === $value ) {
				$value = (array) $value;
			}
		}

		if ( is_array( $value ) ) {
			$sanitized = array();
			$count     = 0;

			foreach ( $value as $sub_key => $sub_value ) {
				if ( $count >= 25 ) {
					$sanitized['_truncated'] = true;
					break;
				}

				$normalized_key = is_int( $sub_key ) ? $sub_key : $this->limit_string_length( sanitize_text_field( (string) $sub_key ), 100 );

				$sanitized[ $normalized_key ] = $this->sanitize_context( $sub_value, $sub_key, $depth + 1 );
				++$count;
			}

			return $sanitized;
		}

		if ( is_bool( $value ) ) {
			return (bool) $value;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			return (float) $value;
		}

		if ( is_string( $value ) ) {
			if ( null !== $key && $this->is_sensitive_key( $key ) ) {
				return '***redacted***';
			}

			return $this->limit_string_length( sanitize_text_field( $value ), 500 );
		}

		return null;
	}

	/**
	 * Determine whether a context key is sensitive and should be masked.
	 *
	 * @param string|null $key Context key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		if ( null === $key ) {
			return false;
		}

		$key = strtolower( (string) $key );

		return in_array( $key, $this->sensitive_keys, true );
	}

	/**
	 * Truncate strings to a maximum length.
	 *
	 * @param string $value String to truncate.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function limit_string_length( $value, $max = 255 ) {
		if ( mb_strlen( $value ) <= $max ) {
			return $value;
		}

		return mb_substr( $value, 0, max( 0, $max - 3 ) ) . '...';
	}

	/**
	 * Clear security logs
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_security_logs() {
		return delete_option( $this->log_option );
	}

	/**
	 * Export logs as JSON
	 *
	 * @param int         $limit    Maximum number of logs to export (default: 1000).
	 * @param string|null $severity Filter by severity level (optional).
	 * @return string JSON-encoded logs.
	 */
	public function export_logs_json( $limit = 1000, $severity = null ) {
		$logs = $this->get_security_logs( $limit, $severity );
		return wp_json_encode( $logs, JSON_PRETTY_PRINT );
	}

	/**
	 * Build context array
	 *
	 * Adds standard context information to custom context.
	 *
	 * @param array $custom_context Custom context data.
	 * @return array Complete context array.
	 */
	private function build_context( $custom_context = array() ) {
		$context = array(
			'user_id'     => get_current_user_id(),
			'ip'          => $this->get_client_ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		);

		if ( ! empty( $custom_context ) ) {
			$context = array_merge( $context, (array) $custom_context );
		}

		return $this->sanitize_context( $context );
	}

	/**
	 * Migrate logs stored under legacy option keys to the canonical option.
	 *
	 * @return void
	 */
	private function maybe_migrate_legacy_logs() {
		foreach ( $this->legacy_log_options as $legacy_key ) {
			if ( 'efs_security_log' === $legacy_key ) {
				continue;
			}

			$legacy_logs = get_option( $legacy_key, null );
			if ( null === $legacy_logs ) {
				continue;
			}

			$current_logs = get_option( $this->log_option, array() );
			if ( empty( $current_logs ) && ! empty( $legacy_logs ) ) {
				update_option( $this->log_option, $legacy_logs, false );
			}

			delete_option( $legacy_key );
		}
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		// Check for proxy headers
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

				// X-Forwarded-For can contain multiple IPs
				if ( false !== strpos( $ip, ',' ) ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}

				// Validate IP
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					break;
				}
			}
		}

		return ! empty( $ip ) ? $ip : 'unknown';
	}
}
