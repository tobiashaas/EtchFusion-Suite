<?php
/**
 * Error Handler for Etch Fusion Suite
 *
 * Handles all errors, warnings, and logging for the migration process
 */

namespace Bricks2Etch\Core;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Error_Handler {

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
	);

	/**
	 * Log an error
	 *
	 * @param string $code Error code
	 * @param array $context Additional context data
	 */
	public function log_error( $code, $context = array() ) {
		// Handle missing error codes gracefully
		if ( ! isset( self::ERROR_CODES[ $code ] ) ) {
			$error_info = array(
				'title'       => 'Unknown Error',
				'description' => 'An unknown error occurred',
				'solution'    => 'Please check the logs for more details',
			);
		} else {
			$error_info = self::ERROR_CODES[ $code ];
		}

		$log_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'type'        => 'error',
			'code'        => $code,
			'title'       => $error_info['title'],
			'description' => $error_info['description'],
			'solution'    => $error_info['solution'],
			'context'     => $context,
		);

		$this->add_to_log( $log_entry );

		// Also log to WordPress error log
		error_log(
			sprintf(
				'[Etch Fusion Suite] %s: %s - %s',
				$code,
				$error_info['title'],
				$error_info['description']
			)
		);
	}

	/**
	 * Log a warning
	 *
	 * @param string $code Warning code
	 * @param array $context Additional context data
	 */
	public function log_warning( $code, $context = array() ) {
		// Handle missing warning codes gracefully
		if ( ! isset( self::WARNING_CODES[ $code ] ) ) {
			$warning_info = array(
				'title'       => 'Unknown Warning',
				'description' => 'An unknown warning occurred',
				'solution'    => 'Please check the logs for more details',
			);
		} else {
			$warning_info = self::WARNING_CODES[ $code ];
		}

		$log_entry = array(
			'timestamp'   => current_time( 'mysql' ),
			'type'        => 'warning',
			'code'        => $code,
			'title'       => $warning_info['title'],
			'description' => $warning_info['description'],
			'solution'    => $warning_info['solution'],
			'context'     => $context,
		);

		$this->add_to_log( $log_entry );
	}

	/**
	 * Add entry to migration log
	 *
	 * @param array $log_entry Log entry data
	 */
	private function add_to_log( $log_entry ) {
		$log   = get_option( 'efs_migration_log', array() );
		$log[] = $log_entry;

		// Keep only last 1000 entries
		if ( count( $log ) > 1000 ) {
			$log = array_slice( $log, -1000 );
		}

		update_option( 'efs_migration_log', $log );
	}

	/**
	 * Get migration log
	 *
	 * @param string $type Filter by type (error, warning, all)
	 * @return array
	 */
	public function get_log( $type = 'all' ) {
		$log = get_option( 'efs_migration_log', array() );

		if ( 'all' !== $type ) {
			$log = array_filter(
				$log,
				function ( $entry ) use ( $type ) {
					return $entry['type'] === $type;
				}
			);
		}

		return $log;
	}

	/**
	 * Get recent log entries
	 *
	 * @param int $limit Number of entries to return
	 * @return array
	 */
	public function get_recent_logs( $limit = 50 ) {
		$limit = absint( $limit );
		if ( $limit <= 0 ) {
			$limit = 50;
		}

		$log = $this->get_log( 'all' );
		if ( empty( $log ) ) {
			return array();
		}

		return array_slice( $log, -1 * $limit );
	}

	/**
	 * Clear migration log
	 */
	public function clear_log() {
		// Clear WordPress debug log
		if ( file_exists( '/var/www/html/wp-content/debug.log' ) ) {
			file_put_contents( '/var/www/html/wp-content/debug.log', '' );
		}

		// Clear migration log option
		delete_option( 'efs_migration_log' );

		return true;
	}

	/**
	 * Get error information by code
	 *
	 * @param string $code Error code
	 * @return array|null
	 */
	public function get_error_info( $code ) {
		return isset( self::ERROR_CODES[ $code ] ) ? self::ERROR_CODES[ $code ] : null;
	}

	/**
	 * Get warning information by code
	 *
	 * @param string $code Warning code
	 * @return array|null
	 */
	public function get_warning_info( $code ) {
		return isset( self::WARNING_CODES[ $code ] ) ? self::WARNING_CODES[ $code ] : null;
	}

	/**
	 * Development debug helper - logs detailed information when WP_DEBUG is enabled
	 *
	 * @param string $message Debug message
	 * @param mixed $data Optional data to log
	 * @param string $context Context identifier (default: EFS_DEBUG)
	 */
	public function debug_log( $message, $data = null, $context = 'EFS_DEBUG' ) {
		if ( ! WP_DEBUG || ! WP_DEBUG_LOG ) {
			return;
		}

		$log_message = sprintf(
			'[%s] %s: %s',
			$context,
			current_time( 'Y-m-d H:i:s' ),
			$message
		);

		if ( null !== $data ) {
			$log_message .= ' | Data: ' . wp_json_encode( $data );
		}

		error_log( $log_message );
	}
}
