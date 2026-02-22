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
  
  // Check port availability
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
  
  log('> Starting WordPress environments via wp-env...');
  
  await checkPrerequisites();

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
  
  // Load wp-env config to get custom ports
  const config = loadWpEnvConfig();
  
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
      const { join } = require('path');
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
  
  if (!skipActivation) {
    log('> Activating required plugins and themes...');
    await runCommand('node', [join('scripts', 'activate-plugins.js')]);
  } else {
    log('[skip] Skipping plugin activation');
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

  } catch (error) {
    log(`[!] Could not gather complete environment info: ${error.message}`);
  }
  
  log('[OK] Use npm run wp / npm run wp:etch for WP-CLI access');
  log('[OK] Use npm run health to check environment health');
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
