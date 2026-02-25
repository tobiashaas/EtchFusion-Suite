<?php
/**
 * Class Reference Scanner
 *
 * Encapsulates all logic for discovering which Bricks global classes are actually
 * referenced in page / post content, so the CSS converter can restrict migration to
 * classes that are in use rather than blindly importing the entire global-class library.
 *
 * Responsibilities:
 * - Load and cache the `bricks_global_classes` WordPress option.
 * - Walk all Bricks content meta-keys in selected post types to collect referenced
 *   class IDs and names.
 * - Decide whether a given class should be excluded from migration (system prefixes,
 *   well-known utility classes, etc.).
 * - Expose a count summary for the migration wizard preview.
 *
 * @package EtchFusionSuite\CSS
 */

namespace Bricks2Etch\CSS;

use Bricks2Etch\Core\EFS_Error_Handler;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans Bricks page content to determine which global classes are referenced.
 */
class EFS_Class_Reference_Scanner {

	/**
	 * Request-lifetime cache for bricks_global_classes.
	 *
	 * Populated on first call to get_bricks_global_classes_cached() and reused
	 * for all subsequent calls within the same request.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $bricks_global_classes_cache = null;

	/**
	 * Whether collect_referenced_global_class_ids() found at least one post that
	 * contains Bricks content meta.
	 *
	 * Used by is_referenced_global_class() to decide the fallback behaviour when
	 * no class references were found: if we never scanned real Bricks content,
	 * we should not filter classes out (safe-default = include everything).
	 *
	 * @var bool
	 */
	private $has_scanned_bricks_content = false;

	/**
	 * Error handler for info-level logging on excluded classes.
	 *
	 * @var EFS_Error_Handler
	 */
	private $error_handler;

	/**
	 * Initialise the scanner.
	 *
	 * @param EFS_Error_Handler $error_handler Error/info logging sink.
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Reset internal state between conversion runs.
	 *
	 * Clears both the class cache and the content-scan flag so a fresh run
	 * picks up any changes made to WordPress options between requests.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->bricks_global_classes_cache = null;
		$this->has_scanned_bricks_content  = false;
	}

	/**
	 * Return the parsed bricks_global_classes option with a request-lifetime cache.
	 *
	 * Handles both JSON-encoded and PHP-serialised option values, falling back to
	 * an empty array for anything else.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_bricks_global_classes_cached(): array {
		if ( null !== $this->bricks_global_classes_cache ) {
			return $this->bricks_global_classes_cache;
		}

		$raw = get_option( 'bricks_global_classes', array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$maybe = maybe_unserialize( $raw );
				$raw   = is_array( $maybe ) ? $maybe : array();
			}
		} elseif ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$this->bricks_global_classes_cache = $raw;
		return $this->bricks_global_classes_cache;
	}

	/**
	 * Decide whether a Bricks global class should be excluded from migration.
	 *
	 * Classes are excluded when they:
	 * - Have no name.
	 * - Start with a system prefix (Bricks internals, WordPress/Gutenberg, WooCommerce).
	 * - Are in the explicit exclusion list of well-known utility classes that should
	 *   be applied on content elements but not migrated as Etch styles.
	 *
	 * Special case: `is-bg*` classes are ACSS utility classes, not Gutenberg state
	 * classes, so they pass through even though they start with `is-`.
	 *
	 * @param array<string,mixed> $class_data Bricks global class entry.
	 * @return bool True when the class should be excluded.
	 */
	public function should_exclude_class( array $class_data ): bool {
		$class_name = isset( $class_data['name'] ) ? $class_data['name'] : '';

		if ( '' === $class_name ) {
			return true;
		}

		// System / third-party prefix block-list.
		$excluded_prefixes = array(
			'brxe-',        // Bricks element classes (generated per element).
			'bricks-',      // Bricks internal system classes.
			'brx-',         // Bricks utility classes.
			'wp-',          // WordPress default classes.
			'wp-block-',    // Gutenberg block wrapper classes.
			'has-',         // Gutenberg utility classes (has-large-font-size, etc.).
			'is-',          // Gutenberg state classes (is-style-*, etc.).
			'woocommerce-', // WooCommerce component classes.
			'wc-',          // WooCommerce short prefix.
			'product-',     // WooCommerce product page classes.
			'cart-',        // WooCommerce cart classes.
			'checkout-',    // WooCommerce checkout classes.
		);

		foreach ( $excluded_prefixes as $prefix ) {
			if ( 0 === strpos( $class_name, $prefix ) ) {
				// is-bg* and is-bg-* are ACSS utility classes — allow them through.
				if ( 'is-' === $prefix && 0 === strpos( $class_name, 'is-bg' ) ) {
					continue;
				}

				$this->error_handler->log_info(
					'CSS Scanner: Excluding class ' . $class_name . ' (prefix: ' . $prefix . ')'
				);
				return true;
			}
		}

		// Explicit exclusion list — well-known content-only utility classes.
		$normalized_class_name = ltrim( (string) preg_replace( '/^acss_import_/', '', $class_name ), '.' );
		$excluded_classes      = array(
			'bg--ultra-light',
			'bg--ultra-dark',
			'fr-lede',
			'fr-intro',
			'fr-note',
			'fr-notes',
			'text--l',
		);
		if ( in_array( $normalized_class_name, $excluded_classes, true ) ) {
			$this->error_handler->log_info(
				'CSS Scanner: Excluding class ' . $class_name . ' (explicit utility exclusion)'
			);
			return true;
		}

		return false;
	}

	/**
	 * Read the selected post types from the active migration option.
	 *
	 * Falls back to an empty array when no active migration is configured; the
	 * caller is responsible for supplying a default set in that case.
	 *
	 * @return array<int,string>
	 */
	public function get_selected_post_types_from_active_migration(): array {
		$active = get_option( 'efs_active_migration', array() );
		if ( ! is_array( $active ) || empty( $active['options'] ) || ! is_array( $active['options'] ) ) {
			return array();
		}

		$selected = isset( $active['options']['selected_post_types'] )
			&& is_array( $active['options']['selected_post_types'] )
				? $active['options']['selected_post_types']
				: array();

		// Sanitise every entry to a plain key string and drop empty values.
		return array_values(
			array_filter(
				array_map( 'sanitize_key', $selected ),
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}

	/**
	 * Return a count summary for the migration wizard preview.
	 *
	 * @param array<int,string> $selected_post_types Post types selected by the user.
	 * @param bool              $restrict_to_used    When true, count only classes that are
	 *                                               referenced in the selected post types.
	 * @return array{total: int, to_migrate: int}
	 */
	public function get_css_class_counts( array $selected_post_types, bool $restrict_to_used ): array {
		$bricks_classes = $this->get_bricks_global_classes_cached();
		$total          = count( $bricks_classes );

		if ( $restrict_to_used ) {
			$referenced = $this->collect_referenced_global_class_ids( $selected_post_types );
			$to_migrate = 0;
			foreach ( $bricks_classes as $class ) {
				if ( $this->is_referenced_global_class( $class, $referenced ) ) {
					++$to_migrate;
				}
			}
		} else {
			$to_migrate = $total;
		}

		return array(
			'total'      => $total,
			'to_migrate' => $to_migrate,
		);
	}

	/**
	 * Collect Bricks global class identifiers that are referenced in content.
	 *
	 * Queries all posts with Bricks meta keys and walks their element trees
	 * recursively to find `_cssGlobalClasses` and `_cssClasses` references.
	 *
	 * Also sets $has_scanned_bricks_content = true when at least one post with
	 * Bricks data is found, which controls the safe-default in
	 * is_referenced_global_class().
	 *
	 * @param array<int,string> $selected_post_types Post types to scan.
	 *                                               Defaults to post, page, bricks_template.
	 * @return array<string,bool> Map of class identifier → true.
	 */
	public function collect_referenced_global_class_ids( array $selected_post_types = array() ): array {
		// Reset scan state for this run.
		$this->has_scanned_bricks_content = false;

		// Always include bricks_template if it is registered on this site.
		$post_types = ! empty( $selected_post_types )
			? $selected_post_types
			: array( 'post', 'page', 'bricks_template' );

		if ( function_exists( 'post_type_exists' ) && post_type_exists( 'bricks_template' )
			&& ! in_array( 'bricks_template', $post_types, true )
		) {
			$post_types[] = 'bricks_template';
		}

		// Sanitise and deduplicate post-type list.
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				static function ( string $v ): bool {
					return '' !== $v;
				}
			)
		);

		if ( empty( $post_types ) ) {
			return array();
		}

		// Fetch only posts that carry at least one Bricks meta key — this avoids
		// loading the entire post table when a site has thousands of posts.
		$posts = get_posts(
			array(
				'post_type'   => $post_types,
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => -1,
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => '_bricks_page_content_2',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bricks_page_content',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bricks_settings',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bricks_page_settings',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		// Any result here proves we found real Bricks content.
		if ( ! empty( $posts ) ) {
			$this->has_scanned_bricks_content = true;
		}

		$referenced = array();
		foreach ( $posts as $post ) {
			// Prefer the v2 content meta; fall back to legacy key.
			$elements = get_post_meta( $post->ID, '_bricks_page_content_2', true );
			if ( empty( $elements ) ) {
				$elements = get_post_meta( $post->ID, '_bricks_page_content', true );
			}
			if ( is_string( $elements ) ) {
				$elements = maybe_unserialize( $elements );
			}
			if ( is_array( $elements ) ) {
				foreach ( $elements as $element ) {
					if ( ! is_array( $element ) ) {
						continue;
					}
					$settings = isset( $element['settings'] ) && is_array( $element['settings'] )
						? $element['settings']
						: array();
					$this->collect_referenced_classes_from_settings_value( $settings, $referenced );
				}
			}

			// Template-level wrapper classes live outside the element tree.
			$wrapper_settings = get_post_meta( $post->ID, '_bricks_settings', true );
			$this->collect_referenced_classes_from_settings_value( $wrapper_settings, $referenced );

			$page_settings = get_post_meta( $post->ID, '_bricks_page_settings', true );
			$this->collect_referenced_classes_from_settings_value( $page_settings, $referenced );
		}

		return $referenced;
	}

	/**
	 * Check whether a global class is referenced by the scanned content.
	 *
	 * Matching logic:
	 * 1. If $referenced_ids is empty AND we never scanned real Bricks content,
	 *    return true so that all classes are included (safe default for fresh installs
	 *    or when the scanner is called without a prior collect_referenced_global_class_ids() call).
	 * 2. Match by Bricks class ID (UUID-like string stored in the `id` field).
	 * 3. Match by class name (plain string stored in the `name` field).
	 * 4. Match by acss_import_-prefixed name (ACSS-imported classes are referenced under this prefix).
	 *
	 * @param array<string,mixed> $class_data    Bricks global class entry.
	 * @param array<string,bool>  $referenced_ids Map of referenced identifiers from content scan.
	 * @return bool
	 */
	public function is_referenced_global_class( array $class_data, array $referenced_ids ): bool {
		// No references found — fall back to "include everything" only when we never
		// successfully scanned any Bricks content.
		if ( empty( $referenced_ids ) ) {
			return ! $this->has_scanned_bricks_content;
		}

		$class_id   = isset( $class_data['id'] ) ? trim( (string) $class_data['id'] ) : '';
		$class_name = isset( $class_data['name'] ) ? trim( (string) $class_data['name'] ) : '';

		// Match by UUID-style Bricks class ID.
		if ( '' !== $class_id && isset( $referenced_ids[ $class_id ] ) ) {
			return true;
		}

		// Match by plain class name.
		if ( '' !== $class_name && isset( $referenced_ids[ $class_name ] ) ) {
			return true;
		}

		// Match by ACSS import prefix (Automatic CSS imports classes as "acss_import_<name>").
		if ( '' !== $class_name ) {
			$prefixed = 'acss_import_' . $class_name;
			if ( isset( $referenced_ids[ $prefixed ] ) ) {
				return true;
			}
		}

		return false;
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Recursively collect referenced class identifiers from a Bricks settings value.
	 *
	 * Supports both `_cssGlobalClasses` (UUID-based references) and `_cssClasses`
	 * (space-separated class-name strings).  Handles JSON-encoded and serialised
	 * strings transparently.
	 *
	 * @param mixed              $settings   Any Bricks settings payload (array, string, scalar).
	 * @param array<string,bool> $referenced Map of found identifiers, modified in place.
	 * @param int                $depth      Current recursion depth — stop at depth 8 to guard
	 *                                       against pathologically deep or circular structures.
	 * @return bool True when at least one class token was found.
	 */
	private function collect_referenced_classes_from_settings_value( $settings, array &$referenced, int $depth = 0 ): bool {
		// Hard depth limit to prevent infinite loops on malformed data.
		if ( $depth > 8 ) {
			return false;
		}

		// Transparently decode serialised or JSON-encoded string payloads.
		if ( is_string( $settings ) ) {
			$decoded = maybe_unserialize( $settings );
			if ( is_array( $decoded ) ) {
				$settings = $decoded;
			} else {
				$json = json_decode( $settings, true );
				if ( is_array( $json ) ) {
					$settings = $json;
				}
			}
		}

		if ( ! is_array( $settings ) ) {
			return false;
		}

		$found = false;

		// `_cssGlobalClasses` holds UUID-style references to global class definitions.
		if ( isset( $settings['_cssGlobalClasses'] ) ) {
			$global_classes = $settings['_cssGlobalClasses'];
			if ( is_scalar( $global_classes ) ) {
				$global_classes = array( $global_classes );
			}
			if ( is_array( $global_classes ) ) {
				foreach ( $global_classes as $class_id ) {
					if ( ! is_scalar( $class_id ) ) {
						continue;
					}
					$key = trim( (string) $class_id );
					if ( '' === $key ) {
						continue;
					}
					$referenced[ $key ] = true;
					$found              = true;
				}
			}
		}

		// `_cssClasses` holds a space-separated list of class names (not UUIDs).
		if ( isset( $settings['_cssClasses'] ) ) {
			$raw_classes = $settings['_cssClasses'];
			$tokens      = array();
			if ( is_scalar( $raw_classes ) ) {
				$split = preg_split( '/\s+/', trim( (string) $raw_classes ) );
				if ( is_array( $split ) ) {
					$tokens = $split;
				}
			} elseif ( is_array( $raw_classes ) ) {
				foreach ( $raw_classes as $class_name ) {
					if ( ! is_scalar( $class_name ) ) {
						continue;
					}
					$parts = preg_split( '/\s+/', trim( (string) $class_name ) );
					if ( is_array( $parts ) ) {
						$tokens = array_merge( $tokens, $parts );
					}
				}
			}
			foreach ( $tokens as $token ) {
				$name = trim( (string) $token );
				if ( '' === $name ) {
					continue;
				}
				$referenced[ $name ] = true;
				$found               = true;
			}
		}

		// Recurse into nested arrays, skipping the already-processed class keys.
		foreach ( $settings as $key => $value ) {
			if ( '_cssGlobalClasses' === $key || '_cssClasses' === $key ) {
				continue;
			}
			if ( is_array( $value ) || is_string( $value ) ) {
				if ( $this->collect_referenced_classes_from_settings_value( $value, $referenced, $depth + 1 ) ) {
					$found = true;
				}
			}
		}

		return $found;
	}
}
