#!/usr/bin/env node

/**
 * Verifies vendor/autoload.php exists in both wp-env environments (cli, tests-cli).
 * Exits 1 if any check fails. Use after composer:install:both for full recovery.
 */

const { spawn } = require('child_process');
const { join } = require('path');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';
const AUTOLOAD_PATH = 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php';
const ENVS = ['cli', 'tests-cli'];

function getWpEnvPath() {
  if (process.platform !== 'win32') return WP_ENV_CMD;
  const local = join(__dirname, '..', 'node_modules', '.bin', 'wp-env.cmd');
  const fs = require('fs');
  return fs.existsSync(local) ? local : WP_ENV_CMD;
}

function runCommandQuiet(command, args) {
  return new Promise((resolve, reject) => {
    let child;
    if (process.platform === 'win32' && (command === WP_ENV_CMD || command.endsWith('wp-env.cmd'))) {
      const cmdLine = [command, ...args].map((a) => (/[\s"&|<>^]/.test(a) ? `"${String(a).replace(/"/g, '""')}"` : a)).join(' ');
      child = spawn(cmdLine, [], { stdio: 'pipe', shell: true });
    } else {
      child = spawn(command, args, { stdio: 'pipe' });
    }
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
  const wpEnv = getWpEnvPath();
  for (const env of ENVS) {
    const result = await runCommandQuiet(wpEnv, [
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
