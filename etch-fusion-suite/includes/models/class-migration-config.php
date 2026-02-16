<?php
namespace Bricks2Etch\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration configuration value object.
 */
class EFS_Migration_Config {
	private array $selected_post_types;
	private array $post_type_mappings;
	private bool $include_media;
	private int $batch_size;

	public function __construct(
		array $selected_post_types = array(),
		array $post_type_mappings = array(),
		bool $include_media = true,
		int $batch_size = 50
	) {
		$this->selected_post_types = $this->normalize_post_types( $selected_post_types );
		$this->post_type_mappings  = $this->normalize_mappings( $post_type_mappings );
		$this->include_media        = $include_media;
		$this->batch_size           = $this->clamp_batch_size( $batch_size );
	}

	/**
	 * Default configuration (migrate everything with media and default batch size).
	 */
	public static function get_default(): self {
		return new self();
	}

	/**
	 * Rehydrate configuration stored in the repository.
	 */
	public static function from_array( array $data ): self {
		return new self(
			$data['selected_post_types'] ?? array(),
			$data['post_type_mappings'] ?? array(),
			isset( $data['include_media'] ) ? (bool) $data['include_media'] : true,
			isset( $data['batch_size'] ) ? (int) $data['batch_size'] : 50
		);
	}

	/**
	 * Serialize the configuration for persistence.
	 */
	public function to_array(): array {
		return array(
			'selected_post_types' => $this->selected_post_types,
			'post_type_mappings'  => $this->post_type_mappings,
			'include_media'       => $this->include_media,
			'batch_size'          => $this->batch_size,
		);
	}

	/**
	 * Validate the configuration.
	 *
	 * @return array{'valid':bool,'errors':string[]}
	 */
	public function validate(): array {
		$errors           = array();
		$valid_post_types = get_post_types( array(), 'names' );

		if ( ! empty( $this->selected_post_types ) ) {
			foreach ( $this->selected_post_types as $post_type ) {
				if ( ! in_array( $post_type, $valid_post_types, true ) ) {
					$errors[] = sprintf( 'Post type "%s" is not registered on this site.', $post_type );
				}
			}

			foreach ( $this->post_type_mappings as $source => $target ) {
				if ( ! in_array( $source, $this->selected_post_types, true ) ) {
					$errors[] = sprintf( 'Post type mapping for "%s" is not part of the selected post types.', $source );
				}
				if ( empty( $target ) || ! is_string( $target ) ) {
					$errors[] = sprintf( 'Target post type for "%s" must be a non-empty string.', $source );
				}
			}
		} else {
			foreach ( $this->post_type_mappings as $source => $target ) {
				if ( ! in_array( $source, $valid_post_types, true ) ) {
					$errors[] = sprintf( 'Post type mapping source "%s" is not registered on this site.', $source );
				}
				if ( empty( $target ) || ! is_string( $target ) ) {
					$errors[] = sprintf( 'Target post type for "%s" must be a non-empty string.', $source );
				}
			}
		}

		if ( $this->batch_size < 1 || $this->batch_size > 100 ) {
			$errors[] = 'Batch size must be between 1 and 100.';
		}

		return array(
			'valid'  => empty( $errors ),
			'errors' => $errors,
		);
	}

	public function get_selected_post_types(): array {
		return $this->selected_post_types;
	}

	public function get_post_type_mappings(): array {
		return $this->post_type_mappings;
	}

	public function should_include_media(): bool {
		return $this->include_media;
	}

	public function get_batch_size(): int {
		return $this->batch_size;
	}

	private function normalize_post_types( array $post_types ): array {
		$post_types = array_map( 'sanitize_key', $post_types );
		$post_types = array_filter( $post_types, 'strlen' );
		return array_values( array_unique( $post_types ) );
	}

	private function normalize_mappings( array $mappings ): array {
		$sanitized = array();
		foreach ( $mappings as $source => $target ) {
			$source = sanitize_key( $source );
			$target = sanitize_key( $target );
			if ( $source && $target ) {
				$sanitized[ $source ] = $target;
			}
		}
		return $sanitized;
	}

	private function clamp_batch_size( int $batch_size ): int {
		return max( 1, min( 100, $batch_size ) );
	}
}
