<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Templates\EFS_Framer_HTML_Sanitizer;
use DOMDocument;
use DOMXPath;
use WP_UnitTestCase;

class FramerHtmlSanitizerTest extends WP_UnitTestCase {

    /** @var EFS_Framer_HTML_Sanitizer */
    private $sanitizer;

    /** @var DOMDocument */
    private $dom;

    public function setUp(): void {
        parent::setUp();

        $container = \etch_fusion_suite_container();
        $this->sanitizer = $container->get('html_sanitizer');

        $fixture_path = ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html';
        $html = file_get_contents($fixture_path);
        $this->assertNotFalse($html, 'Fixture HTML must be readable.');

        $parser = $container->get('html_parser');
        $parsed_dom = $parser->parse_html($html);
        $this->assertInstanceOf(DOMDocument::class, $parsed_dom, 'HTML parser should return DOMDocument instance.');

        $this->dom = $parsed_dom;
    }

    public function test_sanitization_removes_framer_scripts(): void {
        $sanitized = $this->sanitizer->sanitize($this->dom);

        $scripts = $sanitized->getElementsByTagName('script');
        foreach ($scripts as $script) {
            $src = $script->getAttribute('src');
            $this->assertStringNotContainsString('framerusercontent.com', $src);
            $this->assertStringNotContainsString('framer.com', $src);
        }
    }

    public function test_semanticization_converts_sections_and_text(): void {
        // Parse HTML again to get a fresh DOM for this test
        $container = \etch_fusion_suite_container();
        $parser = $container->get('html_parser');
        $fixture_path = ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html';
        $html = file_get_contents($fixture_path);
        $fresh_dom = $parser->parse_html($html);
        
        $sanitized = $this->sanitizer->sanitize($fresh_dom);
        $xpath = new DOMXPath($sanitized);

        $heroSection = $xpath->query("//section[contains(@class, 'hero') or contains(@data-framer-name, 'Hero')]");
        $this->assertGreaterThan(0, $heroSection->length, 'Hero container should be transformed to <section>.');

        $heroHeading = $xpath->query("//h1[contains(translate(text(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'next-gen productivity platform')]");
        $this->assertGreaterThan(0, $heroHeading->length, 'Primary heading should be converted to <h1>.');

        $featureSections = $xpath->query("//section[contains(@class, 'features') or contains(@data-framer-name, 'Features')]");
        $this->assertGreaterThan(0, $featureSections->length, 'Features container should be promoted to <section>.');
    }
}
