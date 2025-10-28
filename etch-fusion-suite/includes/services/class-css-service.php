<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Api\EFS_API_Client;
use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Parsers\EFS_CSS_Converter;

class EFS_CSS_Service {

	/** @var EFS_CSS_Converter */
	private $css_converter;

	/** @var EFS_API_Client */
	private $api_client;

	/** @var EFS_Error_Handler */
	private $error_handler;

	public function __construct(
		EFS_CSS_Converter $css_converter,
		EFS_API_Client $api_client,
		EFS_Error_Handler $error_handler
	) {
		$this->css_converter = $css_converter;
		$this->api_client    = $api_client;
		$this->error_handler = $error_handler;
	}

	/**
	 * @param string $target_url
	 * @param string $api_key
	 *
	 * @return array|\WP_Error
	 */
	public function migrate_css_classes( $target_url, $api_key ) {
		try {
			$etch_styles = $this->css_converter->convert_bricks_classes_to_etch();

			if ( empty( $etch_styles ) ) {
				$this->error_handler->log_error(
					'I008',
					array(
						'message' => 'No CSS classes found to migrate',
						'action'  => 'CSS migration skipped',
					)
				);

				return array(
					'success'  => true,
					'migrated' => 0,
					'message'  => __( 'No CSS classes found to migrate.', 'etch-fusion-suite' ),
				);
			}

			$response = $this->api_client->send_css_styles( $target_url, $api_key, $etch_styles );

			if ( is_wp_error( $response ) ) {
				$this->error_handler->log_error(
					'E106',
					array(
						'error'  => $response->get_error_message(),
						'action' => 'Failed to send CSS styles to target site',
					)
				);

				return $response;
			}

			$this->error_handler->log_error(
				'I009',
				array(
					'css_classes_found' => count( $etch_styles ),
					'css_class_names'   => array_keys( $etch_styles ),
					'action'            => 'CSS migration completed successfully',
				)
			);

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
	 * @param string $target_url Target Etch URL.
	 * @param string $api_key Application password / API key.
	 * @param array  $etch_styles Styles payload containing `styles` and `style_map`.
	 *
	 * @return array|\\WP_Error API response or error.
	 */
	public function send_css_styles_to_target( $target_url, $api_key, array $etch_styles ) {
		return $this->api_client->send_css_styles( $target_url, $api_key, $etch_styles );
	}

	/**
	 * @param string $target_url
	 * @param string $api_key
	 * @param array  $etch_styles
	 *
	 * @return mixed
	 */
	public function import_etch_styles( $target_url, $api_key, array $etch_styles ) {
		return $this->css_converter->import_etch_styles( $target_url, $api_key, $etch_styles );
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
	 * @param string $css
	 *
	 * @return bool
	 */
	public function validate_css_syntax( $css ) {
		return $this->css_converter->validate_css_syntax( $css );
	}
}
