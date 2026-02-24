#!/usr/bin/env node
/**
 * CSS Quality Check: Bricks → Etch
 *
 * Runs the EFS CSS converter on the Bricks site to get the *expected* CSS
 * for every global class, then compares it against the actual etch_styles on
 * the Etch site.
 *
 * Quality categories (based on expected vs. actual CSS byte length):
 *
 *   PERFECT  — ratio 0.8–2.5  (nesting/logical-properties add ~1.5–2× verbosity)
 *   PARTIAL  — ratio 0.3–0.8 or 2.5–5
 *   POOR     — ratio < 0.3 or > 5
 *   LOST     — expected > 0, actual = 0  ← converter bug or transfer loss
 *   GHOST    — expected = 0, actual > 0  ← unexpected CSS in Etch
 *   UTILITY  — expected = 0, actual = 0  ← empty utility class (fine)
 *
 * Separate buckets for ACSS vs. custom classes.
 * Responsive detection uses the colon-key syntax Bricks actually uses
 * (e.g. "color:tablet_portrait"), NOT nested objects.
 *
 * Usage:
 *   node scripts/compare-css.js
 *
 * Output: console summary + css-quality-<timestamp>.json
 */

'use strict';

const { spawnSync } = require('child_process');
const fs            = require('fs');
const path          = require('path');

const CWD        = path.resolve(__dirname, '..');
const SPAWN_OPTS = { encoding: 'utf8', cwd: CWD, maxBuffer: 64 * 1024 * 1024 };

// ─── WP-CLI helpers ───────────────────────────────────────────────────────────

function runWpEnv(args) {
  const result = process.platform === 'win32'
    ? spawnSync('cmd', ['/c', 'npx', 'wp-env', ...args], SPAWN_OPTS)
    : spawnSync('npx', ['wp-env', ...args], SPAWN_OPTS);
  if (result.error) throw result.error;
  if (result.status !== 0)
    throw new Error(result.stderr || result.stdout || `wp-env failed: ${args.join(' ')}`);
  return result.stdout;
}

function runPhpJson(env, phpCode) {
  const raw = runWpEnv(['run', env, 'wp', 'eval', phpCode]);
  for (const line of raw.split('\n')) {
    const t = line.trim();
    if (t.startsWith('[') || t.startsWith('{')) return JSON.parse(t);
  }
  throw new Error(`No JSON in WP-CLI output (env=${env}):\n${raw.slice(0, 500)}`);
}

// ─── Data extraction ──────────────────────────────────────────────────────────

/**
 * Run the EFS CSS converter on the Bricks site and return compact per-class
 * data: expected CSS (from settings), ACSS flag, responsive flag.
 *
 * Note: this uses convert_bricks_class_to_etch() which converts the structured
 * settings object.  Custom CSS from bricks_global_custom_css is handled
 * separately by the full migration and is NOT included here — those classes
 * will appear as expected_len=0 even if they have custom CSS.
 */
function getBricksExpected() {
  console.log('> Running EFS converter on Bricks classes (may take ~30 s)...');

  const php = `set_time_limit(0); $raw=get_option('bricks_global_classes',array()); if(is_string($raw)){$d=json_decode($raw,true);$raw=is_array($d)?$d:(maybe_unserialize($raw)?:array());} $conv=etch_fusion_suite_container()->get('css_converter'); $excPfx=array('brxe-','bricks-','brx-','wp-','wp-block-','has-','is-','woocommerce-','wc-','product-','cart-','checkout-'); $excExact=array('bg--ultra-light','bg--ultra-dark','fr-lede','fr-intro','fr-note','fr-notes','text--l'); $out=array(); foreach($raw as $c){ $orig=!empty($c['name'])?$c['name']:(!empty($c['id'])?$c['id']:''); if(empty($orig))continue; $isAccs=(strpos($orig,'acss_import_')===0); $norm=preg_replace('/^acss_import_/','',$orig); $norm=preg_replace('/^fr-/','',$norm); $excl=false; foreach($excPfx as $p){if(strpos($norm,$p)===0){if($p==='is-'&&strpos($norm,'is-bg')===0)continue; $excl=true;break;}} if($excl||in_array($norm,$excExact))continue; $s=isset($c['settings'])&&is_array($c['settings'])?$c['settings']:array(); $hasS=!empty($s); $hasR=false; foreach($s as $k=>$v){if(strpos((string)$k,':')!==false){$hasR=true;break;}} $etch=$conv->convert_bricks_class_to_etch($c); $css=trim($etch['css']??''); $out[]=array('name'=>$norm,'is_acss'=>$isAccs,'has_settings'=>$hasS,'has_responsive'=>$hasR,'expected_len'=>strlen($css),'selector'=>'.'.$norm); } echo json_encode($out);`;

  return runPhpJson('cli', php);
}

/**
 * Get etch_styles from Etch site — compact form with CSS classification done in PHP.
 */
function getEtchStyles() {
  console.log('> Fetching Etch styles...');

  const php = `$raw=get_option('etch_styles',array()); $st=is_string($raw)?maybe_unserialize($raw):$raw; if(!is_array($st))$st=array(); $out=array(); foreach($st as $s){ if(($s['type']??'')!=='class')continue; $css=trim($s['css']??''); $hm=(strpos($css,'@media')!==false); $hn=(strpos($css,'&')!==false); $b=trim(preg_replace('/&[^{]*\\{[^{}]*\\}/s','',preg_replace('/@media[^{]*\\{(?:[^{}]|\\{[^{}]*\\})*\\}/s','',$css))); $out[]=array('selector'=>$s['selector']??'','empty'=>(strlen($css)===0),'len'=>strlen($css),'has_media'=>$hm,'has_nesting'=>$hn,'has_base'=>(strlen($b)>0),'readonly'=>!empty($s['readonly'])); } echo json_encode($out);`;

  return runPhpJson('tests-cli', php);
}

// ─── Quality scoring ──────────────────────────────────────────────────────────

/**
 * @param {number} expectedLen
 * @param {{empty:boolean, len:number}} merged  — merged Etch entry
 * @returns {'PERFECT'|'PARTIAL'|'POOR'|'LOST'|'GHOST'|'UTILITY'}
 */
function quality(expectedLen, merged) {
  const actualLen = merged.len;
  if (expectedLen === 0 && actualLen === 0) return 'UTILITY';
  if (expectedLen === 0 && actualLen > 0)   return 'GHOST';
  if (expectedLen > 0  && actualLen === 0)  return 'LOST';
  const ratio = actualLen / expectedLen;
  if (ratio >= 0.8 && ratio <= 2.5) return 'PERFECT';
  if (ratio >= 0.3 && ratio <= 5.0) return 'PARTIAL';
  return 'POOR';
}

function cssType(s) {
  if (s.empty)                         return 'empty';
  if (s.has_base && s.has_media)       return 'base+responsive';
  if (s.has_base && s.has_nesting)     return 'base+nesting';
  if (s.has_base)                      return 'base-only';
  if (s.has_media)                     return 'responsive-only';
  if (s.has_nesting)                   return 'nesting-only';
  return 'empty';
}

// ─── Main ─────────────────────────────────────────────────────────────────────

function main() {
  const bricks    = getBricksExpected();
  const etchRaw   = getEtchStyles();

  console.log(`> Bricks eligible classes: ${bricks.length}`);
  console.log(`> Etch class entries:      ${etchRaw.length}`);

  // ── Index Etch by selector ────────────────────────────────────────────────
  const etchIdx = new Map();
  for (const s of etchRaw) {
    if (!s.selector) continue;
    if (!etchIdx.has(s.selector)) etchIdx.set(s.selector, []);
    etchIdx.get(s.selector).push(s);
  }

  // ── Duplicate detection ───────────────────────────────────────────────────
  const duplicates = [];
  for (const [sel, entries] of etchIdx) {
    if (entries.length > 1) duplicates.push({ selector: sel, count: entries.length });
  }
  duplicates.sort((a, b) => b.count - a.count);

  // ── Counters ──────────────────────────────────────────────────────────────
  const counts = {
    total: 0, acss: 0, custom: 0,
    acssWithSettings: 0, customWithSettings: 0,
    acssWithResponsive: 0, customWithResponsive: 0,
  };
  // Quality counters: { ACSS: {...}, Custom: {...} }
  const qCounts = {
    ACSS:   { PERFECT: 0, PARTIAL: 0, POOR: 0, LOST: 0, GHOST: 0, UTILITY: 0, MISSING: 0 },
    Custom: { PERFECT: 0, PARTIAL: 0, POOR: 0, LOST: 0, GHOST: 0, UTILITY: 0, MISSING: 0 },
  };
  const cssTypeCounts = {};

  const lostClasses    = [];  // LOST — expected CSS but nothing in Etch
  const poorClasses    = [];  // POOR quality
  const missingClasses = [];  // not in Etch at all
  const ghostClasses   = [];  // unexpected CSS in Etch

  const bricksSelectors = new Set(bricks.map(c => c.selector));
  const details = [];  // per-class detail rows (for report)

  for (const cls of bricks) {
    counts.total++;
    const bucket = cls.is_acss ? 'ACSS' : 'Custom';
    if (cls.is_acss) {
      counts.acss++;
      if (cls.has_settings)   counts.acssWithSettings++;
      if (cls.has_responsive) counts.acssWithResponsive++;
    } else {
      counts.custom++;
      if (cls.has_settings)   counts.customWithSettings++;
      if (cls.has_responsive) counts.customWithResponsive++;
    }

    const entries = etchIdx.get(cls.selector);
    if (!entries) {
      qCounts[bucket].MISSING++;
      if (!cls.is_acss && cls.expected_len > 0) {
        missingClasses.push({ name: cls.name, expected_len: cls.expected_len });
      }
      details.push({ name: cls.name, is_acss: cls.is_acss, has_settings: cls.has_settings,
        expected_len: cls.expected_len, actual_len: 0, quality: 'MISSING', css_type: 'n/a' });
      continue;
    }

    // Merge entries for this selector
    const merged = {
      empty:       entries.every(e => e.empty),
      len:         entries.reduce((n, e) => n + e.len, 0),
      has_base:    entries.some(e => e.has_base),
      has_media:   entries.some(e => e.has_media),
      has_nesting: entries.some(e => e.has_nesting),
    };

    const q    = quality(cls.expected_len, merged);
    const type = cssType(merged);
    qCounts[bucket][q]++;
    cssTypeCounts[type] = (cssTypeCounts[type] || 0) + 1;

    if (q === 'LOST')  lostClasses.push({ name: cls.name, is_acss: cls.is_acss, expected_len: cls.expected_len, has_settings: cls.has_settings });
    if (q === 'POOR')  poorClasses.push({ name: cls.name, is_acss: cls.is_acss, expected_len: cls.expected_len, actual_len: merged.len, ratio: (merged.len / cls.expected_len).toFixed(2) });
    if (q === 'GHOST') ghostClasses.push({ name: cls.name, actual_len: merged.len });

    details.push({ name: cls.name, is_acss: cls.is_acss, has_settings: cls.has_settings,
      has_responsive: cls.has_responsive, expected_len: cls.expected_len,
      actual_len: merged.len, quality: q, css_type: type,
      ratio: cls.expected_len > 0 ? (merged.len / cls.expected_len).toFixed(2) : null,
      etch_entries: entries.length });
  }

  // Etch orphans (selector not in Bricks)
  const onlyInEtch = [];
  for (const [sel] of etchIdx) {
    if (!bricksSelectors.has(sel)) onlyInEtch.push(sel);
  }

  // ── Summary stats ─────────────────────────────────────────────────────────
  const totalDupeExtra = duplicates.reduce((n, d) => n + (d.count - 1), 0);

  function qualityRate(bucket, cats) {
    const total = Object.values(qCounts[bucket]).reduce((a, b) => a + b, 0);
    if (!total) return 'n/a';
    const good  = cats.reduce((n, c) => n + (qCounts[bucket][c] || 0), 0);
    return ((good / total) * 100).toFixed(1) + '%';
  }

  // ── Console output ─────────────────────────────────────────────────────────
  console.log('\n╔══════════════════════════════════════════════════════════╗');
  console.log('║      CSS Quality Check — Bricks → Etch                 ║');
  console.log('╚══════════════════════════════════════════════════════════╝\n');

  console.log('── Bricks source classes ────────────────────────────────────');
  console.log(`  Total eligible:            ${counts.total}`);
  console.log(`  ACSS (acss_import_*):      ${counts.acss}  (settings: ${counts.acssWithSettings}, responsive: ${counts.acssWithResponsive})`);
  console.log(`  Custom:                    ${counts.custom}  (settings: ${counts.customWithSettings}, responsive: ${counts.customWithResponsive})`);

  console.log('\n── Etch target styles ───────────────────────────────────────');
  console.log(`  Total entries (type=class): ${etchRaw.length}`);
  console.log(`  Unique selectors:           ${etchIdx.size}`);
  console.log(`  Duplicate extra entries:    ${totalDupeExtra}  ← ${duplicates.length} selectors affected`);
  if (duplicates.length > 0) {
    duplicates.slice(0, 5).forEach(d => console.log(`    ×${d.count}  ${d.selector}`));
    if (duplicates.length > 5) console.log(`    … and ${duplicates.length - 5} more`);
  }

  console.log('\n── Migration quality (Custom classes) ───────────────────────');
  const cq = qCounts.Custom;
  const cTotal = Object.values(cq).reduce((a, b) => a + b, 0);
  [['PERFECT', '✓ CSS matches expected'],
   ['PARTIAL', '~ CSS partial / different length'],
   ['POOR',    '✗ CSS very different'],
   ['LOST',    '✗ Expected CSS, got nothing'],
   ['GHOST',   '? Unexpected CSS in Etch'],
   ['UTILITY', '· Empty utility (expected)'],
   ['MISSING', '✗ Class absent from Etch'],
  ].forEach(([k, label]) => {
    const n   = cq[k] || 0;
    const pct = cTotal ? ((n / cTotal) * 100).toFixed(1).padStart(5) : '  n/a';
    console.log(`  ${k.padEnd(8)} ${String(n).padStart(5)}  ${pct}%  ${label}`);
  });
  console.log(`  Good rate (PERFECT+PARTIAL): ${qualityRate('Custom', ['PERFECT', 'PARTIAL', 'GHOST', 'UTILITY'])}`);

  console.log('\n── Migration quality (ACSS classes) ─────────────────────────');
  const aq = qCounts.ACSS;
  const aTotal = Object.values(aq).reduce((a, b) => a + b, 0);
  [['PERFECT', '✓'], ['PARTIAL', '~'], ['POOR', '✗'], ['LOST', '✗'],
   ['GHOST', '?'], ['UTILITY', '·'], ['MISSING', '✗'],
  ].forEach(([k, icon]) => {
    const n   = aq[k] || 0;
    const pct = aTotal ? ((n / aTotal) * 100).toFixed(1).padStart(5) : '  n/a';
    console.log(`  ${k.padEnd(8)} ${String(n).padStart(5)}  ${pct}%`);
  });

  console.log('\n── CSS type breakdown (matched entries) ─────────────────────');
  const typeOrder = ['base-only', 'base+responsive', 'base+nesting', 'responsive-only', 'nesting-only', 'empty'];
  for (const t of typeOrder) {
    const n = cssTypeCounts[t] || 0;
    console.log(`  ${t.padEnd(22)} ${n}`);
  }

  if (lostClasses.filter(c => !c.is_acss).length > 0) {
    const custom = lostClasses.filter(c => !c.is_acss);
    console.log(`\n── LOST — Custom classes: expected CSS → empty in Etch (${custom.length}) ──`);
    custom.forEach(c => console.log(`  ${c.name}  (expected ${c.expected_len}B)`));
  }

  if (poorClasses.filter(c => !c.is_acss).length > 0) {
    const custom = poorClasses.filter(c => !c.is_acss);
    console.log(`\n── POOR quality — Custom (${custom.length}) ───────────────────────`);
    custom.slice(0, 15).forEach(c => console.log(`  ${c.name}  ratio=${c.ratio}  (exp:${c.expected_len}B  act:${c.actual_len}B)`));
    if (custom.length > 15) console.log(`  … and ${custom.length - 15} more (see report)`);
  }

  if (missingClasses.length > 0) {
    console.log(`\n── MISSING Custom classes with expected CSS (${missingClasses.length}) ────────`);
    missingClasses.slice(0, 10).forEach(c => console.log(`  ${c.name}  (${c.expected_len}B)`));
    if (missingClasses.length > 10) console.log(`  … and ${missingClasses.length - 10} more`);
  }

  // ── JSON report ───────────────────────────────────────────────────────────
  const report = {
    timestamp: new Date().toISOString(),
    summary: {
      bricks_eligible: counts.total,
      bricks_acss: counts.acss,
      bricks_custom: counts.custom,
      bricks_custom_with_settings: counts.customWithSettings,
      bricks_custom_with_responsive: counts.customWithResponsive,
      etch_entries_total: etchRaw.length,
      etch_unique_selectors: etchIdx.size,
      etch_duplicate_extra: totalDupeExtra,
      only_in_etch: onlyInEtch.length,
    },
    quality_custom: qCounts.Custom,
    quality_acss:   qCounts.ACSS,
    css_type_breakdown: cssTypeCounts,
    duplicate_selectors: duplicates,
    lost_custom_classes: lostClasses.filter(c => !c.is_acss),
    poor_custom_classes: poorClasses.filter(c => !c.is_acss),
    missing_custom_with_css: missingClasses,
    ghost_classes: ghostClasses,
    only_in_etch_sample: onlyInEtch.slice(0, 50),
    // Full detail rows for deep analysis
    details,
  };

  const reportPath = path.join(CWD, `css-quality-${Date.now()}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
  console.log(`\nFull report: ${path.basename(reportPath)}`);
}

main();
