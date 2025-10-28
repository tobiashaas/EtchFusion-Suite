<?php
/**
 * Input Validator
 *
 * Provides comprehensive input validation and sanitization for all endpoints.
 * Validates URLs, text, integers, arrays, JSON, API keys, and tokens.
 *
 * @package    Bricks2Etch
 * @subpackage Security
 * @since      0.5.0
 */

namespace Bricks2Etch\Security;

/**
 * Input Validator Class
 *
 * Comprehensive validation methods for all input types with security-first approach.
 */
class EFS_Input_Validator {

	/**
	 * Default error code when validation fails without a specific code.
	 */
	private const FALLBACK_ERROR_CODE = 'field_validation_failed';

	/**
	 * Stores the most recent validation error details.
	 *
	 * @var array{code:?string,context:array<string,mixed>}
	 */
	private static $last_error_details = array(
		'code'    => null,
		'context' => array(),
	);

	/**
	 * Reset stored validation error details.
	 *
	 * @return void
	 */
	private static function reset_last_error() {
		self::$last_error_details = array(
			'code'    => null,
			'context' => array(),
		);
	}

	/**
	 * Record validation error details for later retrieval.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param array  $context Additional sanitized context data.
	 * @return void
	 */
	private static function record_error( $code, array $context = array() ) {
		self::$last_error_details = array(
			'code'    => $code,
			'context' => $context,
		);
	}

	/**
	 * Record an error and throw a translated validation exception.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Localised human-readable message.
	 * @param array  $context Additional context.
	 * @return void
	 * @throws \InvalidArgumentException Always thrown.
	 */
	private static function fail( $code, $message, array $context = array() ) {
		self::record_error( $code, $context );
		self::throw_validation_exception( $message );
	}

	/**
	 * Throw a validation exception with a safe, translated message.
	 *
	 * @param string $message Human-readable message.
	 * @return void
	 * @throws \InvalidArgumentException Always thrown.
	 */
	private static function throw_validation_exception( $message ) {
		throw new \InvalidArgumentException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are handled by calling code.
	}

	/**
	 * Retrieve the most recent validation error details.
	 *
	 * @return array{code:?string,context:array<string,mixed>}
	 */
	public static function get_last_error_details() {
		return self::$last_error_details;
	}

	/**
	 * Validate URL.
	 *
	 * @param string $url      URL to validate.
	 * @param bool   $required Whether the field is required (default: true).
	 * @return string|null Validated URL or null if not required and empty.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_url( $url, $required = true ) {
		self::reset_last_error();

		if ( empty( $url ) ) {
			if ( $required ) {
				self::fail( 'url_required', \__( 'URL is required.', 'etch-fusion-suite' ) );
			}
			return null;
		}

		$url = esc_url_raw( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			self::fail( 'url_invalid_format', \__( 'URL format is invalid.', 'etch-fusion-suite' ), array( 'value' => $url ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! isset( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			self::fail(
				'url_invalid_scheme',
				\__( 'URL must use the http or https protocol.', 'etch-fusion-suite' ),
				array( 'scheme' => isset( $parsed['scheme'] ) ? $parsed['scheme'] : null )
			);
		}

		return $url;
	}

	/**
	 * Validate text.
	 *
	 * @param string $text       Text to validate.
	 * @param int    $max_length Maximum length (default: 255).
	 * @param bool   $required   Whether the field is required (default: true).
	 * @return string|null Validated text or null if not required and empty.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_text( $text, $max_length = 255, $required = true ) {
		self::reset_last_error();

		if ( empty( $text ) && '0' !== $text ) {
			if ( $required ) {
				self::fail( 'text_required', \__( 'Text is required.', 'etch-fusion-suite' ) );
			}
			return null;
		}

		$text = sanitize_text_field( $text );

		if ( strlen( $text ) > $max_length ) {
			self::fail(
				'text_max_length',
				\__( 'Text exceeds the allowed length.', 'etch-fusion-suite' ),
				array(
					'max_length'     => absint( $max_length ),
					'current_length' => (int) strlen( $text ),
				)
			);
		}

		return $text;
	}

	/**
	 * Validate integer.
	 *
	 * @param mixed $value    Value to validate.
	 * @param int   $min      Minimum value (optional).
	 * @param int   $max      Maximum value (optional).
	 * @param bool  $required Whether the field is required (default: true).
	 * @return int|null Validated integer or null if not required and empty.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_integer( $value, $min = null, $max = null, $required = true ) {
		self::reset_last_error();

		if ( null === $value || '' === $value ) {
			if ( $required ) {
				self::fail( 'integer_required', \__( 'Value is required.', 'etch-fusion-suite' ) );
			}
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			self::fail( 'integer_type_mismatch', \__( 'Value must be an integer.', 'etch-fusion-suite' ), array( 'value' => $value ) );
		}

		$value = intval( $value );

		if ( null !== $min && $value < $min ) {
			self::fail(
				'integer_below_min',
				\__( 'Value is below the minimum allowed.', 'etch-fusion-suite' ),
				array(
					'min'   => absint( $min ),
					'value' => $value,
				)
			);
		}

		if ( null !== $max && $value > $max ) {
			self::fail(
				'integer_above_max',
				\__( 'Value exceeds the maximum allowed.', 'etch-fusion-suite' ),
				array(
					'max'   => absint( $max ),
					'value' => $value,
				)
			);
		}

		return $value;
	}

	/**
	 * Validate array.
	 *
	 * @param mixed $array        Array to validate.
	 * @param array $allowed_keys Allowed keys (optional, empty = allow all).
	 * @param bool  $required     Whether the field is required (default: true).
	 * @return array|null Validated array or null if not required and empty.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_array( $input, $allowed_keys = array(), $required = true ) {
		self::reset_last_error();

		if ( empty( $input ) ) {
			if ( $required ) {
				self::fail( 'array_required', \__( 'Array input is required.', 'etch-fusion-suite' ) );
			}
			return null;
		}

		if ( ! is_array( $input ) ) {
			self::fail( 'array_type_mismatch', \__( 'Value must be an array.', 'etch-fusion-suite' ), array( 'value_type' => gettype( $input ) ) );
		}

		if ( ! empty( $allowed_keys ) ) {
			$sanitized_allowed_keys = array_map(
				static function ( $allowed_key ) {
					return sanitize_key( (string) $allowed_key );
				},
				$allowed_keys
			);

			foreach ( array_keys( $input ) as $key ) {
				$sanitized_key = sanitize_key( (string) $key );
				if ( ! in_array( $sanitized_key, $sanitized_allowed_keys, true ) ) {
					self::fail(
						'array_invalid_key',
						\__( 'Array contains disallowed keys.', 'etch-fusion-suite' ),
						array(
							'key'          => $sanitized_key,
							'allowed_keys' => $sanitized_allowed_keys,
						)
					);
				}
			}
		}

		return $this->sanitize_array_recursive( $input );
	}

	/**
	 * Validate JSON.
	 *
	 * @param string $json     JSON string to validate.
	 * @param bool   $required Whether the field is required (default: true).
	 * @return array|null Decoded JSON array or null if not required and empty.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_json( $json, $required = true ) {
		self::reset_last_error();

		if ( empty( $json ) ) {
			if ( $required ) {
				self::fail( 'json_required', \__( 'JSON input is required.', 'etch-fusion-suite' ) );
			}
			return null;
		}

		$decoded = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			self::fail(
				'json_invalid',
				\__( 'JSON string is invalid.', 'etch-fusion-suite' ),
				array( 'error' => sanitize_text_field( (string) json_last_error_msg() ) )
			);
		}

		return $decoded;
	}

	/**
	 * Validate API key.
	 *
	 * @param string $key API key to validate.
	 * @return string Validated API key.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_api_key( $key ) {
		self::reset_last_error();

		if ( empty( $key ) ) {
			self::fail( 'api_key_required', \__( 'API key is required.', 'etch-fusion-suite' ) );
		}

		$key = sanitize_text_field( $key );

		if ( strlen( $key ) < 20 ) {
			self::fail( 'api_key_min_length', \__( 'API key is too short.', 'etch-fusion-suite' ), array( 'min_length' => 20 ) );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_\-.]+$/', $key ) ) {
			self::fail( 'api_key_invalid_characters', \__( 'API key contains invalid characters.', 'etch-fusion-suite' ) );
		}

		return $key;
	}

	/**
	 * Validate migration token.
	 *
	 * @param string $token Migration token to validate.
	 * @return string Validated token.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_token( $token ) {
		self::reset_last_error();

		if ( empty( $token ) ) {
			self::fail( 'token_required', \__( 'Token is required.', 'etch-fusion-suite' ) );
		}

		$token = sanitize_text_field( $token );

		if ( strlen( $token ) < 64 ) {
			self::fail( 'token_min_length', \__( 'Token is too short.', 'etch-fusion-suite' ), array( 'min_length' => 64 ) );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9]+$/', $token ) ) {
			self::fail( 'token_invalid_characters', \__( 'Token contains invalid characters.', 'etch-fusion-suite' ) );
		}

		return $token;
	}

	/**
	 * Validate post ID.
	 *
	 * @param int $id Post ID to validate.
	 * @return int Validated post ID.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function validate_post_id( $id ) {
		self::reset_last_error();

		$id = $this->validate_integer( $id, 1 );

		if ( ! get_post( $id ) ) {
			self::fail( 'post_not_found', \__( 'Post could not be found.', 'etch-fusion-suite' ), array( 'post_id' => (int) $id ) );
		}

		return $id;
	}

	/**
	 * Sanitize array recursively.
	 *
	 * @param array             $input         Array to sanitize.
	 * @param string            $path          Dot-notation path for logging context.
	 * @param \Bricks2Etch\Security\EFS_Audit_Logger|null $audit_logger Optional audit logger for anomaly reporting.
	 * @param int               $depth         Current recursion depth.
	 * @return array Sanitized array.
	 */
	public function sanitize_array_recursive( $input, $path = '', $audit_logger = null, $depth = 0 ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		// Prevent excessively deep recursion from exhausting resources.
		if ( $depth > 20 ) {
			if ( $audit_logger ) {
				$audit_logger->log_security_event(
					'payload_depth_truncated',
					'low',
					'Payload sanitization truncated due to excessive nesting.',
					array(
						'path'  => $path,
						'depth' => $depth,
					)
				);
			}

			return array();
		}

		foreach ( $input as $key => $value ) {
			$current_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$input[ $key ] = $this->sanitize_array_recursive( $value, $current_path, $audit_logger, $depth + 1 );
			} elseif ( is_object( $value ) ) {
				$converted = json_decode( wp_json_encode( $value ), true );

				if ( null === $converted ) {
					$converted = (array) $value;
				}

				if ( $audit_logger ) {
					$audit_logger->log_security_event(
						'payload_object_normalized',
						'low',
						'Detected object payload converted to array during sanitization.',
						array(
							'path' => $current_path,
							'type' => get_class( $value ),
						)
					);
				}

				$input[ $key ] = $this->sanitize_array_recursive( is_array( $converted ) ? $converted : array(), $current_path, $audit_logger, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$sanitized = sanitize_text_field( $value );
				if ( strlen( $sanitized ) > 2048 ) {
					$sanitized = substr( $sanitized, 0, 2048 );
					if ( $audit_logger ) {
						$audit_logger->log_security_event(
							'payload_value_truncated',
							'low',
							'String value truncated during sanitization.',
							array(
								'path'     => $current_path,
								'original' => strlen( $value ),
								'trimmed'  => 2048,
							)
						);
					}
				}
				$input[ $key ] = $sanitized;
			} elseif ( is_scalar( $value ) || null === $value ) {
				$input[ $key ] = $value;
			} else {
				$input[ $key ] = null;
			}
		}

		return $input;
	}

	/**
	 * Validate request data against rules.
	 *
	 * @param array $data  Data to validate.
	 * @param array $rules Validation rules (field => [type, required, options]).
	 * @return array Validated data.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public static function validate_request_data( $data, $rules ) {
		$validator = new self();
		$validated = array();

		foreach ( $rules as $field => $rule ) {
			if ( ! is_array( $rule ) ) {
				$rule = array( 'type' => 'text' );
			}

			$type     = $rule['type'] ?? 'text';
			$required = $rule['required'] ?? true;
			$value    = $data[ $field ] ?? null;

			try {
				switch ( $type ) {
					case 'url':
						$validated[ $field ] = $validator->validate_url( $value, $required );
						break;
					case 'text':
						$max_length          = $rule['max_length'] ?? 255;
						$validated[ $field ] = $validator->validate_text( $value, $max_length, $required );
						break;
					case 'integer':
						$min                 = $rule['min'] ?? null;
						$max                 = $rule['max'] ?? null;
						$validated[ $field ] = $validator->validate_integer( $value, $min, $max, $required );
						break;
					case 'array':
						$allowed_keys        = $rule['allowed_keys'] ?? array();
						$validated[ $field ] = $validator->validate_array( $value, $allowed_keys, $required );
						break;
					case 'json':
						$validated[ $field ] = $validator->validate_json( $value, $required );
						break;
					case 'api_key':
						$validated[ $field ] = $validator->validate_api_key( $value );
						break;
					case 'token':
						$validated[ $field ] = $validator->validate_token( $value );
						break;
					case 'post_id':
						$validated[ $field ] = $validator->validate_post_id( $value );
						break;
					default:
						$validated[ $field ] = sanitize_text_field( $value );
				}
			} catch ( \InvalidArgumentException $e ) {
				$sanitized_field  = sanitize_key( (string) $field );
				$error_details    = self::get_last_error_details();
				$context          = $error_details['context'] ?? array();
				$context['field'] = $sanitized_field;
				self::record_error( $error_details['code'] ?? self::FALLBACK_ERROR_CODE, $context );
				self::fail( self::FALLBACK_ERROR_CODE, \__( 'Submitted data is invalid.', 'etch-fusion-suite' ), $context );
			}
		}

		return $validated;
	}

	/**
	 * Build a user-facing error message based on stored details.
	 *
	 * @param array{code:?string,context:array<string,mixed>}|null $details Error details.
	 * @return string Localized user-facing message.
	 */
	public static function get_user_error_message( $details = null ) {
		if ( null === $details ) {
			$details = self::$last_error_details;
		}

		$code    = $details['code'] ?? null;
		$context = $details['context'] ?? array();

		switch ( $code ) {
			case 'url_required':
				return \__( 'Please enter the Etch target URL.', 'etch-fusion-suite' );
			case 'url_invalid_format':
				return \__( 'The URL looks invalid. Double-check the address and try again.', 'etch-fusion-suite' );
			case 'url_invalid_scheme':
				return \__( 'Only http and https URLs are supported. Please update the address.', 'etch-fusion-suite' );
			case 'text_required':
				return \__( 'This field cannot be empty.', 'etch-fusion-suite' );
			case 'text_max_length':
				$max_length = isset( $context['max_length'] ) ? absint( $context['max_length'] ) : 0;
				if ( $max_length > 0 ) {
					// translators: %d - maximum allowed characters.
					return sprintf( \__( 'Please shorten the text to %d characters or fewer.', 'etch-fusion-suite' ), $max_length );
				}
				return \__( 'Please shorten the text value.', 'etch-fusion-suite' );
			case 'integer_required':
				return \__( 'A numeric value is required.', 'etch-fusion-suite' );
			case 'integer_type_mismatch':
				return \__( 'Please enter a numeric value.', 'etch-fusion-suite' );
			case 'integer_below_min':
				$min = isset( $context['min'] ) ? absint( $context['min'] ) : null;
				if ( null !== $min ) {
					// translators: %d - minimum allowed value.
					return sprintf( \__( 'Value must be at least %d.', 'etch-fusion-suite' ), $min );
				}
				return \__( 'Value is too low.', 'etch-fusion-suite' );
			case 'integer_above_max':
				$max = isset( $context['max'] ) ? absint( $context['max'] ) : null;
				if ( null !== $max ) {
					// translators: %d - maximum allowed value.
					return sprintf( \__( 'Value must be %d or less.', 'etch-fusion-suite' ), $max );
				}
				return \__( 'Value is too high.', 'etch-fusion-suite' );
			case 'array_required':
				return \__( 'A selection is required.', 'etch-fusion-suite' );
			case 'array_type_mismatch':
				return \__( 'Unexpected data format. Please try again.', 'etch-fusion-suite' );
			case 'array_invalid_key':
				return \__( 'One or more options are not supported.', 'etch-fusion-suite' );
			case 'json_required':
				return \__( 'JSON input is required.', 'etch-fusion-suite' );
			case 'json_invalid':
				return \__( 'The JSON data could not be read. Please check the format.', 'etch-fusion-suite' );
			case 'api_key_required':
				return \__( 'Please enter your Etch API key.', 'etch-fusion-suite' );
			case 'api_key_min_length':
				return \__( 'The API key seems incomplete. Copy the full key from Etch.', 'etch-fusion-suite' );
			case 'api_key_invalid_characters':
				return \__( 'The API key contains unsupported characters. Copy it again from Etch.', 'etch-fusion-suite' );
			case 'token_required':
				return \__( 'Please enter the migration token from Etch.', 'etch-fusion-suite' );
			case 'token_min_length':
				return \__( 'The migration token looks incomplete. Copy the full token from Etch.', 'etch-fusion-suite' );
			case 'token_invalid_characters':
				return \__( 'The migration token contains unsupported characters. Copy it again from Etch.', 'etch-fusion-suite' );
			case 'post_not_found':
				return \__( 'We could not find the selected WordPress post.', 'etch-fusion-suite' );
			case self::FALLBACK_ERROR_CODE:
				return \__( 'One or more fields need attention. Please review the form.', 'etch-fusion-suite' );
		}

		return \__( 'Some inputs need review. Please check your entries and try again.', 'etch-fusion-suite' );
	}
}
