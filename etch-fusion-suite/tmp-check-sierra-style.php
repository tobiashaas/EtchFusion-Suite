<?php
$styles = get_option('etch_styles', array());
foreach ($styles as $id => $style) {
  $selector = isset($style['selector']) ? (string)$style['selector'] : '';
  if (strpos($selector, 'fr-feature-card-sierra') !== false) {
    echo "ID=$id\n";
    echo "SELECTOR=$selector\n";
    echo "CSS=\n" . (string)($style['css'] ?? '') . "\n----\n";
  }
}
