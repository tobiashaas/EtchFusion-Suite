<?php
/**
 * Manual PSR-4 Autoloader (Composer Alternative)
 *
 * This file provides autoloading for namespaced classes without requiring Composer.
 */

spl_autoload_register(
	function ( $class_name ) {
		// Base namespace
		$prefix   = 'Bricks2Etch\\';
		$base_dir = __DIR__ . '/';

		// Check if the class uses the namespace prefix
		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		// Get the relative class name
		$relative_class = substr( $class_name, $len );

		// Map namespace to directory structure
		$namespace_map = array(
			'Container\\'                => 'container/',
			'Services\\'                 => 'services/',
			'Repositories\\Interfaces\\' => 'repositories/interfaces/',
			'Repositories\\'             => 'repositories/',
			'Core\\'                     => '',
			'Api\\'                      => '',
			'Controllers\\'              => 'controllers/',
			'Admin\\'                    => '',
			'Ajax\\Handlers\\'           => 'ajax/handlers/',
			'Ajax\\'                     => 'ajax/',
			'Parsers\\'                  => '',
			'Templates\\Interfaces\\'    => 'templates/interfaces/',
			'Templates\\'                => 'templates/',
			'Migrators\\Interfaces\\'    => 'migrators/interfaces/',
			'Migrators\\'                => '',
			'Converters\\Elements\\'     => 'converters/elements/',
			'Converters\\'               => 'converters/',
			'Security\\'                 => 'security/',
		);

		// Find matching namespace
		foreach ( $namespace_map as $namespace => $dir ) {
			if ( 0 === strpos( $relative_class, $namespace ) ) {
				$relative_name                       = substr( $relative_class, strlen( $namespace ) );
				$slug                                = strtolower( str_replace( '_', '-', $relative_name ) );
				$slug_no_prefix                      = preg_replace( '/^(?:b2e|efs)-/', '', $slug );
				$slug_no_suffix                      = preg_replace( '/-interface$/', '', $slug );
				$slug_no_prefix_no_suffix            = preg_replace( '/-interface$/', '', $slug_no_prefix );
				$slug_trimmed                        = preg_replace( '/^(?:b2e|efs)-(?:element-)?/', '', $slug );
				$slug_trimmed_no_suffix              = preg_replace( '/-interface$/', '', $slug_trimmed );
				$underscore_slug                     = strtolower( $relative_name );
				$underscore_slug_no_prefix           = preg_replace( '/^(?:b2e|efs)_/', '', $underscore_slug );
				$underscore_slug_no_suffix           = preg_replace( '/_interface$/', '', $underscore_slug );
				$underscore_slug_no_prefix_no_suffix = preg_replace( '/_interface$/', '', $underscore_slug_no_prefix );
				$underscore_slug_trimmed             = preg_replace( '/^(?:b2e|efs)_(?:element_)?/', '', $underscore_slug );
				$underscore_slug_trimmed_no_suffix   = preg_replace( '/_interface$/', '', $underscore_slug_trimmed );

				$files = array(
					'class-' . $slug . '.php',
					$slug . '.php',
					'class-' . $underscore_slug . '.php',
					$underscore_slug . '.php',
				);

				if ( $slug_no_prefix !== $slug ) {
					$files[] = 'class-' . $slug_no_prefix . '.php';
					$files[] = $slug_no_prefix . '.php';
				}

				if ( $underscore_slug_no_prefix !== $underscore_slug ) {
					$files[] = 'class-' . $underscore_slug_no_prefix . '.php';
					$files[] = $underscore_slug_no_prefix . '.php';
				}

				if ( $slug_no_suffix !== $slug ) {
					$files[] = 'interface-' . $slug_no_suffix . '.php';
				}

				if ( $slug_no_prefix_no_suffix !== $slug_no_prefix ) {
					$files[] = 'interface-' . $slug_no_prefix_no_suffix . '.php';
				}

				if ( $slug_trimmed !== $slug ) {
					$files[] = 'class-' . $slug_trimmed . '.php';
					$files[] = $slug_trimmed . '.php';
				}

				if ( $slug_trimmed_no_suffix !== $slug_trimmed ) {
					$files[] = 'class-' . $slug_trimmed_no_suffix . '.php';
					$files[] = $slug_trimmed_no_suffix . '.php';
				}

				if ( $underscore_slug_no_suffix !== $underscore_slug ) {
					$files[] = 'interface-' . str_replace( '_', '-', $underscore_slug_no_suffix ) . '.php';
				}

				if ( $underscore_slug_no_prefix_no_suffix !== $underscore_slug_no_prefix ) {
					$files[] = 'interface-' . str_replace( '_', '-', $underscore_slug_no_prefix_no_suffix ) . '.php';
				}

				if ( $underscore_slug_trimmed !== $underscore_slug ) {
					$files[] = 'class-' . $underscore_slug_trimmed . '.php';
					$files[] = $underscore_slug_trimmed . '.php';
				}

				if ( $underscore_slug_trimmed_no_suffix !== $underscore_slug_trimmed ) {
					$files[] = 'class-' . $underscore_slug_trimmed_no_suffix . '.php';
					$files[] = $underscore_slug_trimmed_no_suffix . '.php';
				}

				// Add abstract class pattern
				$files[] = 'abstract-class-' . $slug . '.php';
				if ( $slug_no_prefix !== $slug ) {
					$files[] = 'abstract-class-' . $slug_no_prefix . '.php';
				}
				// Handle Abstract_* class names (remove 'abstract-' prefix from filename)
				if ( 0 === strpos( $slug, 'abstract-' ) ) {
					$files[] = 'abstract-class-' . substr( $slug, 9 ) . '.php';
				}

				// Handle Ajax handler filenames (class-css-ajax.php)
				if ( 0 === substr_compare( $slug, '-ajax-handler', -13 ) ) {
					$slug_ajax = substr( $slug, 0, -13 ) . '-ajax';
					$files[]   = 'class-' . $slug_ajax . '.php';
					$files[]   = $slug_ajax . '.php';
				}

				if ( 0 === substr_compare( $slug_no_prefix, '-ajax-handler', -13 ) ) {
					$slug_no_prefix_ajax = substr( $slug_no_prefix, 0, -13 ) . '-ajax';
					$files[]             = 'class-' . $slug_no_prefix_ajax . '.php';
					$files[]             = $slug_no_prefix_ajax . '.php';
				}

				$files = array_unique( $files );

				foreach ( $files as $file ) {
					$path = $base_dir . $dir . $file;

					if ( file_exists( $path ) ) {
						require_once $path;
						return;
					}
				}

				// Special handling for Migrators namespace - try both locations
				if ( 'Migrators\\' === $namespace ) {
					foreach ( $files as $file ) {
						$alt_path = $base_dir . 'migrators/' . $file;
						if ( file_exists( $alt_path ) ) {
							require_once $alt_path;
							return;
						}
					}
				}
			}
		}
	}
);
