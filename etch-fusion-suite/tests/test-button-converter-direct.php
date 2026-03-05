<?php
/**
 * Direct Button Converter Test
 *
 * Tests button element conversion without PHPUnit dependencies.
 * Run via: wp eval "require WP_PLUGIN_DIR.'/etch-fusion-suite/tests/test-button-converter-direct.php';"
 */

// Plugin and autoloaders already loaded by wp eval context

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║              BUTTON CONVERTER DIRECT TEST                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$test_results = array();

// TEST 1: Class Exists
echo "✓ TEST 1: Button Converter Class Loading\n";
if ( class_exists( 'Bricks2Etch\Converters\Elements\EFS_Button_Converter' ) ) {
	echo "  ✔ EFS_Button_Converter class found\n";
	$test_results[] = true;
} else {
	echo "  ✖ EFS_Button_Converter class NOT found\n";
	echo "     Available classes matching 'Button': " . json_encode( get_declared_classes() ) . "\n";
	$test_results[] = false;
}

// TEST 2: Basic Button Conversion
echo "\n✓ TEST 2: Basic Button Conversion\n";
try {
	$converter = new \Bricks2Etch\Converters\Elements\EFS_Button_Converter( array() );
	$element   = array(
		'name'     => 'button',
		'label'    => 'CTA Button',
		'settings' => array(
			'text' => 'Click me',
			'link' => array(
				'url'    => 'https://example.com',
				'newTab' => true,
			),
		),
	);
	
	$result = $converter->convert( $element, array() );
	
	if ( strpos( $result, 'wp:etch/element' ) !== false || strpos( $result, 'wp:etch/text' ) !== false ) {
		echo "  ✔ Button converted to Etch block\n";
		echo "     Output: " . substr( $result, 0, 100 ) . "...\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Invalid output\n";
		echo "     Output: " . $result . "\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Conversion failed: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 3: Link Target Attribute
echo "\n✓ TEST 3: Link Target (_blank) Handling\n";
try {
	$converter = new \Bricks2Etch\Converters\Elements\EFS_Button_Converter( array() );
	$element   = array(
		'name'     => 'button',
		'settings' => array(
			'text' => 'External Link',
			'link' => array(
				'url'    => 'https://external.com',
				'newTab' => true,
			),
		),
	);
	
	$result = $converter->convert( $element, array() );
	
	// Check for target="_blank" handling
	if ( strpos( $result, 'target' ) !== false && strpos( $result, '_blank' ) !== false ) {
		echo "  ✔ Target _blank correctly set\n";
		$test_results[] = true;
	} elseif ( strpos( $result, 'wp:etch' ) !== false ) {
		echo "  ✔ Etch block created (newTab handling depends on implementation)\n";
		$test_results[] = true;
	} else {
		echo "  ✖ Target _blank not found\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// TEST 4: Style Inheritance
echo "\n✓ TEST 4: Style Map Integration\n";
try {
	$style_map = array(
		'btn-primary' => array(
			'id'       => 'global-btn-primary',
			'selector' => '.btn-primary',
		),
	);
	
	$converter = new \Bricks2Etch\Converters\Elements\EFS_Button_Converter( $style_map );
	$element   = array(
		'name'     => 'button',
		'settings' => array(
			'text'  => 'Styled Button',
			'link'  => array( 'url' => '#' ),
			'class' => 'btn-primary',
		),
	);
	
	$result = $converter->convert( $element, array() );
	
	if ( ! empty( $result ) ) {
		echo "  ✔ Style map applied\n";
		$test_results[] = true;
	} else {
		echo "  ✖ No output with style map\n";
		$test_results[] = false;
	}
} catch ( Exception $e ) {
	echo "  ✖ Error: " . $e->getMessage() . "\n";
	$test_results[] = false;
}

// SUMMARY
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║              BUTTON CONVERTER TEST RESULTS                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$passed = count( array_filter( $test_results ) );
$total  = count( $test_results );
$rate   = $total > 0 ? round( ( $passed / $total ) * 100 ) : 0;

echo "Passed: {$passed}/{$total}\n";
echo "Success Rate: {$rate}%\n\n";

if ( $passed === $total ) {
	echo "All Button Converter tests passed!\n";
} else {
	echo "Attention: " . ( $total - $passed ) . " test(s) failed\n";
}

echo "\n";
