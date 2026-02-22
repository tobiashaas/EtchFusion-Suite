#!/usr/bin/env node

/**
 * Verifies vendor/autoload.php exists in both wp-env environments (cli, tests-cli).
 * Exits 1 if any check fails. Use after composer:install:both for full recovery.
 */

const { spawn } = require('child_process');
const { join } = require('path');

const AUTOLOAD_PATH = 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php';
const ENVS = ['cli', 'tests-cli'];
function runCommandQuiet(command, args) {
  return new Promise((resolve, reject) => {
    const isWpEnv = command === 'wp-env' || command.endsWith('wp-env.cmd');
    const isWin = process.platform === 'win32';
    const child = isWpEnv
      ? spawn(
          isWin ? 'cmd' : 'npx',
          isWin ? ['/c', 'npx', 'wp-env', ...args] : ['wp-env', ...args],
          { stdio: 'pipe', cwd: join(__dirname, '..') }
        )
      : spawn(command, args, { stdio: 'pipe' });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (d) => { stdout += d.toString(); });
    child.stderr?.on('data', (d) => { stderr += d.toString(); });
    child.on('error', reject);
    child.on('exit', (code) => resolve({ code, stdout, stderr }));
  });
}

async function main() {
  console.log('Composer vendor/autoload.php check (cli, tests-cli)\n');
  let anyFail = false;
  for (const env of ENVS) {
    const result = await runCommandQuiet('wp-env', [
      'run',
      env,
      'test',
      '-f',
      AUTOLOAD_PATH
    ]);
    const ok = result.code === 0;
    const msg = ok ? 'PASS' : `FAIL - run composer:install:${env}`;
    console.log(`${env}: ${msg}`);
    if (!ok) anyFail = true;
  }
  if (anyFail) process.exit(1);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
