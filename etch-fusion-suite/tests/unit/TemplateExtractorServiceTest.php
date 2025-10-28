<?php

declare(strict_types=1);

namespace Bricks2Etch\Tests\Unit;

use Bricks2Etch\Services\EFS_Template_Extractor_Service;
use WP_UnitTestCase;

class TemplateExtractorServiceTest extends WP_UnitTestCase {

    /** @var EFS_Template_Extractor_Service */
    private $service;

    /** @var string */
    private $fixtureHtml;

    public function setUp(): void {
        parent::setUp();

        $container = \etch_fusion_suite_container();
        $this->service = $container->get('template_extractor_service');

        $fixture_path = ETCH_FUSION_SUITE_DIR . '/tests/fixtures/framer-sample.html';
        $this->fixtureHtml = file_get_contents($fixture_path);
        $this->assertNotFalse($this->fixtureHtml, 'Fixture HTML should be readable.');
    }

    public function test_extract_from_html_string_returns_payload(): void {
        $payload = $this->service->extract_from_html($this->fixtureHtml);

        $this->assertIsArray($payload, 'Extractor should return an array payload.');

        foreach (array('blocks', 'styles', 'metadata', 'stats') as $expectedKey) {
            $this->assertArrayHasKey($expectedKey, $payload, sprintf('Payload should contain "%s" key.', $expectedKey));
        }

        $this->assertNotEmpty($payload['blocks'], 'Generated blocks should not be empty.');
        $this->assertIsInt($payload['metadata']['complexity_score'], 'Complexity score should be an integer value.');
        $this->assertSame(
            count($payload['blocks']),
            $payload['stats']['block_count'],
            'Stats block_count should equal the number of generated blocks.'
        );
    }

    public function test_validate_template_detects_empty_blocks(): void {
        $validation = $this->service->validate_template(array('blocks' => array()));

        $this->assertFalse($validation['valid'], 'Validation should fail when blocks are empty.');
        $this->assertNotEmpty($validation['errors'], 'Validation errors should be returned for empty blocks.');
    }
}
