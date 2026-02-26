<?php
$raw = get_option('etch_styles', []);
$styles = is_string($raw) ? maybe_unserialize($raw) : $raw;

$targets = ['slider-hotel__progress','btn--white','feature-card-victor__heading',
  'hero-november__heading','feature-card-victor','feature-card-victor__content-wrapper',
  'hero-november__content'];

$out = [];
foreach ((array)$styles as $s) {
  if (!is_array($s) || ($s['type'] ?? '') !== 'class') continue;
  $selector = ltrim(trim((string)($s['selector'] ?? '')), '.');
  if (!in_array($selector, $targets, true)) continue;
  $out[$selector] = $s['css'] ?? '';
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
