<?php
/**
 * Yoda conditions regression tests.
 *
 * @package EtchFusionSuite\Tests
 */

declare( strict_types=1 );

namespace {
	$GLOBALS['__efs_test_options'] = array();

	function get_option( $option, $default = false ) {
		return $GLOBALS['__efs_test_options'][ $option ] ?? $default;
	}

	function update_option( $option, $value, $autoload = false ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$GLOBALS['__efs_test_options'][ $option ] = $value;
		return true;
	}

	function delete_option( $option ) {
		unset( $GLOBALS['__efs_test_options'][ $option ] );
		return true;
	}

	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( $file ) {
			return rtrim( dirname( $file ), '/\\' ) . '/';
		}
	}
}

namespace EtchFusionSuite\Tests\Unit {

use Bricks2Etch\Parsers\EFS_CSS_Converter;
use Bricks2Etch\Parsers\EFS_Gutenberg_Generator;
use Bricks2Etch\Repositories\Interfaces\Settings_Repository_Interface;
use Bricks2Etch\Repositories\Interfaces\Style_Repository_Interface;
use Bricks2Etch\Security\EFS_Audit_Logger;
use Bricks2Etch\Security\EFS_CORS_Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class Test_Yoda_Conditions extends TestCase {
	/**
	 * Ensures CSS converter style map selector comparisons remain Yoda-compliant.
	 */
	public function test_css_converter_style_map_selector_matching() : void {
		/** @var Style_Repository_Interface&MockObject $style_repository */
		$style_repository = $this->createMock( Style_Repository_Interface::class );
		/** @var \Bricks2Etch\Core\EFS_Error_Handler&MockObject $error_handler */
		$error_handler = $this->createMock( \Bricks2Etch\Core\EFS_Error_Handler::class );

		$converter = new EFS_CSS_Converter( $error_handler, $style_repository );

		$style_map = array(
			'bricks-old'    => array(
				'id'       => 'etch-old',
				'selector' => '.selector-old',
			),
			'bricks-new'    => array(
				'id'       => 'etch-new',
				'selector' => 'selector-new',
			),
			'legacy-string' => 'etch-legacy',
		);

		$stylesheet = <<<CSS
.selector-old {
  color: red;
}

.selector-new {
  font-weight: bold;
}
CSS;

		$reflection = new \ReflectionClass( $converter );
		$method     = $reflection->getMethod( 'parse_custom_css_stylesheet' );
		$method->setAccessible( true );

		$styles = $method->invoke( $converter, $stylesheet, $style_map );

		self::assertArrayHasKey( 'etch-old', $styles );
		self::assertSame( '.selector-old', $styles['etch-old']['selector'] );
		self::assertArrayHasKey( 'etch-new', $styles );
		self::assertSame( '.selector-new', $styles['etch-new']['selector'] );
		self::assertArrayNotHasKey( 'etch-legacy', $styles );
	}

	/**
	 * Ensures Gutenberg generator class and style mapping comparisons remain Yoda-safe.
	 */
	public function test_gutenberg_generator_class_and_style_mapping() : void {
		/** @var Style_Repository_Interface&MockObject $style_repository */
		$style_repository = $this->createMock( Style_Repository_Interface::class );
		/** @var \Bricks2Etch\Core\EFS_Error_Handler&MockObject $error_handler */
		$error_handler = $this->createMock( \Bricks2Etch\Core\EFS_Error_Handler::class );
		/** @var \Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter&MockObject $dynamic_data */
		$dynamic_data = $this->createMock( \Bricks2Etch\Parsers\EFS_Dynamic_Data_Converter::class );
		/** @var \Bricks2Etch\Parsers\EFS_Content_Parser&MockObject $content_parser */
		$content_parser = $this->createMock( \Bricks2Etch\Parsers\EFS_Content_Parser::class );

		$gutenberg_generator = new EFS_Gutenberg_Generator( $error_handler, $dynamic_data, $content_parser );

		$style_map = array(
			'bricks-old'    => array(
				'id'       => 'etch-old',
				'selector' => '.selector-old',
			),
			'bricks-new'    => array(
				'id'       => 'etch-new',
				'selector' => '.selector-new',
			),
			'legacy-format' => 'etch-legacy',
		);

		$classes_option = array(
			array(
				'id'   => 'bricks-old',
				'name' => 'selector-old',
			),
			array(
				'id'   => 'bricks-new',
				'name' => 'selector-new',
			),
		);

		$previous_options = $GLOBALS['__efs_test_options'];
		$GLOBALS['__efs_test_options']['b2e_style_map']        = $style_map;
		$GLOBALS['__efs_test_options']['bricks_global_classes'] = $classes_option;

		$element = array(
			'name'     => 'div',
			'children' => array(),
			'settings' => array(
				'_cssGlobalClasses' => array( 'bricks-old' ),
				'_cssClasses'       => 'selector-new',
			),
		);

		$style_ids = $this->callPrivateMethod( $gutenberg_generator, 'get_element_style_ids', array( $element ) );
		self::assertContains( 'etch-old', $style_ids );
		self::assertContains( 'etch-new', $style_ids );

		$classes = $this->callPrivateMethod( $gutenberg_generator, 'get_element_classes', array( $element ) );
		self::assertContains( 'selector-old', $classes );
		self::assertContains( 'selector-new', $classes );

		$css_classes = $this->callPrivateMethod( $gutenberg_generator, 'get_css_classes_from_style_ids', array( array( 'etch-old', 'etch-new' ) ) );
		self::assertStringContainsString( 'selector-old', $css_classes );
		self::assertStringContainsString( 'selector-new', $css_classes );

		$GLOBALS['__efs_test_options'] = $previous_options;
	}

	/**
	 * Helper to call private methods for regression checks.
	 *
	 * @param object $object    Class instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Arguments.
	 * @return mixed
	 */
	private function callPrivateMethod( object $object, string $method, array $arguments = array() ) {
		$reflection = new \ReflectionClass( $object );
		$callable   = $reflection->getMethod( $method );
		$callable->setAccessible( true );

		return $callable->invokeArgs( $object, $arguments );
	}

	/**
	 * Ensures CORS comparisons remain compliant after Yoda conversion.
	 *
	 * @dataProvider corsOriginProvider
	 */
	public function test_cors_origin_matching_with_yoda_comparison( string $origin, array $allowed, bool $expected ) : void {
		/** @var Settings_Repository_Interface&MockObject $settings_repo */
		$settings_repo = $this->createStub( Settings_Repository_Interface::class );
		$settings_repo->method( 'get_cors_allowed_origins' )->willReturn( $allowed );

		$cors_manager = new EFS_CORS_Manager( $settings_repo );
		$result       = $cors_manager->is_origin_allowed( $origin );

		self::assertSame( $expected, $result );
	}

	/**
	 * Provides origin matching scenarios.
	 *
	 * @return array<string,array{string,array<int,string>,bool}>
	 */
	public static function corsOriginProvider() : array {
		return array(
			'allowed exact match'           => array( 'https://allowed.test', array( 'https://allowed.test' ), true ),
			'allowed with trailing slash'   => array( 'https://allowed.test/', array( 'https://allowed.test' ), true ),
			'allowed differing slash'       => array( 'https://allowed.test', array( 'https://allowed.test/' ), true ),
			'not allowed origin'            => array( 'https://denied.test', array( 'https://allowed.test' ), false ),
			'empty origin'                  => array( '', array( 'https://allowed.test' ), false ),
		);
	}

	/**
	 * Ensures audit logger severity filter remains correct after Yoda conversion.
	 */
	public function test_audit_logger_severity_filtering_with_yoda_comparison() : void {
		// Reset option storage between assertions.
		$GLOBALS['__efs_test_options'] = array();

		$logger = new EFS_Audit_Logger();

		$logs = array(
			array( 'severity' => 'low' ),
			array( 'severity' => 'high' ),
			array( 'severity' => 'critical' ),
		);

		update_option( 'efs_security_log', $logs, false );

		$filtered = $logger->get_security_logs( 10, 'high' );
		self::assertCount( 1, $filtered );
		self::assertSame( 'high', $filtered[0]['severity'] );
	}
}

}
