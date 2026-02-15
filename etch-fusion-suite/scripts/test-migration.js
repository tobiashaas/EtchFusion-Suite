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

function generateMigrationKey(targetUrl) {
  console.log('> Generating migration key on Etch instance...');
  const cmd = `echo etch_fusion_suite_container()->get('token_manager')->generate_migration_token('${targetUrl}')['token'];`;
  const rawOutput = runWpCli(['tests-cli'], ['eval', cmd]).trim();
  const match = rawOutput.match(/eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/);
  const key = match ? match[0] : '';

  if (!key) {
    throw new Error(`Migration key generation returned invalid output: ${rawOutput}`);
  }

  return key;
}

function triggerMigration(migrationKey, targetUrl) {
  console.log('> Triggering migration via WP-CLI...');
  const cmd = `if (!function_exists('bricks_is_builder')) { function bricks_is_builder() { return true; } } $result=etch_fusion_suite_container()->get('migration_controller')->start_migration(array('migration_key'=>'${migrationKey}','target_url'=>'${targetUrl}','batch_size'=>50)); if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message()); exit(1); } echo wp_json_encode($result);`;
  runWpCli(['cli'], ['eval', cmd]);
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
  const sourcePosts = runWpCli(['cli'], ['post', 'list', '--post_type=post', '--format=count']);
  const targetPosts = runWpCli(['tests-cli'], ['post', 'list', '--post_type=post', '--format=count']);

  return {
    sourcePosts: parseInt(sourcePosts, 10),
    targetPosts: parseInt(targetPosts, 10)
  };
}

async function main() {
  const skipBaseline = process.env.SKIP_BASELINE === '1' || process.env.SKIP_BASELINE === 'true';
  if (!skipBaseline) {
    console.log('> Creating baseline content on Bricks instance...');
    await createTestContent();
  } else {
    console.log('> Skipping baseline content (SKIP_BASELINE=1); using existing Bricks content.');
  }

  const targetUrl = 'http://localhost:8889';
  const migrationKey = generateMigrationKey(targetUrl);
  triggerMigration(migrationKey, targetUrl);
  const progress = await waitForCompletion();

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
  console.log('Source posts:', stats.sourcePosts);
  console.log('Target posts:', stats.targetPosts);
}

main().catch((error) => {
  console.error('\nERROR Migration test failed:', error.message);
  process.exit(1);
});
