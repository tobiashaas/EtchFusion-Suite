<?php
/**
 * Test Element Converters (LocalWP Version)
 * 
 * Tests the new modular element converter structure
 */

echo "=== Testing Element Converters ===\n\n";

// Get style map
$style_map = get_option('efs_style_map', array());
echo "Style map loaded: " . count($style_map) . " entries\n\n";

// Create factory
$registry = new \Bricks2Etch\Converters\EFS_Converter_Registry();
$factory = new \Bricks2Etch\Converters\EFS_Element_Factory( $registry, $style_map );

// Test 1: Container with ul tag
echo "--- Test 1: Container with ul tag ---\n";
$container_element = array(
    'id' => '1bf80b',
    'name' => 'container',
    'label' => 'Feature Grid Sierra (CSS Tab) <ul>',
    'settings' => array(
        '_cssGlobalClasses' => array('bTySculwtsp'),
        'tag' => 'ul'
    )
);

$result = $factory->convert_element($container_element, array());
if ($result) {
    // Check if tagName is set
    if (strpos($result, '"tagName":"ul"') !== false) {
        echo "✅ PASS: tagName is 'ul'\n";
    } else {
        echo "❌ FAIL: tagName is NOT 'ul'\n";
        echo "Result: " . substr($result, 0, 200) . "...\n";
    }
    
    // Check if tag in block is ul
    if (strpos($result, '"tag":"ul"') !== false) {
        echo "✅ PASS: block.tag is 'ul'\n";
    } else {
        echo "❌ FAIL: block.tag is NOT 'ul'\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 2: Div with li tag ---\n";
$div_element = array(
    'id' => 'b08ea2',
    'name' => 'div',
    'label' => 'Feature Card Sierra <li>',
    'settings' => array(
        '_cssGlobalClasses' => array('bTySctnmzzp'),
        'tag' => 'li'
    )
);

$result = $factory->convert_element($div_element, array());
if ($result) {
    // Check if tagName is set
    if (strpos($result, '"tagName":"li"') !== false) {
        echo "✅ PASS: tagName is 'li'\n";
    } else {
        echo "❌ FAIL: tagName is NOT 'li'\n";
    }
    
    // Check if tag in block is li
    if (strpos($result, '"tag":"li"') !== false) {
        echo "✅ PASS: block.tag is 'li'\n";
    } else {
        echo "❌ FAIL: block.tag is NOT 'li'\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 3: Heading (h2) ---\n";
$heading_element = array(
    'id' => '84b973',
    'name' => 'heading',
    'settings' => array(
        'text' => 'Your heading',
        'tag' => 'h2',
        '_cssGlobalClasses' => array('bTySctkbujj')
    )
);

$result = $factory->convert_element($heading_element, array());
if ($result) {
    if (strpos($result, 'wp:heading') !== false) {
        echo "✅ PASS: Is heading block\n";
    } else {
        echo "❌ FAIL: Not a heading block\n";
    }
    
    if (strpos($result, '"level":2') !== false) {
        echo "✅ PASS: Level is 2\n";
    } else {
        echo "❌ FAIL: Level is NOT 2\n";
    }
    
    if (strpos($result, 'Your heading') !== false) {
        echo "✅ PASS: Text content is correct\n";
    } else {
        echo "❌ FAIL: Text content is missing\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n=== Element Converter Tests Complete ===\n";
