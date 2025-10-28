<?php
/**
 * Migrator discovery for Bricks to Etch migration plugin
 *
 * Handles registration of built-in and third-party migrators via the
 * registry and WordPress hooks.
 *
 * @package Bricks2Etch\Migrators
 */

namespace Bricks2Etch\Migrators;

use Bricks2Etch\Migrators\Interfaces\Migrator_Interface;
use Bricks2Etch\Container\EFS_Service_Container;
use Exception;
use DirectoryIterator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Migrator_Discovery {
	/**
	 * Discovers migrators and registers them with registry.
	 */
	public static function discover_migrators( EFS_Migrator_Registry $registry ) {
		$container     = etch_fusion_suite_container();
		$error_handler = $container->has( 'error_handler' ) ? $container->get( 'error_handler' ) : null;

		$builtin_keys = array(
			'cpt'           => 'cpt_migrator',
			'acf'           => 'acf_migrator',
			'metabox'       => 'metabox_migrator',
			'custom_fields' => 'custom_fields_migrator',
		);

		foreach ( $builtin_keys as $type => $key ) {
			if ( ! $container->has( $key ) ) {
				continue;
			}

			$migrator = $container->get( $key );
			if ( $migrator instanceof Migrator_Interface ) {
				try {
					$registry->register( $migrator );
				} catch ( Exception $e ) {
					if ( $error_handler ) {
						$error_handler->log_warning(
							'W002',
							array(
								'migrator_type' => $type,
								'message'       => $e->getMessage(),
							)
						);
					}
				}
			}
		}

		/**
		 * Allow third-party plugins to register migrators.
		 *
		 * New hook prefix: etch_fusion_suite_*.
		 * Backwards compatibility hooks (b2e_*, efs_*) retained temporarily.
		 */
		do_action( 'etch_fusion_suite_register_migrators', $registry );
		do_action( 'efs_register_migrators', $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy compatibility.
		do_action( 'b2e_register_migrators', $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy compatibility.

		$discovered = $registry->get_all();

		/**
		 * Allow modifications to discovered migrators.
		 */
		$filtered = apply_filters( 'etch_fusion_suite_migrators_discovered', $discovered, $registry );
		$filtered = apply_filters( 'efs_migrators_discovered', $filtered, $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy compatibility.
		$filtered = apply_filters( 'b2e_migrators_discovered', $filtered, $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy compatibility.

		if ( $filtered !== $discovered ) {
			$registry->clear();
			foreach ( $filtered as $migrator ) {
				if ( $migrator instanceof Migrator_Interface ) {
					try {
						$registry->register( $migrator );
					} catch ( Exception $e ) {
						if ( $error_handler ) {
							$error_handler->log_warning(
								'W002',
								array(
									'message' => $e->getMessage(),
								)
							);
						}
					}
				}
			}
		}

		if ( $error_handler ) {
			$error_handler->debug_log(
				'Migrators discovered',
				array(
					'count' => $registry->count(),
					'types' => $registry->get_types(),
				),
				'B2E_MIGRATOR'
			);
		}
	}

	/**
	 * Auto-discover migrators from directory (optional helper).
	 */
	public static function auto_discover_from_directory( $directory, B2E_Migrator_Registry $registry ) {
		if ( ! is_dir( $directory ) || ! is_readable( $directory ) ) {
			return;
		}

		foreach ( new DirectoryIterator( $directory ) as $file ) {
			if ( $file->isDot() || $file->getExtension() !== 'php' ) {
				continue;
			}

			require_once $file->getPathname();
		}

		$container     = function_exists( 'b2e_container' ) ? b2e_container() : null;
		$error_handler = ( $container && $container->has( 'error_handler' ) ) ? $container->get( 'error_handler' ) : null;
		$api_client    = ( $container && $container->has( 'api_client' ) ) ? $container->get( 'api_client' ) : null;

		$declared = get_declared_classes();
		foreach ( $declared as $class ) {
			$implements = class_implements( $class );
			if ( ! is_array( $implements ) || ! in_array( Migrator_Interface::class, $implements, true ) ) {
				continue;
			}

			$instance = null;

			if ( $container ) {
				try {
					$instance = $container->get( $class );
				} catch ( Exception $e ) {
					$instance = null;
					if ( $error_handler ) {
						$error_handler->log_warning(
							'W002',
							array(
								'class'   => $class,
								'message' => $e->getMessage(),
							)
						);
					}
				}
			}

			if ( ! $instance && class_exists( $class ) ) {
				try {
					$instance = new $class( $error_handler, $api_client );
				} catch ( Exception $e ) {
					$instance = null;
					if ( $error_handler ) {
						$error_handler->log_warning(
							'W002',
							array(
								'class'   => $class,
								'message' => $e->getMessage(),
							)
						);
					}
				}
			}

			if ( $instance instanceof Migrator_Interface ) {
				try {
					$registry->register( $instance );
				} catch ( Exception $e ) {
					if ( $error_handler ) {
						$error_handler->log_warning(
							'W002',
							array(
								'class'   => $class,
								'message' => $e->getMessage(),
							)
						);
					}
				}
			}
		}
	}
}
