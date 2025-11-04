<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Integration;

use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use WP_UnitTestCase;

class FramerExtractionIntegrationTest extends WP_UnitTestCase {

    /** @var EFS_Template_Extractor_Service */
    private $extractor;

    /** @var string */
    private $fixtureHtml;

    public function setUp(): void {
        parent::setUp();

        $container = \etch_fusion_suite_container();
        $this->extractor = $container->get('template_extractor_service');

        $fixture_path = ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html';
        $this->fixtureHtml = file_get_contents($fixture_path);
        $this->assertNotFalse($this->fixtureHtml, 'Fixture HTML must be readable.');
    }

    public function test_complete_extraction_pipeline(): void {
        $payload = $this->extractor->extract_from_html($this->fixtureHtml);

        $this->assertIsArray($payload, 'Extractor should return a payload array.');
        $this->assertNotEmpty($payload['blocks'], 'Pipeline should produce at least one block.');
        $this->assertArrayHasKey('metadata', $payload);
        $this->assertArrayHasKey('styles', $payload);

        $overview = $payload['metadata']['section_overview'];
        $this->assertContains('hero', $overview, 'Section overview should contain hero.');
        $this->assertContains('features', $overview, 'Section overview should contain features.');

        $foundSectionTag = false;
        foreach ($payload['blocks'] as $block) {
            if (false !== strpos($block, '"tag":"section"')) {
                $foundSectionTag = true;
                break;
            }
        }
        $this->assertTrue($foundSectionTag, 'At least one generated block should be tagged as section in Etch metadata.');

        $this->assertNotEmpty($payload['styles'], 'CSS variables should produce style definitions.');
    }
}
