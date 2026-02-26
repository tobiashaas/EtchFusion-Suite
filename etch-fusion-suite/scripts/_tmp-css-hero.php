<?php
$classes = get_option('bricks_global_classes', []);
if (is_string($classes)) $classes = json_decode($classes, true);

$out = [];
foreach ((array)$classes as $c) {
  $name = $c['name'] ?? $c['id'] ?? '';
  if (strpos($name, 'hero-november') === false && strpos($name, 'feature-card-victor__heading') === false) continue;
  $out[$name] = ['id' => $c['id'] ?? '', 'settings' => $c['settings'] ?? []];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
