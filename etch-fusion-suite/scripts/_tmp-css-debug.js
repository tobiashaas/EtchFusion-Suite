#!/usr/bin/env node
'use strict';
const { spawnSync } = require('child_process');
const path = require('path');
const CWD = path.resolve(__dirname, '..');
const SPAWN = { encoding: 'utf8', cwd: CWD, maxBuffer: 8 * 1024 * 1024 };

function wpEval(env, php) {
  const r = process.platform === 'win32'
    ? spawnSync('cmd', ['/c', 'npx', 'wp-env', 'run', env, 'wp', 'eval', php], SPAWN)
    : spawnSync('npx', ['wp-env', 'run', env, 'wp', 'eval', php], SPAWN);
  if (r.error) throw r.error;
  return (r.stdout || '') + (r.stderr || '');
}

function extractJson(raw) {
  const s = raw.indexOf('{');
  if (s < 0) return null;
  let depth = 0;
  for (let i = s; i < raw.length; i++) {
    if (raw[i] === '{') depth++;
    else if (raw[i] === '}') { depth--; if (!depth) return JSON.parse(raw.slice(s, i + 1)); }
  }
  return null;
}

const targets = ['slider-hotel__progress','btn--white','feature-card-victor__heading',
  'hero-november__heading','grid--3','hero-november__content',
  'feature-card-victor','feature-card-victor__content-wrapper'];

const targetList = targets.map(t => `'${t}'`).join(',');

const php = `
$classes = get_option('bricks_global_classes', []);
if (is_string($classes)) $classes = json_decode($classes, true);
$conv = etch_fusion_suite_container()->get('css_converter');
$targets = [${targetList}];
$out = [];
foreach ((array)$classes as $c) {
  $name = $c['name'] ?? $c['id'] ?? '';
  if (!in_array($name, $targets, true)) continue;
  $r = $conv->convert_bricks_class_to_etch($c);
  $out[$name] = ['settings' => $c['settings'] ?? [], 'css' => $r['css'] ?? ''];
}
echo json_encode($out);
`;

const raw = wpEval('cli', php);
const data = extractJson(raw);
if (!data) { console.error('No JSON.\nRaw:', raw.slice(0, 1000)); process.exit(1); }

for (const [name, info] of Object.entries(data)) {
  console.log(`\n=== ${name} ===`);
  const s = info.settings;
  const keys = Object.keys(s);
  const responsive = keys.filter(k => k.includes(':'));
  const base = keys.filter(k => !k.includes(':'));
  console.log(`  base settings (${base.length}):`, base.map(k => `${k}=${JSON.stringify(s[k])}`).join(', ') || '(none)');
  console.log(`  responsive settings (${responsive.length}):`, responsive.map(k => `${k}=${JSON.stringify(s[k])}`).join(', ') || '(none)');
  console.log(`  css_expected: ${info.css || '(EMPTY)'}`);
}
