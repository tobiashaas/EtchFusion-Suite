<?php
namespace Bricks2Etch\Container;

class EFS_Service_Provider {

	/**
	 * Register core services in the container.
	 *
	 * @param EFS_Service_Container $container
	 */
	public function register( EFS_Service_Container $container ) {
		// Repository Services
		$container->singleton(
			'settings_repository',
			function ( $c ) {
				return new \Bricks2Etch\Repositories\EFS_WordPress_Settings_Repository();
			}
		);

		$container->singleton(
			'migration_repository',
			function ( $c ) {
				return new \Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository();
			}
		);

		$container->singleton(
			'token_manager',
			function ( $c ) {
				return new \Bricks2Etch\Core\EFS_Migration_Token_Manager( $c->get( 'migration_repository' ) );
			}
		);

		$container->singleton(
			'style_repository',
			function ( $c ) {
				return new \Bricks2Etch\Repositories\EFS_WordPress_Style_Repository();
			}
		);

		// Security Services
		$container->singleton(
			'cors_manager',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_CORS_Manager( $c->get( 'settings_repository' ) );
			}
		);

		$container->singleton(
			'rate_limiter',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_Rate_Limiter();
			}
		);

		$container->singleton(
			'input_validator',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_Input_Validator();
			}
		);

		$container->singleton(
			'security_headers',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_Security_Headers();
			}
		);

		$container->singleton(
			'audit_logger',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_Audit_Logger( $c->get( 'error_handler' ) );
			}
		);

		$container->singleton(
			'environment_detector',
			function ( $c ) {
				return new \Bricks2Etch\Security\EFS_Environment_Detector();
			}
		);

		$container->singleton(
			'github_updater',
			function ( $c ) {
				return new \Bricks2Etch\Updater\EFS_GitHub_Updater(
					$c->get( 'error_handler' )
				);
			}
		);

		// Core Services
		$container->singleton(
			'error_handler',
			function ( $c ) {
				return new \Bricks2Etch\Core\EFS_Error_Handler();
			}
		);

		$container->singleton(
			'plugin_detector',
			function ( $c ) {
				return new \Bricks2Etch\Core\EFS_Plugin_Detector( $c->get( 'error_handler' ) );
			}
		);

		// API Services
		$container->singleton(
			'api_client',
			function ( $c ) {
				return new \Bricks2Etch\Api\EFS_API_Client( $c->get( 'error_handler' ) );
			}
		);

		// Parser Services
		$container->singleton(
			'content_parser',
			function ( $c ) {
				return new \Bricks2Etch\Parsers\EFS_Content_Parser( $c->get( 'error_handler' ) );
			}
		);

		$container->singleton(
			'dynamic_data_converter',
			function ( $c ) {
				return new \Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter( $c->get( 'error_handler' ) );
			}
		);

		$container->singleton(
			'css_converter',
			function ( $c ) {
				return new \Bricks2Etch\Parsers\EFS_CSS_Converter( $c->get( 'error_handler' ), $c->get( 'style_repository' ) );
			}
		);

		// Converter Services
		$container->singleton(
			'element_factory',
			function ( $c ) {
				return new \Bricks2Etch\Converters\EFS_Element_Factory();
			}
		);

		$container->singleton(
			'gutenberg_generator',
			function ( $c ) {
				return new \Bricks2Etch\Parsers\EFS_Gutenberg_Generator(
					$c->get( 'error_handler' ),
					$c->get( 'dynamic_data_converter' ),
					$c->get( 'content_parser' )
				);
			}
		);

		// Migrator Services
		$container->singleton(
			'media_migrator',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_Media_Migrator(
					$c->get( 'error_handler' ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'cpt_migrator',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_CPT_Migrator(
					$c->get( 'error_handler' ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'acf_migrator',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_ACF_Field_Groups_Migrator(
					$c->get( 'error_handler' ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'metabox_migrator',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_MetaBox_Migrator(
					$c->get( 'error_handler' ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'custom_fields_migrator',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_Custom_Fields_Migrator(
					$c->get( 'error_handler' ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'migrator_registry',
			function ( $c ) {
				return \Bricks2Etch\Migrators\EFS_Migrator_Registry::instance();
			}
		);

		$container->singleton(
			'migrator_discovery',
			function ( $c ) {
				return new \Bricks2Etch\Migrators\EFS_Migrator_Discovery();
			}
		);

		$container->singleton(
			'html_parser',
			function ( $c ) {
				return new \Bricks2Etch\Templates\EFS_HTML_Parser( $c->get( 'error_handler' ) );
			}
		);

		$container->singleton(
			'html_sanitizer',
			function ( $c ) {
				return new \Bricks2Etch\Templates\EFS_Framer_HTML_Sanitizer( $c->get( 'error_handler' ), $c->get( 'input_validator' ) );
			}
		);

		$container->singleton(
			'template_analyzer',
			function ( $c ) {
				return new \Bricks2Etch\Templates\EFS_Framer_Template_Analyzer( $c->get( 'html_parser' ), $c->get( 'error_handler' ) );
			}
		);

		$container->singleton(
			'framer_to_etch_converter',
			function ( $c ) {
				return new \Bricks2Etch\Templates\EFS_Framer_To_Etch_Converter( $c->get( 'error_handler' ), $c->get( 'element_factory' ), $c->get( 'style_repository' ) );
			}
		);

		$container->singleton(
			'etch_template_generator',
			function ( $c ) {
				return new \Bricks2Etch\Templates\EFS_Etch_Template_Generator( $c->get( 'framer_to_etch_converter' ), $c->get( 'error_handler' ), $c->get( 'style_repository' ) );
			}
		);

		$container->singleton(
			'template_extractor_service',
			function ( $c ) {
				return new \Bricks2Etch\Services\EFS_Template_Extractor_Service(
					$c->get( 'html_parser' ),
					$c->get( 'html_sanitizer' ),
					$c->get( 'template_analyzer' ),
					$c->get( 'etch_template_generator' ),
					$c->get( 'error_handler' ),
					$c->get( 'input_validator' ),
					$c->get( 'migration_repository' )
				);
			}
		);

		// Business Services
		$container->singleton(
			'css_service',
			function ( $c ) {
				return new \Bricks2Etch\Services\EFS_CSS_Service(
					$c->get( 'css_converter' ),
					$c->get( 'api_client' ),
					$c->get( 'error_handler' )
				);
			}
		);

		$container->singleton(
			'media_service',
			function ( $c ) {
				return new \Bricks2Etch\Services\EFS_Media_Service(
					$c->get( 'media_migrator' ),
					$c->get( 'error_handler' )
				);
			}
		);

		$container->singleton(
			'content_service',
			function ( $c ) {
				return new \Bricks2Etch\Services\EFS_Content_Service(
					$c->get( 'content_parser' ),
					$c->get( 'gutenberg_generator' ),
					$c->get( 'error_handler' )
				);
			}
		);

		$container->singleton(
			'migration_service',
			function ( $c ) {
				return new \Bricks2Etch\Services\EFS_Migration_Service(
					$c->get( 'error_handler' ),
					$c->get( 'plugin_detector' ),
					$c->get( 'content_parser' ),
					$c->get( 'css_service' ),
					$c->get( 'media_service' ),
					$c->get( 'content_service' ),
					$c->get( 'api_client' ),
					$c->get( 'migrator_registry' ),
					$c->get( 'migration_repository' ),
					$c->get( 'token_manager' )
				);
			}
		);

		// Controller Services
		$container->singleton(
			'settings_controller',
			function ( $c ) {
				return new \Bricks2Etch\Controllers\EFS_Settings_Controller( $c->get( 'api_client' ), $c->get( 'settings_repository' ) );
			}
		);

		$container->singleton(
			'migration_controller',
			function ( $c ) {
				return new \Bricks2Etch\Controllers\EFS_Migration_Controller(
					new \Bricks2Etch\Core\EFS_Migration_Manager( $c->get( 'migration_service' ), $c->get( 'migration_repository' ) ),
					$c->get( 'api_client' )
				);
			}
		);

		$container->singleton(
			'dashboard_controller',
			function ( $c ) {
				return new \Bricks2Etch\Controllers\EFS_Dashboard_Controller(
					$c->get( 'plugin_detector' ),
					$c->get( 'error_handler' ),
					$c->get( 'migration_service' ),
					$c->get( 'settings_controller' ),
					$c->get( 'template_controller' )
				);
			}
		);

		$container->singleton(
			'template_controller',
			function ( $c ) {
				return new \Bricks2Etch\Controllers\EFS_Template_Controller(
					$c->get( 'template_extractor_service' ),
					$c->get( 'input_validator' ),
					$c->get( 'error_handler' )
				);
			}
		);

		// AJAX Handler Services
		$container->singleton(
			'validation_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Validation_Ajax_Handler(
					$c->get( 'api_client' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'migration_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Migration_Ajax_Handler(
					$c->get( 'migration_controller' ),
					$c->get( 'settings_controller' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'content_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Content_Ajax_Handler(
					$c->get( 'migration_service' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'css_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_CSS_Ajax_Handler(
					$c->get( 'css_service' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'media_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Media_Ajax_Handler(
					$c->get( 'media_service' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'logs_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Logs_Ajax_Handler(
					$c->get( 'audit_logger' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' )
				);
			}
		);

		$container->singleton(
			'connection_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Connection_Ajax_Handler(
					$c->get( 'api_client' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'cleanup_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Cleanup_Ajax_Handler(
					$c->get( 'api_client' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'template_ajax',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\Handlers\EFS_Template_Ajax_Handler(
					$c->get( 'template_controller' ),
					$c->get( 'rate_limiter' ),
					$c->get( 'input_validator' ),
					$c->get( 'audit_logger' )
				);
			}
		);

		$container->singleton(
			'ajax_handler',
			function ( $c ) {
				return new \Bricks2Etch\Ajax\EFS_Ajax_Handler(
					$c->get( 'css_ajax' ),
					$c->get( 'content_ajax' ),
					$c->get( 'media_ajax' ),
					$c->get( 'validation_ajax' ),
					$c->get( 'logs_ajax' ),
					$c->get( 'connection_ajax' ),
					$c->get( 'cleanup_ajax' ),
					$c->get( 'template_ajax' ),
					$c->get( 'migration_ajax' )
				);
			}
		);

		// Admin Interface
		$container->singleton(
			'admin_interface',
			function ( $c ) {
				return new \Bricks2Etch\Admin\EFS_Admin_Interface(
					$c->get( 'dashboard_controller' ),
					$c->get( 'settings_controller' ),
					$c->get( 'migration_controller' ),
					true
				);
			}
		);
	}
	/**
	 * List of provided services.
	 *
	 * @return array<int, string>
	 */
	public function provides() {
		return array(
			'settings_repository',
			'migration_repository',
			'style_repository',
			'cors_manager',
			'rate_limiter',
			'input_validator',
			'security_headers',
			'audit_logger',
			'environment_detector',
			'github_updater',
			'error_handler',
			'plugin_detector',
			'api_client',
			'content_parser',
			'dynamic_data_converter',
			'css_converter',
			'element_factory',
			'gutenberg_generator',
			'media_migrator',
			'cpt_migrator',
			'acf_migrator',
			'metabox_migrator',
			'custom_fields_migrator',
			'migrator_registry',
			'migrator_discovery',
			'html_parser',
			'html_sanitizer',
			'template_analyzer',
			'framer_to_etch_converter',
			'etch_template_generator',
			'template_extractor_service',
			'css_service',
			'media_service',
			'content_service',
			'migration_service',
			'settings_controller',
			'migration_controller',
			'dashboard_controller',
			'template_controller',
			'validation_ajax',
			'content_ajax',
			'css_ajax',
			'media_ajax',
			'logs_ajax',
			'connection_ajax',
			'cleanup_ajax',
			'template_ajax',
			'ajax_handler',
			'admin_interface',
		);
	}
}
