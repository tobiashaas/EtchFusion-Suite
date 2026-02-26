#!/usr/bin/env node
'use strict';
const { spawnSync } = require('child_process');
const path = require('path');
const CWD = path.resolve(__dirname, '..');

function runWpEnv(args) {
  const result = process.platform === 'win32'
    ? spawnSync('cmd', ['/c', 'npx', 'wp-env', ...args], { encoding: 'utf8', cwd: CWD })
    : spawnSync('npx', ['wp-env', ...args], { encoding: 'utf8', cwd: CWD });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || `failed`);
  return result.stdout.trim();
}

function runWpCli(env, cmd) {
  return runWpEnv(['run', env, 'wp', ...cmd]);
}

function extractJson(raw) {
  const input = String(raw || '');
  const start = Math.max(input.indexOf('{'), input.indexOf('['));
  if (start < 0) return null;
  const type = input[start];
  const close = type === '{' ? '}' : ']';
  let depth = 0;
  for (let i = start; i < input.length; i++) {
    if (input[i] === type) depth++;
    else if (input[i] === close) { depth--; if (depth === 0) return JSON.parse(input.slice(start, i + 1)); }
  }
  return null;
}

async function main() {
  // 1. Generate token on Etch side
  console.log('> Generating migration token...');
  const tokenRaw = runWpCli('tests-cli', ['eval',
    "echo etch_fusion_suite_container()->get('token_manager')->generate_migration_token('http://localhost:8889', null, 'http://localhost:8888')['token'];"
  ]);
  const token = (tokenRaw.match(/eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/) || [])[0];
  if (!token) throw new Error('Token not found: ' + tokenRaw);
  console.log('> Token:', token.slice(0, 40) + '...');

  // 2. Start migration (pages only, no media)
  console.log('> Starting pages-only migration...');
  const startCmd = [
    'eval',
    `if(!function_exists('bricks_is_builder')){function bricks_is_builder(){return true;}} $r=etch_fusion_suite_container()->get('migration_controller')->start_migration(array('migration_key'=>'${token}','target_url'=>'http://localhost:8889','batch_size'=>50,'mode'=>'headless','selected_post_types'=>array('page'),'post_type_mappings'=>array('page'=>'page'),'include_media'=>false)); if(is_wp_error($r)){fwrite(STDERR,$r->get_error_message());exit(1);} echo wp_json_encode($r);`
  ];
  const startRaw = runWpCli('cli', startCmd);
  const startResult = extractJson(startRaw);
  const migrationId = (startRaw.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i) || [])[0];
  console.log('> Migration ID:', migrationId || '(none — checking progress)');

  // 3. Drive headless
  console.log('> Driving headless migration...');
  runWpCli('cli', ['eval',
    `set_time_limit(0); etch_fusion_suite_container()->get('headless_migration_job')->run_headless_job('${migrationId || ''}');`
  ]);

  // 4. Check result
  const progressRaw = runWpCli('cli', ['option', 'get', 'efs_migration_progress', '--format=json']);
  const progress = JSON.parse(progressRaw);
  console.log('> Status:', progress.status);
  console.log('> Percentage:', progress.percentage + '%');
  console.log('> Message:', progress.message);

  if (progress.status !== 'completed') {
    throw new Error('Migration did not complete: ' + progress.status);
  }

  // 5. Count pages
  const srcPages = parseInt(runWpCli('cli', ['post', 'list', '--post_type=page', '--format=count']), 10);
  const tgtPages = parseInt(runWpCli('tests-cli', ['post', 'list', '--post_type=page', '--format=count']), 10);
  console.log(`\n> Source pages: ${srcPages}`);
  console.log(`> Target pages: ${tgtPages}`);
  if (tgtPages < srcPages) console.warn(`WARNING: ${srcPages - tgtPages} page(s) missing on target`);
  else console.log('OK All pages migrated');
}

main().catch(e => { console.error('ERROR:', e.message); process.exit(1); });
