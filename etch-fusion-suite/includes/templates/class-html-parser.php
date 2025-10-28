<?php
namespace Bricks2Etch\Templates;

use Bricks2Etch\Core\EFS_Error_Handler;
use DOMDocument;
use DOMElement;
use DOMXPath;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML parser utility for template extraction workflows.
 */
class EFS_HTML_Parser {
	/**
	 * Error handler used for logging.
	 *
	 * @var EFS_Error_Handler
	 */
	protected $error_handler;

	/**
	 * Constructor.
	 *
	 * @param EFS_Error_Handler $error_handler Error handler instance.
	 */
	public function __construct( EFS_Error_Handler $error_handler ) {
		$this->error_handler = $error_handler;
	}

	/**
	 * Parses raw HTML into a DOMDocument instance.
	 *
	 * @param string $html Raw HTML string.
	 * @return DOMDocument|WP_Error Parsed DOM on success or WP_Error on failure.
	 */
	public function parse_html( $html ) {
		if ( empty( $html ) ) {
			return new WP_Error( 'b2e_html_parser_empty_html', __( 'No HTML provided for parsing.', 'etch-fusion-suite' ) );
		}

		$dom = new DOMDocument();

		libxml_use_internal_errors( true );

		$options = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
		$loaded  = $dom->loadHTML( $html, $options );

		$errors = libxml_get_errors();
		libxml_clear_errors();

		if ( ! $loaded ) {
			$this->log_libxml_errors( $errors );

			return new WP_Error( 'b2e_html_parser_load_failed', __( 'Failed to parse HTML content.', 'etch-fusion-suite' ) );
		}

		return $dom;
	}

	/**
	 * Fetches HTML from the provided URL and parses it into a DOMDocument.
	 *
	 * @param string $url Remote URL.
	 * @return DOMDocument|WP_Error Parsed DOM or error.
	 */
	public function parse_from_url( $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'b2e_html_parser_invalid_url', __( 'An invalid URL was provided.', 'etch-fusion-suite' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'headers'   => array(
					'User-Agent' => 'EtchFusionSuite/1.0 (+https://etchwp.com)',
				),
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error(
				'b2e_html_parser_http_error',
				__( 'Failed to fetch HTML from the remote source.', 'etch-fusion-suite' ),
				array( 'status_code' => $code )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'b2e_html_parser_empty_body', __( 'Empty response body received from remote source.', 'etch-fusion-suite' ) );
		}

		return $this->parse_html( $body );
	}

	/**
	 * Creates an XPath helper for the provided DOM document.
	 *
	 * @param DOMDocument $dom DOM document instance.
	 * @return DOMXPath XPath helper.
	 */
	public function get_xpath( DOMDocument $dom ) {
		return new DOMXPath( $dom );
	}

	/**
	 * Executes an XPath query against a DOM document and returns matching elements.
	 *
	 * @param DOMDocument $dom          DOM document.
	 * @param string      $xpath_query  XPath query string.
	 * @return array<int,DOMElement> Matching elements.
	 */
	public function query_elements( DOMDocument $dom, $xpath_query ) {
		$xpath   = $this->get_xpath( $dom );
		$results = $xpath->query( $xpath_query );

		$elements = array();
		if ( false === $results ) {
			return $elements;
		}

		foreach ( $results as $node ) {
			if ( $node instanceof DOMElement ) {
				$elements[] = $node;
			}
		}

		return $elements;
	}

	/**
	 * Returns a cleaned text content for the provided element.
	 *
	 * @param DOMElement $element Element to extract text from.
	 * @return string Sanitized text content.
	 */
	public function extract_text_content( DOMElement $element ) {
		// textContent is a native DOMElement property (camelCase) provided by PHP.
		$text = trim( preg_replace( '/\s+/', ' ', $element->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return wp_strip_all_tags( $text );
	}

	/**
	 * Returns all attributes on the element as an associative array.
	 *
	 * @param DOMElement $element Element to inspect.
	 * @return array<string,string> Attribute map.
	 */
	public function get_element_attributes( DOMElement $element ) {
		$attributes = array();
		foreach ( $element->attributes as $attribute ) {
			$attributes[ $attribute->name ] = $attribute->value;
		}

		return $attributes;
	}

	/**
	 * Logs libxml parsing errors via the error handler.
	 *
	 * @param array<int,\LibXMLError> $errors Collected libxml errors.
	 * @return void
	 */
	protected function log_libxml_errors( $errors ) {
		if ( empty( $errors ) ) {
			return;
		}

		foreach ( $errors as $error ) {
			$this->error_handler->log_error(
				'B2E_HTML_PARSER',
				array(
					'level'   => $error->level,
					'code'    => $error->code,
					'message' => trim( $error->message ),
					'line'    => $error->line,
				),
				'warning'
			);
		}
	}
}
