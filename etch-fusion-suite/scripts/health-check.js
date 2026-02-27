#!/usr/bin/env node

const { spawn } = require('child_process');
const { writeFileSync } = require('fs');
const { join } = require('path');

const REQUIRED_MEMORY_MB = 512;
const WARNING_MEMORY_MB = 256;
const VENDOR_AUTOLOAD_PATH = 'wp-content/plugins/etch-fusion-suite/vendor/autoload.php';
function spawnWpEnv(args) {
  const isWin = process.platform === 'win32';
  return spawn(
    isWin ? 'cmd' : 'npx',
    isWin ? ['/c', 'npx', 'wp-env', ...args] : ['wp-env', ...args],
    { stdio: 'pipe', cwd: join(__dirname, '..') }
  );
}

function runWpEnv(args) {
  return new Promise((resolve) => {
    let child;
    try {
      child = spawnWpEnv(args);
    } catch (error) {
      resolve({ code: 1, stdout: '', stderr: error.message });
      return;
    }
    let stdout = '';
    let stderr = '';

    child.stdout.on('data', (data) => {
      stdout += data.toString();
    });
    child.stderr.on('data', (data) => {
      stderr += data.toString();
    });

    child.on('close', (code) => {
      resolve({ code, stdout, stderr });
    });

    child.on('error', (error) => {
      resolve({ code: 1, stdout, stderr: error.message });
    });
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

function normalizeEnvironmentFilter(filter) {
  if (filter === null || filter === undefined || filter === false || filter === '') {
    return null;
  }
  return String(filter).trim();
}

async function checkWordPress(environment, name) {
  const result = await runWpEnv(['run', environment, 'wp', 'core', 'is-installed']);
  if (result.code === 0) {
    return {
      environment,
      category: 'core',
      status: 'pass',
      label: `${name} WordPress`,
      message: 'WordPress is installed and reachable.',
      details: { environment }
    };
  }

  return {
    environment,
    category: 'core',
    status: 'fail',
    label: `${name} WordPress`,
    message: 'WordPress is not reachable via wp-env.',
    details: {
      environment,
      error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
    }
  };
}

async function checkPluginStatus(environment, name, pluginSlug, options = {}) {
  const required = options.required !== false;
  const label = options.label || `${name} plugin ${pluginSlug}`;
  const result = await runWpEnv(['run', environment, 'wp', 'plugin', 'is-active', pluginSlug]);

  if (result.code === 0) {
    return {
      environment,
      category: 'plugin',
      status: 'pass',
      label,
      message: `${pluginSlug} is active.`,
      details: { environment, plugin: pluginSlug }
    };
  }

  return {
    environment,
    category: 'plugin',
    status: required ? 'fail' : 'warning',
    label,
    message: `${pluginSlug} is not active.`,
    details: {
      environment,
      plugin: pluginSlug,
      required,
      error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
    }
  };
}

async function checkMemoryLimit(environment, name) {
  const result = await runWpEnv(['run', environment, 'wp', 'eval', 'echo WP_MEMORY_LIMIT;']);
  if (result.code !== 0) {
    return {
      environment,
      category: 'memory',
      status: 'fail',
      label: `${name} memory limit`,
      message: 'Could not read WP_MEMORY_LIMIT.',
      details: {
        environment,
        recommendedMinimum: `${WARNING_MEMORY_MB}M`,
        expectedValue: `${REQUIRED_MEMORY_MB}M`,
        error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
      }
    };
  }

  const limit = (result.stdout || '').trim();
  const memoryMb = parseMemoryToMb(limit);

  if (memoryMb === null) {
    return {
      environment,
      category: 'memory',
      status: 'fail',
      label: `${name} memory limit`,
      message: `Invalid memory limit value: ${limit || 'empty'}.`,
      details: {
        environment,
        limit,
        recommendedMinimum: `${WARNING_MEMORY_MB}M`,
        expectedValue: `${REQUIRED_MEMORY_MB}M`
      }
    };
  }

  let status = 'fail';
  if (memoryMb === Infinity || memoryMb >= REQUIRED_MEMORY_MB) {
    status = 'pass';
  } else if (memoryMb >= WARNING_MEMORY_MB) {
    status = 'warning';
  }

  return {
    environment,
    category: 'memory',
    status,
    label: `${name} memory limit`,
    message: `WP_MEMORY_LIMIT is ${limit} (target: ${REQUIRED_MEMORY_MB}M, minimum: ${WARNING_MEMORY_MB}M).`,
    details: {
      environment,
      limit,
      valueMb: memoryMb,
      recommendedMinimum: `${WARNING_MEMORY_MB}M`,
      expectedValue: `${REQUIRED_MEMORY_MB}M`
    }
  };
}

async function checkThemeConfiguration(environment, name) {
  const result = await runWpEnv(['run', environment, 'wp', 'theme', 'list', '--format=json']);
  if (result.code !== 0) {
    return {
      environment,
      category: 'theme',
      status: 'fail',
      label: `${name} theme configuration`,
      message: 'Could not inspect theme configuration.',
      details: {
        environment,
        error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
      }
    };
  }

  let themes = [];
  try {
    themes = JSON.parse((result.stdout || '[]').trim() || '[]');
  } catch (error) {
    return {
      environment,
      category: 'theme',
      status: 'fail',
      label: `${name} theme configuration`,
      message: 'Theme list output is not valid JSON.',
      details: { environment, parseError: error.message }
    };
  }

  const activeTheme = themes.find((theme) => theme.status === 'active');
  const themeNames = themes.map((theme) => theme.name);
  const hasBricks = themeNames.includes('bricks');

  if (environment === 'tests-cli') {
    if (!activeTheme || activeTheme.name !== 'etch-theme') {
      return {
        environment,
        category: 'theme',
        status: 'fail',
        label: `${name} theme configuration`,
        message: `Expected active theme etch-theme, found ${activeTheme ? activeTheme.name : 'none'}.`,
        details: {
          environment,
          activeTheme: activeTheme ? activeTheme.name : null,
          installedThemes: themeNames
        }
      };
    }

    if (hasBricks) {
      return {
        environment,
        category: 'theme',
        status: 'warning',
        label: `${name} theme configuration`,
        message: 'Bricks themes are still installed on tests-cli.',
        details: {
          environment,
          activeTheme: activeTheme.name,
          installedThemes: themeNames
        }
      };
    }

    return {
      environment,
      category: 'theme',
      status: 'pass',
      label: `${name} theme configuration`,
      message: 'Active theme etch-theme on tests-cli.',
      details: {
        environment,
        activeTheme: activeTheme.name,
        installedThemes: themeNames
      }
    };
  }

  if (!activeTheme || activeTheme.name !== 'bricks') {
    return {
      environment,
      category: 'theme',
      status: 'fail',
      label: `${name} theme configuration`,
      message: `Expected active theme bricks, found ${activeTheme ? activeTheme.name : 'none'}.`,
      details: {
        environment,
        activeTheme: activeTheme ? activeTheme.name : null,
        installedThemes: themeNames
      }
    };
  }

  return {
    environment,
    category: 'theme',
    status: 'pass',
    label: `${name} theme configuration`,
    message: `Active theme: ${activeTheme.name}.`,
    details: {
      environment,
      activeTheme: activeTheme.name,
      installedThemes: themeNames
    }
  };
}

async function checkComposerDependencies(environment, name) {
  const result = await runWpEnv(['run', environment, 'test', '-f', VENDOR_AUTOLOAD_PATH]);
  if (result.code === 0) {
    return {
      environment,
      category: 'composer',
      status: 'pass',
      label: `${name} Composer dependencies`,
      message: 'vendor/autoload.php is present.',
      details: { environment, path: VENDOR_AUTOLOAD_PATH }
    };
  }

  return {
    environment,
    category: 'composer',
    status: 'fail',
    label: `${name} Composer dependencies`,
    message: 'vendor/autoload.php is missing. Run npm run composer:install:both.',
    details: {
      environment,
      path: VENDOR_AUTOLOAD_PATH,
      error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
    }
  };
}

async function checkPermalinks(environment, name) {
  // Read via wp eval — passing /%postname%/ as a CLI argument is unsafe on
  // Windows because Git bash expands leading slashes to Windows filesystem paths.
  const result = await runWpEnv([
    'run', environment, 'wp', 'eval',
    "echo get_option('permalink_structure');"
  ]);

  if (result.code !== 0) {
    return {
      environment,
      category: 'permalink',
      status: 'fail',
      label: `${name} permalink structure`,
      message: 'Could not read permalink_structure option.',
      details: {
        environment,
        error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim(),
        fix: 'Run: npm run activate'
      }
    };
  }

  const value = (result.stdout || '').trim();

  if (value === '/%postname%/') {
    return {
      environment,
      category: 'permalink',
      status: 'pass',
      label: `${name} permalink structure`,
      message: 'Permalink structure is /%postname%/ — REST API accessible at /wp-json/.',
      details: { environment, value }
    };
  }

  // Detect the Windows Git bash path-expansion artefact that corrupts the option
  // when someone runs `wp option update permalink_structure /%postname%/` in a
  // Git bash shell (the leading slash is expanded to C:/Program Files/Git/…).
  const isWindowsArtefact = value.includes('Program Files') || value.includes(':\\');

  if (!value || isWindowsArtefact) {
    const reason = !value
      ? 'Permalink structure is empty (plain URLs).'
      : `Permalink structure contains a Windows path artefact: "${value}".`;
    return {
      environment,
      category: 'permalink',
      status: 'fail',
      label: `${name} permalink structure`,
      message: `${reason} REST API /wp-json/ will return 404.`,
      details: {
        environment,
        value,
        fix: 'Run: npm run activate  (sets /%postname%/ safely via PHP eval)'
      }
    };
  }

  // Some other non-standard structure — REST API may still work, but warn.
  return {
    environment,
    category: 'permalink',
    status: 'warning',
    label: `${name} permalink structure`,
    message: `Unexpected permalink structure: "${value}". REST API should work but /%postname%/ is recommended.`,
    details: { environment, value }
  };
}

async function checkRestApi(environment, name) {
  // Use internal REST dispatch to avoid container-localhost networking issues.
  const codeScript = "$request = new WP_REST_Request('GET', '/'); $response = rest_do_request($request); if (is_wp_error($response)) { fwrite(STDERR, $response->get_error_message()); exit(1); } if (!($response instanceof WP_REST_Response)) { $response = rest_ensure_response($response); } $code = (int) $response->get_status(); echo $code; if ($code < 200 || $code >= 400) { exit(2); }";
  const result = await runWpEnv(['run', environment, 'wp', 'eval', codeScript]);

  if (result.code === 0) {
    const code = Number.parseInt((result.stdout || '').trim(), 10);
    return {
      environment,
      category: 'rest',
      status: 'pass',
      label: `${name} REST API`,
      message: `REST API responded with status ${Number.isFinite(code) ? code : '2xx'}.`,
      details: {
        environment,
        statusCode: Number.isFinite(code) ? code : null
      }
    };
  }

  return {
    environment,
    category: 'rest',
    status: 'fail',
    label: `${name} REST API`,
    message: 'REST API is not responding with a successful status code.',
    details: {
      environment,
      error: (result.stderr || result.stdout || `Exit code ${result.code}`).trim()
    }
  };
}

function parseArgs(argv) {
  const args = argv.slice(2);
  const envIndex = args.indexOf('--environment');
  const environment = envIndex >= 0 ? args[envIndex + 1] : null;
  const saveReport = args.includes('--save-report');
  return { environment, saveReport };
}

function getEnvironments(filter) {
  const map = {
    development: [{ environment: 'cli', name: 'Bricks' }],
    cli: [{ environment: 'cli', name: 'Bricks' }],
    tests: [{ environment: 'tests-cli', name: 'Etch' }],
    'tests-cli': [{ environment: 'tests-cli', name: 'Etch' }]
  };

  if (filter && map[filter]) {
    return map[filter];
  }

  return [
    { environment: 'cli', name: 'Bricks' },
    { environment: 'tests-cli', name: 'Etch' }
  ];
}

function sortChecks(checks) {
  const order = {
    core: 1,
    permalink: 2,
    memory: 3,
    plugin: 4,
    theme: 5,
    composer: 6,
    rest: 7
  };

  return checks.slice().sort((a, b) => {
    const aOrder = order[a.category] || 99;
    const bOrder = order[b.category] || 99;
    return aOrder - bOrder;
  });
}

function printResults(checks, envs, summary) {
  const statusIcon = {
    pass: 'OK',
    warning: 'WARN',
    fail: 'FAIL'
  };

  console.log('\nHealth Check Report\n');

  for (const env of envs) {
    console.log(`${env.name} (${env.environment})`);
    const envChecks = sortChecks(checks.filter((check) => check.environment === env.environment));
    for (const check of envChecks) {
      console.log(`  ${statusIcon[check.status] || 'INFO'} ${check.label}: ${check.message}`);
    }
    console.log('');
  }

  console.log('Summary');
  console.log(`  Pass: ${summary.pass}`);
  console.log(`  Warnings: ${summary.warning}`);
  console.log(`  Failures: ${summary.fail}`);
}

function saveReport(checks, envs, summary) {
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const reportPath = join(__dirname, '..', `health-report-${timestamp}.json`);
  const payload = {
    generatedAt: new Date().toISOString(),
    environments: envs,
    summary,
    checks
  };

  writeFileSync(reportPath, JSON.stringify(payload, null, 2), 'utf8');
  console.log(`\nSaved health report: ${reportPath}`);
}

async function runHealthCheck(environmentFilter = null) {
  const normalizedFilter = normalizeEnvironmentFilter(environmentFilter);
  const envs = getEnvironments(normalizedFilter);
  const checkPromises = [];

  for (const env of envs) {
    checkPromises.push(checkWordPress(env.environment, env.name));
    checkPromises.push(checkPermalinks(env.environment, env.name));
    checkPromises.push(checkMemoryLimit(env.environment, env.name));
    checkPromises.push(checkPluginStatus(env.environment, env.name, 'etch-fusion-suite', {
      label: `${env.name} plugin etch-fusion-suite`
    }));
    checkPromises.push(checkThemeConfiguration(env.environment, env.name));
    checkPromises.push(checkComposerDependencies(env.environment, env.name));
    checkPromises.push(checkRestApi(env.environment, env.name));

    if (env.environment === 'tests-cli') {
      checkPromises.push(checkPluginStatus(env.environment, env.name, 'etch', {
        label: `${env.name} plugin etch`
      }));
    }

    }
  }

  const checks = await Promise.all(checkPromises);

  const summary = {
    pass: checks.filter((check) => check.status === 'pass').length,
    warning: checks.filter((check) => check.status === 'warning').length,
    fail: checks.filter((check) => check.status === 'fail').length,
    total: checks.length,
    // Compatibility keys used by Playwright setup.
    passed: checks.filter((check) => check.status === 'pass').length,
    warnings: checks.filter((check) => check.status === 'warning').length,
    failed: checks.filter((check) => check.status === 'fail').length
  };

  return { checks, envs, summary };
}

async function main() {
  const { environment, saveReport: saveReportEnabled } = parseArgs(process.argv);
  const { checks, envs, summary } = await runHealthCheck(environment);

  printResults(checks, envs, summary);

  if (saveReportEnabled) {
    saveReport(checks, envs, summary);
  }

  process.exit(summary.fail > 0 ? 1 : 0);
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Health check failed:', error.message);
    process.exit(1);
  });
}

module.exports = {
  checkMemoryLimit,
  checkPermalinks,
  checkThemeConfiguration,
  checkRestApi,
  runHealthCheck
};
