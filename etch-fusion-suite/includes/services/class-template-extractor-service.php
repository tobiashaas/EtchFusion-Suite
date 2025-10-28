<?php
namespace Bricks2Etch\Services;

use Bricks2Etch\Core\EFS_Error_Handler;
use Bricks2Etch\Repositories\EFS_WordPress_Migration_Repository;
use Bricks2Etch\Security\EFS_Input_Validator;
use Bricks2Etch\Templates\EFS_Etch_Template_Generator;
use Bricks2Etch\Templates\EFS_Framer_HTML_Sanitizer;
use Bricks2Etch\Templates\EFS_Framer_Template_Analyzer;
use Bricks2Etch\Templates\EFS_HTML_Parser;
use Bricks2Etch\Templates\Interfaces\EFS_Template_Extractor_Interface;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service orchestrating template extraction pipeline.
 */
class EFS_Template_Extractor_Service implements EFS_Template_Extractor_Interface {
	/**
	 * @var EFS_HTML_Parser
	 */
	protected $html_parser;

	/**
	 * @var EFS_Framer_HTML_Sanitizer
	 */
	protected $html_sanitizer;

	/**
	 * @var EFS_Framer_Template_Analyzer
	 */
	protected $template_analyzer;

	/**
	 * @var EFS_Etch_Template_Generator
	 */
	protected $template_generator;

	/**
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * @var EFS_Input_Validator
	 */
	protected $input_validator;

	/**
	 * @var EFS_WordPress_Migration_Repository
	 */
	protected $migration_repository;

	/**
	 * Supported extraction sources.
	 *
	 * @var array<int,string>
	 */
	protected $supported_sources = array( 'framer_url', 'framer_html' );

	/**
	 * Internal extraction statistics.
	 *
	 * @var array<string,mixed>
	 */
	protected $stats = array();

	/**
	 * Latest extraction progress snapshot.
	 *
	 * @var array<string,mixed>
	 */
	protected $progress = array();

	/**
	 * Constructor.
	 */
	public function __construct(
		EFS_HTML_Parser $html_parser,
		EFS_Framer_HTML_Sanitizer $html_sanitizer,
		EFS_Framer_Template_Analyzer $template_analyzer,
		EFS_Etch_Template_Generator $template_generator,
		EFS_Error_Handler $error_handler,
		EFS_Input_Validator $input_validator,
		EFS_WordPress_Migration_Repository $migration_repository
	) {
		$this->html_parser          = $html_parser;
		$this->html_sanitizer       = $html_sanitizer;
		$this->template_analyzer    = $template_analyzer;
		$this->template_generator   = $template_generator;
		$this->error_handler        = $error_handler;
		$this->input_validator      = $input_validator;
		$this->migration_repository = $migration_repository;
	}

	/**
	 * {@inheritdoc}
	 */
	public function extract_from_url( string $url ) {
		return $this->extract_template( $url, 'url' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function extract_from_html( string $html ) {
		return $this->extract_template( $html, 'html' );
	}

	/**
	 * Extracts a template from a source input.
	 *
	 * @param string $source Source string (URL or HTML).
	 * @param string $source_type Source type (url|html).
	 * @return array|WP_Error
	 */
	public function extract_template( $source, $source_type = 'url' ) {
		$validation = $this->validate_source( $source, $source_type );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$this->progress = $this->init_progress();
		$this->update_progress( 'fetching' );

		if ( 'url' === $source_type ) {
			$dom = $this->html_parser->parse_from_url( $source );
		} else {
			$dom = $this->html_parser->parse_html( $source );
		}

		if ( is_wp_error( $dom ) ) {
			return $dom;
		}

		$this->update_progress( 'sanitizing' );
		$sanitized_dom = $this->html_sanitizer->sanitize( $dom );

		$this->update_progress( 'analyzing' );
		$analysis = $this->template_analyzer->analyze( $sanitized_dom );

		$this->update_progress( 'generating' );
		$template = $this->template_generator->generate( $sanitized_dom, $analysis, $this->html_sanitizer->get_css_variables() );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		$this->update_progress( 'validating', 0.95 );

		$validation = $this->template_generator->validate_generated_template( $template['blocks'] );
		if ( ! $validation['valid'] ) {
			return new WP_Error( 'b2e_template_extractor_invalid_template', __( 'Generated template failed validation.', 'etch-fusion-suite' ), $validation['errors'] );
		}

		$this->stats = array(
			'source_type'      => $source_type,
			'fetched_at'       => current_time( 'mysql' ),
			'block_count'      => count( $template['blocks'] ),
			'complexity_score' => $analysis['complexity_score'] ?? 0,
			'section_count'    => isset( $analysis['sections'] ) ? count( $analysis['sections'] ) : 0,
			'media_detected'   => isset( $analysis['media'] ) ? count( $analysis['media'] ) : 0,
		);

		$this->update_progress( 'completed', 1 );

		return array_merge( $template, array( 'analysis' => $analysis ) );
	}

	/**
	 * Fetches HTML content from a URL.
	 *
	 * @param string $url
	 * @return string|WP_Error
	 */
	public function fetch_html( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'headers'   => array( 'User-Agent' => 'EtchFusionSuite/1.0 (+https://etchwp.com)' ),
				'sslverify' => apply_filters( 'etch_fusion_suite_https_local_ssl_verify', true ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'b2e_template_extractor_fetch_failed', __( 'Failed to fetch HTML from remote source.', 'etch-fusion-suite' ), array( 'status_code' => $code ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'b2e_template_extractor_empty_html', __( 'Received empty HTML response.', 'etch-fusion-suite' ) );
		}

		return $body;
	}

	/**
	 * Processes a raw HTML string through the pipeline.
	 *
	 * @param string $html
	 * @return array|WP_Error
	 */
	public function process_html_string( $html ) {
		return $this->extract_template( $html, 'html' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate_template( array $template ) {
		$blocks = array();
		if ( isset( $template['blocks'] ) && is_array( $template['blocks'] ) ) {
			$blocks = $template['blocks'];
		}

		return $this->template_generator->validate_generated_template( $blocks );
	}

	/**
	 * Validates incoming source payloads.
	 *
	 * @param string $source
	 * @param string $type
	 * @return true|WP_Error
	 */
	public function validate_source( $source, $type ) {
		if ( 'url' === $type ) {
			if ( ! filter_var( $source, FILTER_VALIDATE_URL ) ) {
				return new WP_Error( 'b2e_template_extractor_invalid_url', __( 'Please provide a valid Framer URL.', 'etch-fusion-suite' ) );
			}
		} elseif ( 'html' === $type ) {
			if ( empty( trim( $source ) ) ) {
				return new WP_Error( 'b2e_template_extractor_empty_html', __( 'Please provide Framer HTML to import.', 'etch-fusion-suite' ) );
			}
		} else {
			return new WP_Error( 'b2e_template_extractor_invalid_source_type', __( 'Unsupported source type provided.', 'etch-fusion-suite' ) );
		}

		return true;
	}

	/**
	 * Saves template as Etch draft post.
	 *
	 * @param array  $template Template payload.
	 * @param string $name Desired template name.
	 * @return int|WP_Error Post ID on success.
	 */
	public function save_template( array $template, $name ) {
		$sanitized_name = sanitize_text_field( $name );
		if ( '' === $sanitized_name ) {
			$sanitized_name = __( 'Imported Framer Template', 'etch-fusion-suite' );
		}

		$post_blocks = array();
		if ( isset( $template['blocks'] ) && is_array( $template['blocks'] ) ) {
			$post_blocks = $template['blocks'];
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'etch_template',
				'post_title'   => $sanitized_name,
				'post_status'  => 'draft',
				'post_content' => maybe_serialize( $post_blocks ),
			)
		);

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return new WP_Error( 'b2e_template_save_failed', __( 'Unable to save template as draft.', 'etch-fusion-suite' ) );
		}

		update_post_meta( $post_id, '_b2e_template_styles', $template['styles'] ?? array() );
		update_post_meta( $post_id, '_b2e_template_metadata', $template['metadata'] ?? array() );
		update_post_meta( $post_id, '_b2e_template_stats', $template['stats'] ?? array() );

		return $post_id;
	}

	/**
	 * Returns progress data for current extraction.
	 *
	 * @return array<string,mixed>
	 */
	public function get_extraction_progress() {
		return $this->progress;
	}

	/**
	 * Returns saved templates summary.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_saved_templates() {
		$query = new \WP_Query(
			array(
				'post_type'      => 'etch_template',
				'post_status'    => array( 'draft', 'publish' ),
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$templates = array();

		foreach ( $query->posts as $post ) {
			$templates[] = array(
				'id'         => $post->ID,
				'title'      => get_the_title( $post ),
				'created_at' => $post->post_date,
				'metadata'   => get_post_meta( $post->ID, '_b2e_template_metadata', true ),
				'preview'    => maybe_unserialize( $post->post_content ),
			);
		}

		return $templates;
	}

	/**
	 * Returns stats from last extraction.
	 *
	 * @return array<string,mixed>
	 */
	public function get_extraction_stats() {
		return $this->stats;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_supported_sources() {
		return $this->supported_sources;
	}

	/**
	 * Initializes a progress structure.
	 *
	 * @return array<string,mixed>
	 */
	protected function init_progress() {
		return array(
			'status'   => 'idle',
			'step'     => 'idle',
			'progress' => 0,
			'steps'    => array( 'fetching', 'sanitizing', 'analyzing', 'generating', 'validating', 'completed' ),
		);
	}

	/**
	 * Updates progress tracker.
	 *
	 * @param string   $step
	 * @param float    $percent
	 * @return void
	 */
	protected function update_progress( $step, $percent = 0 ) {
		$this->progress['status']   = $step;
		$this->progress['step']     = $step;
		$this->progress['progress'] = max( $this->progress['progress'], $percent > 0 ? $percent : $this->guess_step_progress( $step ) );
	}

	/**
	 * Roughly maps step to progress value.
	 *
	 * @param string $step
	 * @return float
	 */
	protected function guess_step_progress( $step ) {
		$map = array(
			'fetching'   => 0.1,
			'sanitizing' => 0.35,
			'analyzing'  => 0.55,
			'generating' => 0.75,
			'validating' => 0.95,
			'completed'  => 1,
		);

		return $map[ $step ] ?? $this->progress['progress'];
	}
}
