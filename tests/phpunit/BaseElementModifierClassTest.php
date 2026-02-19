<?php
/**
 * Base element modifier-class handling tests.
 */

declare( strict_types=1 );

namespace {
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			if ( isset( $GLOBALS['__efs_test_options'] ) && is_array( $GLOBALS['__efs_test_options'] ) && array_key_exists( $name, $GLOBALS['__efs_test_options'] ) ) {
				return $GLOBALS['__efs_test_options'][ $name ];
			}
			return $default;
		}
	}
}

namespace EtchFusionSuite\Tests {

use Bricks2Etch\Converters\EFS_Base_Element;
use PHPUnit\Framework\TestCase;

final class BaseElementModifierClassTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__efs_test_options'] = array();
	}

	public function test_resolve_single_neighbor_style_id_ignores_modifier_classes_from_css_classes(): void {
		$converter = new StubBaseElement(
			array(
				'row-base'     => array(
					'id'       => 'style-base',
					'selector' => '.fr-feature-card-yankee__row',
				),
				'row-featured' => array(
					'id'       => 'style-featured',
					'selector' => '.fr-feature-card-yankee__row--featured',
				),
			)
		);

		$resolved_style_id = $converter->resolveSingleNeighborStyleIdPublic(
			array(
				'settings' => array(
					'_cssClasses' => 'fr-feature-card-yankee__row fr-feature-card-yankee__row--featured',
				),
			)
		);

		$this->assertSame( 'style-base', $resolved_style_id );
	}

	public function test_resolve_single_neighbor_style_id_works_with_legacy_style_map_and_global_class_name_lookup(): void {
		$GLOBALS['__efs_test_options']['bricks_global_classes'] = array(
			array(
				'id'   => 'row-base-id',
				'name' => 'fr-feature-card-yankee__row',
			),
			array(
				'id'   => 'row-mod-id',
				'name' => 'fr-feature-card-yankee__row--featured',
			),
		);

		$converter = new StubBaseElement(
			array(
				'row-base-id' => 'style-base',
				'row-mod-id'  => 'style-mod',
			)
		);

		$resolved_style_id = $converter->resolveSingleNeighborStyleIdPublic(
			array(
				'settings' => array(
					'_cssGlobalClasses' => array( 'row-base-id', 'row-mod-id' ),
				),
			)
		);

		$this->assertSame( 'style-base', $resolved_style_id );
	}

	public function test_resolve_single_neighbor_style_id_falls_back_to_etch_styles_when_style_map_has_no_selector(): void {
		$GLOBALS['__efs_test_options']['etch_styles'] = array(
			'style-base' => array(
				'selector' => '.fr-feature-card-yankee__row',
				'css'      => 'display: grid;',
			),
		);

		$converter = new StubBaseElement(
			array(
				'legacy-id' => 'style-base',
			)
		);

		$resolved_style_id = $converter->resolveSingleNeighborStyleIdPublic(
			array(
				'settings' => array(
					'_cssClasses' => 'fr-feature-card-yankee__row fr-feature-card-yankee__row--featured',
				),
			)
		);

		$this->assertSame( 'style-base', $resolved_style_id );
	}

	public function test_modifier_detection_matches_bem_like_double_dash_tokens(): void {
		$converter = new StubBaseElement();

		$this->assertTrue( $converter->isModifierLikeSelectorPublic( 'card--featured' ) );
		$this->assertTrue( $converter->isModifierLikeSelectorPublic( 'card__row--featured' ) );
		$this->assertFalse( $converter->isModifierLikeSelectorPublic( 'card__row' ) );
		$this->assertFalse( $converter->isModifierLikeSelectorPublic( '' ) );
	}

	public function test_build_attributes_maps_brxe_id_prefix_to_etch_and_keeps_regular_ids(): void {
		$converter = new StubBaseElement();

		$attrs_mapped = $converter->buildAttributesPublic(
			'Label',
			array(),
			array(),
			'div',
			array(
				'settings' => array(
					'_attributes' => array(
						array(
							'name'  => 'id',
							'value' => 'brxe-328483',
						),
					),
				),
			)
		);

		$this->assertSame( 'etch-328483', $attrs_mapped['attributes']['id'] ?? '' );

		$attrs_plain = $converter->buildAttributesPublic(
			'Label',
			array(),
			array(),
			'div',
			array(
				'settings' => array(
					'_attributes' => array(
						array(
							'name'  => 'id',
							'value' => 'my-anchor',
						),
					),
				),
			)
		);

		$this->assertSame( 'my-anchor', $attrs_plain['attributes']['id'] ?? '' );
	}

	public function test_build_attributes_normalizes_id_references_for_aria_and_href(): void {
		$converter = new StubBaseElement();

		$attrs = $converter->buildAttributesPublic(
			'Label',
			array(),
			array(),
			'div',
			array(
				'settings' => array(
					'_attributes' => array(
						array(
							'name'  => 'id',
							'value' => 'brxe-foo',
						),
						array(
							'name'  => 'aria-labelledby',
							'value' => 'brxe-title other-id',
						),
						array(
							'name'  => 'for',
							'value' => 'brxe-input',
						),
						array(
							'name'  => 'href',
							'value' => '#brxe-target',
						),
					),
				),
			)
		);

		$this->assertSame( 'etch-foo', $attrs['attributes']['id'] ?? '' );
		$this->assertSame( 'etch-title other-id', $attrs['attributes']['aria-labelledby'] ?? '' );
		$this->assertSame( 'etch-input', $attrs['attributes']['for'] ?? '' );
		$this->assertSame( '#etch-target', $attrs['attributes']['href'] ?? '' );
	}
}

final class StubBaseElement extends EFS_Base_Element {

	public function convert( $element, $children = array(), $context = array() ) {
		return '';
	}

	public function resolveSingleNeighborStyleIdPublic( array $element ): string {
		return (string) $this->resolve_single_neighbor_style_id( $element );
	}

	public function isModifierLikeSelectorPublic( string $selector ): bool {
		return (bool) $this->is_modifier_like_selector( $selector );
	}

	public function buildAttributesPublic( $label, $style_ids, $etch_attributes, $tag = 'div', $element = array() ): array {
		return $this->build_attributes( $label, $style_ids, $etch_attributes, $tag, $element );
	}
}
}
