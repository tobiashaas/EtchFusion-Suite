<?php
$classes = get_option('bricks_global_classes', array());
if (is_string($classes)) {
  $decoded = json_decode($classes, true);
  $classes = is_array($decoded) ? $decoded : maybe_unserialize($classes);
}
if (!is_array($classes)) { $classes = array(); }
$targets = array('tnmzzp','ulwtsp');
$out = array();
foreach ($classes as $c) {
  if (!is_array($c)) continue;
  $id = (string)($c['id'] ?? '');
  if (!in_array($id, $targets, true)) continue;
  $out[] = $c;
}
echo wp_json_encode($out, JSON_PRETTY_PRINT);
