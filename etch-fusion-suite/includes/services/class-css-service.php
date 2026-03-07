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

	/**
	 * Cached conversion payload for the current request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $migration_css_payload;

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
			$etch_styles    = $this->get_migration_css_payload();
			$migrated_count = $this->get_css_payload_count( $etch_styles );

			if ( 0 === $migrated_count ) {
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
				$style_map = isset( $etch_styles['style_map'] ) && is_array( $etch_styles['style_map'] )
					? $etch_styles['style_map']
					: array();

				foreach ( $style_map as $bricks_class => $etch_class ) {
					$selector = is_array( $etch_class ) ? (string) ( $etch_class['selector'] ?? '' ) : '';
					$this->progress_tracker->log_css_migration(
						(string) $bricks_class,
						'converted',
						array(
							'etch_class_name' => ltrim( $selector, '.' ),
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
						$migrated_count,
						$migrated_count,
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
					'css_classes_found' => $migrated_count,
					'css_class_names'   => isset( $etch_styles['style_map'] ) && is_array( $etch_styles['style_map'] ) ? array_keys( $etch_styles['style_map'] ) : array(),
				)
			);

			// Log batch completion.
			if ( $this->progress_tracker ) {
				$this->progress_tracker->log_batch_completion(
					'css_classes',
					$migrated_count,
					$migrated_count,
					0,
					array(
						'batch_size' => $migrated_count,
					)
				);
			}

			return array(
				'success'  => true,
				'migrated' => $migrated_count,
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
	 * Get CSS class counts using the same restrict-to-used setting as the migration run.
	 *
	 * The wizard and the actual converter default to restricting CSS to referenced
	 * classes. Initial receiving totals must use the same rule, otherwise the Etch
	 * receiving UI can show a correct per-type breakdown with an inflated global
	 * total and percentage.
	 *
	 * @param array<int,string>       $selected_post_types Post types selected for migration.
	 * @param array<string,mixed>|null $options            Migration options payload.
	 * @return array{total: int, to_migrate: int}
	 */
	public function get_migration_css_class_counts( array $selected_post_types = array(), ?array $options = null ): array {
		$payload = $this->get_migration_css_payload();
		$count   = $this->get_css_payload_count( $payload );

		return array(
			'total'      => $count,
			'to_migrate' => $count,
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

	/**
	 * Build or reuse the current migration's converted CSS payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_migration_css_payload(): array {
		if ( null === $this->migration_css_payload ) {
			$payload = $this->css_converter->convert_bricks_classes_to_etch();
			$this->migration_css_payload = is_array( $payload ) ? $payload : array();
		}

		return $this->migration_css_payload;
	}

	/**
	 * Count migratable CSS classes represented by a conversion payload.
	 *
	 * @param array<string,mixed> $payload CSS conversion payload.
	 * @return int
	 */
	private function get_css_payload_count( array $payload ): int {
		$style_map = isset( $payload['style_map'] ) && is_array( $payload['style_map'] )
			? $payload['style_map']
			: array();

		if ( ! empty( $style_map ) ) {
			return count( $style_map );
		}

		$styles = isset( $payload['styles'] ) && is_array( $payload['styles'] )
			? $payload['styles']
			: array();

		$count = 0;
		foreach ( $styles as $style_key => $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}

			$type     = isset( $style['type'] ) ? sanitize_key( (string) $style['type'] ) : '';
			$selector = isset( $style['selector'] ) ? trim( (string) $style['selector'] ) : '';

			if ( 'class' !== $type ) {
				continue;
			}

			if ( is_string( $style_key ) && 0 === strpos( $style_key, 'etch-' ) ) {
				continue;
			}

			if ( '' === $selector || '.' !== $selector[0] ) {
				continue;
			}

			++$count;
		}

		return $count;
	}
}
