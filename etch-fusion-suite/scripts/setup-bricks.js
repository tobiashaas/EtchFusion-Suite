#!/usr/bin/env node

/**
 * Bricks Builder Setup Script
 *
 * Automatically prepares both wp-env instances after start.
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

function log(message) {
  console.log(`[Bricks Setup] ${message}`);
}

function error(message) {
  console.error(`[Bricks Setup] ERROR: ${message}`);
}

async function runCommand(command, args, cwd = process.cwd()) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      stdio: 'pipe',
      shell: true
    });

    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (data) => {
      stdout += data.toString();
    });

    child.stderr.on('data', (data) => {
      stderr += data.toString();
    });

    child.on('close', (code) => {
      if (code === 0) {
        resolve({ stdout, stderr });
      } else {
        reject(new Error(`Command failed with code ${code}: ${stderr || stdout}`));
      }
    });
  });
}

/** Run wp-env and return { code, stdout, stderr } (no throw). */
async function runWpEnv(args) {
  return new Promise((resolve) => {
    const child = spawn('npx', ['wp-env', ...args], {
      stdio: 'pipe',
      shell: true
    });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (data) => {
      stdout += data.toString();
    });
    child.stderr?.on('data', (data) => {
      stderr += data.toString();
    });
    child.on('close', (code) => resolve({ code, stdout, stderr }));
  });
}

function parseMemoryToMb(value) {
  if (!value) return null;
  const normalized = String(value).trim().toUpperCase();
  if (!normalized) return null;
  if (normalized === '-1') return Infinity;

  const match = normalized.match(/^([0-9]+(?:\.[0-9]+)?)([KMG]?)$/);
  if (!match) return null;

  const amount = parseFloat(match[1]);
  const unit = match[2];

  if (unit === 'G') return amount * 1024;
  if (unit === 'M' || unit === '') return amount;
  if (unit === 'K') return amount / 1024;
  return null;
}

async function checkMemoryLimit(environment, name) {
  const result = await runWpEnv(['run', environment, 'wp', 'eval', 'echo WP_MEMORY_LIMIT;']);
  if (result.code !== 0) {
    const details = (result.stderr || result.stdout || `Exit code ${result.code}`).trim();
    log(`${name}: unable to verify WP_MEMORY_LIMIT (${details})`);
    return {
      status: 'warning',
      currentLimit: null,
      meetsMinimum: false,
      minimum: '256M',
      details
    };
  }

  const currentLimit = (result.stdout || '').trim();
  const currentMb = parseMemoryToMb(currentLimit);
  const meetsMinimum = currentMb === Infinity || (typeof currentMb === 'number' && currentMb >= 256);

  if (!meetsMinimum) {
    log(`WARNING: ${name} memory limit is ${currentLimit || 'unknown'} (recommended minimum: 256M)`);
  }

  return {
    status: meetsMinimum ? 'pass' : 'warning',
    currentLimit,
    meetsMinimum,
    minimum: '256M',
    currentMb
  };
}

async function cleanupBricksFromTests() {
  const listResult = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'list', '--field=name', '--format=json']);
  if (listResult.code !== 0) {
    log('Etch: could not inspect installed themes for cleanup');
    return { cleaned: false, bricksFound: false, deleted: false };
  }

  let installedThemes = [];
  try {
    installedThemes = JSON.parse((listResult.stdout || '[]').trim() || '[]');
  } catch (err) {
    log(`Etch: failed to parse theme list during cleanup (${err.message})`);
    return { cleaned: false, bricksFound: false, deleted: false };
  }

  const bricksFound = Array.isArray(installedThemes) && installedThemes.includes('bricks');

  // Keep tests environment on WordPress default theme for stability.
  const activateDefault = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'activate', 'twentytwentyfour']);
  if (activateDefault.code === 0) {
    log('Etch: activated twentytwentyfour as fallback theme');
  } else {
    log('Etch: failed to activate twentytwentyfour during cleanup');
  }

  if (!bricksFound) {
    log('Etch: Bricks theme is not installed, cleanup not required');
    return { cleaned: true, bricksFound: false, deleted: false };
  }

  log('Etch: Bricks theme found on tests-cli, cleaning up');

  const deactivate = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'deactivate', 'bricks']);
  if (deactivate.code !== 0) {
    log('Etch: wp theme deactivate bricks failed (expected on some wp-cli versions)');
  }

  const deleteResult = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'delete', 'bricks', '--force']);
  if (deleteResult.code === 0) {
    log('Etch: deleted Bricks theme from tests-cli');
  } else {
    log('Etch: could not delete Bricks theme from tests-cli');
  }

  return {
    cleaned: true,
    bricksFound: true,
    deleted: deleteResult.code === 0
  };
}

async function checkBricksLicense() {
  const licensePaths = [
    path.join(process.cwd(), 'bricks-license.txt'),
    path.join(process.cwd(), '.bricks-license'),
    path.join(process.cwd(), 'config', 'bricks-license.txt'),
    path.join(process.env.HOME || '', '.bricks-license'),
    path.join(process.env.HOME || '', 'bricks-license.txt')
  ];

  for (const licensePath of licensePaths) {
    if (fs.existsSync(licensePath)) {
      log(`Found Bricks license at: ${licensePath}`);
      return licensePath;
    }
  }

  return null;
}

/**
 * Ensure a default theme is active on both instances to avoid HTTP 500 (no active theme).
 * Also keep Bricks theme out of tests-cli.
 */
async function ensureDefaultTheme() {
  try {
    await runCommand('npx', ['wp-env', 'run', 'cli', 'wp', 'theme', 'install', 'twentytwentyfour']);
    log('Bricks: twentytwentyfour installed as fallback (not activated)');
  } catch (err) {
    log(`Bricks: fallback theme install skipped or failed (${err.message})`);
  }

  const activeDevThemes = await runWpEnv(['run', 'cli', 'wp', 'theme', 'list', '--status=active', '--field=name', '--format=json']);
  if (activeDevThemes.code === 0) {
    try {
      const names = JSON.parse((activeDevThemes.stdout || '[]').trim() || '[]');
      if (!Array.isArray(names) || names.length === 0) {
        const activateFallback = await runWpEnv(['run', 'cli', 'wp', 'theme', 'activate', 'twentytwentyfour']);
        if (activateFallback.code === 0) {
          log('Bricks: no active theme detected, activated twentytwentyfour fallback');
        } else {
          log('Bricks: no active theme detected, but fallback activation failed');
        }
      } else {
        log(`Bricks: keeping active theme unchanged (${names.join(', ')})`);
      }
    } catch (err) {
      log(`Bricks: could not parse active theme list (${err.message})`);
    }
  } else {
    log('Bricks: could not verify active theme; leaving development theme unchanged');
  }

  try {
    await runCommand('npx', ['wp-env', 'run', 'tests-cli', 'wp', 'theme', 'install', 'twentytwentyfour', '--activate']);
    log('Etch: twentytwentyfour installed and activated');
  } catch (err) {
    log(`Etch: default theme install/activate skipped or failed (${err.message})`);
  }

  const testThemes = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'list', '--field=name', '--format=json']);
  if (testThemes.code === 0) {
    try {
      const names = JSON.parse((testThemes.stdout || '[]').trim() || '[]');
      if (Array.isArray(names) && names.includes('bricks')) {
        await cleanupBricksFromTests();
      }
    } catch (err) {
      log(`Etch: unable to parse theme list for verification (${err.message})`);
    }
  }
}

async function installBricks() {
  try {
    log('Checking for Bricks Builder installation...');

    let themes = [];
    try {
      const { stdout: themeList } = await runCommand('npx', ['wp-env', 'run', 'cli', 'wp', 'theme', 'list', '--status=active', '--format=json']);
      themes = JSON.parse(themeList || '[]');
    } catch {
      themes = [];
    }

    const bricksActive = Array.isArray(themes) && themes.some((theme) => theme.name === 'bricks');

    if (bricksActive) {
      log('Bricks Builder is already installed and active');
      return true;
    }

    const licensePath = await checkBricksLicense();
    if (!licensePath) {
      log('No Bricks license file found');
      log('To auto-install Bricks, add your license key to one of these locations:');
      const licensePaths = [
        path.join(process.cwd(), 'bricks-license.txt'),
        path.join(process.cwd(), '.bricks-license'),
        path.join(process.cwd(), 'config', 'bricks-license.txt'),
        path.join(process.env.HOME || '', '.bricks-license'),
        path.join(process.env.HOME || '', 'bricks-license.txt')
      ];
      licensePaths.forEach((licenseFilePath) => {
        log(`  - ${licenseFilePath}`);
      });
      log('For now, install Bricks manually in WordPress admin');
      return false;
    }

    const licenseKey = fs.readFileSync(licensePath, 'utf8').trim();
    if (!licenseKey) {
      error('License file is empty');
      return false;
    }

    log('Bricks Builder is a premium theme and cannot be auto-installed');
    log('Please install Bricks Builder manually:');
    log('  1. Download Bricks from your account');
    log('  2. Upload via WordPress Admin > Appearance > Themes > Add Theme > Upload Theme');
    log('  3. Activate the theme');
    log('  4. Use your license key from bricks-license.txt');

    return false;
  } catch (err) {
    error(`Setup failed: ${err.message}`);
    return false;
  }
}

async function main() {
  log('Starting Bricks Builder setup');

  try {
    await ensureDefaultTheme();

    const bricksMemory = await checkMemoryLimit('cli', 'Bricks');
    const etchMemory = await checkMemoryLimit('tests-cli', 'Etch');

    log('Memory verification:');
    log(`Bricks (cli): ${bricksMemory.currentLimit || 'unknown'} (${bricksMemory.meetsMinimum ? 'OK' : 'LOW'})`);
    log(`Etch (tests-cli): ${etchMemory.currentLimit || 'unknown'} (${etchMemory.meetsMinimum ? 'OK' : 'LOW'})`);
    await cleanupBricksFromTests();

    log('Installing Composer dependencies in cli (Bricks)...');
    await runCommand('npx', ['wp-env', 'run', 'cli', '--env-cwd=wp-content/plugins/etch-fusion-suite', 'composer', 'install', '--no-dev', '--optimize-autoloader']);
    const verify = await runWpEnv(['run', 'cli', 'test', '-f', 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php']);
    if (verify.code !== 0) {
      error('Missing vendor/autoload.php in cli. Run `npm run composer:install` then retry.');
      process.exit(1);
    }
    log('Composer dependencies OK in cli');

    const success = await installBricks();

    if (success) {
      log('Bricks Builder setup completed successfully');
      process.exit(0);
    } else {
      log('Bricks Builder setup completed with warnings');
      log('Development environment is ready, but Bricks may need manual installation');
      process.exit(0);
    }
  } catch (err) {
    error(`Setup failed: ${err.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = {
  installBricks,
  checkBricksLicense,
  checkMemoryLimit,
  cleanupBricksFromTests
};
