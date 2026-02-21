<?php
/**
 * Simple Debug Test for LocalWP
 */

// Determine WordPress path
$wp_path = getenv('WP_PATH');
if (!$wp_path) {
    $username = getenv('USERNAME') ?: getenv('USER');
    $possible_paths = [
        "C:\\Users\\{$username}\\Local Sites\\bricks\\app\\public",
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path . '/wp-load.php')) {
            $wp_path = $path;
            break;
        }
    }
}

if (!$wp_path || !file_exists($wp_path . '/wp-load.php')) {
    echo "❌ WordPress not found!\n";
    exit(1);
}

echo "✅ Found WordPress at: {$wp_path}\n";

// Load WordPress
require_once($wp_path . '/wp-load.php');

echo "✅ WordPress loaded\n";

// Check plugin
if (!defined('EFS_PLUGIN_VERSION')) {
    echo "❌ Plugin not active!\n";
    exit(1);
}

echo "✅ Plugin active: v" . EFS_PLUGIN_VERSION . "\n\n";

// Test 1: Check classes
echo "--- Checking Classes ---\n";
$classes = [
    'Bricks2Etch\Converters\EFS_Element_Factory',
    'Bricks2Etch\Ajax\EFS_Ajax_Handler',
    'Bricks2Etch\Parsers\EFS_Gutenberg_Generator',
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "✅ {$class}\n";
    } else {
        echo "❌ {$class} NOT FOUND\n";
    }
}

echo "\n--- Checking Functions ---\n";
$functions = ['efs_container', 'etch_fusion_suite'];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✅ {$func}()\n";
    } else {
        echo "❌ {$func}() NOT FOUND\n";
    }
}

echo "\n--- Checking Options ---\n";
$options = ['efs_style_map', 'efs_api_key', 'efs_migration_token'];

foreach ($options as $option) {
    $value = get_option($option, false);
    if ($value !== false) {
        echo "✅ {$option}: " . (is_array($value) ? count($value) . " entries" : "set") . "\n";
    } else {
        echo "⚠️  {$option}: not set (OK for fresh install)\n";
    }
}

echo "\n--- Checking AJAX Hooks ---\n";
global $wp_filter;
$hooks = ['wp_ajax_efs_migrate_css', 'wp_ajax_efs_validate_api_key'];

foreach ($hooks as $hook) {
    if (isset($wp_filter[$hook]) && !empty($wp_filter[$hook])) {
        echo "✅ {$hook}\n";
    } else {
        echo "❌ {$hook} NOT REGISTERED\n";
    }
}

echo "\n--- Testing Factory ---\n";
try {
    $registry = new \Bricks2Etch\Converters\EFS_Converter_Registry();
    $factory  = new \Bricks2Etch\Converters\EFS_Element_Factory( $registry, [] );
    echo "✅ Factory created successfully\n";
    
    // Test 1: Heading
    echo "Testing heading conversion...\n";
    $test_element = [
        'id' => 'test123',
        'name' => 'heading',
        'settings' => [
            'text' => 'Test Heading',
            'tag' => 'h2'
        ]
    ];
    
    echo "Calling convert_element...\n";
    $result = $factory->convert_element($test_element, []);
    echo "Result received: " . ($result ? "YES" : "NULL") . "\n";
    
    if ($result) {
        echo "Result length: " . strlen($result) . " chars\n";
        if (strpos($result, 'wp:heading') !== false) {
            echo "✅ Heading conversion works\n";
        } else {
            echo "❌ Heading conversion failed - no 'wp:heading' found\n";
            echo "First 200 chars: " . substr($result, 0, 200) . "\n";
        }
    } else {
        echo "❌ Heading conversion returned NULL\n";
    }
    
    // Test 2: Container with custom tag
    $container = [
        'id' => 'test456',
        'name' => 'container',
        'settings' => [
            'tag' => 'ul'
        ]
    ];
    
    $result = $factory->convert_element($container, []);
    if ($result && strpos($result, '"tagName":"ul"') !== false) {
        echo "✅ Container with custom tag works\n";
    } else {
        echo "❌ Container with custom tag failed\n";
    }
    
    // Test 3: Div with custom tag
    $div = [
        'id' => 'test789',
        'name' => 'div',
        'settings' => [
            'tag' => 'li'
        ]
    ];
    
    $result = $factory->convert_element($div, []);
    if ($result && strpos($result, '"tagName":"li"') !== false) {
        echo "✅ Div with custom tag works\n";
    } else {
        echo "❌ Div with custom tag failed\n";
    }
    
} catch (Exception $e) {
    echo "❌ Factory error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n--- Testing AJAX Handler Classes ---\n";
try {
    // AJAX handlers are only registered in admin context
    // But we can check if the classes exist and are instantiable
    $css_handler = new \Bricks2Etch\Ajax\Handlers\EFS_CSS_Ajax_Handler();
    echo "✅ CSS Ajax Handler instantiable\n";
    
    $validation_handler = new \Bricks2Etch\Ajax\Handlers\EFS_Validation_Ajax_Handler();
    echo "✅ Validation Ajax Handler instantiable\n";
    
    $content_handler = new \Bricks2Etch\Ajax\Handlers\EFS_Content_Ajax_Handler();
    echo "✅ Content Ajax Handler instantiable\n";
    
} catch (Exception $e) {
    echo "❌ AJAX Handler error: " . $e->getMessage() . "\n";
}

echo "\n✅ All debug tests complete!\n";
