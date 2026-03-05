<?php
/**
 * Error Handler for Etch Fusion Suite
 *
 * Handles all errors, warnings, logging, and error code definitions for the migration process.
 *
 * @package Bricks2Etch\Core
 */

namespace Bricks2Etch\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EFS_Error_Handler
 *
 * Handles plugin errors, warnings, logging, and provides error code information.
 */
class EFS_Error_Handler {
	/**
	 * Maximum number of migration log entries stored in the wp option.
	 */
	const MAX_LOG_ENTRIES = 1000;

	/**
	 * Error codes with descriptions and solutions
	 */
	const ERROR_CODES = array(
		// Content Errors (E0xx)
		'E001' => array(
			'title'       => 'Missing Media File',
			'description' => 'Image or media file referenced in Bricks content not found',
			'solution'    => 'Check if the media file exists in the source site media library',
		),
		'E002' => array(
			'title'       => 'Invalid CSS Syntax',
			'description' => 'CSS syntax error detected in Bricks global class',
			'solution'    => 'Auto-fix attempted. Review the migrated CSS for accuracy',
		),
		'E003' => array(
			'title'       => 'Unsupported Bricks Element',
			'description' => 'Bricks-specific element cannot be automatically migrated',
			'solution'    => 'Recreate this element manually in Etch (slider, accordion, etc.)',
		),
		'E004' => array(
			'title'       => 'Dynamic Data Tag Not Mappable',
			'description' => 'Bricks dynamic data tag has no Etch equivalent',
			'solution'    => 'Manually update the dynamic data reference in Etch',
		),
		'E005' => array(
			'title'       => 'Custom Field Not Found',
			'description' => 'ACF or custom field referenced but not found',
			'solution'    => 'Check if the custom field exists in the source site and is properly configured',
		),

		// API Errors (E1xx)
		'E101' => array(
			'title'       => 'Invalid Bricks Content Structure',
			'description' => 'Bricks page content is not in expected array format',
			'solution'    => 'Check if _bricks_page_content_2 contains valid serialized array',
		),
		'E102' => array(
			'title'       => 'Bricks Page Validation Failed',
			'description' => 'Page does not have required Bricks meta keys',
			'solution'    => 'Verify _bricks_template_type and _bricks_editor_mode are set',
		),
		'E103' => array(
			'title'       => 'API Connection Failed',
			'description' => 'Unable to connect to target site API',
			'solution'    => 'Check API URL, verify plugin is installed on target site',
		),
		'E104' => array(
			'title'       => 'API Key Expired',
			'description' => 'API key has exceeded 8-hour validity period',
			'solution'    => 'Generate a new API key and retry the migration',
		),
		'E105' => array(
			'title'       => 'API Request Timeout',
			'description' => 'API request exceeded timeout limit',
			'solution'    => 'Increase timeout setting or check server resources',
		),
		'E106' => array(
			'title'       => 'CSS Import to Target Failed',
			'description' => 'The converted CSS styles could not be imported to the target site API',
			'solution'    => 'Check target site connectivity and verify the migration key is still valid; re-run the migration to retry',
		),
		'E107' => array(
			'title'       => 'Failed to Send Post to Target',
			'description' => 'Post could not be delivered to the target site after all retry attempts',
			'solution'    => 'Check target site connectivity, verify the migration key is valid, then re-run or migrate the post individually',
		),
		'E108' => array(
			'title'       => 'Post Type Not Mapped to Target',
			'description' => 'A post\'s post type has no corresponding mapping on the target site',
			'solution'    => 'Ensure the required post type exists on the target site; run the CPT migrator before the content migration',
		),

		// Migration Process Errors (E2xx)
		'E201' => array(
			'title'       => 'Post Creation Failed',
			'description' => 'Failed to create post on target site',
			'solution'    => 'Check target site permissions and database connectivity',
		),
		'E202' => array(
			'title'       => 'CSS Conversion Failed',
			'description' => 'Failed to convert Bricks CSS to Etch format',
			'solution'    => 'Review CSS syntax and try manual conversion',
		),
		'E203' => array(
			'title'       => 'Dynamic Data Conversion Failed',
			'description' => 'Failed to convert Bricks dynamic data tags',
			'solution'    => 'Check dynamic data syntax and Etch compatibility',
		),

		// Custom Fields & Post Meta Errors
		'E301' => array(
			'title'       => 'Custom Field Migration Failed',
			'description' => 'Failed to migrate custom field data for post',
			'solution'    => 'Check custom field plugin compatibility and data structure',
		),
		'E302' => array(
			'title'       => 'ACF Field Group Import Failed',
			'description' => 'Failed to import ACF field group configuration',
			'solution'    => 'Verify ACF plugin is active and field group data is valid',
		),

		// Media Migration Errors
		'E401' => array(
			'title'       => 'Media File Migration Failed',
			'description' => 'Failed to migrate media file to target site',
			'solution'    => 'Check file permissions and target site storage capacity',
		),
		'E402' => array(
			'title'       => 'Media File Download Failed',
			'description' => 'Failed to download media file from source site',
			'solution'    => 'Check file URL accessibility and network connectivity',
		),
		'E403' => array(
			'title'       => 'Media File Upload Failed',
			'description' => 'Failed to upload media file to target site',
			'solution'    => 'Check target site upload permissions and file size limits',
		),

		// Service-level Exception Errors (E9xx)
		'E905' => array(
			'title'       => 'Media Migration Service Exception',
			'description' => 'An unexpected exception occurred in the media migration service',
			'solution'    => 'Check the error context for the exception message; ensure WP filesystem permissions are correct and retry the migration',
		),
		'E906' => array(
			'title'       => 'CSS Conversion Service Exception',
			'description' => 'An unexpected exception occurred while converting Bricks global CSS classes',
			'solution'    => 'Check the error context for the exception message; inspect the Bricks global class data for malformed CSS',
		),
		'E907' => array(
			'title'       => 'CSS Element Style Collection Exception',
			'description' => 'An unexpected exception occurred while collecting element-level inline styles',
			'solution'    => 'Check the error context for the exception message; re-run the migration to retry the CSS collection phase',
		),

		// Info codes (I0xx) - Success messages
		'I020' => array(
			'title'       => 'No Bricks Content Found',
			'description' => 'No Bricks content found for conversion',
			'solution'    => 'Skip this post - no conversion needed',
		),
		'I021' => array(
			'title'       => 'Failed to Parse Bricks Elements',
			'description' => 'Failed to parse Bricks elements',
			'solution'    => 'Check Bricks content structure',
		),
		'I022' => array(
			'title'       => 'Failed to Generate Gutenberg Blocks',
			'description' => 'Failed to generate Gutenberg blocks',
			'solution'    => 'Check element conversion logic',
		),
		'I023' => array(
			'title'       => 'Bricks to Gutenberg Conversion Successful',
			'description' => 'Bricks converted to Gutenberg and saved to database',
			'solution'    => 'Etch will process the blocks automatically',
		),
		'I024' => array(
			'title'       => 'Failed to Save Gutenberg Content',
			'description' => 'Failed to save Gutenberg content to database',
			'solution'    => 'Check database permissions and post ID',
		),

		// Migration Manager Info Codes
		'I001' => array(
			'title'       => 'Migration Initialized',
			'description' => 'Migration process initialized successfully',
			'solution'    => 'Migration is ready to start',
		),
		'I002' => array(
			'title'       => 'Target Site Validated',
			'description' => 'Target site requirements validated successfully',
			'solution'    => 'Target site is ready for migration',
		),
		'I003' => array(
			'title'       => 'Bricks Content Analyzed',
			'description' => 'Bricks content analyzed successfully',
			'solution'    => 'Content is ready for migration',
		),
		'I004' => array(
			'title'       => 'Custom Post Types Migrated',
			'description' => 'Custom post types migrated successfully',
			'solution'    => 'Post types are ready on target site',
		),
		'I005' => array(
			'title'       => 'ACF Field Groups Migrated',
			'description' => 'ACF field groups migrated successfully',
			'solution'    => 'Field groups are ready on target site',
		),
		'I006' => array(
			'title'       => 'MetaBox Configurations Migrated',
			'description' => 'MetaBox configurations migrated successfully',
			'solution'    => 'MetaBox configs are ready on target site',
		),
		'I007' => array(
			'title'       => 'Media Files Migrated',
			'description' => 'Media files migrated successfully',
			'solution'    => 'Media files are ready on target site',
		),
		'I008' => array(
			'title'       => 'CSS Classes Converted',
			'description' => 'CSS classes converted successfully',
			'solution'    => 'CSS classes are ready for Etch',
		),
		'I009' => array(
			'title'       => 'Posts and Content Migrated',
			'description' => 'Posts and content migrated successfully',
			'solution'    => 'Content is ready for Etch processing',
		),
	);

	/**
	 * Warning codes with descriptions
	 */
	const WARNING_CODES = array(
		'W001' => array(
			'title'       => 'Non-Bricks Page Skipped',
			'description' => 'Page does not appear to be a Bricks page and was skipped',
			'solution'    => 'Verify page was created with Bricks Builder',
		),
		'W002' => array(
			'title'       => 'Plugin Not Active',
			'description' => 'Required plugin is not active on target site',
			'solution'    => 'Install and activate the required plugin',
		),
		'W003' => array(
			'title'       => 'Post Type Already Exists',
			'description' => 'Custom post type already exists on target site',
			'solution'    => 'Post type will be skipped or updated',
		),
		'W004' => array(
			'title'       => 'Media Migration Completed',
			'description' => 'Media files migration process completed',
			'solution'    => 'Review migration results for any failed files',
		),
		'W005' => array(
			'title'       => 'Dynamic Data Tag Not Mappable',
			'description' => 'Bricks dynamic data tag has no Etch equivalent; raw syntax preserved',
			'solution'    => 'Manually update the dynamic data reference in Etch',
		),
		'W006' => array(
			'title'       => '.brxe-block Display Fallback Applied',
			'description' => 'Layout fallback fired for .brxe-block; check migrated layout',
			'solution'    => 'Review the migrated element display and flex-direction settings',
		),
		'W007' => array(
			'title'       => 'Loop Not Converted',
			'description' => 'Unsupported Bricks loop query shape; placeholder inserted',
			'solution'    => 'Manually recreate loop query in Etch',
		),
		'W008' => array(
			'title'       => 'Condition Not Converted',
			'description' => 'Unsupported Bricks condition; placeholder inserted',
			'solution'    => 'Manually recreate condition logic in Etch',
		),
		'W009' => array(
			'title'       => 'Post Type Mismatch on Idempotent Upsert',
			'description' => 'Existing post found via source-ID mapping but requested post_type does not exist on target; post_type kept unchanged to avoid duplicate',
			'solution'    => 'Register the required post type on the target site, then re-run the migration',
		),
		'W010' => array(
			'title'       => 'Post Send Failed - Will Retry',
			'description' => 'Post conversion/send failed; will be retried',
			'solution'    => 'Automatic retry scheduled; check logs if post ultimately fails',
		),
		'W011' => array(
			'title'       => 'Post Failed After Max Retries',
			'description' => 'Post exhausted all retry attempts and was skipped',
			'solution'    => 'Review the post manually and re-run or migrate it individually',
		),
		'W012' => array(
			'title'       => 'Media Failed After Max Retries',
			'description' => 'Media item exhausted all retry attempts and was skipped',
			'solution'    => 'Review the media item manually and re-run or migrate it individually',
		),
		'W013' => array(
			'title'       => 'Component Skipped Due to Missing Dependency',
			'description' => 'Component requires a parent element that was not found',
			'solution'    => 'Review the component context; ensure parent elements are properly structured',
		),
		'W014' => array(
			'title'       => 'Optional Migrator Failed',
			'description' => 'An optional migrator (ACF, MetaBox, or Custom Fields) failed; migration continued',
			'solution'    => 'Review the warning context for the migrator type and error message; re-run or configure the plugin on the target site',
		),

		// Component Migrator Warnings (W4xx)
		'W401' => array(
			'title'       => 'Component Skipped',
			'description' => 'Bricks component not migrated due to compatibility issues',
			'solution'    => 'Manually recreate this component in Etch if needed',
		),

		// Custom Warnings (W9xx)
		'W900' => array(
			'title'       => 'Custom Element Conversion Attempted',
			'description' => 'An element marked as custom was converted using fallback logic',
			'solution'    => 'Review the converted element to ensure correct structure and styling',
		),
	);

	/**
	 * Handle error
	 *
	 * @param string $message Error message.
	 * @param string $level   Error level (warning, error, critical).
	 */
	public function handle( $message, $level = 'error' ) {
		error_log( "EFS [{$level}]: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Get error information by code
	 *
	 * @param string $code Error code.
	 * @return array|null Error information array with title, description, solution, or null if not found.
	 */
	public function get_error_info( $code ) {
		return isset( self::ERROR_CODES[ $code ] ) ? self::ERROR_CODES[ $code ] : null;
	}

	/**
	 * Get warning information by code
	 *
	 * @param string $code Warning code.
	 * @return array|null Warning information array with title, description, solution, or null if not found.
	 */
	public function get_warning_info( $code ) {
		return isset( self::WARNING_CODES[ $code ] ) ? self::WARNING_CODES[ $code ] : null;
	}

	/**
	 * Debug log - logs detailed information when WP_DEBUG is enabled
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data    Optional data to log.
	 * @param string $level   Optional log level label.
	 */
	public function debug_log( $message, $data = null, $level = 'debug' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$level = strtoupper( sanitize_key( (string) $level ) );
			$level = '' !== $level ? $level : 'DEBUG';
			error_log( "EFS [{$level}]: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( $data ) {
				error_log( wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Log info message - for informational logging during migration
	 *
	 * @param string $message Message to log.
	 * @param mixed  $data    Optional data to log.
	 */
	public function log_info( $message, $data = null ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "EFS [INFO]: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			if ( $data ) {
				error_log( wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * Log warning entry to migration log option.
	 *
	 * @param string $code    Warning code or short message.
	 * @param mixed  $context Optional context payload.
	 * @param string $level   Optional level label. Defaults to warning.
	 * @return void
	 */
	public function log_warning( $code, $context = array(), $level = 'warning' ) {
		$warning_info = $this->get_warning_info( (string) $code );
		$title        = isset( $warning_info['title'] ) ? $warning_info['title'] : (string) $code;
		$message      = isset( $warning_info['description'] ) ? $warning_info['description'] : (string) $code;

		$this->append_log_entry(
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'warning',
				'level'     => (string) $level,
				'code'      => (string) $code,
				'title'     => $title,
				'message'   => $message,
				'context'   => $this->normalize_context( $context ),
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "EFS [WARNING] {$code}: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Log error entry to migration log option.
	 *
	 * @param string $code    Error code or short message.
	 * @param mixed  $context Optional context payload.
	 * @param string $level   Optional level label. Defaults to error.
	 * @return void
	 */
	public function log_error( $code, $context = array(), $level = 'error' ) {
		$error_info = $this->get_error_info( (string) $code );
		$title      = isset( $error_info['title'] ) ? $error_info['title'] : (string) $code;
		$message    = isset( $error_info['description'] ) ? $error_info['description'] : (string) $code;

		$this->append_log_entry(
			array(
				'timestamp' => current_time( 'mysql' ),
				'type'      => 'error',
				'level'     => (string) $level,
				'code'      => (string) $code,
				'title'     => $title,
				'message'   => $message,
				'context'   => $this->normalize_context( $context ),
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "EFS [ERROR] {$code}: {$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Normalize context to an array-safe payload for option storage.
	 *
	 * @param mixed $context Context payload.
	 * @return array
	 */
	private function normalize_context( $context ) {
		if ( is_array( $context ) ) {
			return $context;
		}

		if ( is_object( $context ) ) {
			$encoded = wp_json_encode( $context );
			$decoded = $encoded ? json_decode( $encoded, true ) : null;
			return is_array( $decoded ) ? $decoded : array( 'value' => (string) $encoded );
		}

		if ( null === $context ) {
			return array();
		}

		return array( 'value' => $context );
	}

	/**
	 * Append one entry to the migration log option with bounded retention.
	 *
	 * @param array $entry Log entry.
	 * @return void
	 */
	private function append_log_entry( array $entry ) {
		$logs = get_option( 'efs_migration_log', array() );
		$logs = is_array( $logs ) ? $logs : array();

		array_unshift( $logs, $entry );
		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( 'efs_migration_log', $logs, false );
	}

	/**
	 * Get recent migration logs from WP option
	 *
	 * @return array Array of recent log entries.
	 */
	public function get_recent_logs() {
		return get_option( 'efs_migration_log', array() );
	}

	/**
	 * Clear migration logs from WP option
	 *
	 * @return bool True if successful.
	 */
	public function clear_log() {
		return delete_option( 'efs_migration_log' );
	}
}
