<?php
$classes = get_option('bricks_global_classes', []);
if (is_string($classes)) $classes = json_decode($classes, true);
$conv = etch_fusion_suite_container()->get('css_converter');

$targets = ['slider-hotel__progress','btn--white','feature-card-victor__heading',
  'hero-november__heading','grid--3','hero-november__content',
  'feature-card-victor','feature-card-victor__content-wrapper'];

$out = [];
foreach ((array)$classes as $c) {
  $name = $c['name'] ?? $c['id'] ?? '';
  if (!in_array($name, $targets, true)) continue;
  $r = $conv->convert_bricks_class_to_etch($c);
  $out[$name] = ['settings' => $c['settings'] ?? [], 'css' => $r['css'] ?? ''];
}
echo json_encode($out, JSON_PRETTY_PRINT);
