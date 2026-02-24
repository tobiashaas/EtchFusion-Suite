#!/usr/bin/env node

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const createTestContent = require('./create-test-content');

const CWD = path.resolve(__dirname, '..');

function runWpEnv(args) {
  const run = process.platform === 'win32'
    ? () => spawnSync('cmd', ['/c', 'npx', 'wp-env', ...args], { encoding: 'utf8', cwd: CWD })
    : () => spawnSync('npx', ['wp-env', ...args], { encoding: 'utf8', cwd: CWD });
  const result = run();

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `Command failed: ${args.join(' ')}`);
  }

  return result.stdout.trim();
}

function runWpCli(environmentArgs, commandArgs) {
  return runWpEnv(['run', ...environmentArgs, 'wp', ...commandArgs]);
}

function extractFirstJsonValue(raw) {
  const input = String(raw || '').trim();
  if (!input) return null;

  try {
    return JSON.parse(input);
  } catch (error) {
    // continue
  }

  const starts = [input.indexOf('{'), input.indexOf('[')].filter((i) => i >= 0);
  if (starts.length === 0) return null;

  const start = Math.min(...starts);
  let inString = false;
  let escaped = false;
  const stack = [];

  for (let i = start; i < input.length; i += 1) {
    const ch = input[i];
    if (inString) {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === '"') inString = false;
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
      if (stack.length === 0 || stack[stack.length - 1] !== expected) continue;
      stack.pop();
      if (stack.length === 0) {
        try {
          return JSON.parse(input.slice(start, i + 1));
        } catch (error) {
          return null;
        }
      }
    }
  }

  return null;
}

function parseJsonArray(raw) {
  const parsed = extractFirstJsonValue(raw);
  return Array.isArray(parsed) ? parsed : [];
}

function runLocalNodeScript(scriptRelPath, args = []) {
  const scriptPath = path.resolve(CWD, scriptRelPath);
  const result = spawnSync('node', [scriptPath, ...args], { encoding: 'utf8', cwd: CWD });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `Node script failed: ${scriptRelPath}`);
  }

  return result.stdout.trim();
}

function getActivePlugins(env) {
  const raw = runWpCli([env], ['plugin', 'list', '--status=active', '--field=name', '--format=json']);
  return parseJsonArray(raw).map((v) => String(v).trim()).filter(Boolean);
}

function getActiveTheme(env) {
  const raw = runWpCli([env], ['theme', 'list', '--status=active', '--field=name', '--format=json']);
  const themes = parseJsonArray(raw).map((v) => String(v).trim()).filter(Boolean);
  return themes[0] || '';
}

function hasMigrationContainerFunction() {
  const output = runWpCli(['tests-cli'], ['eval', "echo function_exists('etch_fusion_suite_container') ? 'yes' : 'no';"]);
  return String(output).includes('yes');
}

function assertEnvironmentReady() {
  const devPlugins = getActivePlugins('cli');
  const testPlugins = getActivePlugins('tests-cli');
  const devTheme = getActiveTheme('cli');
  const testTheme = getActiveTheme('tests-cli');
  const hasContainerFn = hasMigrationContainerFunction();

  const missing = [];
  const requiredDevPlugins = ['frames-plugin', 'automaticcss-plugin', 'etch-fusion-suite'];
  const requiredTestPlugins = ['etch', 'automaticcss-plugin', 'etch-fusion-suite'];

  for (const slug of requiredDevPlugins) {
    if (!devPlugins.includes(slug)) missing.push(`cli plugin: ${slug}`);
  }
  for (const slug of requiredTestPlugins) {
    if (!testPlugins.includes(slug)) missing.push(`tests-cli plugin: ${slug}`);
  }

  if (devTheme !== 'bricks') missing.push(`cli theme expected bricks, got ${devTheme || 'none'}`);
  if (testTheme !== 'etch-theme') missing.push(`tests-cli theme expected etch-theme, got ${testTheme || 'none'}`);
  if (!hasContainerFn) missing.push('tests-cli function etch_fusion_suite_container() missing');

  return { ok: missing.length === 0, missing };
}

function ensureEnvironmentReady() {
  console.log('> Preflight: checking plugins/theme/bootstrap state...');
  let state = assertEnvironmentReady();
  if (state.ok) {
    console.log('> Preflight OK');
    return;
  }

  console.log('> Preflight issues detected:');
  state.missing.forEach((m) => console.log(`  - ${m}`));
  console.log('> Running auto-repair via scripts/activate-plugins.js ...');

  runLocalNodeScript('scripts/activate-plugins.js');

  state = assertEnvironmentReady();
  if (!state.ok) {
    throw new Error(`Preflight failed after auto-repair: ${state.missing.join(' | ')}`);
  }

  console.log('> Preflight repaired successfully');
}

let cachedTemplateTargetPostType = null;

function resolveTemplateTargetPostType() {
  if (cachedTemplateTargetPostType) {
    return cachedTemplateTargetPostType;
  }

  const value = runWpCli(
    ['tests-cli'],
    ['eval', "echo post_type_exists('patterns') ? 'patterns' : ( post_type_exists('wp_block') ? 'wp_block' : 'page' );"]
  ).trim();

  cachedTemplateTargetPostType = (value === 'patterns' || value === 'wp_block' || value === 'page') ? value : 'page';
  return cachedTemplateTargetPostType;
}

function generateMigrationKey(targetUrl, sourceUrl) {
  console.log('> Generating migration key on Etch instance...');
  const sourceArg = sourceUrl ? `'${sourceUrl}'` : 'null';
  const cmd = `echo etch_fusion_suite_container()->get('token_manager')->generate_migration_token('${targetUrl}', null, ${sourceArg})['token'];`;
  const rawOutput = runWpCli(['tests-cli'], ['eval', cmd]).trim();
  const match = rawOutput.match(/eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
  const key = match ? match[0] : '';

  if (!key) {
    throw new Error(`Migration key generation returned invalid output: ${rawOutput}`);
  }

  return key;
}

function triggerMigration(migrationKey, targetUrl) {
  console.log('> Triggering migration via WP-CLI (headless mode)...');
  const templateTarget = resolveTemplateTargetPostType();
  console.log(`> Mapping bricks_template -> ${templateTarget}`);

  const cmd = `if (!function_exists('bricks_is_builder')) { function bricks_is_builder() { return true; } } $result=etch_fusion_suite_container()->get('migration_controller')->start_migration(array('migration_key'=>'${migrationKey}','target_url'=>'${targetUrl}','batch_size'=>50,'mode'=>'headless','selected_post_types'=>array('post','page','bricks_template'),'post_type_mappings'=>array('post'=>'post','page'=>'page','bricks_template'=>'${templateTarget}'))); if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message()); exit(1); } echo wp_json_encode($result);`;
  const rawOutput = runWpCli(['cli'], ['eval', cmd]);
  const match = rawOutput.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
  return match ? match[0] : null;
}

function driveHeadlessMigration(migrationId) {
  console.log(`> Driving headless migration ${migrationId} synchronously via WP-CLI...`);
  const cmd = `set_time_limit(0); etch_fusion_suite_container()->get('headless_migration_job')->run_headless_job('${migrationId}');`;
  runWpCli(['cli'], ['eval', cmd]);
  console.log('> Headless migration driver completed');
}

function getProgress() {
  try {
    const progressJson = runWpCli(['cli'], ['option', 'get', 'efs_migration_progress', '--format=json']);

    if (!progressJson || progressJson.trim() === '') {
      return null;
    }

    return JSON.parse(progressJson);
  } catch (error) {
    if (error.message.includes('does not exist') || error.message.includes('Could not get')) {
      return null;
    }
    throw error;
  }
}

function waitForCompletion(timeoutMs = 600000, intervalMs = 5000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();

    const check = () => {
      const progress = getProgress();

      if (progress && progress.status === 'completed') {
        console.log('OK Migration completed successfully');
        resolve(progress);
        return;
      }

      if (progress && (progress.status === 'failed' || progress.status === 'error')) {
        reject(new Error(`Migration failed: ${progress.message || 'Unknown error'}`));
        return;
      }

      if (Date.now() - start > timeoutMs) {
        reject(new Error('Migration timed out'));
        return;
      }

      console.log('... Migration in progress, waiting for next update');
      setTimeout(check, intervalMs);
    };

    check();
  });
}

function collectStats() {
  const postTypes = 'post,page,bricks_template';
  const targetPostTypes = resolveTemplateTargetPostType();
  const targetTypes = `post,page,${targetPostTypes}`;

  const sourcePosts = runWpCli(['cli'], ['post', 'list', `--post_type=${postTypes}`, '--format=count']);
  const targetPosts = runWpCli(['tests-cli'], ['post', 'list', `--post_type=${targetTypes}`, '--format=count']);

  const sourceMedia = runWpCli(['cli'], ['post', 'list', '--post_type=attachment', '--format=count']);
  const targetMedia = runWpCli(['tests-cli'], ['post', 'list', '--post_type=attachment', '--format=count']);

  return {
    sourcePosts: parseInt(sourcePosts, 10),
    targetPosts: parseInt(targetPosts, 10),
    sourceMedia: parseInt(sourceMedia, 10),
    targetMedia: parseInt(targetMedia, 10),
  };
}

async function main() {
  ensureEnvironmentReady();

  const skipBaseline = process.env.SKIP_BASELINE === '1' || process.env.SKIP_BASELINE === 'true';
  if (!skipBaseline) {
    console.log('> Creating baseline content on Bricks instance...');
    await createTestContent();
  } else {
    console.log('> Skipping baseline content (SKIP_BASELINE=1); using existing Bricks content.');
  }

  const targetUrl = 'http://localhost:8889';
  const sourceUrl = 'http://localhost:8888';
  const migrationKey = generateMigrationKey(targetUrl, sourceUrl);
  const migrationId = triggerMigration(migrationKey, targetUrl);

  let progress;
  if (migrationId) {
    console.log(`> Started migration ID: ${migrationId}`);
    driveHeadlessMigration(migrationId);
    progress = getProgress();
    if (!progress || progress.status !== 'completed') {
      const status = progress ? progress.status : 'unknown';
      throw new Error(`Migration did not complete. Final status: ${status}`);
    }
    console.log('OK Migration completed successfully');
  } else {
    console.log('> No migrationId returned, falling back to polling...');
    progress = await waitForCompletion();
  }

  const stats = collectStats();
  const report = {
    timestamp: new Date().toISOString(),
    progress,
    stats
  };

  const reportDir = path.resolve(__dirname, '..');
  const reportPath = path.join(reportDir, `migration-report-${Date.now()}.json`);
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

  console.log('\nMigration report saved to', reportPath);
  console.log('Source posts (post/page/template):', stats.sourcePosts);
  console.log('Target posts (post/page/template):', stats.targetPosts);
  console.log('Source media:', stats.sourceMedia);
  console.log('Target media:', stats.targetMedia);

  if (stats.targetPosts < stats.sourcePosts) {
    console.warn(`WARNING: ${stats.sourcePosts - stats.targetPosts} post(s) may not have been migrated.`);
  }
  if (stats.targetMedia < stats.sourceMedia) {
    console.warn(`WARNING: ${stats.sourceMedia - stats.targetMedia} media item(s) may not have been migrated.`);
  }
}

main().catch((error) => {
  console.error('\nERROR Migration test failed:', error.message);
  process.exit(1);
});
