#!/usr/bin/env node

const { spawn } = require('child_process');
const { join } = require('path');
const fs = require('fs');
const { waitForWordPress } = require('./wait-for-wordpress');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function loadWpEnvConfig() {
  try {
    const configPath = join(__dirname, '../.wp-env.json');
    const overridePath = join(__dirname, '../.wp-env.override.json');
    
    let config = {};
    let override = {};
    
    if (fs.existsSync(configPath)) {
      config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    }
    
    if (fs.existsSync(overridePath)) {
      override = JSON.parse(fs.readFileSync(overridePath, 'utf8'));
    }
    
    // Merge configurations (override takes precedence)
    const merged = { ...config, ...override };
    
    return {
      port: merged.port || 8888,
      testsPort: merged.testsPort || 8889
    };
  } catch (error) {
    console.warn('Warning: Could not load wp-env configuration:', error.message);
    return {
      port: 8888,
      testsPort: 8889
    };
  }
}

function log(message) {
  console.log(`[${new Date().toISOString().slice(11, 19)}] ${message}`);
}

function showSpinner() {
  const spinner = ['-', '\\', '|', '/'];
  let i = 0;
  return setInterval(() => {
    process.stdout.write(`\r${spinner[i]} `);
    i = (i + 1) % spinner.length;
  }, 100);
}

function runCommand(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      stdio: 'inherit',
      shell: process.platform === 'win32',
      ...options
    });

    child.on('error', reject);

    child.on('exit', (code) => {
      if (code === 0) {
        resolve();
      } else {
        reject(new Error(`${command} ${args.join(' ')} exited with code ${code}`));
      }
    });
  });
}

function runCommandQuiet(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      stdio: 'pipe',
      shell: process.platform === 'win32',
      ...options
    });
    let stdout = '';
    let stderr = '';

    child.stdout?.on('data', (data) => {
      stdout += data.toString();
    });

    child.stderr?.on('data', (data) => {
      stderr += data.toString();
    });

    child.on('error', reject);

    child.on('exit', (code) => {
      resolve({ code, stdout, stderr });
    });
  });
}

async function checkComposerInContainer() {
  console.log('> Checking for Composer in wp-env container...');
  const result = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'composer', '--version']);
  return result.code === 0;
}

/**
 * Return true when the given TCP port is already bound by another process.
 * Never throws — safe to call as a probe before deciding whether to start.
 */
async function isPortInUse(port) {
  const net = require('net');
  return new Promise((resolve) => {
    const server = net.createServer();
    server.listen(port, () => {
      server.once('close', () => resolve(false));
      server.close();
    });
    server.on('error', () => resolve(true));
  });
}

/**
 * Return true when BOTH wp-env ports are already occupied.
 * When both are busy we assume wp-env is already running and skip `wp-env start`.
 * When only one is busy a third-party service is conflicting — we still abort.
 */
async function areBothWpEnvPortsInUse(port1, port2) {
  const [p1, p2] = await Promise.all([isPortInUse(port1), isPortInUse(port2)]);
  return p1 && p2;
}

async function checkPrerequisites() {
  log('> Checking prerequisites...');

  // Check Docker
  try {
    await runCommandQuiet('docker', ['ps']);
    log('[OK] Docker is running');
  } catch (error) {
    throw new Error('Docker is not running. Please start Docker Desktop and try again.');
  }

  // Check Node version
  const nodeVersion = process.version;
  const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
  if (majorVersion < 18) {
    throw new Error(`Node.js ${nodeVersion} detected. Node.js >= 18 is required.`);
  }
  log(`[OK] Node.js ${nodeVersion}`);

  // Load wp-env config to get custom ports
  const config = loadWpEnvConfig();

  // Check port availability (only called when we are about to run wp-env start).
  await checkPortAvailability(config.port);
  await checkPortAvailability(config.testsPort);
}

async function checkPortAvailability(port) {
  const net = require('net');

  return new Promise((resolve, reject) => {
    const server = net.createServer();

    server.listen(port, () => {
      server.once('close', () => {
        log(`[OK] Port ${port} is available`);
        resolve();
      });
      server.close();
    });

    server.on('error', (err) => {
      if (err.code === 'EADDRINUSE') {
        reject(new Error(`Port ${port} is already in use. Please stop the service using this port or use --ports flag to specify different ports.`));
      } else {
        reject(err);
      }
    });
  });
}

async function checkCommercialPlugins() {
  const localPluginsDir = join(__dirname, '..', 'local-plugins');
  const requiredFiles = [
    'bricks-latest.zip',
    'etch-latest.zip',
    'etch-theme-latest.zip'
  ];

  for (const file of requiredFiles) {
    const filePath = join(localPluginsDir, file);
    if (!fs.existsSync(filePath)) {
      return false;
    }
  }

  return true;
}

async function detectBricksCliContainer() {
  const result = await runCommandQuiet('docker', ['ps', '--format', '{{.Names}}']);
  if (result.code !== 0) {
    throw new Error(result.stderr || result.stdout || 'Could not list Docker containers.');
  }

  const names = result.stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (names.includes('bricks-cli')) {
    return 'bricks-cli';
  }

  const fallback = names.find((name) => /bricks.*cli/i.test(name));
  if (fallback) {
    return fallback;
  }

  throw new Error('Could not detect Bricks CLI container.');
}

async function main() {
  const args = process.argv.slice(2);
  const skipComposer = args.includes('--skip-composer');
  const skipActivation = args.includes('--skip-activation');
  const skipTests = args.includes('--skip-tests');
  const forceRestart = args.includes('--restart');

  log('> EtchFusion Suite — dev environment startup');

  // Docker must be running in all cases.
  try {
    await runCommandQuiet('docker', ['ps']);
    log('[OK] Docker is running');
  } catch (error) {
    throw new Error('Docker is not running. Please start Docker Desktop and try again.');
  }

  const config = loadWpEnvConfig();

  // Detect whether wp-env containers are already up.
  // When both dedicated ports are occupied we assume wp-env is running and skip
  // `wp-env start` — this is the common situation after a Windows reboot or a
  // Docker Desktop restart where containers come back automatically.
  // Pass --restart to force a full stop + start cycle.
  const alreadyRunning = !forceRestart && await areBothWpEnvPortsInUse(config.port, config.testsPort);

  if (alreadyRunning) {
    log(`> wp-env already running (ports ${config.port}/${config.testsPort} occupied) — skipping start`);
    log('  Tip: use --restart to force a full stop + start cycle.');
  } else {
    // -----------------------------------------------------------------------
    // Full startup path: prerequisites → commercial plugins → wp-env start →
    // wait for WP → Composer install.
    // -----------------------------------------------------------------------

    // Node version check (Docker already verified above).
    const nodeVersion = process.version;
    const majorVersion = parseInt(nodeVersion.slice(1).split('.')[0]);
    if (majorVersion < 18) {
      throw new Error(`Node.js ${nodeVersion} detected. Node.js >= 18 is required.`);
    }
    log(`[OK] Node.js ${nodeVersion}`);

    // Port availability — will throw if a non-wp-env service is using the port.
    await checkPortAvailability(config.port);
    await checkPortAvailability(config.testsPort);

    log('> Checking commercial plugins setup...');
    const pluginsSetup = await checkCommercialPlugins();
    if (!pluginsSetup) {
      log('[!] Running commercial plugins setup...');
      await runCommand('node', [join('scripts', 'setup-commercial-plugins.js')]);
    } else {
      log('[OK] Commercial plugins setup detected');
    }

    const spinner = showSpinner();
    try {
      await runCommand(WP_ENV_CMD, ['start']);
    } finally {
      clearInterval(spinner);
      process.stdout.write('\r');
    }

    log('> Verifying WordPress instances...');
    log(`... Waiting for Bricks instance (port ${config.port})...`);
    await waitForWordPress({ port: config.port, timeout: 120 });

    log(`... Waiting for Etch instance (port ${config.testsPort})...`);
    await waitForWordPress({ port: config.testsPort, timeout: 120 });

    if (!skipComposer) {
      log('> Installing Composer dependencies...');
      const hasComposer = await checkComposerInContainer();

      if (hasComposer) {
        const envs = ['cli', 'tests-cli'];
        for (const env of envs) {
          log(`[OK] Composer found in container, installing dependencies in ${env}...`);
          await runCommandWithRetry(WP_ENV_CMD, [
            'run',
            env,
            '--env-cwd=wp-content/plugins/etch-fusion-suite',
            'composer',
            'install',
            '--no-dev',
            '--optimize-autoloader'
          ]);
          const verify = await runCommandQuiet(WP_ENV_CMD, [
            'run',
            env,
            'test',
            '-f',
            'wp-content/plugins/etch-fusion-suite/vendor/autoload.php'
          ]);
          if (verify.code !== 0) {
            log(`Missing autoload.php in ${env} - Run \`npm run composer:install\` manually`);
            process.exit(1);
          }
        }
      } else {
        log('[!] Composer not found in container, attempting host installation...');
        const pluginDir = join(__dirname, '..');

        try {
          await runCommandWithRetry('composer', ['install', '--no-dev', '--optimize-autoloader'], { cwd: pluginDir });
          log('[OK] Composer dependencies installed from host (may not propagate to containers)');
        } catch (error) {
          throw new Error(
            'Composer is not available in the wp-env container or on the host.\n' +
            'Please install Composer locally or bootstrap it in the container.\n' +
            'See README for details.'
          );
        }
      }
    } else {
      log('[skip] Skipping Composer installation');
    }
  }

  // -------------------------------------------------------------------------
  // Plugin / theme activation runs EVERY time — whether we just started wp-env
  // or detected that it was already running.  This is the single reliable fix
  // for "plugins inactive after Docker/Windows restart".
  // -------------------------------------------------------------------------
  if (!skipActivation) {
    log('> Activating required plugins, themes, and licenses...');
    await runCommand('node', [join('scripts', 'activate-plugins.js')]);
  } else {
    log('[skip] Skipping plugin activation');
  }

  // -------------------------------------------------------------------------
  // WordPress test suite setup — run after plugin activation so the environment
  // is fully ready. This is idempotent and can be run multiple times safely.
  // -------------------------------------------------------------------------
  if (!skipTests) {
    log('> Setting up WordPress test suite in Docker...');
    try {
      // Run install-wp-tests.sh directly inside the wp-env CLI container so this
      // works cross-platform (no dependency on a host-side bash executable).
      await runCommand(WP_ENV_CMD, [
        'run', 'cli', 'bash',
        '/var/www/html/wp-content/plugins/etch-fusion-suite/install-wp-tests.sh',
        'wordpress_test', 'root', 'password', '127.0.0.1:3306', 'latest', 'true'
      ]);
      log('[OK] WordPress test suite ready for testing');
    } catch (error) {
      log(`[!] WordPress test suite setup failed: ${error.message}`);
      log('    You can run manually later: npm run test:setup');
    }
  } else {
    log('[skip] Skipping test suite setup');
  }

  // Display summary
  await displaySummary();
}

async function runCommandWithRetry(command, args, options = {}, retries = 1) {
  for (let i = 0; i <= retries; i++) {
    try {
      await runCommand(command, args, options);
      return;
    } catch (error) {
      if (i === retries) {
        throw error;
      }
      log(`[!] Command failed, retrying in 5 seconds... (${i + 1}/${retries})`);
      await new Promise(resolve => setTimeout(resolve, 5000));
    }
  }
}

async function displaySummary() {
  log('\n📊 Environment Summary:');
  
  // Load wp-env config to get custom ports
  const config = loadWpEnvConfig();
  
  try {
    // Get WordPress versions
    const bricksVersion = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'wp', 'core', 'version']);
    const etchVersion = await runCommandQuiet(WP_ENV_CMD, ['run', 'tests-cli', 'wp', 'core', 'version']);
    
    log(`[OK] Bricks (WordPress ${bricksVersion.stdout.trim()}): http://localhost:${config.port}/wp-admin (admin/password)`);
    log(`[OK] Etch (WordPress ${etchVersion.stdout.trim()}): http://localhost:${config.testsPort}/wp-admin (admin/password)`);
    
    // Check plugin status
    const bricksPlugins = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'wp', 'plugin', 'list', '--status=active', '--format=count']);
    const etchPlugins = await runCommandQuiet(WP_ENV_CMD, ['run', 'tests-cli', 'wp', 'plugin', 'list', '--status=active', '--format=count']);
    
    log(`Active plugins - Bricks: ${bricksPlugins.stdout.trim()}, Etch: ${etchPlugins.stdout.trim()}`);
    
    // Check database connection
    const bricksDb = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'wp', 'db', 'check']);
    const etchDb = await runCommandQuiet(WP_ENV_CMD, ['run', 'tests-cli', 'wp', 'db', 'check']);
    
    if (bricksDb.code === 0 && etchDb.code === 0) {
      log('Database connections: OK');
    } else {
      log('[!] Database connection issues detected');
    }

    // Vendor deps status per env
    const vendorCli = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'test', '-f', 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php']);
    const vendorTestsCli = await runCommandQuiet(WP_ENV_CMD, ['run', 'tests-cli', 'test', '-f', 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php']);
    log(`Vendor deps: cli - ${vendorCli.code === 0 ? 'PASS' : 'FAIL (run composer:install)'}`);
    log(`Vendor deps: tests-cli - ${vendorTestsCli.code === 0 ? 'PASS' : 'FAIL (run composer:install)'}`);

    // Test suite status
    const testSuiteCheck = await runCommandQuiet(WP_ENV_CMD, ['run', 'cli', 'test', '-d', '/wordpress-phpunit']);
    log(`Test suite: ${testSuiteCheck.code === 0 ? 'READY' : 'NOT INSTALLED (run npm run test:setup)'}`);

    log('\nAvailable commands:');
    log('   npm run test:unit         - Run 162 unit tests');
    log('   npm run test:connection   - Test API connectivity');
    log('   npm run test:migration    - End-to-end migration test');
  } catch (error) {
    log(`[!] Could not display summary: ${error.message}`);
  }
}

main().catch((error) => {
  log('\n[FAIL] Setup failed:', error.message);
  log('\nTroubleshooting:');
  log('   • Ensure Docker is running: docker ps');
  log('   • Check port availability: npm run ports:check');
  log('   • Verify wp-env installation: wp-env --version');
  log('   • Try a hard reset: npm run reset:hard');
  log('   • Check environment health: npm run health');
  process.exit(1);
});
