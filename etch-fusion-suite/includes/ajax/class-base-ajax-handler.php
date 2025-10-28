<?php
/**
 * Base AJAX Handler
 *
 * Abstract base class for all AJAX handlers
 *
 * @package Etch_Fusion_Suite
 * @since 0.5.1
 */

namespace Bricks2Etch\Ajax;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class EFS_Base_Ajax_Handler {

	/**
	 * Nonce action
	 */
	protected $nonce_action = 'efs_nonce';

	/**
	 * Nonce field
	 */
	protected $nonce_field = 'nonce';

	/**
	 * Rate Limiter instance
	 *
	 * @var \Bricks2Etch\Security\EFS_Rate_Limiter|null
	 */
	protected $rate_limiter;

	/**
	 * Input Validator instance
	 *
	 * @var \Bricks2Etch\Security\EFS_Input_Validator|null
	 */
	protected $input_validator;

	/**
	 * Audit Logger instance
	 *
	 * @var \Bricks2Etch\Security\EFS_Audit_Logger|null
	 */
	protected $audit_logger;

	/**
	 * Constructor
	 *
	 * @param \Bricks2Etch\Security\EFS_Rate_Limiter|null $rate_limiter Rate limiter instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Input_Validator|null $input_validator Input validator instance (optional).
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Audit logger instance (optional).
	 */
	public function __construct( $rate_limiter = null, $input_validator = null, $audit_logger = null ) {
		$this->rate_limiter    = $rate_limiter;
		$this->input_validator = $input_validator;
		$this->audit_logger    = $audit_logger;

		// Try to resolve from container if not provided
		if ( function_exists( 'etch_fusion_suite_container' ) ) {
			try {
				$container = etch_fusion_suite_container();

				if ( ! $this->rate_limiter && $container->has( 'rate_limiter' ) ) {
					$this->rate_limiter = $container->get( 'rate_limiter' );
				}

				if ( ! $this->input_validator && $container->has( 'input_validator' ) ) {
					$this->input_validator = $container->get( 'input_validator' );
				}

				if ( ! $this->audit_logger && $container->has( 'audit_logger' ) ) {
					$this->audit_logger = $container->get( 'audit_logger' );
				}
			} catch ( \Exception $e ) {
				// Silently fail if container not available
			}
		}

		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks
	 * Must be implemented by child classes
	 */
	abstract protected function register_hooks();

	/**
	 * Verify nonce
	 *
	 * @return bool
	 */
	protected function verify_nonce() {
		return check_ajax_referer( $this->nonce_action, $this->nonce_field, false );
	}

	/**
	 * Check user capabilities
	 *
	 * @param string $capability Default: 'manage_options'
	 * @return bool
	 */
	protected function check_capability( $capability = 'manage_options' ) {
		return current_user_can( $capability );
	}

	/**
	 * Verify request (nonce + capability)
	 *
	 * @param string $capability Default: 'manage_options'
	 * @return bool
	 */
	protected function verify_request( $capability = 'manage_options' ) {
		$user_id = get_current_user_id();

		if ( ! $this->verify_nonce() ) {
			if ( $this->audit_logger ) {
				$this->audit_logger->log_authentication_attempt( false, 'user_' . $user_id, 'nonce' );
			}

			wp_send_json_error(
				array(
					'message' => __( 'The request could not be authenticated. Please refresh the page and try again.', 'etch-fusion-suite' ),
					'code'    => 'invalid_nonce',
				),
				401
			);
			return false;
		}

		if ( ! $this->check_capability( $capability ) ) {
			if ( $this->audit_logger ) {
				$this->audit_logger->log_authorization_failure( $user_id, 'ajax_request', $this->get_request_action() );
			}

			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action.', 'etch-fusion-suite' ),
					'code'    => 'forbidden',
				),
				403
			);
			return false;
		}

		if ( $this->audit_logger ) {
			$this->audit_logger->log_authentication_attempt( true, 'user_' . $user_id, 'nonce' );
		}

		return true;
	}

	/**
	 * Get POST parameter
	 *
	 * @param string $key Parameter key
	 * @param mixed  $default_value Default value
	 * @return mixed
	 */
	protected function get_post( $key, $default_value = null, $sanitize = 'text' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Concrete handlers call verify_request() (check_ajax_referer) before accessing POST data.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default_value;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Value is sanitized below after verify_request() has enforced nonce checks.
		$value = wp_unslash( $_POST[ $key ] );

		if ( 'array' === $sanitize ) {
			return is_array( $value ) ? $this->sanitize_array( $value ) : $default_value;
		}

		if ( is_array( $value ) ) {
			// Unexpected array for scalar sanitizers.
			return $default_value;
		}

		switch ( $sanitize ) {
			case 'raw':
				return $value;
			case 'key':
				return sanitize_key( $value );
			case 'url':
				return esc_url_raw( $value );
			case 'int':
				return (int) $value;
			case 'float':
				return (float) $value;
			case 'bool':
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Safely retrieve the current AJAX action name.
	 *
	 * @return string
	 */
	protected function get_request_action() {
		if ( ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 'unknown';
		}

		return sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Check rate limit for action
	 *
	 * @param string $action Action name.
	 * @param int $limit Request limit (default: 60).
	 * @param int $window Time window in seconds (default: 60).
	 * @return bool True if within limit, false if exceeded.
	 */
	protected function check_rate_limit( $action, $limit = 60, $window = 60 ) {
		if ( ! $this->rate_limiter ) {
			return true; // No rate limiting if service not available
		}

		$identifier = $this->rate_limiter->get_identifier();

		if ( $this->rate_limiter->check_rate_limit( $identifier, $action, $limit, $window ) ) {
			// Log rate limit exceeded
			if ( $this->audit_logger ) {
				$this->audit_logger->log_rate_limit_exceeded( $identifier, $action );
			}

			header( 'Retry-After: ' . absint( $window ) );

			wp_send_json_error(
				array(
					'message' => __( 'Rate limit exceeded. Please try again later.', 'etch-fusion-suite' ),
					'code'    => 'rate_limit_exceeded',
				),
				429
			);
			return false;
		}

		// Record this request
		$this->rate_limiter->record_request( $identifier, $action, $window );

		return true;
	}

	/**
	 * Validate input data
	 *
	 * @param array $data Data to validate.
	 * @param array $rules Validation rules.
	 * @return array Validated data.
	 */
	protected function validate_input( $data, $rules ) {
		if ( ! $this->input_validator ) {
			return $data; // No validation if service not available
		}

		try {
			return \Bricks2Etch\Security\EFS_Input_Validator::validate_request_data( $data, $rules );
		} catch ( \InvalidArgumentException $e ) {
			$details           = \Bricks2Etch\Security\EFS_Input_Validator::get_last_error_details();
			$error_code        = isset( $details['code'] ) ? sanitize_key( (string) $details['code'] ) : 'invalid_input';
			$user_message      = \Bricks2Etch\Security\EFS_Input_Validator::get_user_error_message( $details );
			$sanitized_details = array(
				'code'    => $details['code'] ?? null,
				'context' => $details['context'] ?? array(),
			);

			// Log invalid input with contextual details.
			if ( $this->audit_logger ) {
				$this->audit_logger->log_security_event(
					'invalid_input',
					'medium',
					$user_message,
					array(
						'data'    => $this->mask_sensitive_values( $data ),
						'rules'   => $this->mask_sensitive_values( $rules ),
						'details' => $sanitized_details,
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => $user_message,
					'code'    => $error_code,
					'details' => $sanitized_details,
				),
				400
			);
			return array();
		}
	}

	/**
	 * Sanitize array recursively.
	 *
	 * @param array $input Array to sanitize.
	 * @return array Sanitized array.
	 */
	protected function sanitize_array( $input, $path = '' ) {
		if ( $this->input_validator ) {
			return $this->input_validator->sanitize_array_recursive( $input, $path, $this->audit_logger );
		}

		if ( ! is_array( $input ) ) {
			return array();
		}

		foreach ( $input as $key => $value ) {
			$current_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$input[ $key ] = $this->sanitize_array( $value, $current_path );
			} elseif ( is_object( $value ) ) {
				$converted     = $this->convert_object_to_array( $value, $current_path );
				$input[ $key ] = $this->sanitize_array( $converted, $current_path );
			} elseif ( is_string( $value ) ) {
				$input[ $key ] = sanitize_text_field( $value );
			} elseif ( is_scalar( $value ) ) {
				$input[ $key ] = $value;
			} else {
				$input[ $key ] = null;
			}
		}

		return $input;
	}

	/**
	 * Convert objects within payloads to arrays and log the occurrence.
	 *
	 * @param object $value Object to convert.
	 * @param string $path  Dot-notation path to the value.
	 * @return array
	 */
	protected function convert_object_to_array( $value, $path ) {
		$converted = json_decode( wp_json_encode( $value ), true );

		if ( null === $converted ) {
			$converted = (array) $value;
		}

		if ( $this->audit_logger ) {
			$this->audit_logger->log_security_event(
				'payload_object_normalized',
				'low',
				'Detected object payload converted to array during sanitization.',
				array(
					'path' => $path,
					'type' => is_object( $value ) ? get_class( $value ) : gettype( $value ),
				)
			);
		}

		return is_array( $converted ) ? $converted : array();
	}

	/**
	 * Mask sensitive values before logging or returning data.
	 *
	 * @param mixed $data Data to inspect.
	 * @return mixed
	 */
	protected function mask_sensitive_values( $data ) {
		$sensitive_keys = array( 'api_key', 'token', 'authorization', 'password', 'secret' );

		if ( is_array( $data ) ) {
			$masked = array();
			foreach ( $data as $key => $value ) {
				if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
					$masked[ $key ] = '***redacted***';
					continue;
				}

				$masked[ $key ] = $this->mask_sensitive_values( $value );
			}
			return $masked;
		}

		if ( is_object( $data ) ) {
			return $this->mask_sensitive_values( (array) $data );
		}

		if ( is_string( $data ) ) {
			return $data;
		}

		return $data;
	}

	/**
	 * Log security event
	 *
	 * @param string $type Event type.
	 * @param string $message Event message.
	 * @param array $context Additional context (optional).
	 * @param string|null $severity_override Optional severity override (low, medium, high, critical).
	 */
	protected function log_security_event( $type, $message, $context = array(), $severity_override = null ) {
		if ( $this->audit_logger ) {
			// Use override if provided, otherwise determine based on event type
			if ( null !== $severity_override ) {
				$severity = $severity_override;
			} else {
				$severity = 'low';

				// Determine severity based on event type
				if ( in_array( $type, array( 'auth_failure', 'rate_limit_exceeded', 'invalid_input' ), true ) ) {
					$severity = 'medium';
				}
			}

			$this->audit_logger->log_security_event( $type, $severity, $message, $context );
		}
	}

	/**
	 * @param int|null $min Minimum value (optional).
	 * @param int|null $max Maximum value (optional).
	 * @return int|null Validated integer or null.
	 */
	protected function validate_integer( $value, $min = null, $max = null ) {
		if ( $this->input_validator ) {
			try {
				return $this->input_validator->validate_integer( $value, $min, $max, true );
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}
		}

		// Fallback validation
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$value = intval( $value );

		if ( null !== $min && $min > $value ) {
			return null;
		}

		if ( null !== $max && $max < $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Validate JSON
	 *
	 * @param string $json JSON string to validate.
	 * @return array|null Decoded array or null on failure.
	 */
	protected function validate_json( $json ) {
		if ( $this->input_validator ) {
			try {
				return $this->input_validator->validate_json( $json, true );
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}
		}

		// Fallback validation
		$decoded = json_decode( $json, true );
		return ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : null;
	}

	/**
	 * Get client IP address
	 *
	 * @return string Client IP address.
	 */
	protected function get_client_ip() {
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

	/**
	 * Sanitize URL
	 *
	 * @param string $url
	 * @return string
	 */
	protected function sanitize_url( $url ) {
		if ( $this->input_validator ) {
			try {
				return $this->input_validator->validate_url( $url, true );
			} catch ( \InvalidArgumentException $e ) {
				return '';
			}
		}
		return esc_url_raw( $url );
	}

	/**
	 * Sanitize text
	 *
	 * @param string $text
	 * @return string
	 */
	protected function sanitize_text( $text ) {
		if ( $this->input_validator ) {
			try {
				return $this->input_validator->validate_text( $text, 255, true );
			} catch ( \InvalidArgumentException $e ) {
				return '';
			}
		}
		return sanitize_text_field( $text );
	}

	/**
	 * Log message
	 *
	 * @param string $message
	 */
	protected function log( $message ) {
		error_log( 'EFS AJAX: ' . $message );
	}
}
