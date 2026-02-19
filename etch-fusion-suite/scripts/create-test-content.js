#!/usr/bin/env node

const { spawnSync } = require('child_process');
const path = require('path');

const CWD = path.resolve(__dirname, '..');
const WP_ENV_ARGS = ['run', 'cli', 'wp', '--skip-themes'];

function runWpCli(args) {
  const run = process.platform === 'win32'
    ? () => spawnSync('cmd', ['/c', 'npx', 'wp-env', ...WP_ENV_ARGS, ...args], { encoding: 'utf8', cwd: CWD })
    : () => spawnSync('npx', ['wp-env', ...WP_ENV_ARGS, ...args], { encoding: 'utf8', cwd: CWD });
  const result = run();

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `Command failed: wp ${args.join(' ')}`);
  }

  return result.stdout.trim();
}

function getBricksBaseSummary() {
  return runWpCli([
    'eval',
    [
      '$classes = get_option("bricks_global_classes", array());',
      '$media = wp_count_attachments();',
      '$images = 0;',
      'foreach ((array) $media as $mime => $count) {',
      '  if (0 === strpos((string) $mime, "image/")) { $images += (int) $count; }',
      '}',
      '$templates = wp_count_posts("bricks_template")->publish ?? 0;',
      '$pages = wp_count_posts("page")->publish ?? 0;',
      '$posts = wp_count_posts("post")->publish ?? 0;',
      'echo "classes=" . (is_array($classes) ? count($classes) : 0) . PHP_EOL;',
      'echo "images=" . (int) $images . PHP_EOL;',
      'echo "templates=" . (int) $templates . PHP_EOL;',
      'echo "pages=" . (int) $pages . PHP_EOL;',
      'echo "posts=" . (int) $posts . PHP_EOL;'
    ].join(' ')
  ]);
}

async function createTestContent() {
  console.log('Using existing Bricks base content.');
  console.log('No test text content will be created.');
  console.log('No test media will be imported.');

  const summary = getBricksBaseSummary();
  console.log(summary);

  console.log('Bricks base check complete.');
}

if (require.main === module) {
  createTestContent().catch((error) => {
    console.error('Failed to check Bricks base:', error.message);
    process.exit(1);
  });
}

module.exports = createTestContent;