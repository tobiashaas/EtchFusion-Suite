#!/usr/bin/env node

/**
 * Full wp-env setup verification for EtchFusion-Suite.
 * Addresses HTTP 500 (critical error) by ensuring theme, plugins, and permalinks are set.
 *
 * Steps:
 * 1. Logs: instructions for npx wp-env logs and tail debug.log
 * 2. Theme: install and activate twentytwentyfour on Bricks (cli) and Etch (tests-cli)
 * 3. Plugins: deactivate all, activate etch-fusion-suite on both
 * 4. Permalinks: rewrite flush --hard on both
 * 5. Verify: run health check and test:connection
 * 6. env-info: instruct to run npm run env:info > env-info-output.txt
 */

const { spawnSync } = require('child_process');
const path = require('path');

function runWpEnv(args, opts = {}) {
  const isWin = process.platform === 'win32';
  const result = spawnSync(
    isWin ? 'cmd' : 'npx',
    isWin ? ['/c', 'npx', 'wp-env', ...args] : ['wp-env', ...args],
    { encoding: 'utf8', stdio: 'pipe', cwd: path.resolve(__dirname, '..'), ...opts }
  );
  return { code: result.status, stdout: result.stdout || '', stderr: result.stderr || '' };
}

function runWp(env, wpArgs) {
  return runWpEnv(['run', env, 'wp', ...wpArgs]);
}

function log(msg) {
  console.log(msg);
}

function step(name) {
  log(`\n▶ ${name}...`);
}

function main() {
  const args = process.argv.slice(2);
  const skipLogs = args.includes('--skip-logs');
  const skipVerify = args.includes('--skip-verify');

  log('🔍 EtchFusion-Suite wp-env verification');
  log('   Bricks = cli (port 8888), Etch = tests-cli (port 8889)\n');

  // 1. Logs
  if (!skipLogs) {
    step('Logs (run manually if needed)');
    log('   npx wp-env logs');
    log('   npx wp-env run cli -- tail -f wp-content/debug.log    # Bricks');
    log('   npx wp-env run tests-cli -- tail -f wp-content/debug.log  # Etch (repeat for tests)');
  }

  // 2. Theme
  step('Theme: install and activate twentytwentyfour');
  for (const env of ['cli', 'tests-cli']) {
    const r = runWp(env, ['theme', 'install', 'twentytwentyfour', '--activate']);
    if (r.code === 0) {
      log(`   ✅ ${env}: twentytwentyfour active`);
    } else {
      log(`   ⚠️ ${env}: ${r.stderr || r.stdout || 'exit ' + r.code}`);
    }
  }

  // 3. Plugins
  step('Plugins: deactivate all, activate etch-fusion-suite');
  for (const env of ['cli', 'tests-cli']) {
    runWp(env, ['plugin', 'deactivate', '--all']);
    const r = runWp(env, ['plugin', 'activate', 'etch-fusion-suite']);
    if (r.code === 0) {
      log(`   ✅ ${env}: etch-fusion-suite active`);
    } else {
      log(`   ⚠️ ${env}: ${r.stderr || r.stdout || 'exit ' + r.code}`);
    }
  }

  // 4. Permalinks
  step('Permalinks: rewrite flush --hard');
  for (const env of ['cli', 'tests-cli']) {
    const r = runWp(env, ['rewrite', 'flush', '--hard']);
    if (r.code === 0) {
      log(`   ✅ ${env}: rewrite flushed`);
    } else {
      log(`   ⚠️ ${env}: ${r.stderr || r.stdout || 'exit ' + r.code}`);
    }
  }

  // 5. Verify
  if (!skipVerify) {
    step('Verify: health check');
    const health = spawnSync('node', [path.join(__dirname, 'health-check.js'), '--save-report'], {
      cwd: path.join(__dirname, '..'),
      encoding: 'utf8',
      stdio: 'inherit'
    });
    if (health.status !== 0) {
      log(`   Health exit code: ${health.status}`);
    }

    step('Verify: test:connection');
    const testConn = spawnSync('node', [path.join(__dirname, 'test-connection.js')], {
      cwd: path.join(__dirname, '..'),
      encoding: 'utf8',
      stdio: 'inherit'
    });
    if (testConn.status !== 0) {
      log(`   test:connection exit code: ${testConn.status}`);
    }
  }

  // 6. env-info
  step('env-info');
  log('   Run: npm run env:info > env-info-output.txt');
  log('   (Should show WP version, 1 plugin, theme.)');

  log('\n✅ Verification steps completed.');
  log('   Browser: http://localhost:8888/wp-admin and http://localhost:8889/wp-admin (admin / password)');
  log('   If still 500: npx wp-env clean --yes && npx wp-env start ; check scripts/setup-bricks.js');
}

main();
