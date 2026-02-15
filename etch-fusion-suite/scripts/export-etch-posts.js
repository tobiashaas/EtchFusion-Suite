#!/usr/bin/env node

/**
 * Exports migrated posts and pages from the Etch (tests-cli) instance to
 * validation-results/ as JSON for etchData validation.
 *
 * Run after: npm run create-test-content && npm run test:migration
 * Then: npm run validate:etchdata -- validation-results/post-*.json validation-results/page-*.json
 */

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const CWD = path.resolve(__dirname, '..');
const OUT_DIR = path.join(CWD, 'validation-results');

function runWpEtch(args) {
  const run = process.platform === 'win32'
    ? () => spawnSync('cmd', ['/c', 'npx', 'wp-env', 'run', 'tests-cli', 'wp', ...args], { encoding: 'utf8', cwd: CWD })
    : () => spawnSync('npx', ['wp-env', 'run', 'tests-cli', 'wp', ...args], { encoding: 'utf8', cwd: CWD });
  const result = run();

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `wp ${args.join(' ')} failed`);
  }

  return result.stdout.trim();
}

function ensureOutDir() {
  if (!fs.existsSync(OUT_DIR)) {
    fs.mkdirSync(OUT_DIR, { recursive: true });
  }
}

function exportPosts() {
  const listJson = runWpEtch([
    'post', 'list',
    '--post_type=post',
    '--format=json',
    '--fields=ID,post_title'
  ]);

  const posts = JSON.parse(listJson || '[]');
  for (const post of posts) {
    const json = runWpEtch(['post', 'get', String(post.ID), '--format=json']);
    const outPath = path.join(OUT_DIR, `post-${post.ID}.json`);
    fs.writeFileSync(outPath, json, 'utf8');
    console.log(`  Exported post ${post.ID}: ${post.post_title} -> ${path.basename(outPath)}`);
  }
  return posts.length;
}

function exportPages() {
  const listJson = runWpEtch([
    'post', 'list',
    '--post_type=page',
    '--format=json',
    '--fields=ID,post_name,post_title'
  ]);

  const pages = JSON.parse(listJson || '[]');
  for (const page of pages) {
    const json = runWpEtch(['post', 'get', String(page.ID), '--format=json']);
    const base = page.post_name || `page-${page.ID}`;
    const outPath = path.join(OUT_DIR, `page-${base}.json`);
    fs.writeFileSync(outPath, json, 'utf8');
    console.log(`  Exported page ${page.ID}: ${page.post_title} -> ${path.basename(outPath)}`);
  }
  return pages.length;
}

function main() {
  console.log('▶ Exporting migrated posts and pages from Etch (tests-cli)...');
  ensureOutDir();
  const postCount = exportPosts();
  const pageCount = exportPages();
  console.log(`✓ Exported ${postCount} posts and ${pageCount} pages to ${OUT_DIR}`);
  console.log('  Run: npm run validate:etchdata -- validation-results/post-*.json validation-results/page-*.json');
}

main();
