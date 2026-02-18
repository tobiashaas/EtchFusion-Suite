<?php
$classes = get_option('bricks_global_classes', array());
if (is_string($classes)) {
  $decoded = json_decode($classes, true);
  $classes = is_array($decoded) ? $decoded : maybe_unserialize($classes);
}
if (!is_array($classes)) {
  $classes = array();
}
$matches = array();
foreach ($classes as $class) {
  if (!is_array($class)) {
    continue;
  }
  $id = isset($class['id']) ? (string) $class['id'] : '';
  $name = isset($class['name']) ? (string) $class['name'] : '';
  $settings = isset($class['settings']) && is_array($class['settings']) ? $class['settings'] : array();
  foreach ($settings as $key => $value) {
    if (!is_string($value)) {
      continue;
    }
    if (strpos($value, '-hsl') !== false || preg_match('/var\(\s*--[^\)]*-hsl\s*\)/i', $value)) {
      $matches[] = array(
        'id' => $id,
        'name' => $name,
        'setting_key' => (string) $key,
        'snippet' => substr(trim($value), 0, 320),
      );
    }
  }
}
echo wp_json_encode(array(
  'count' => count($matches),
  'matches' => array_slice($matches, 0, 120),
), JSON_PRETTY_PRINT);
