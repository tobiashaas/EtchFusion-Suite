#!/usr/bin/env node
/**
 * CSS Quality Check: Bricks -> Etch
 *
 * - Reads global classes from Bricks.
 * - Reads class styles from Etch.
 * - Compares "all classes" in one run.
 * - Uses declaration-based matching for custom CSS (nested-aware).
 * - Treats ACSS classes as external stylesheet classes.
 *
 * Usage:
 *   node scripts/compare-css.js
 *
 * Output:
 *   console summary + css-quality-<timestamp>.json
 */

'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CWD = path.resolve(__dirname, '..');
const SPAWN_OPTS = { encoding: 'utf8', cwd: CWD, maxBuffer: 64 * 1024 * 1024 };

function runWpEnv(args) {
  const result = process.platform === 'win32'
    ? spawnSync('cmd', ['/c', 'npx', 'wp-env', ...args], SPAWN_OPTS)
    : spawnSync('npx', ['wp-env', ...args], SPAWN_OPTS);
  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `wp-env failed: ${args.join(' ')}`);
  }
  return result.stdout;
}

function runPhpJson(env, phpCode) {
  const raw = runWpEnv(['run', env, 'wp', 'eval', phpCode]);
  const input = String(raw || '').trim();
  const starts = [input.indexOf('{'), input.indexOf('[')].filter((i) => i >= 0);
  if (starts.length === 0) {
    throw new Error(`No JSON in WP-CLI output (env=${env}): ${input.slice(0, 800)}`);
  }

  const start = Math.min(...starts);
  let inString = false;
  let escaped = false;
  const stack = [];

  for (let i = start; i < input.length; i += 1) {
    const ch = input[i];
    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (ch === '\\') {
        escaped = true;
      } else if (ch === '"') {
        inString = false;
      }
      continue;
    }

    if (ch === '"') {
      inString = true;
      continue;
    }

    if (ch === '{' || ch === '[') {
      stack.push(ch);
      continue;
    }

    if (ch === '}' || ch === ']') {
      const expected = ch === '}' ? '{' : '[';
      if (stack.length === 0 || stack[stack.length - 1] !== expected) {
        continue;
      }
      stack.pop();
      if (stack.length === 0) {
        const snippet = input.slice(start, i + 1);
        return JSON.parse(snippet);
      }
    }
  }

  throw new Error(`No parseable JSON in WP-CLI output (env=${env}): ${input.slice(0, 800)}`);
}

function normalizeSelectorName(name) {
  return String(name || '')
    .trim()
    .replace(/^\./, '')
    .replace(/^acss_import_/, '')
    .replace(/^fr-/, '');
}

function getBricksExpected() {
  console.log('> Reading Bricks global classes...');

  const php = `
set_time_limit(0);
$raw = get_option('bricks_global_classes', array());
if (is_string($raw)) {
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) {
    $raw = $decoded;
  } else {
    $maybe = maybe_unserialize($raw);
    $raw = is_array($maybe) ? $maybe : array();
  }
}
$conv = etch_fusion_suite_container()->get('css_converter');
$out = array();
foreach ((array) $raw as $class_data) {
  if (!is_array($class_data)) continue;

  $orig = !empty($class_data['name']) ? (string) $class_data['name'] : (!empty($class_data['id']) ? (string) $class_data['id'] : '');
  if ('' === trim($orig)) continue;

  $category = isset($class_data['category']) ? strtolower(trim((string) $class_data['category'])) : '';
  $is_acss = (0 === strpos($orig, 'acss_import_')) || (false !== strpos($category, 'acss')) || (false !== strpos($category, 'automatic'));

  $normalized = preg_replace('/^acss_import_/', '', $orig);
  $normalized = preg_replace('/^fr-/', '', $normalized);
  $normalized = ltrim((string) $normalized, '.');
  if ('' === trim($normalized)) continue;

  $settings = isset($class_data['settings']) && is_array($class_data['settings']) ? $class_data['settings'] : array();
  $has_responsive = false;
  foreach ($settings as $key => $value) {
    if (false !== strpos((string) $key, ':')) {
      $has_responsive = true;
      break;
    }
  }

  $etch = $conv->convert_bricks_class_to_etch($class_data);
  $expected_css = trim((string) ($etch['css'] ?? ''));

  $out[] = array(
    'name' => $normalized,
    'selector' => '.' . $normalized,
    'category' => $category,
    'is_acss' => $is_acss,
    'has_settings' => !empty($settings),
    'has_responsive' => $has_responsive,
    'expected_css' => $expected_css,
    'expected_len' => strlen($expected_css),
  );
}
echo json_encode($out);
`;

  return runPhpJson('cli', php.replace(/\s*\n\s*/g, ' '));
}

function getEtchStyles() {
  console.log('> Reading Etch class styles...');

  const php = `
$raw = get_option('etch_styles', array());
$styles = is_string($raw) ? maybe_unserialize($raw) : $raw;
if (!is_array($styles)) $styles = array();
$out = array();
foreach ($styles as $s) {
  if (!is_array($s)) continue;
  if (($s['type'] ?? '') !== 'class') continue;
  $selector = isset($s['selector']) ? (string) $s['selector'] : '';
  if ('' === trim($selector)) continue;
  $css = trim((string) ($s['css'] ?? ''));
  $out[] = array(
    'selector' => $selector,
    'css' => $css,
    'len' => strlen($css),
    'readonly' => !empty($s['readonly']),
  );
}
echo json_encode($out);
`;

  return runPhpJson('tests-cli', php.replace(/\s*\n\s*/g, ' '));
}

function extractDeclarations(css) {
  const out = new Set();
  const raw = String(css || '')
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/\s+/g, ' ');

  const re = /([a-zA-Z_-][a-zA-Z0-9_-]*)\s*:\s*([^;{}]+);/g;
  let match;
  while ((match = re.exec(raw)) !== null) {
    const prop = String(match[1] || '').trim().toLowerCase();
    const value = String(match[2] || '').trim().replace(/\s+/g, ' ').toLowerCase();
    if (!prop || !value) continue;
    out.add(`${prop}:${value}`);
  }

  return out;
}

function compareDeclarationSets(expectedSet, actualSet) {
  const expectedTotal = expectedSet.size;
  const actualTotal = actualSet.size;

  if (expectedTotal === 0 && actualTotal === 0) {
    return { status: 'EMPTY', expectedTotal, actualTotal, matched: 0, coverage: null };
  }
  if (expectedTotal === 0 && actualTotal > 0) {
    return { status: 'GHOST', expectedTotal, actualTotal, matched: 0, coverage: null };
  }
  if (expectedTotal > 0 && actualTotal === 0) {
    return { status: 'LOST', expectedTotal, actualTotal, matched: 0, coverage: 0 };
  }

  let matched = 0;
  for (const key of expectedSet) {
    if (actualSet.has(key)) matched += 1;
  }

  const coverage = expectedTotal > 0 ? matched / expectedTotal : null;
  if (coverage >= 0.9) return { status: 'FULL', expectedTotal, actualTotal, matched, coverage };
  if (coverage >= 0.5) return { status: 'PARTIAL', expectedTotal, actualTotal, matched, coverage };
  return { status: 'LOW', expectedTotal, actualTotal, matched, coverage };
}

function main() {
  const bricksRaw = getBricksExpected();
  const etchRaw = getEtchStyles();

  const bricks = bricksRaw.map((c) => ({
    ...c,
    selector: `.${normalizeSelectorName(c.selector || c.name)}`,
  }));

  const etchIdx = new Map();
  for (const entry of etchRaw) {
    const selector = `.${normalizeSelectorName(entry.selector)}`;
    if (!selector || selector === '.') continue;
    if (!etchIdx.has(selector)) etchIdx.set(selector, []);
    etchIdx.get(selector).push(entry);
  }

  const duplicates = [];
  for (const [selector, entries] of etchIdx.entries()) {
    if (entries.length > 1) duplicates.push({ selector, count: entries.length });
  }
  duplicates.sort((a, b) => b.count - a.count);

  const qCustom = { FULL: 0, PARTIAL: 0, LOW: 0, LOST: 0, GHOST: 0, EMPTY: 0, MISSING: 0 };
  const qAcss = { EXTERNAL_EMPTY: 0, EXTERNAL_PRESENT: 0, MISSING: 0 };

  const counts = {
    total: 0,
    custom: 0,
    acss: 0,
    custom_with_settings: 0,
    custom_with_responsive: 0,
    acss_with_settings: 0,
    acss_with_responsive: 0,
  };

  const missingCustomWithCss = [];
  const lowCustom = [];
  const lostCustom = [];
  const details = [];

  const bricksSelectors = new Set();
  for (const cls of bricks) {
    counts.total += 1;
    bricksSelectors.add(cls.selector);

    if (cls.is_acss) {
      counts.acss += 1;
      if (cls.has_settings) counts.acss_with_settings += 1;
      if (cls.has_responsive) counts.acss_with_responsive += 1;
    } else {
      counts.custom += 1;
      if (cls.has_settings) counts.custom_with_settings += 1;
      if (cls.has_responsive) counts.custom_with_responsive += 1;
    }

    const entries = etchIdx.get(cls.selector) || [];
    const mergedCss = entries.map((e) => String(e.css || '')).join('\n');
    const expectedSet = extractDeclarations(cls.expected_css || '');
    const actualSet = extractDeclarations(mergedCss);

    if (cls.is_acss) {
      let acssStatus = 'EXTERNAL_EMPTY';
      if (entries.length === 0) acssStatus = 'MISSING';
      else if (actualSet.size > 0) acssStatus = 'EXTERNAL_PRESENT';
      qAcss[acssStatus] += 1;

      details.push({
        name: cls.name,
        selector: cls.selector,
        type: 'ACSS',
        category: cls.category || '',
        status: acssStatus,
        expected_decls: expectedSet.size,
        actual_decls: actualSet.size,
        etch_entries: entries.length,
      });
      continue;
    }

    if (entries.length === 0) {
      qCustom.MISSING += 1;
      if (expectedSet.size > 0) {
        missingCustomWithCss.push({ name: cls.name, selector: cls.selector, expected_decls: expectedSet.size });
      }
      details.push({
        name: cls.name,
        selector: cls.selector,
        type: 'Custom',
        status: 'MISSING',
        expected_decls: expectedSet.size,
        actual_decls: 0,
        matched_decls: 0,
        coverage: null,
        etch_entries: 0,
      });
      continue;
    }

    const cmp = compareDeclarationSets(expectedSet, actualSet);
    qCustom[cmp.status] += 1;

    if (cmp.status === 'LOST') {
      lostCustom.push({ name: cls.name, selector: cls.selector, expected_decls: cmp.expectedTotal });
    }
    if (cmp.status === 'LOW') {
      lowCustom.push({
        name: cls.name,
        selector: cls.selector,
        expected_decls: cmp.expectedTotal,
        actual_decls: cmp.actualTotal,
        matched_decls: cmp.matched,
        coverage: cmp.coverage,
      });
    }

    details.push({
      name: cls.name,
      selector: cls.selector,
      type: 'Custom',
      status: cmp.status,
      expected_decls: cmp.expectedTotal,
      actual_decls: cmp.actualTotal,
      matched_decls: cmp.matched,
      coverage: cmp.coverage,
      etch_entries: entries.length,
      has_settings: cls.has_settings,
      has_responsive: cls.has_responsive,
    });
  }

  const onlyInEtch = [];
  for (const selector of etchIdx.keys()) {
    if (!bricksSelectors.has(selector)) onlyInEtch.push(selector);
  }

  const customComparable = Object.values(qCustom).reduce((a, b) => a + b, 0);
  const customGood = (qCustom.FULL || 0) + (qCustom.PARTIAL || 0);
  const customGoodRate = customComparable > 0 ? ((customGood / customComparable) * 100).toFixed(1) : 'n/a';

  console.log('\n=== CSS Quality Check (All Classes) ===');
  console.log(`Bricks classes total: ${counts.total}`);
  console.log(`- Custom: ${counts.custom}`);
  console.log(`- ACSS:   ${counts.acss}`);
  console.log(`Etch class entries: ${etchRaw.length}`);
  console.log(`Etch unique selectors: ${etchIdx.size}`);
  console.log(`Duplicate selector groups: ${duplicates.length}`);

  console.log('\nCustom class quality (declaration-based, nested-aware):');
  for (const key of ['FULL', 'PARTIAL', 'LOW', 'LOST', 'GHOST', 'EMPTY', 'MISSING']) {
    console.log(`- ${key}: ${qCustom[key] || 0}`);
  }
  console.log(`Custom good rate (FULL+PARTIAL): ${customGoodRate}%`);

  console.log('\nACSS class tracking (external stylesheet expected):');
  for (const key of ['EXTERNAL_EMPTY', 'EXTERNAL_PRESENT', 'MISSING']) {
    console.log(`- ${key}: ${qAcss[key] || 0}`);
  }

  if (missingCustomWithCss.length > 0) {
    console.log(`\nMissing custom classes with expected declarations: ${missingCustomWithCss.length}`);
    for (const row of missingCustomWithCss.slice(0, 12)) {
      console.log(`- ${row.name} (${row.expected_decls} decls)`);
    }
  }

  if (lowCustom.length > 0) {
    console.log(`\nLOW custom matches: ${lowCustom.length}`);
    for (const row of lowCustom.slice(0, 12)) {
      const cov = row.coverage === null ? 'n/a' : `${(row.coverage * 100).toFixed(1)}%`;
      console.log(`- ${row.name} (coverage ${cov}, expected ${row.expected_decls}, actual ${row.actual_decls})`);
    }
  }

  const report = {
    timestamp: new Date().toISOString(),
    summary: {
      bricks_total: counts.total,
      bricks_custom: counts.custom,
      bricks_acss: counts.acss,
      bricks_custom_with_settings: counts.custom_with_settings,
      bricks_custom_with_responsive: counts.custom_with_responsive,
      bricks_acss_with_settings: counts.acss_with_settings,
      bricks_acss_with_responsive: counts.acss_with_responsive,
      etch_entries_total: etchRaw.length,
      etch_unique_selectors: etchIdx.size,
      etch_duplicate_selector_groups: duplicates.length,
      only_in_etch: onlyInEtch.length,
      custom_good_rate_percent: customGoodRate,
    },
    quality_custom: qCustom,
    quality_acss: qAcss,
    duplicate_selectors: duplicates,
    missing_custom_with_expected_declarations: missingCustomWithCss,
    low_custom_matches: lowCustom,
    lost_custom_matches: lostCustom,
    only_in_etch_sample: onlyInEtch.slice(0, 100),
    details,
  };

  const reportPath = path.join(CWD, `css-quality-${Date.now()}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
  console.log(`\nReport: ${path.basename(reportPath)}`);
}

main();
