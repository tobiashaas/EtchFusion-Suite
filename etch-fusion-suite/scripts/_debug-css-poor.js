'use strict';
const { spawnSync } = require('child_process');
const CWD  = require('path').resolve(__dirname, '..');
const OPTS = { encoding: 'utf8', cwd: CWD, maxBuffer: 32 * 1024 * 1024 };

function runPhpJson(env, php) {
  const r = process.platform === 'win32'
    ? spawnSync('cmd', ['/c', 'npx', 'wp-env', 'run', env, 'wp', 'eval', php], OPTS)
    : spawnSync('npx', ['wp-env', 'run', env, 'wp', 'eval', php], OPTS);
  if (r.error) throw r.error;
  for (const l of r.stdout.split('\n')) {
    const t = l.trim();
    if (t.startsWith('[') || t.startsWith('{')) return JSON.parse(t);
  }
  throw new Error('No JSON:\n' + r.stdout.slice(0, 400));
}

// 1) Per-class converter output for the ratio<0.3 classes
const TARGETS = ['list-alpha', 'feature-card-echo__text', 'testimonial-grid-delta', 'logo-charlie', 'social-alpha'];

// Normalize name the same way the converter does (strip acss_import_ and fr- prefix)
const bricksPhp = `set_time_limit(0); $raw=get_option('bricks_global_classes',array()); if(is_string($raw)){$raw=maybe_unserialize($raw);} $conv=etch_fusion_suite_container()->get('css_converter'); $targets=array('list-alpha','feature-card-echo__text','testimonial-grid-delta','logo-charlie','social-alpha'); $out=array(); foreach((array)$raw as $c){ $raw_n=$c['name']??''; $n=preg_replace('/^acss_import_/','',$raw_n); $n=preg_replace('/^fr-/','',$n); if(!in_array($n,$targets))continue; $e=$conv->convert_bricks_class_to_etch($c); $s=$c['settings']??array(); $rk=array_values(array_filter(array_keys($s),function($k){return strpos($k,':')!==false;})); $out[$n]=array('raw_name'=>$raw_n,'css'=>$e['css'],'css_len'=>strlen($e['css']),'has_cssCustom'=>!empty($s['_cssCustom']),'responsive_keys'=>array_slice($rk,0,5)); } echo json_encode($out);`;

console.log('> Fetching per-class converter output from Bricks...');
const bricks = runPhpJson('cli', bricksPhp);

// 2) Actual CSS in Etch for same selectors
const etchPhp = `$raw=get_option('etch_styles',array()); $targets=array('.list-alpha','.feature-card-echo__text','.testimonial-grid-delta','.logo-charlie','.social-alpha'); $out=array(); foreach((array)$raw as $s){ if(($s['type']??'')!=='class')continue; $sel=$s['selector']??''; if(in_array($sel,$targets)){$out[$sel]=array('css'=>$s['css']??'','len'=>strlen($s['css']??''));} } echo json_encode($out);`;

console.log('> Fetching actual CSS from Etch...');
const etch = runPhpJson('tests-cli', etchPhp);

// 3) Compare
console.log('\n=== COMPARISON: Per-class converter vs Actual Etch ===\n');
for (const name of TARGETS) {
  const sel = '.' + name;
  const b   = bricks[name];
  const e   = etch[sel];
  if (!b) { console.log(name + ': NOT IN BRICKS\n'); continue; }
  console.log('─── ' + name + ' ───────────────────────────────');
  console.log('  has _cssCustom:       ' + b.has_cssCustom);
  console.log('  responsive keys:      ' + (b.responsive_keys.length ? b.responsive_keys.join(', ') : 'none'));
  console.log('  Converter output (' + b.css_len + 'B):');
  console.log('  ' + b.css.slice(0, 200).replace(/\n/g, '\n  '));
  console.log('');
  if (e) {
    console.log('  Etch actual (' + e.len + 'B):');
    console.log('  ' + e.css.slice(0, 200).replace(/\n/g, '\n  '));
  } else {
    console.log('  Etch: NOT FOUND');
  }
  console.log('');
}
