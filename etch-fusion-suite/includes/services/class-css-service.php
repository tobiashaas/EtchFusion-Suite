<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_CSS_Converter;

class EFS_CSS_Service {

	/**
	 * @var EFS_CSS_Converter
	 */
	private $css_converter;

	/**
	 * @var EFS_API_Client
	 */
	private $api_client;

	/**
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	/**
	 * @var EFS_Detailed_Progress_Tracker|null
	 */
	private $progress_tracker;

	public function __construct(
		EFS_CSS_Converter $css_converter,
		EFS_API_Client $api_client,
		EFS_Error_Handler $error_handler,
		EFS_Detailed_Progress_Tracker $progress_tracker = null
	) {
		$this->css_converter    = $css_converter;
		$this->api_client       = $api_client;
		$this->error_handler    = $error_handler;
		$this->progress_tracker = $progress_tracker;
	}

	/**
	 * Set the progress tracker for detailed logging.
	 *
	 * @param EFS_Detailed_Progress_Tracker $tracker
	 * @return void
	 */
	public function set_progress_tracker( EFS_Detailed_Progress_Tracker $tracker ) {
		$this->progress_tracker = $tracker;
	}

	/**
	 * @param string $target_url
	 * @param string $jwt_token
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_css_classes( $target_url, $jwt_token ) {
		try {
			$etch_styles = $this->css_converter->convert_bricks_classes_to_etch();

			if ( empty( $etch_styles ) ) {
				$this->error_handler->log_info(
					'No CSS classes found to migrate',
					array(
						'action' => 'CSS migration skipped',
					)
				);

				return array(
					'success'  => true,
					'migrated' => 0,
					'message'  => __( 'No CSS classes found to migrate.', 'etch-fusion-suite' ),
				);
			}

			// Log each CSS class conversion with progress tracker.
			if ( $this->progress_tracker ) {
				foreach ( $etch_styles as $bricks_class => $etch_class ) {
					$this->progress_tracker->log_css_migration(
						$bricks_class,
						'converted',
						array(
							'etch_class_name' => $etch_class,
							'conflicts'       => 0,
						)
					);
				}
			}

			$response = $this->api_client->send_css_styles( $target_url, $jwt_token, $etch_styles );

			if ( is_wp_error( $response ) ) {
				$this->error_handler->log_error(
					'E106',
					array(
						'error'  => $response->get_error_message(),
						'action' => 'Failed to send CSS styles to target site',
					)
				);

				// Log batch failure.
				if ( $this->progress_tracker ) {
					$this->progress_tracker->log_batch_completion(
						'css_classes',
						0,
						count( $etch_styles ),
						count( $etch_styles ),
						array(
							'error' => $response->get_error_message(),
						)
					);
				}

				return $response;
			}

			$this->error_handler->log_info(
				'CSS migration completed successfully',
				array(
					'css_classes_found' => count( $etch_styles ),
					'css_class_names'   => array_keys( $etch_styles ),
				)
			);

			// Log batch completion.
			if ( $this->progress_tracker ) {
				$this->progress_tracker->log_batch_completion(
					'css_classes',
					count( $etch_styles ),
					count( $etch_styles ),
					0,
					array(
						'batch_size' => count( $etch_styles ),
					)
				);
			}

			return array(
				'success'  => true,
				'migrated' => count( $etch_styles ),
				'message'  => __( 'CSS classes migrated successfully.', 'etch-fusion-suite' ),
				'response' => $response,
			);
		} catch ( \Exception $exception ) {
			$this->error_handler->log_error(
				'E906',
				array(
					'message' => $exception->getMessage(),
					'action'  => 'CSS migration failed',
				)
			);

			return new \WP_Error( 'css_migration_failed', $exception->getMessage() );
		}
	}

	/**
	 * @return array
	 */
	public function convert_bricks_to_etch() {
		return $this->css_converter->convert_bricks_classes_to_etch();
	}

	/**
	 * Send converted CSS styles to the target Etch instance.
	 *
	 * @param string $target_url  Target Etch URL.
	 * @param string $api_key     Application password / API key.
	 * @param array  $etch_styles Styles payload containing `styles` and `style_map`.
	 *
	 * @return array|\\WP_Error API response or error.
	 */
	public function send_css_styles_to_target( $target_url, $jwt_token, array $etch_styles ) {
		return $this->api_client->send_css_styles( $target_url, $jwt_token, $etch_styles );
	}

	/**
	 * @param string $target_url
	 * @param string $api_key
	 * @param array  $etch_styles
	 *
	 * @return mixed
	 */
	public function import_etch_styles( $target_url, $jwt_token, array $etch_styles ) {
		return $this->css_converter->import_etch_styles( $target_url, $jwt_token, $etch_styles );
	}

	/**
	 * Get CSS class counts for progress tracking.
	 *
	 * @param  array $selected_post_types Post types selected for migration.
	 * @param  bool  $restrict_to_used    Whether to count only referenced classes.
	 * @return array{total: int, to_migrate: int}
	 */
	public function get_css_class_counts( array $selected_post_types = array(), bool $restrict_to_used = false ): array {
		if ( method_exists( $this->css_converter, 'get_css_class_counts' ) ) {
			return $this->css_converter->get_css_class_counts( $selected_post_types, $restrict_to_used );
		}
		return array(
			'total'      => 0,
			'to_migrate' => 0,
		);
	}

	/**
	 * @return array
	 */
	public function get_bricks_global_classes() {
		if ( method_exists( $this->css_converter, 'get_bricks_global_classes' ) ) {
			return $this->css_converter->get_bricks_global_classes();
		}

		return array();
	}

	/**
	 * Migrate Bricks global CSS (bricks_global_settings['customCss']) to the
	 * Etch global stylesheets option on the target site.
	 *
	 * Bricks stores site-wide custom CSS as a plain string under the 'customCss'
	 * key inside bricks_global_settings.  Etch stores global stylesheets as an
	 * associative array keyed by stylesheet ID in etch_global_stylesheets; each
	 * entry has 'name', 'css', and 'type' fields.  We send the CSS as a single
	 * stylesheet entry with the reserved ID 'bricks-global' so it does not
	 * overwrite Etch's default 'Main' stylesheet.
	 *
	 * @param string $target_url Target Etch URL.
	 * @param string $jwt_token  Migration JWT token.
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_global_css( $target_url, $jwt_token ) {
		try {
			$global_settings = get_option( 'bricks_global_settings', array() );
			$custom_css      = isset( $global_settings['customCss'] ) ? trim( $global_settings['customCss'] ) : '';

			if ( '' === $custom_css ) {
				$this->error_handler->log_info(
					'No Bricks global CSS found — skipping',
					array( 'action' => 'global CSS migration skipped' )
				);
				return array(
					'success' => true,
					'skipped' => true,
					'message' => __( 'No Bricks global CSS found to migrate.', 'etch-fusion-suite' ),
				);
			}

			// Wrap in the etch_global_stylesheets entry format.
			$payload = array(
				'id'   => 'bricks-global',
				'name' => __( 'Migrated from Bricks', 'etch-fusion-suite' ),
				'css'  => $custom_css,
				'type' => 'custom',
			);

			$response = $this->api_client->send_global_css( $target_url, $jwt_token, $payload );

			if ( is_wp_error( $response ) ) {
				$this->error_handler->log_error(
					'E107',
					array(
						'error'  => $response->get_error_message(),
						'action' => 'Failed to send global CSS to target site',
					)
				);
				return $response;
			}

			$this->error_handler->log_info(
				'Global CSS migrated successfully',
				array( 'css_length' => strlen( $custom_css ) )
			);

			return array(
				'success' => true,
				'message' => __( 'Global CSS migrated successfully.', 'etch-fusion-suite' ),
			);
		} catch ( \Exception $exception ) {
			$this->error_handler->log_error(
				'E907',
				array(
					'message' => $exception->getMessage(),
					'action'  => 'Global CSS migration failed',
				)
			);
			return new \WP_Error( 'global_css_migration_failed', $exception->getMessage() );
		}
	}

	/**
	 * @param string $css
	 *
	 * @return bool
	 */
	public function validate_css_syntax( $css ) {
		return $this->css_converter->validate_css_syntax( $css );
	}
}
