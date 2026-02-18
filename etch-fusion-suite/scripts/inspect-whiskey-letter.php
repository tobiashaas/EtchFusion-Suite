<?php
$classes = get_option('bricks_global_classes', array());
if (is_string($classes)) {
  $decoded = json_decode($classes, true);
  $classes = is_array($decoded) ? $decoded : maybe_unserialize($classes);
}
if (!is_array($classes)) { $classes = array(); }
$target = null;
foreach ($classes as $c) {
  if (!is_array($c)) continue;
  if (($c['name'] ?? '') === 'content-section-whiskey__letter' || ($c['id'] ?? '') === 'dvmkaf') {
    $target = $c;
    break;
  }
}
echo wp_json_encode($target, JSON_PRETTY_PRINT);
