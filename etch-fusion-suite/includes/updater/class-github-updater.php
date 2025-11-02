<?php
/**
 * GitHub Updater Class
 *
 * Handles plugin updates from GitHub releases using WordPress update API.
 *
 * @package    Bricks2Etch
 * @subpackage Updater
 * @since      0.10.3
 */

namespace Bricks2Etch\Updater;

use Bricks2Etch\Core\EFS_Error_Handler;
use WP_Error;
use stdClass;

/**
 * Class EFS_GitHub_Updater
 *
 * Integrates with WordPress update system to fetch plugin updates from GitHub releases.
 * Uses transient caching to minimize API requests and follows the plugin's service container pattern.
 *
 * @since 0.10.3
 */
class EFS_GitHub_Updater {

	/**
	 * Error handler instance for logging
	 *
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	/**
	 * Plugin header data (requires, tested, etc.)
	 *
	 * @var array
	 */
	private $plugin_headers;

	/**
	 * Full path to main plugin file
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin basename (e.g., 'etch-fusion-suite/etch-fusion-suite.php')
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Plugin slug (e.g., 'etch-fusion-suite')
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * GitHub repository owner
	 *
	 * @var string
	 */
	private $github_repo_owner;

	/**
	 * GitHub repository name
	 *
	 * @var string
	 */
	private $github_repo_name;

	/**
	 * Transient cache key
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * Cache duration in seconds (default: 12 hours)
	 *
	 * @var int
	 */
	private $cache_expiration;

	/**
	 * Constructor
	 *
	 * @param EFS_Error_Handler $error_handler Error handler instance.
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;

		// Initialize properties from constants.
		$this->plugin_file     = ETCH_FUSION_SUITE_FILE;
		$this->plugin_basename = ETCH_FUSION_SUITE_BASENAME;
		$this->plugin_slug     = dirname( $this->plugin_basename );

		// Default GitHub repository settings.
		$this->github_repo_owner = apply_filters( 'etch_fusion_suite_github_updater_repo_owner', 'tobiashaas' );
		$this->github_repo_owner = apply_filters_deprecated(
			'efs_github_updater_repo_owner',
			array( $this->github_repo_owner ),
			'0.11.27',
			'etch_fusion_suite_github_updater_repo_owner'
		);

		$this->github_repo_name = apply_filters( 'etch_fusion_suite_github_updater_repo_name', 'EtchFusion-Suite' );
		$this->github_repo_name = apply_filters_deprecated(
			'efs_github_updater_repo_name',
			array( $this->github_repo_name ),
			'0.11.27',
			'etch_fusion_suite_github_updater_repo_name'
		);

		// Cache settings.
		$this->cache_key        = 'efs_github_update_data';
		$this->cache_expiration = apply_filters( 'etch_fusion_suite_github_updater_cache_expiration', 43200 ); // 12 hours.
		$this->cache_expiration = apply_filters_deprecated(
			'efs_github_updater_cache_expiration',
			array( $this->cache_expiration ),
			'0.11.27',
			'etch_fusion_suite_github_updater_cache_expiration'
		);

		// Read plugin headers for version requirements.
		$this->plugin_headers = $this->read_plugin_headers();
	}

	/**
	 * Initialize updater by registering WordPress hooks
	 *
	 * @since 0.10.3
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), 10, 1 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'secure_download_handler' ), 10, 3 );
	}

	/**
	 * Check for plugin updates from GitHub
	 *
	 * @param object $transient Update transient object.
	 * @return object Modified transient object.
	 */
	public function check_for_update( $transient ) {
		// Early return if transient is empty or checked property not set.
		if ( empty( $transient ) || ! isset( $transient->checked ) ) {
			return $transient;
		}

		// Get installed version.
		$installed_version = isset( $transient->checked[ $this->plugin_basename ] )
			? $transient->checked[ $this->plugin_basename ]
			: ETCH_FUSION_SUITE_VERSION;

		// Fetch remote version data.
		$remote_data = $this->get_remote_version();

		// Return unchanged if remote data is error or empty.
		if ( is_wp_error( $remote_data ) || empty( $remote_data ) ) {
			return $transient;
		}

		// Validate download URL before proceeding.
		if ( empty( $remote_data['download_url'] ) ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'message' => 'Download URL is empty, cannot advertise update',
					'version' => $remote_data['version'],
				)
			);
			return $transient;
		}

		// Compare versions.
		if ( version_compare( $installed_version, $remote_data['version'], '<' ) ) {
			// Update available - create update object.
			$update              = new stdClass();
			$update->slug        = $this->plugin_slug;
			$update->plugin      = $this->plugin_basename;
			$update->new_version = $remote_data['version'];
			$update->url         = 'https://github.com/' . $this->github_repo_owner . '/' . $this->github_repo_name;
			$update->package     = $remote_data['download_url'];
			$update->tested      = $this->plugin_headers['tested'];
			$update->requires    = $this->plugin_headers['requires'];
			$update->icons       = array(
				'default' => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/icon-256x256.png',
			);
			$update->banners     = array(
				'low'  => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/banner-772x250.png',
				'high' => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/banner-1544x500.png',
			);

			// Add to response.
			$transient->response[ $this->plugin_basename ] = $update;
		} else {
			// No update available - optionally set no_update for clarity.
			$no_update              = new stdClass();
			$no_update->slug        = $this->plugin_slug;
			$no_update->plugin      = $this->plugin_basename;
			$no_update->new_version = $remote_data['version'];
			$no_update->url         = 'https://github.com/' . $this->github_repo_owner . '/' . $this->github_repo_name;
			$no_update->package     = $remote_data['download_url'];
			$no_update->tested      = $this->plugin_headers['tested'];
			$no_update->requires    = $this->plugin_headers['requires'];
			$no_update->icons       = array(
				'default' => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/icon-256x256.png',
			);

			$transient->no_update[ $this->plugin_basename ] = $no_update;
		}

		return $transient;
	}

	/**
	 * Provide plugin information for "View details" modal
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array Modified result.
	 */
	public function plugin_info( $result, $action, $args ) {
		// Only handle plugin_information requests.
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		// Only handle requests for this plugin.
		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		// Fetch remote version data.
		$remote_data = $this->get_remote_version();

		// Return unchanged if remote data is error or empty.
		if ( is_wp_error( $remote_data ) || empty( $remote_data ) ) {
			return $result;
		}

		// Create plugin info object.
		$info                = new stdClass();
		$info->name          = 'Etch Fusion Suite';
		$info->slug          = $this->plugin_slug;
		$info->version       = $remote_data['version'];
		$info->author        = '<a href="https://github.com/tobiashaas">Tobias Haas</a>';
		$info->homepage      = 'https://github.com/' . $this->github_repo_owner . '/' . $this->github_repo_name;
		$info->requires      = $this->plugin_headers['requires'];
		$info->tested        = $this->plugin_headers['tested'];
		$info->download_link = $remote_data['download_url'];
		$info->external      = true;

		// Build sections.
		$info->sections = array(
			'description'  => 'A comprehensive migration tool for converting Bricks Builder sites to Etch theme.',
			'installation' => 'Download the plugin, upload to WordPress, and activate. Navigate to Tools > Etch Fusion Suite to begin migration.',
			'changelog'    => ! empty( $remote_data['changelog'] ) ? $this->parse_changelog( $remote_data['changelog'] ) : 'No changelog available.',
		);

		// Add banners and icons.
		$info->banners = array(
			'low'  => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/banner-772x250.png',
			'high' => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/banner-1544x500.png',
		);
		$info->icons   = array(
			'default' => 'https://raw.githubusercontent.com/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/main/assets/icon-256x256.png',
		);

		return $info;
	}

	/**
	 * Purge cache after plugin update
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $options  Update options.
	 */
	public function purge_cache( $upgrader, $options ) {
		// Check if this is a plugin update.
		if ( ! isset( $options['action'] ) || 'update' !== $options['action'] ) {
			return;
		}

		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		// Check if this plugin was updated.
		if ( isset( $options['plugins'] ) && is_array( $options['plugins'] ) ) {
			if ( in_array( $this->plugin_basename, $options['plugins'], true ) ) {
				delete_transient( $this->cache_key );
			}
		}
	}

	/**
	 * Get remote version data from GitHub API
	 *
	 * @return array|WP_Error Release data or error.
	 */
	private function get_remote_version() {
		// Check transient cache.
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached && ! is_wp_error( $cached ) ) {
			return $cached;
		}

		// Build GitHub API URL.
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			$this->github_repo_owner,
			$this->github_repo_name
		);

		// Make HTTP request.
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				),
			)
		);

		// Handle WP_Error.
		if ( is_wp_error( $response ) ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);
			return $response;
		}

		// Check response code.
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$error = new WP_Error(
				'github_api_error',
				sprintf( 'GitHub API returned status code %d', $response_code )
			);
			$this->error_handler->log_error(
				'E103',
				array(
					'url'           => $url,
					'response_code' => $response_code,
				)
			);
			return $error;
		}

		// Decode JSON response.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Handle JSON decode errors.
		if ( null === $data || JSON_ERROR_NONE !== json_last_error() ) {
			$error = new WP_Error(
				'json_decode_error',
				'Failed to decode GitHub API response'
			);
			$this->error_handler->log_error(
				'E103',
				array(
					'url'        => $url,
					'json_error' => json_last_error_msg(),
				)
			);
			return $error;
		}

		// Parse release data.
		$version      = $this->parse_version_from_tag( $data['tag_name'] ?? '' );
		$download_url = $this->get_download_url( $data );
		$changelog    = $data['body'] ?? '';
		$published_at = $data['published_at'] ?? '';

		// Handle version parsing errors.
		if ( is_wp_error( $version ) ) {
			$this->error_handler->log_error(
				'E103',
				array(
					'message'  => 'Invalid or missing version tag',
					'tag_name' => $data['tag_name'] ?? '',
					'error'    => $version->get_error_message(),
				)
			);
			return $version;
		}

		// Build data array.
		$release_data = array(
			'version'      => $version,
			'download_url' => $download_url,
			'changelog'    => $changelog,
			'published_at' => $published_at,
		);

		// Cache data.
		set_transient( $this->cache_key, $release_data, $this->cache_expiration );

		return $release_data;
	}

	/**
	 * Parse version from GitHub tag name
	 *
	 * @param string $tag_name Tag name from GitHub release.
	 * @return string|WP_Error Sanitized version string or error.
	 */
	private function parse_version_from_tag( $tag_name ) {
		// Strip 'v' prefix.
		$version = ltrim( $tag_name, 'v' );

		// Validate semantic version format (basic validation).
		if ( preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			return $version;
		}

		// Return error instead of fallback version.
		return new WP_Error(
			'invalid_version_tag',
			sprintf( 'Invalid version tag format: %s', $tag_name )
		);
	}

	/**
	 * Get download URL from release data
	 *
	 * @param array $release_data GitHub release data.
	 * @return string Download URL.
	 */
	private function get_download_url( $release_data ) {
		$url = '';

		// Check for assets with .zip extension (PHP 7.4 compatible).
		if ( isset( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			foreach ( $release_data['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && 0 === substr_compare( $asset['name'], '.zip', -4 ) ) {
					$url = $asset['browser_download_url'] ?? '';
					break;
				}
			}
		}

		// Fallback to zipball_url.
		if ( empty( $url ) && isset( $release_data['zipball_url'] ) ) {
			$url = $release_data['zipball_url'];
		}

		// Apply filter for extensibility.
		$url = apply_filters( 'etch_fusion_suite_github_updater_download_url', $url, $release_data );
		$url = apply_filters_deprecated(
			'efs_github_updater_download_url',
			array( $url, $release_data ),
			'0.11.27',
			'etch_fusion_suite_github_updater_download_url'
		);

		return $url;
	}

	/**
	 * Parse changelog from GitHub release body
	 *
	 * @param string $body Release body in markdown format.
	 * @return string Formatted changelog HTML.
	 */
	private function parse_changelog( $body ) {
		// Basic markdown to HTML conversion for changelog.
		$changelog = wpautop( $body );

		// Convert markdown headers to HTML.
		$changelog = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $changelog );
		$changelog = preg_replace( '/^## (.+)$/m', '<h2>$1</h2>', $changelog );
		$changelog = preg_replace( '/^# (.+)$/m', '<h1>$1</h1>', $changelog );

		return $changelog;
	}

	/**
	 * Check if URL is for this plugin's repository
	 *
	 * Validates that the URL belongs to this plugin's repository on either:
	 * - github.com (release assets via browser_download_url)
	 * - api.github.com (zipball downloads via zipball_url)
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL is for this plugin's repo.
	 */
	private function is_repo_url_for_this_plugin( $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['host'] ) || ! isset( $parsed['path'] ) ) {
			return false;
		}

		$host = $parsed['host'];
		$path = $parsed['path'];

		// Check github.com URLs (e.g., release assets).
		if ( 'github.com' === $host ) {
			$expected_prefix = '/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/';
			return 0 === strpos( $path, $expected_prefix );
		}

		// Check api.github.com URLs (e.g., zipball downloads).
		if ( 'api.github.com' === $host ) {
			$expected_prefix = '/repos/' . $this->github_repo_owner . '/' . $this->github_repo_name . '/';
			return 0 === strpos( $path, $expected_prefix );
		}

		return false;
	}

	/**
	 * Secure download handler for plugin updates
	 *
	 * Validates download URL and sets secure request parameters.
	 * Supports both github.com release assets (browser_download_url) and
	 * api.github.com zipball downloads (zipball_url fallback).
	 *
	 * @param false|string $reply      Whether to bail without returning the package (default: false).
	 * @param string       $package    The package URL.
	 * @param object       $upgrader   The upgrader instance.
	 * @return false|string|WP_Error Modified reply or error.
	 */
	public function secure_download_handler( $reply, $package, $upgrader ) {
		// Only handle downloads for this plugin's repository.
		if ( ! $this->is_repo_url_for_this_plugin( $package ) ) {
			return $reply;
		}

		// Validate URL is from GitHub over HTTPS.
		if ( 0 !== strpos( $package, 'https://github.com/' ) && 0 !== strpos( $package, 'https://api.github.com/' ) ) {
			$error = new WP_Error(
				'invalid_download_url',
				'Download URL must be from GitHub over HTTPS'
			);
			$this->error_handler->log_error(
				'E103',
				array(
					'message' => 'Invalid download URL',
					'url'     => $package,
				)
			);
			return $error;
		}

		// Add secure download filter.
		add_filter(
			'http_request_args',
			array( $this, 'secure_download_request_args' ),
			10,
			2
		);

		return $reply;
	}

	/**
	 * Modify HTTP request args for secure downloads
	 *
	 * Injects User-Agent and optional Authorization headers for downloads
	 * from both github.com release assets and api.github.com zipball URLs.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL.
	 * @return array Modified arguments.
	 */
	public function secure_download_request_args( $args, $url ) {
		// Only modify requests to this plugin's GitHub repository.
		if ( ! $this->is_repo_url_for_this_plugin( $url ) ) {
			return $args;
		}

		// Set explicit User-Agent.
		$args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ) . '; EtchFusionSuite/' . ETCH_FUSION_SUITE_VERSION;

		// Add authorization header if token is configured.
		$github_token = apply_filters( 'etch_fusion_suite_github_updater_token', '' );
		$github_token = apply_filters_deprecated(
			'efs_github_updater_token',
			array( $github_token ),
			'0.11.27',
			'etch_fusion_suite_github_updater_token'
		);
		if ( ! empty( $github_token ) ) {
			$args['headers']['Authorization'] = 'token ' . $github_token;
		}

		return $args;
	}

	/**
	 * Read plugin headers for version requirements
	 *
	 * @return array Plugin header data.
	 */
	private function read_plugin_headers() {
		$headers = get_file_data(
			$this->plugin_file,
			array(
				'requires' => 'Requires at least',
				'tested'   => 'Tested up to',
			)
		);

		// Provide defaults if headers are missing.
		return array(
			'requires' => ! empty( $headers['requires'] ) ? $headers['requires'] : '5.0',
			'tested'   => ! empty( $headers['tested'] ) ? $headers['tested'] : get_bloginfo( 'version' ),
		);
	}
}
