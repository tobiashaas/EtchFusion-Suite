<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Templates\EFS_Framer_HTML_Sanitizer;
use Bricks2Etch\Templates\EFS_Framer_Template_Analyzer;
use Bricks2Etch\Templates\EFS_HTML_Parser;
use DOMDocument;
use WP_UnitTestCase;

class FramerTemplateAnalyzerTest extends WP_UnitTestCase {

    /** @var EFS_HTML_Parser */
    private $parser;

    /** @var EFS_Framer_HTML_Sanitizer */
    private $sanitizer;

    /** @var EFS_Framer_Template_Analyzer */
    private $analyzer;

    /** @var DOMDocument */
    private $sanitizedDom;

    /** @var array */
    private $analysis;

    public function setUp(): void {
        parent::setUp();

        $container = \efs_container();
        $this->parser = $container->get('html_parser');
        $this->sanitizer = $container->get('html_sanitizer');
        $this->analyzer = $container->get('template_analyzer');

        $fixture_path = EFS_PLUGIN_DIR . '/tests/fixtures/framer-sample.html';
        $html = file_get_contents($fixture_path);
        $this->assertNotFalse($html, 'Fixture HTML must be readable.');

        $dom = $this->parser->parse_html($html);
        $this->assertInstanceOf(DOMDocument::class, $dom);

        $this->sanitizedDom = $this->sanitizer->sanitize($dom);
        $this->analysis = $this->analyzer->analyze($this->sanitizedDom);
    }

    public function test_identify_sections_detects_expected_types(): void {
        $sections = $this->analysis['sections'];
        $types = array();

        foreach ($sections as $section) {
            $types[] = $section['type'];
        }

        $this->assertContains('hero', $types, 'Hero section should be identified.');
        $this->assertContains('features', $types, 'Features section should be identified.');
        $this->assertContains('footer', $types, 'Footer section should be identified.');

        foreach ($sections as $section) {
            $this->assertArrayHasKey('confidence', $section);
            $this->assertGreaterThan(0, $section['confidence'], 'Section confidence should be positive.');
        }
    }

    public function test_detect_media_elements_flags_framerusercontent(): void {
        $media = $this->analysis['media'];
        $this->assertNotEmpty($media, 'Media detection should return items.');

        $found = false;
        foreach ($media as $item) {
            if (isset($item['source']) && 'framerusercontent' === $item['source']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Media detector should flag framerusercontent images.');
    }
}
