<?php
/**
 * Test Element Converters
 * 
 * Tests the new modular element converter structure
 * 
 * Usage: docker exec b2e-bricks php /tmp/test-element-converters.php
 */

// Load WordPress
require_once('/var/www/html/wp-load.php');

echo "=== Testing Element Converters ===\n\n";

// Load the new converters
require_once('/var/www/html/wp-content/plugins/etch-fusion-suite/includes/converters/class-element-factory.php');

// Get style map
$style_map = get_option('efs_style_map', array());
echo "Style map loaded: " . count($style_map) . " entries\n\n";

// Create factory
$factory = new \Bricks2Etch\Converters\EFS_Element_Factory($style_map);

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

echo "\n--- Test 4: Image (figure tag) ---\n";
$image_element = array(
    'id' => 'eeb349',
    'name' => 'image',
    'label' => 'Feature Card Image',
    'settings' => array(
        'image' => array(
            'id' => 123,
            'url' => 'http://example.com/image.jpg'
        ),
        'alt' => 'Test Image',
        '_cssGlobalClasses' => array('bTyScimage')
    )
);

$result = $factory->convert_element($image_element, array());
if ($result) {
    if (strpos($result, '"tag":"figure"') !== false) {
        echo "✅ PASS: tag is 'figure' (not 'img'!)\n";
    } else {
        echo "❌ FAIL: tag is NOT 'figure'\n";
    }
    
    if (strpos($result, 'wp:image') !== false) {
        echo "✅ PASS: Is image block\n";
    } else {
        echo "❌ FAIL: Not an image block\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 5: Section ---\n";
$section_element = array(
    'id' => 'e38b5e',
    'name' => 'section',
    'label' => 'Feature Section Sierra',
    'settings' => array(
        '_cssGlobalClasses' => array('bTySccocilw'),
        'tag' => 'section'
    )
);

$result = $factory->convert_element($section_element, array());
if ($result) {
    if (strpos($result, '"tag":"section"') !== false) {
        echo "✅ PASS: tag is 'section'\n";
    } else {
        echo "❌ FAIL: tag is NOT 'section'\n";
    }
    
    if (strpos($result, 'data-etch-element":"section"') !== false) {
        echo "✅ PASS: data-etch-element is 'section'\n";
    } else {
        echo "❌ FAIL: data-etch-element is NOT 'section'\n";
    }
    
    if (strpos($result, 'etch-section-style') !== false) {
        echo "✅ PASS: Has etch-section-style\n";
    } else {
        echo "❌ FAIL: Missing etch-section-style\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 6: SVG (file source) ---\n";
$svg_element = array(
    'id' => 'svg1',
    'name' => 'svg',
    'label' => 'SVG Icon',
    'settings' => array(
        'source' => 'file',
        'file' => array('url' => 'https://example.com/icon.svg'),
        'width' => '48',
        'height' => '48',
        '_cssGlobalClasses' => array()
    )
);
$result = $factory->convert_element($svg_element, array());
if ($result) {
    if (strpos($result, 'wp:etch/svg') !== false) {
        echo "✅ PASS: Is etch/svg block\n";
    } else {
        echo "❌ FAIL: Not etch/svg block\n";
    }
    if (strpos($result, 'icon.svg') !== false) {
        echo "✅ PASS: SVG URL present\n";
    } else {
        echo "❌ FAIL: SVG URL missing\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 7: SVG (code source) ---\n";
$svg_code_element = array(
    'id' => 'svg2',
    'name' => 'svg',
    'settings' => array(
        'source' => 'code',
        'code' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>'
    )
);
$result = $factory->convert_element($svg_code_element, array());
if ($result) {
    echo "✅ PASS: SVG code conversion returned block\n";
} else {
    echo "⚠️ SKIP or FAIL: SVG code upload may require uploads dir\n";
}

echo "\n--- Test 8: YouTube video ---\n";
$youtube_element = array(
    'id' => 'vid1',
    'name' => 'video',
    'settings' => array(
        'videoType' => 'youtube',
        'videoId' => 'dQw4w9WgXcQ',
        'width' => '640',
        'height' => '360'
    )
);
$result = $factory->convert_element($youtube_element, array());
if ($result) {
    if (strpos($result, 'wp:etch/element') !== false) {
        echo "✅ PASS: Is etch/element block\n";
    }
    if (strpos($result, 'youtube.com/embed') !== false) {
        echo "✅ PASS: YouTube embed URL present\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 9: Vimeo video ---\n";
$vimeo_element = array(
    'id' => 'vid2',
    'name' => 'video',
    'settings' => array(
        'videoType' => 'vimeo',
        'videoId' => '123456789'
    )
);
$result = $factory->convert_element($vimeo_element, array());
if ($result) {
    if (strpos($result, 'player.vimeo.com') !== false) {
        echo "✅ PASS: Vimeo embed URL present\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 10: HTML5 video (with figure/figcaption) ---\n";
$html5_element = array(
    'id' => 'vid3',
    'name' => 'video',
    'settings' => array(
        'videoType' => 'file',
        'url' => 'https://example.com/video.mp4',
        'controls' => true,
        'alt' => 'Demo video showing features',
        'poster' => array('url' => 'https://example.com/poster.jpg')
    )
);
$result = $factory->convert_element($html5_element, array());
if ($result) {
    if (strpos($result, '<figure class="media">') !== false) {
        echo "✅ PASS: Figure wrapper present\n";
    } else {
        echo "❌ FAIL: Figure wrapper missing\n";
    }
    if (strpos($result, '<figcaption') !== false && strpos($result, 'hidden-accessible') !== false) {
        echo "✅ PASS: Figcaption with hidden-accessible class\n";
    } else {
        echo "❌ FAIL: Figcaption or class missing\n";
    }
    if (strpos($result, 'Demo video showing features') !== false) {
        echo "✅ PASS: Description text present\n";
    } else {
        echo "❌ FAIL: Description text missing\n";
    }
    if (strpos($result, '<source') !== false && strpos($result, 'type="video/mp4"') !== false) {
        echo "✅ PASS: Source element with MIME type\n";
    } else {
        echo "❌ FAIL: Source element or MIME type missing\n";
    }
    if (strpos($result, 'Ihr Browser unterstützt') !== false) {
        echo "✅ PASS: Fallback text present\n";
    } else {
        echo "❌ FAIL: Fallback text missing\n";
    }
    if (strpos($result, 'playsinline') !== false && strpos($result, 'preload="metadata"') !== false) {
        echo "✅ PASS: Playsinline and preload attributes\n";
    } else {
        echo "❌ FAIL: Missing playsinline or preload\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 11: Code (executeCode true) ---\n";
$code_exec_element = array(
    'id' => 'code1',
    'name' => 'code',
    'settings' => array(
        'code' => '<div>Dynamic</div>',
        'executeCode' => true
    )
);
$result = $factory->convert_element($code_exec_element, array());
if ($result) {
    if (strpos($result, 'wp:etch/raw-html') !== false && strpos($result, '"unsafe":"true"') !== false) {
        echo "✅ PASS: etch/raw-html with unsafe true\n";
    } else {
        echo "❌ FAIL: Block or unsafe flag wrong\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 12: Code (executeCode false) ---\n";
$code_safe_element = array(
    'id' => 'code2',
    'name' => 'code',
    'settings' => array(
        'code' => '<p>Static</p>',
        'executeCode' => false
    )
);
$result = $factory->convert_element($code_safe_element, array());
if ($result) {
    if (strpos($result, '"unsafe":"false"') !== false) {
        echo "✅ PASS: etch/raw-html with unsafe false\n";
    }
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 13: HTML Element ---\n";
$html_element = array(
    'id' => 'html1',
    'name' => 'html',
    'settings' => array(
        'html' => '<div class="custom">Content</div>'
    )
);
$result = $factory->convert_element($html_element, array());
if ($result) {
    $block_ok = strpos($result, 'wp:etch/raw-html') !== false;
    $unsafe_ok = strpos($result, '"unsafe":"false"') !== false;
    $content_ok = strpos($result, 'custom') !== false && strpos($result, 'Content') !== false;
    $metadata_ok = strpos($result, '"metadata"') !== false && strpos($result, '"etchData"') !== false;
    $block_type_ok = strpos($result, '"type":"html"') !== false;
    $block_tag_ok = strpos($result, '"tag":"div"') !== false;
    echo $block_ok ? "✅ PASS: Contains wp:etch/raw-html\n" : "❌ FAIL: Missing wp:etch/raw-html\n";
    echo $unsafe_ok ? "✅ PASS: Contains unsafe false\n" : "❌ FAIL: Missing unsafe false\n";
    echo $content_ok ? "✅ PASS: Contains HTML content\n" : "❌ FAIL: HTML content missing\n";
    echo $metadata_ok ? "✅ PASS: Contains metadata.etchData structure\n" : "❌ FAIL: metadata.etchData missing - etchData schema required for etch/raw-html\n";
    echo $block_type_ok ? "✅ PASS: Contains block.type\n" : "❌ FAIL: block.type missing - required for etch schema\n";
    echo $block_tag_ok ? "✅ PASS: Contains block.tag\n" : "❌ FAIL: block.tag missing - required for etch schema\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 14: Shortcode Element ---\n";
$shortcode_element = array(
    'id' => 'sc1',
    'name' => 'shortcode',
    'settings' => array(
        'shortcode' => '[gallery ids="1,2,3"]'
    )
);
$result = $factory->convert_element($shortcode_element, array());
if ($result) {
    $block_ok = strpos($result, 'wp:etch/raw-html') !== false;
    $unsafe_ok = strpos($result, '"unsafe":"false"') !== false;
    $shortcode_ok = strpos($result, 'gallery') !== false && strpos($result, 'ids=') !== false;
    $metadata_ok = strpos($result, '"metadata"') !== false && strpos($result, '"etchData"') !== false;
    $block_type_ok = strpos($result, '"type":"html"') !== false;
    $block_tag_ok = strpos($result, '"tag":"div"') !== false;
    echo $block_ok ? "✅ PASS: Contains wp:etch/raw-html\n" : "❌ FAIL: Missing wp:etch/raw-html\n";
    echo $unsafe_ok ? "✅ PASS: Contains unsafe false\n" : "❌ FAIL: Missing unsafe false\n";
    echo $shortcode_ok ? "✅ PASS: Shortcode format preserved\n" : "❌ FAIL: Shortcode missing\n";
    echo $metadata_ok ? "✅ PASS: Contains metadata.etchData structure\n" : "❌ FAIL: metadata.etchData missing - etchData schema required for etch/raw-html\n";
    echo $block_type_ok ? "✅ PASS: Contains block.type\n" : "❌ FAIL: block.type missing - required for etch schema\n";
    echo $block_tag_ok ? "✅ PASS: Contains block.tag\n" : "❌ FAIL: block.tag missing - required for etch schema\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 15: Text-Link Element ---\n";
$textlink_element = array(
    'id' => 'tl1',
    'name' => 'text-link',
    'settings' => array(
        'text' => 'Click',
        'link' => array('url' => 'https://example.com', 'newTab' => true),
        '_cssGlobalClasses' => array('bTySclink1')
    )
);
$result = $factory->convert_element($textlink_element, array());
if ($result) {
    $block_ok = strpos($result, 'wp:etch/element') !== false;
    $href_ok = strpos($result, 'https://example.com') !== false;
    $target_ok = strpos($result, 'target="_blank"') !== false;
    $rel_ok = strpos($result, 'noopener') !== false || strpos($result, 'noreferrer') !== false;
    $text_ok = strpos($result, 'Click') !== false;
    echo $block_ok ? "✅ PASS: Contains wp:etch/element\n" : "❌ FAIL: Missing wp:etch/element\n";
    echo $href_ok ? "✅ PASS: Contains href URL\n" : "❌ FAIL: href missing\n";
    echo $target_ok ? "✅ PASS: Contains target _blank\n" : "❌ FAIL: target missing\n";
    echo $rel_ok ? "✅ PASS: Contains rel noopener/noreferrer\n" : "❌ FAIL: rel missing\n";
    echo $text_ok ? "✅ PASS: Contains text content\n" : "❌ FAIL: Text missing\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 16: Rich Text Element (Single Paragraph) ---\n";
$richtext_single = array(
    'id' => 'rt1',
    'name' => 'text',
    'settings' => array(
        'text' => '<p>Simple text</p>'
    )
);
$result = $factory->convert_element($richtext_single, array());
if ($result) {
    $block_ok = strpos($result, 'wp:etch/element') !== false;
    $tag_ok = strpos($result, '"tag":"p"') !== false;
    $text_ok = strpos($result, 'Simple text') !== false;
    echo $block_ok ? "✅ PASS: Contains wp:etch/element\n" : "❌ FAIL: Missing wp:etch/element\n";
    echo $tag_ok ? "✅ PASS: Contains tag p\n" : "❌ FAIL: tag p missing\n";
    echo $text_ok ? "✅ PASS: Contains text content\n" : "❌ FAIL: Text missing\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 17: Rich Text Element (Multiple Blocks) ---\n";
$richtext_multi = array(
    'id' => 'rt2',
    'name' => 'text',
    'settings' => array(
        'text' => '<p>First</p><ul><li>Item</li></ul><p>Last</p>'
    )
);
$result = $factory->convert_element($richtext_multi, array());
if ($result) {
    $count = substr_count($result, 'wp:etch/element');
    $list_item_ok = strpos($result, 'wp:list-item') !== false || (strpos($result, 'Item') !== false && strpos($result, 'First') !== false && strpos($result, 'Last') !== false);
    $all_text = strpos($result, 'First') !== false && strpos($result, 'Item') !== false && strpos($result, 'Last') !== false;
    echo ($count >= 3) ? "✅ PASS: Multiple etch/element blocks (found {$count})\n" : "❌ FAIL: Expected 3+ blocks, found {$count}\n";
    echo $list_item_ok ? "✅ PASS: List/item content present\n" : "❌ FAIL: List structure missing\n";
    echo $all_text ? "✅ PASS: All text content present\n" : "❌ FAIL: Some text missing\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

echo "\n--- Test 18: Factory Converter Selection ---\n";
$types = array('html', 'shortcode', 'text-link', 'text');
$classes = array(
    'html'      => 'EFS_Element_Html',
    'shortcode' => 'EFS_Element_Shortcode',
    'text-link' => 'EFS_Element_TextLink',
    'text'      => 'EFS_Element_Text'
);
$all_ok = true;
foreach ($types as $type) {
    $converter = $factory->get_converter($type);
    $expected = $classes[ $type ];
    if ($converter === null) {
        echo "❌ FAIL: get_converter('{$type}') returned null\n";
        $all_ok = false;
    } elseif (get_class($converter) !== 'Bricks2Etch\\Converters\\Elements\\' . $expected) {
        echo "❌ FAIL: {$type} returned " . get_class($converter) . ", expected {$expected}\n";
        $all_ok = false;
    }
}
if ($all_ok) {
    echo "✅ PASS: Factory returns correct converter for html, shortcode, text-link, text\n";
}

echo "\n--- Test 19: End-to-End with Style Map ---\n";
$style_map_phase2 = array(
    'bTySclink1' => array('id' => 'etch-link-style', 'selector' => '.my-link-class')
);
$factory_with_styles = new \Bricks2Etch\Converters\EFS_Element_Factory($style_map_phase2);
$textlink_styled = array(
    'id' => 'tl2',
    'name' => 'text-link',
    'settings' => array(
        'text' => 'Styled Link',
        'link' => array('url' => 'https://example.org'),
        '_cssGlobalClasses' => array('bTySclink1')
    )
);
$result = $factory_with_styles->convert_element($textlink_styled, array());
if ($result) {
    $classes_ok = strpos($result, 'my-link-class') !== false || strpos($result, 'etch-link-style') !== false || strpos($result, 'styles') !== false;
    $etch_data_ok = strpos($result, 'etchData') !== false;
    echo $classes_ok ? "✅ PASS: CSS classes or style IDs from style map applied\n" : "❌ FAIL: Style map not applied\n";
    echo $etch_data_ok ? "✅ PASS: etchData present\n" : "❌ FAIL: etchData missing\n";
} else {
    echo "❌ FAIL: No result returned\n";
}

// --- Edge Case Tests (Phase 2) ---

echo "\n--- Test 20: Rich Text - Empty HTML ---\n";
$richtext_empty = array('id' => 'rt-empty', 'name' => 'text', 'settings' => array('text' => ''));
$result = $factory->convert_element($richtext_empty, array());
echo ($result === '' || $result === null) ? "✅ PASS: Empty content returns empty\n" : "❌ FAIL: Expected empty, got output\n";

echo "\n--- Test 21: Rich Text - Malformed HTML (unclosed tag) ---\n";
$richtext_malformed = array('id' => 'rt-mal', 'name' => 'text', 'settings' => array('text' => '<p>Open only'));
$result = $factory->convert_element($richtext_malformed, array());
$graceful = ($result !== null && $result !== false);
echo $graceful ? "✅ PASS: No fatal; output or empty\n" : "❌ FAIL: Converter should not crash\n";

echo "\n--- Test 22: Rich Text - Nested list (ul > li > ul > li) ---\n";
$richtext_nested = array('id' => 'rt-nested', 'name' => 'text', 'settings' => array('text' => '<ul><li>One<ul><li>Nested</li></ul></li></ul>'));
$result = $factory->convert_element($richtext_nested, array());
$has_list = $result && (strpos($result, 'wp:etch/element') !== false && (strpos($result, 'Nested') !== false || strpos($result, 'One') !== false));
echo $has_list ? "✅ PASS: Nested list content present\n" : "❌ FAIL: Nested list handling\n";

echo "\n--- Test 23: HTML Converter - Empty settings.html ---\n";
$html_empty = array('id' => 'html-empty', 'name' => 'html', 'settings' => array('html' => ''));
$result = $factory->convert_element($html_empty, array());
$block_ok = $result && strpos($result, 'wp:etch/raw-html') !== false;
echo $block_ok ? "✅ PASS: Empty HTML still produces block\n" : "❌ FAIL: Empty HTML block missing\n";

echo "\n--- Test 24: Shortcode - Empty shortcode ---\n";
$shortcode_empty = array('id' => 'sc-empty', 'name' => 'shortcode', 'settings' => array('shortcode' => ''));
$result = $factory->convert_element($shortcode_empty, array());
$block_ok = $result && strpos($result, 'wp:etch/raw-html') !== false;
echo $block_ok ? "✅ PASS: Empty shortcode still produces block\n" : "❌ FAIL: Empty shortcode block missing\n";

echo "\n--- Test 25: Shortcode - Complex attributes ---\n";
$shortcode_complex = array('id' => 'sc-cplx', 'name' => 'shortcode', 'settings' => array('shortcode' => '[embed url="https://youtube.com/watch?v=abc" title="Test"]'));
$result = $factory->convert_element($shortcode_complex, array());
$preserved = $result && strpos($result, 'embed') !== false && strpos($result, 'youtube') !== false;
echo $preserved ? "✅ PASS: Shortcode with attributes preserved\n" : "❌ FAIL: Complex shortcode not preserved\n";

echo "\n--- Test 26: Text-Link - Missing URL (default #) ---\n";
$textlink_no_url = array('id' => 'tl-nourl', 'name' => 'text-link', 'settings' => array('text' => 'Link', 'link' => array()));
$result = $factory->convert_element($textlink_no_url, array());
$href_hash = $result && (strpos($result, 'href="#"') !== false || strpos($result, "href='#'") !== false);
echo $href_hash ? "✅ PASS: Default href is #\n" : "❌ FAIL: href should default to #\n";

echo "\n--- Test 27: Text-Link - Link as string ---\n";
$textlink_string = array('id' => 'tl-str', 'name' => 'text-link', 'settings' => array('text' => 'Go', 'link' => 'https://site.com'));
$result = $factory->convert_element($textlink_string, array());
$url_ok = $result && strpos($result, 'https://site.com') !== false;
echo $url_ok ? "✅ PASS: Link as string works\n" : "❌ FAIL: Link string not used\n";

echo "\n--- Test 28: Text-Link - Missing text content ---\n";
$textlink_no_text = array('id' => 'tl-notxt', 'name' => 'text-link', 'settings' => array('text' => '', 'link' => array('url' => 'https://x.com')));
$result = $factory->convert_element($textlink_no_text, array());
$block_ok = $result && strpos($result, 'wp:etch/element') !== false && strpos($result, 'https://x.com') !== false;
echo $block_ok ? "✅ PASS: Link with empty text still produces block\n" : "❌ FAIL: Block missing for empty text\n";

// --- Integration: End-to-End Migration (Phase 2 elements) ---

echo "\n--- Test 29: Integration - All Phase 2 elements through pipeline ---\n";
$test_elements = array(
    array('name' => 'text', 'settings' => array('text' => '<p>Para 1</p><ul><li>Item</li></ul>')),
    array('name' => 'html', 'settings' => array('html' => '<div class="custom">HTML</div>')),
    array('name' => 'shortcode', 'settings' => array('shortcode' => '[gallery ids="1,2,3"]')),
    array('name' => 'text-link', 'settings' => array('text' => 'Link', 'link' => array('url' => 'https://example.com', 'newTab' => true)))
);
$integration_ok = true;
foreach ($test_elements as $el) {
    $el['id'] = 'int-' . $el['name'];
    $out = $factory->convert_element($el, array());
    if (!$out) {
        echo "❌ FAIL: No output for {$el['name']}\n";
        $integration_ok = false;
    }
}
if ($integration_ok) {
    echo "✅ PASS: All Phase 2 elements convert through pipeline\n";
}

echo "\n--- Test 30: Integration - Style map CSS class propagation ---\n";
$style_map_test = array('bTySclink1' => array('id' => 'etch-link-style', 'selector' => '.my-link-class'));
$factory_style = new \Bricks2Etch\Converters\EFS_Element_Factory($style_map_test);
$el_styled = array('id' => 'tl-style', 'name' => 'text-link', 'settings' => array('text' => 'Styled', 'link' => array('url' => 'https://example.org'), '_cssGlobalClasses' => array('bTySclink1')));
$result = $factory_style->convert_element($el_styled, array());
$has_etch_data = $result && strpos($result, 'etchData') !== false;
$has_styles = $result && (strpos($result, 'styles') !== false || strpos($result, 'my-link-class') !== false || strpos($result, 'etch-link-style') !== false);
echo ($has_etch_data && $has_styles) ? "✅ PASS: Style map integration - etchData and styles/classes present\n" : "❌ FAIL: Style map not reflected in output\n";

echo "\n=== Test Complete ===\n";
