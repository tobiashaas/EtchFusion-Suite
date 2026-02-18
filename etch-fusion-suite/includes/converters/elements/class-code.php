<?php
/**
 * Code Element Converter
 *
 * Converts Bricks code elements to Etch blocks with maximum native integration.
 *
 * JavaScript from both the `javascriptCode` field and `<script>` tags in the
 * HTML `code` field is extracted and placed into the Etch-native `script.code`
 * attribute (base64) on a `wp:etch/element` block.
 *
 * CSS from the `cssCode` field and `<style>` tags in the HTML `code` field is
 * output as `wp:etch/raw-html` with `<style>` wrapping, since Etch has no
 * dedicated CSS attribute on its element block.
 *
 * Remaining HTML is output as `wp:etch/raw-html`. PHP code is detected and
 * output as a non-executable warning block.
 *
 * @package    Bricks_Etch_Migration
 * @subpackage Converters\Elements
 * @since      0.5.0
 */

namespace Bricks2Etch\Converters\Elements;

use Bricks2Etch\Converters\EFS_Base_Element;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EFS_Element_Code extends EFS_Base_Element {

	/**
	 * Element type.
	 *
	 * @var string
	 */
	protected $element_type = 'code';

	/**
	 * Convert Bricks code element to Etch block(s).
	 *
	 * Extracts JS, CSS, and HTML from the Bricks code element fields and
	 * generates the most native Etch representation possible for each.
	 *
	 * @param array $element  Bricks element.
	 * @param array $children Child elements (unused for code).
	 * @param array $context  Optional. Conversion context.
	 * @return string Gutenberg block HTML.
	 */
	public function convert( $element, $children = array(), $context = array() ) {
		$settings = $element['settings'] ?? array();

		$code     = is_string( $settings['code'] ?? '' ) ? trim( $settings['code'] ?? '' ) : '';
		$css_code = is_string( $settings['cssCode'] ?? '' ) ? trim( $settings['cssCode'] ?? '' ) : '';
		$js_code  = is_string( $settings['javascriptCode'] ?? '' ) ? trim( $settings['javascriptCode'] ?? '' ) : '';

		// 1. Check for PHP — output warning block and return early.
		if ( $this->contains_php( $code ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logs conversion warnings for unsupported PHP in code block.
			error_log( 'EFS Code: PHP tags detected in element "' . $this->get_label( $element ) . '", not supported in Etch' );
			return $this->build_php_warning_block( $code, $element );
		}

		// 2. Extract <script> tags from the HTML field.
		$script_result  = $this->extract_script_tags( $code );
		$extracted_js   = $script_result['scripts'];
		$remaining_html = $script_result['remaining_html'];

		// 3. Extract <style> tags from the remaining HTML.
		$style_result   = $this->extract_style_tags( $remaining_html );
		$extracted_css  = $style_result['styles'];
		$remaining_html = $style_result['remaining_html'];

		// 4. Combine JS sources: javascriptCode field + extracted <script> tags.
		$all_js_parts = array();
		if ( '' !== $js_code ) {
			$all_js_parts[] = $js_code;
		}
		foreach ( $extracted_js as $script ) {
			$trimmed = trim( $script );
			if ( '' !== $trimmed ) {
				$all_js_parts[] = $trimmed;
			}
		}
		$combined_js = implode( "\n\n", $all_js_parts );

		// 5. Combine CSS sources: cssCode field + extracted <style> tags.
		$all_css_parts = array();
		if ( '' !== $css_code ) {
			$all_css_parts[] = $css_code;
		}
		foreach ( $extracted_css as $style ) {
			$trimmed = trim( $style );
			if ( '' !== $trimmed ) {
				$all_css_parts[] = $trimmed;
			}
		}
		$combined_css = implode( "\n\n", $all_css_parts );

		// 6. Clean remaining HTML (strip empty comments, whitespace-only content).
		$remaining_html = $this->clean_remaining_html( $remaining_html );

		// 7. Generate output blocks.
		$blocks = array();

		$js_block = $this->build_js_block( $combined_js, $element );
		if ( '' !== $js_block ) {
			$blocks[] = $js_block;
		}

		$css_block = $this->build_css_block( $combined_css, $element );
		if ( '' !== $css_block ) {
			$blocks[] = $css_block;
		}

		$html_block = $this->build_html_block( $remaining_html, $element );
		if ( '' !== $html_block ) {
			$blocks[] = $html_block;
		}

		if ( empty( $blocks ) ) {
			return '';
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Extract `<script>` tags from HTML and return their contents plus the remaining HTML.
	 *
	 * Handles `<script>`, `<script type="text/javascript">`, and `<script type="module">`.
	 *
	 * @param string $html Raw HTML content.
	 * @return array { scripts: string[], remaining_html: string }
	 */
	private function extract_script_tags( $html ) {
		$scripts = array();

		if ( '' === $html ) {
			return array(
				'scripts'        => $scripts,
				'remaining_html' => $html,
			);
		}

		$remaining = preg_replace_callback(
			'/<script(?:\s[^>]*)?>(.+?)<\/script>/is',
			static function ( $matches ) use ( &$scripts ) {
				$scripts[] = $matches[1];
				return '';
			},
			$html
		);

		return array(
			'scripts'        => $scripts,
			'remaining_html' => null !== $remaining ? $remaining : $html,
		);
	}

	/**
	 * Extract `<style>` tags from HTML and return their contents plus the remaining HTML.
	 *
	 * @param string $html Raw HTML content.
	 * @return array { styles: string[], remaining_html: string }
	 */
	private function extract_style_tags( $html ) {
		$styles = array();

		if ( '' === $html ) {
			return array(
				'styles'         => $styles,
				'remaining_html' => $html,
			);
		}

		$remaining = preg_replace_callback(
			'/<style(?:\s[^>]*)?>(.+?)<\/style>/is',
			static function ( $matches ) use ( &$styles ) {
				$styles[] = $matches[1];
				return '';
			},
			$html
		);

		return array(
			'styles'         => $styles,
			'remaining_html' => null !== $remaining ? $remaining : $html,
		);
	}

	/**
	 * Check whether HTML contains PHP opening tags.
	 *
	 * @param string $code HTML/code content.
	 * @return bool True if PHP tags are present.
	 */
	private function contains_php( $code ) {
		return '' !== $code && false !== strpos( $code, '<?' );
	}

	/**
	 * Strip HTML comments and collapse whitespace; return empty string if nothing remains.
	 *
	 * @param string $html Raw HTML after script/style extraction.
	 * @return string Cleaned HTML or empty string.
	 */
	private function clean_remaining_html( $html ) {
		// Remove HTML comments.
		$cleaned = preg_replace( '/<!--.*?-->/s', '', $html );
		if ( null === $cleaned ) {
			$cleaned = $html;
		}

		// Trim whitespace.
		$cleaned = trim( $cleaned );

		return $cleaned;
	}

	/**
	 * Build an etch/element block carrying base64-encoded JavaScript.
	 *
	 * Mirrors the format produced by the Etch visual editor:
	 * `{"script":{"code":"<base64>"},"tag":"div","metadata":{...}}`
	 *
	 * @param string $js_code Raw JavaScript source.
	 * @param array  $element Bricks element (for label / styles).
	 * @return string Block HTML, or empty string when there is no JS.
	 */
	private function build_js_block( $js_code, array $element ) {
		if ( '' === $js_code ) {
			return '';
		}

		$label     = $this->get_label( $element );
		$style_ids = $this->get_style_ids( $element );

		$attrs = $this->build_attributes( $label, $style_ids, array(), 'div', $element );

		// Attach JS as base64 — Etch native format.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required: Etch stores element JS as base64.
		$attrs['script'] = array(
			'code' => base64_encode( $js_code ),
		);

		return $this->generate_etch_element_block( $attrs );
	}

	/**
	 * Build an etch/raw-html block wrapping CSS in a `<style>` tag.
	 *
	 * Etch has no native `style.code` attribute, so custom CSS must be embedded
	 * via `etch/raw-html` with a `<style>` tag.
	 *
	 * @param string $css_code Raw CSS content.
	 * @param array  $element  Bricks element (for label).
	 * @return string Block HTML, or empty string when there is no CSS.
	 */
	private function build_css_block( $css_code, array $element ) {
		if ( '' === $css_code ) {
			return '';
		}

		$label = $this->get_label( $element );

		$attrs = array(
			'content'  => '<style>' . $css_code . '</style>',
			'unsafe'   => false,
			'metadata' => array(
				'name' => $label . ' (CSS)',
			),
		);

		$attrs_json = $this->encode_block_comment_attrs( $attrs );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}

	/**
	 * Build an etch/raw-html block for remaining HTML content.
	 *
	 * @param string $html    HTML content.
	 * @param array  $element Bricks element (for label / executeCode flag).
	 * @return string Block HTML, or empty string when there is no HTML.
	 */
	private function build_html_block( $html, array $element ) {
		if ( '' === $html ) {
			return '';
		}

		$settings     = $element['settings'] ?? array();
		$execute_code = isset( $settings['executeCode'] ) && true === $settings['executeCode'];
		$label        = $this->get_label( $element );

		$attrs = array(
			'content'  => $html,
			'unsafe'   => $execute_code,
			'metadata' => array(
				'name' => $label,
			),
		);

		$attrs_json = $this->encode_block_comment_attrs( $attrs );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}

	/**
	 * Build a non-executable etch/raw-html block with a PHP warning comment.
	 *
	 * The original PHP code is preserved inside an HTML comment so it is not
	 * executed on the Etch site, while remaining visible for manual migration.
	 *
	 * @param string $code    Original HTML/PHP code.
	 * @param array  $element Bricks element (for label).
	 * @return string Block HTML.
	 */
	private function build_php_warning_block( $code, array $element ) {
		$label = $this->get_label( $element );

		$warning = '<!-- [EFS Migration] PHP code detected — manual migration required.' . "\n"
			. 'Original code:' . "\n"
			. esc_html( $code ) . "\n"
			. '-->';

		$attrs = array(
			'content'  => $warning,
			'unsafe'   => false,
			'metadata' => array(
				'name' => $label . ' (PHP — requires manual migration)',
			),
		);

		$attrs_json = $this->encode_block_comment_attrs( $attrs );

		return '<!-- wp:etch/raw-html ' . $attrs_json . ' -->' . "\n" .
			'<!-- /wp:etch/raw-html -->';
	}

	/**
	 * Encode block attributes for safe use inside HTML comment delimiters.
	 *
	 * Gutenberg block comments can be destabilized by raw "--" sequences in JSON
	 * values (common in CSS custom properties). Replace them with Unicode escapes
	 * at the JSON text layer so parsing remains stable while semantic content stays
	 * equivalent after JSON decode.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	private function encode_block_comment_attrs( array $attrs ) {
		$json = wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		// Keep block comment payload parse-safe when content includes CSS vars/comments.
		return str_replace( '--', '\u002d\u002d', (string) $json );
	}
}
