#!/usr/bin/env node

const { spawn } = require('child_process');
const { existsSync, readFileSync } = require('fs');
const { join } = require('path');

const LOCAL_PLUGINS_DIR = join(__dirname, '..', 'local-plugins');
const CONTAINER_LOCAL_PLUGINS_DIR = '/var/www/html/wp-content/plugins/etch-fusion-suite/local-plugins';
const LOCAL_BACKUPS_DIR = join(__dirname, '..', 'local-backups');
const CONTAINER_LOCAL_BACKUPS_DIR = '/var/www/html/wp-content/plugins/etch-fusion-suite/local-backups';
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
    const child = spawnWpEnv(args);
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

const MEMORY_RETRY_LIMIT = '512M';

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

async function checkMemoryLimit(environment) {
  const result = await runWpEnv(['run', environment, 'wp', 'eval', 'echo WP_MEMORY_LIMIT;']);
  if (result.code !== 0) {
    return null;
  }
  const value = (result.stdout || '').trim();
  return value || null;
}

async function displayMemoryStatus(environment, name) {
  const memoryLimit = await checkMemoryLimit(environment);
  if (!memoryLimit) {
    console.warn(`WARNING ${name} (${environment}) memory check failed`);
    return null;
  }

  const memoryMb = parseMemoryToMb(memoryLimit);
  const ok = memoryMb === Infinity || (typeof memoryMb === 'number' && memoryMb >= 256);
  const icon = ok ? 'OK' : 'WARNING';
  console.log(`${icon} ${name} (${environment}) WP_MEMORY_LIMIT=${memoryLimit}`);
  if (!ok) {
    console.warn(`WARNING ${name} memory is below recommended minimum (256M)`);
  }
  return memoryLimit;
}

function detectMemoryError(message) {
  const lower = String(message || '').toLowerCase();
  return (
    lower.includes('memory') && (
      lower.includes('exhausted') ||
      lower.includes('allowed memory size') ||
      lower.includes('out of memory')
    )
  );
}

function extractRunEnvironment(args) {
  if (!Array.isArray(args) || args.length < 2) return null;
  if (args[0] !== 'run') return null;
  return args[1];
}

async function increaseWpMemoryLimits(environment, limit = MEMORY_RETRY_LIMIT) {
  const constants = ['WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT'];
  const failures = [];

  for (const constantName of constants) {
    const result = await runWpEnv(['run', environment, 'wp', 'config', 'set', constantName, limit, '--type=constant']);
    if (result.code !== 0) {
      const details = (result.stderr || result.stdout || `Exit code ${result.code}`).trim();
      failures.push(`${constantName}: ${details}`);
    }
  }

  if (failures.length > 0) {
    return { success: false, details: failures.join(' | ') };
  }

  return { success: true, limit };
}

function runTask(label, args, retries = 1) {
  return new Promise((resolve) => {
    const attempt = async (attemptCount = 0, memoryIncreaseAttempted = false, memoryIncreaseApplied = false) => {
      const child = spawnWpEnv(args);

      let output = '';
      let errorOutput = '';

      child.stdout.on('data', (data) => {
        output += data.toString();
      });

      child.stderr.on('data', (data) => {
        errorOutput += data.toString();
      });

      child.on('close', async (code) => {
        if (code === 0) {
          console.log(`OK ${label}`);
          resolve({ success: true, output, memoryIncreaseApplied });
          return;
        }

        const message = errorOutput.trim() || output.trim() || `Exit code ${code}`;
        const lower = message.toLowerCase();
        const isNotFound = lower.includes('not found') || lower.includes('does not exist');
        const isMemoryError = detectMemoryError(message);

        if (isMemoryError) {
          const env = extractRunEnvironment(args);
          if (!memoryIncreaseAttempted && env) {
            console.warn(`WARNING ${label} - PHP memory exhausted. Attempting memory increase to ${MEMORY_RETRY_LIMIT}...`);
            const increase = await increaseWpMemoryLimits(env, MEMORY_RETRY_LIMIT);
            if (increase.success) {
              console.warn(`WARNING ${label} - Retrying once after setting WP_MEMORY_LIMIT/WP_MAX_MEMORY_LIMIT=${MEMORY_RETRY_LIMIT}`);
              return attempt(attemptCount, true, true);
            }
            console.warn(`WARNING ${label} - Failed to raise memory limits on ${env}: ${increase.details}`);
            resolve({
              success: false,
              output: message,
              isMemoryError: true,
              memoryIncreaseAttempted: true,
              memoryIncreaseApplied: false
            });
            return;
          }

          if (memoryIncreaseAttempted) {
            console.error(`ERROR ${label} - PHP memory exhausted even after raising WP memory limits to ${MEMORY_RETRY_LIMIT}`);
          } else {
            console.warn(`WARNING ${label} - PHP memory exhausted`);
          }
          console.warn('  Verify memory settings in .wp-env.json and run: npm run health');
          resolve({
            success: false,
            output: message,
            isMemoryError: true,
            memoryIncreaseAttempted,
            memoryIncreaseApplied
          });
          return;
        }

        if (attemptCount < retries && !isNotFound) {
          console.warn(`WARNING ${label} failed, retrying in 2 seconds... (${attemptCount + 1}/${retries})`);
          await new Promise((resolveDelay) => setTimeout(resolveDelay, 2000));
          return attempt(attemptCount + 1, memoryIncreaseAttempted, memoryIncreaseApplied);
        }

        const memoryContext = memoryIncreaseApplied
          ? ` after attempting WP_MEMORY_LIMIT/WP_MAX_MEMORY_LIMIT=${MEMORY_RETRY_LIMIT}`
          : '';
        if (isNotFound) {
          console.warn(`WARNING ${label} - Plugin not found`);
        } else {
          console.warn(`WARNING ${label}${memoryContext} - ${message}`);
        }

        resolve({
          success: false,
          output: message,
          isNotFound,
          isMemoryError: false,
          memoryIncreaseAttempted,
          memoryIncreaseApplied
        });
      });

      child.on('error', async (error) => {
        if (attemptCount < retries) {
          console.warn(`WARNING ${label} failed, retrying in 2 seconds... (${attemptCount + 1}/${retries})`);
          await new Promise((resolveDelay) => setTimeout(resolveDelay, 2000));
          return attempt(attemptCount + 1, memoryIncreaseAttempted, memoryIncreaseApplied);
        }
        console.error(`ERROR ${label} - ${error.message}`);
        resolve({
          success: false,
          output: error.message,
          isMemoryError: false,
          memoryIncreaseAttempted,
          memoryIncreaseApplied
        });
      });
    };

    attempt();
  });
}

function parseJsonArray(raw, fallback = []) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return fallback;
  try {
    const parsed = JSON.parse(trimmed);
    return Array.isArray(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
}

function slugMatches(actual, expected) {
  const a = String(actual || '').trim().toLowerCase();
  const e = String(expected || '').trim().toLowerCase();
  if (!a || !e) return false;
  if (a === e) return true;

  // Keep fuzzy fallback for long identifiers only; avoid false matches like "etch" vs "etch-fusion-suite".
  if (e.length >= 5 && (a.includes(e) || e.includes(a))) return true;
  return false;
}

async function getInstalledPlugins(environment) {
  const result = await runWpEnv(['run', environment, 'wp', 'plugin', 'list', '--field=name', '--format=json']);
  if (result.code !== 0) {
    console.warn(`WARNING Failed to list plugins for ${environment}`);
  }
  return parseJsonArray(result.stdout, []);
}

function findPluginSlug(installedPlugins, expectedNames) {
  for (const name of expectedNames) {
    const found = installedPlugins.find((plugin) => slugMatches(plugin, name));
    if (found) return found;
  }
  return null;
}

async function getInstalledThemes(environment) {
  const result = await runWpEnv(['run', environment, 'wp', 'theme', 'list', '--field=name', '--format=json']);
  if (result.code !== 0) {
    console.warn(`WARNING Failed to list themes for ${environment}`);
  }
  return parseJsonArray(result.stdout, []);
}

function findThemeSlug(installedThemes, expectedNames) {
  for (const name of expectedNames) {
    const found = installedThemes.find((theme) => slugMatches(theme, name));
    if (found) return found;
  }
  return null;
}

async function ensurePluginFromZip(environment, zipName, expectedNames) {
  let installedPlugins = await getInstalledPlugins(environment);
  let slug = findPluginSlug(installedPlugins, expectedNames);
  if (slug) {
    return slug;
  }

  const hostZip = join(LOCAL_PLUGINS_DIR, zipName);
  if (!existsSync(hostZip)) {
    return null;
  }

  const containerZip = `${CONTAINER_LOCAL_PLUGINS_DIR}/${zipName}`;
  await runTask(`Install ${zipName} on ${environment}`, ['run', environment, 'wp', 'plugin', 'install', containerZip, '--force'], 0);
  installedPlugins = await getInstalledPlugins(environment);
  slug = findPluginSlug(installedPlugins, expectedNames);
  return slug;
}

async function ensureThemeFromZip(environment, zipName, expectedNames) {
  let installedThemes = await getInstalledThemes(environment);
  let slug = findThemeSlug(installedThemes, expectedNames);
  if (slug) {
    return slug;
  }

  const hostZip = join(LOCAL_PLUGINS_DIR, zipName);
  if (!existsSync(hostZip)) {
    return null;
  }

  const containerZip = `${CONTAINER_LOCAL_PLUGINS_DIR}/${zipName}`;
  await runTask(`Install ${zipName} on ${environment}`, ['run', environment, 'wp', 'theme', 'install', containerZip, '--force'], 0);
  installedThemes = await getInstalledThemes(environment);
  slug = findThemeSlug(installedThemes, expectedNames);
  return slug;
}

async function verifyActivePlugins(environment, expectedSlugs) {
  try {
    const result = await runWpEnv(['run', environment, 'wp', 'plugin', 'list', '--status=active', '--field=name', '--format=json']);
    const activePlugins = parseJsonArray(result.stdout, []);
    return expectedSlugs.filter((slug) => !activePlugins.includes(slug));
  } catch (error) {
    return expectedSlugs;
  }
}

async function removeDefaultPlugins(environment) {
  const installed = await getInstalledPlugins(environment);
  const defaults = ['akismet', 'hello', 'hello-dolly'];
  const toDelete = defaults.filter((slug) => installed.includes(slug));

  for (const slug of toDelete) {
    await runTask(`Deactivate default plugin ${slug} on ${environment}`, ['run', environment, 'wp', 'plugin', 'deactivate', slug], 0);
    await runTask(`Remove default plugin ${slug} on ${environment}`, ['run', environment, 'wp', 'plugin', 'delete', slug], 0);
  }
}

async function removeUnneededThemes(environment, keepThemes = []) {
  const listResult = await runWpEnv(['run', environment, 'wp', 'theme', 'list', '--field=name', '--format=json']);
  const installedThemes = parseJsonArray(listResult.stdout, []);

  const keep = new Set(keepThemes.filter(Boolean));
  const toDelete = installedThemes.filter((theme) => !keep.has(theme));

  for (const slug of toDelete) {
    await runTask(`Remove unused theme ${slug} on ${environment}`, ['run', environment, 'wp', 'theme', 'delete', slug], 0);
  }
}

async function removeDemoContentIfFresh(environment) {
  const freshResult = await runWpEnv(['run', environment, 'wp', 'option', 'get', 'fresh_site']);
  if (freshResult.code !== 0) {
    return;
  }

  const isFresh = (freshResult.stdout || '').trim() === '1';
  if (!isFresh) {
    return;
  }

  const postsResult = await runWpEnv([
    'run',
    environment,
    'wp',
    'post',
    'list',
    '--post_type=post,page',
    '--fields=ID,post_name,post_title',
    '--format=json'
  ]);
  const posts = parseJsonArray(postsResult.stdout, []);
  const defaultSlugs = new Set(['hello-world', 'sample-page', 'privacy-policy']);
  const idsToDelete = posts
    .filter((post) => defaultSlugs.has(post.post_name))
    .map((post) => post.ID)
    .filter(Boolean);

  if (idsToDelete.length > 0) {
    await runTask(
      `Remove demo posts/pages on ${environment}`,
      ['run', environment, 'wp', 'post', 'delete', ...idsToDelete.map(String), '--force'],
      0
    );
  }

  const commentsResult = await runWpEnv(['run', environment, 'wp', 'comment', 'list', '--field=comment_ID', '--format=json']);
  const commentIds = parseJsonArray(commentsResult.stdout, []);
  if (commentIds.length > 0) {
    await runTask(
      `Remove demo comments on ${environment}`,
      ['run', environment, 'wp', 'comment', 'delete', ...commentIds.map(String), '--force'],
      0
    );
  }

  await runTask(`Mark fresh_site=0 on ${environment}`, ['run', environment, 'wp', 'option', 'update', 'fresh_site', '0'], 0);
}

/**
 * Ensure WordPress uses pretty permalinks (/%postname%/) so the REST API is
 * accessible at /wp-json/.
 *
 * wp-env fresh installs use the plain ?p=123 structure by default. On Windows
 * with Git bash, passing /%postname%/ as a WP-CLI argument is impossible:
 * bash silently expands the leading slash to "C:/Program Files/Git/%postname%/".
 * We work around this by writing the option via `wp eval` and PHP's
 * update_option(), which bypasses shell interpretation entirely.
 *
 * @param {string} environment  wp-env environment name ('cli' or 'tests-cli').
 */
async function ensurePermalinks(environment) {
  // Read the current value via eval to avoid any shell quoting issues.
  const check = await runWpEnv([
    'run', environment, 'wp', 'eval',
    "echo get_option('permalink_structure');"
  ]);
  const current = (check.stdout || '').trim();

  if (current === '/%postname%/') {
    // Structure is correct; still flush so .htaccess stays in sync after
    // container restarts that may have wiped the rewrite cache.
    const flush = await runWpEnv(['run', environment, 'wp', 'rewrite', 'flush', '--hard']);
    const warnEmpty = (flush.stderr || '').toLowerCase().includes('empty');
    if (!warnEmpty) {
      console.log(`OK Permalinks /%postname%/ confirmed on ${environment}`);
    } else {
      console.warn(`WARNING Permalink flush reported empty rules on ${environment}`);
    }
    return;
  }

  // Set to /%postname%/ using wp eval — safe against Windows Git bash expansion
  // of leading slashes to Windows filesystem paths.
  const setResult = await runWpEnv([
    'run', environment, 'wp', 'eval',
    "update_option('permalink_structure', '/%postname%/'); echo get_option('permalink_structure');"
  ]);

  const written = (setResult.stdout || '').trim();
  if (setResult.code !== 0 || !written.includes('%postname%')) {
    console.warn(`WARNING Failed to set permalink_structure on ${environment} (got: "${written}")`);
    return;
  }

  // Flush to regenerate .htaccess rewrite rules for the new structure.
  const flushResult = await runWpEnv(['run', environment, 'wp', 'rewrite', 'flush', '--hard']);
  if (flushResult.code === 0) {
    console.log(`OK Permalinks set to /%postname%/ on ${environment}`);
  } else {
    console.warn(`WARNING Permalink structure set on ${environment} but rewrite flush failed`);
  }
}

async function ensureTestsThemeConfiguration() {
  const listResult = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'list', '--format=json']);
  if (listResult.code !== 0) {
    console.warn('WARNING Could not verify tests-cli theme configuration');
    return { etchThemeActive: false, etchThemeInstalled: false };
  }

  let themes = [];
  try {
    themes = JSON.parse((listResult.stdout || '[]').trim() || '[]');
  } catch (error) {
    console.warn(`WARNING Could not parse tests-cli themes: ${error.message}`);
    return { etchThemeActive: false, etchThemeInstalled: false };
  }

  const etchTheme = themes.find((theme) => theme.name === 'etch-theme');
  const etchThemeInstalled = Boolean(etchTheme);
  const etchThemeActive = Boolean(etchTheme && etchTheme.status === 'active');

  if (!etchThemeInstalled) {
    console.warn('WARNING Etch theme is not installed on tests-cli');
    return { etchThemeActive: false, etchThemeInstalled: false };
  }

  if (!etchThemeActive) {
    const activateEtchTheme = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'activate', 'etch-theme']);
    if (activateEtchTheme.code !== 0) {
      console.warn('WARNING Failed to activate etch-theme on tests-cli');
      return { etchThemeActive: false, etchThemeInstalled: true };
    }
    console.log('OK tests-cli theme set to etch-theme');
    return { etchThemeActive: true, etchThemeInstalled: true };
  }

  return { etchThemeActive: true, etchThemeInstalled: true };
}

async function main() {
  const args = process.argv.slice(2);
  const force = args.includes('--force');
  const dryRun = args.includes('--dry-run');
  const verbose = args.includes('--verbose');
  const skipVendorCheck = args.includes('--skip-vendor-check');

  if (dryRun) {
    console.log('DRY RUN MODE - No changes will be made\n');
  }

  const vendorPath = join(__dirname, '..', 'vendor', 'autoload.php');
  const hasVendor = existsSync(vendorPath) || skipVendorCheck;

  if (!hasVendor && !skipVendorCheck) {
    console.warn('WARNING vendor/autoload.php not found. Skipping migration plugin activation.');
    console.warn('  Run Composer install first to activate the migration plugin.');
    console.warn('  Use --skip-vendor-check to attempt activation anyway.');
  } else if (skipVendorCheck) {
    console.log('WARNING Skipping vendor check per --skip-vendor-check flag');
  }

  console.log('\nMemory Configuration');
  await displayMemoryStatus('cli', 'Bricks');
  await displayMemoryStatus('tests-cli', 'Etch');

  console.log('\nPermalink configuration...');
  await ensurePermalinks('cli');
  await ensurePermalinks('tests-cli');

  console.log('\nDiscovering installed plugins and themes...');
  let [devPlugins, testPlugins, devThemes, testThemes] = await Promise.all([
    getInstalledPlugins('cli'),
    getInstalledPlugins('tests-cli'),
    getInstalledThemes('cli'),
    getInstalledThemes('tests-cli')
  ]);

  if (verbose) {
    console.log(`Found ${devPlugins.length} dev plugins, ${testPlugins.length} test plugins`);
    console.log(`Found ${devThemes.length} dev themes, ${testThemes.length} test themes`);
  }

  // Ensure required commercial packages are installed from local ZIP archives.
  await ensurePluginFromZip('cli', 'frames-latest.zip', ['frames']);
  await ensurePluginFromZip('cli', 'acss-v3-latest.zip', ['automaticcss', 'automatic-css', 'automatic.css', 'automattic-css']);
  await ensureThemeFromZip('cli', 'bricks-latest.zip', ['bricks']);

  await ensurePluginFromZip('tests-cli', 'etch-latest.zip', ['etch']);
  await ensurePluginFromZip('tests-cli', 'acss-v4-latest.zip', ['automaticcss', 'automatic-css', 'automatic.css', 'automattic-css']);
  await ensureThemeFromZip('tests-cli', 'etch-theme-latest.zip', ['etch-theme']);

  [devPlugins, testPlugins, devThemes, testThemes] = await Promise.all([
    getInstalledPlugins('cli'),
    getInstalledPlugins('tests-cli'),
    getInstalledThemes('cli'),
    getInstalledThemes('tests-cli')
  ]);

  const tasks = [];
  const expectedActivations = { cli: [], 'tests-cli': [] };

  const bricksThemeSlug = findThemeSlug(devThemes, ['bricks']);
  const preferredDevTheme = bricksThemeSlug;

  if (preferredDevTheme) {
    tasks.push({
      label: `Activate ${preferredDevTheme} theme on development`,
      args: ['run', 'cli', 'wp', 'theme', 'activate', preferredDevTheme],
      env: 'cli',
      slug: preferredDevTheme
    });
  } else {
    console.warn('WARNING Bricks theme not found in development environment');
  }

  const framesSlug = findPluginSlug(devPlugins, ['frames']);
  if (framesSlug) {
    tasks.push({ label: 'Activate Frames on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', framesSlug], env: 'cli', slug: framesSlug });
    expectedActivations.cli.push(framesSlug);
  } else {
    console.warn('WARNING Frames plugin not found in development environment');
  }

  const acssDevSlug = findPluginSlug(devPlugins, ['automaticcss', 'automatic-css', 'automatic.css', 'automattic-css']);
  if (acssDevSlug) {
    tasks.push({ label: 'Activate Automatic.css on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', acssDevSlug], env: 'cli', slug: acssDevSlug });
    expectedActivations.cli.push(acssDevSlug);
  } else {
    console.warn('WARNING Automatic.css plugin not found in development environment');
  }

  const etchSlug = findPluginSlug(testPlugins, ['etch']);
  if (etchSlug) {
    tasks.push({ label: 'Activate Etch on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', etchSlug], env: 'tests-cli', slug: etchSlug });
    expectedActivations['tests-cli'].push(etchSlug);
  } else {
    console.warn('WARNING Etch plugin not found in test environment');
  }

  const acssTestSlug = findPluginSlug(testPlugins, ['automaticcss', 'automatic-css', 'automatic.css', 'automattic-css']);
  if (acssTestSlug) {
    tasks.push({ label: 'Activate Automatic.css on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', acssTestSlug], env: 'tests-cli', slug: acssTestSlug });
    expectedActivations['tests-cli'].push(acssTestSlug);
  } else {
    console.warn('WARNING Automatic.css plugin not found in test environment');
  }

  const etchThemeSlug = findThemeSlug(testThemes, ['etch-theme']);
  if (etchThemeSlug) {
    tasks.push({ label: 'Activate Etch Theme on tests', args: ['run', 'tests-cli', 'wp', 'theme', 'activate', etchThemeSlug], env: 'tests-cli', slug: etchThemeSlug });
  } else {
    console.warn('WARNING Etch theme not found in test environment');
  }

  if (hasVendor) {
    const migrationSlug = 'etch-fusion-suite';
    const migrationDevSlug = findPluginSlug(devPlugins, [migrationSlug]);
    const migrationTestSlug = findPluginSlug(testPlugins, [migrationSlug]) || migrationSlug;

    if (migrationDevSlug) {
      tasks.push({ label: 'Activate migration plugin on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', migrationDevSlug], env: 'cli', slug: migrationDevSlug });
      expectedActivations.cli.push(migrationDevSlug);
    }

    tasks.push({ label: 'Activate migration plugin on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', migrationTestSlug], env: 'tests-cli', slug: migrationTestSlug });
    if (!expectedActivations['tests-cli'].includes(migrationTestSlug)) {
      expectedActivations['tests-cli'].push(migrationTestSlug);
    }
  }

  if (force && !dryRun) {
    console.log('\nForce mode: Deactivating plugins first...');
    for (const env of ['cli', 'tests-cli']) {
      for (const slug of expectedActivations[env]) {
        try {
          await runTask(`Deactivate ${slug} on ${env}`, ['run', env, 'wp', 'plugin', 'deactivate', slug], 0);
        } catch (error) {
          // Ignore deactivation errors
        }
      }
    }
  }

  console.log(`\nActivating ${tasks.length} plugins and themes...\n`);

  const results = [];
  for (const task of tasks) {
    if (dryRun) {
      console.log(`Would activate: ${task.label}`);
      results.push({ ...task, success: true, output: 'DRY RUN' });
    } else {
      const result = await runTask(task.label, task.args, 1);
      results.push({ ...task, ...result });

      if (verbose && result.output) {
        const snippet = result.output.substring(0, 100);
        console.log(`  Output: ${snippet}${result.output.length > 100 ? '...' : ''}`);
      }
    }
  }

  const successful = results.filter((r) => r.success).length;
  const failed = results.filter((r) => !r.success).length;
  const notFound = results.filter((r) => r.isNotFound).length;
  const memoryErrors = results.filter((r) => r.isMemoryError).length;

  console.log('\nActivation Summary:');
  console.log(`  Successful: ${successful}`);
  console.log(`  Failed: ${failed}`);
  if (notFound > 0) {
    console.log(`  Not found: ${notFound}`);
  }
  if (memoryErrors > 0) {
    console.log(`  Memory errors: ${memoryErrors}`);
    console.log('  Run `npm run health` to verify memory configuration.');
  }

  if (!dryRun && successful > 0) {
    console.log('\nVerifying activation...');

    for (const env of ['cli', 'tests-cli']) {
      const envName = env === 'cli' ? 'development' : 'tests';
      const missing = await verifyActivePlugins(env, expectedActivations[env]);

      if (missing.length === 0) {
        console.log(`  OK All expected plugins active on ${envName}`);
      } else {
        console.log(`  WARNING Missing plugins on ${envName}: ${missing.join(', ')}`);
      }
    }

    console.log('\nCleaning defaults (themes/plugins/demo content)...');
    await removeDefaultPlugins('cli');
    await removeDefaultPlugins('tests-cli');
    await removeUnneededThemes('cli', ['bricks']);
    await removeUnneededThemes('tests-cli', ['etch-theme']);
    await removeDemoContentIfFresh('cli');
    await removeDemoContentIfFresh('tests-cli');
  }

  if (!dryRun) {
    await activateLicenses();
    await importBricksAssets();
  }
}

// =============================================================================
// License key activation
// =============================================================================

/**
 * Parse a .env file into a plain key→value object.
 * Only handles KEY=value lines; ignores comments and blank lines.
 *
 * @param {string} content Raw file content.
 * @returns {Record<string,string>}
 */
function parseEnvFile(content) {
  const vars = {};
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eqIdx = trimmed.indexOf('=');
    if (eqIdx < 1) continue;
    const key = trimmed.slice(0, eqIdx).trim();
    const value = trimmed.slice(eqIdx + 1).trim();
    if (key && value) {
      vars[key] = value;
    }
  }
  return vars;
}

/**
 * Read license keys from .env and write them to the WordPress options table via
 * WP-CLI so that each plugin is immediately licensed without manual admin-panel
 * interaction.
 *
 * License WP option names — confirmed by reading the plugin/theme source:
 *   Bricks (cli):      bricks_license_key          (simple string)
 *   ACSS (both):       automatic_css_license_key   (simple string, same v3 + v4)
 *   Frames (cli):      frames_license_key          (simple string)
 *   Etch plugin:       etch_license_key            (simple string, tests-cli)
 *   Etch Theme:        "Etch Theme_license_options" → { license_key: "…" }
 *                      (SureCart nested array, tests-cli)
 *
 * Keys are read from .env (gitignored). The .env.example shows the expected
 * variable names with empty values.
 */
async function activateLicenses() {
  const envPath = join(__dirname, '..', '.env');
  if (!existsSync(envPath)) {
    // No .env file — silently skip.
    return;
  }

  let envVars;
  try {
    envVars = parseEnvFile(readFileSync(envPath, 'utf8'));
  } catch (err) {
    console.warn(`WARNING License activation: could not read .env (${err.message})`);
    return;
  }

  console.log('\nLicense key activation...');

  // Simple string options: wp option update <option> <value>.
  // Only plugins that store the key directly as a plain option and do not
  // require a SureCart activation API round-trip belong here.
  const simpleTargets = [
    {
      envKey: 'BRICKS_LICENSE_KEY',
      label: 'Bricks',
      targets: [{ env: 'cli', option: 'bricks_license_key' }]
    },
    {
      envKey: 'ACSS_LICENSE_KEY',
      label: 'Automatic.css',
      targets: [
        { env: 'cli',       option: 'automatic_css_license_key' },
        { env: 'tests-cli', option: 'automatic_css_license_key' }
      ]
    },
    {
      envKey: 'FRAMES_LICENSE_KEY',
      label: 'Frames',
      targets: [{ env: 'cli', option: 'frames_license_key' }]
    }
  ];

  let anyFound = false;

  for (const { envKey, label, targets } of simpleTargets) {
    const key = envVars[envKey];
    if (!key) continue;
    anyFound = true;
    for (const { env, option } of targets) {
      await runTask(
        `Set ${label} license key on ${env}`,
        ['run', env, 'wp', 'option', 'update', option, key],
        0
      );
    }
  }

  // Etch plugin — uses SureCart licensing. Storing just `etch_license_key` is
  // not enough: the plugin also needs `etch_license_status=valid` and
  // `etch_license_options` containing sc_license_id + sc_activation_id, which
  // are returned by the SureCart API on first activation. We call the plugin's
  // own activate_license() method so it performs the API call and writes all
  // three options itself.
  const etchPluginKey = envVars['ETCH_LICENSE_KEY'];
  if (etchPluginKey) {
    anyFound = true;
    const safeKey = etchPluginKey.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const phpSnippet = [
      '$license = \\Etch\\WpAdmin\\License::get_instance();',
      '$license->init();',
      '$result = $license->activate_license( "' + safeKey + '" );',
      'if ( is_wp_error( $result ) ) { echo "ERROR: " . $result->get_error_message(); exit( 1 ); } else { echo "OK"; }'
    ].join(' ');
    await runTask(
      'Activate Etch plugin license on tests-cli',
      ['run', 'tests-cli', 'wp', 'eval', phpSnippet],
      0
    );
  }

  // Etch Theme — also uses SureCart. The correct option key is
  // `etchtheme_license_options` (generated from the client name "Etch Theme")
  // and the stored fields are sc_license_key, sc_license_id, sc_activation_id.
  // We instantiate the theme's SureCart Client directly (classes already loaded
  // because the theme is active) and call activate() so the API round-trip
  // happens and all fields are written correctly.
  const etchThemeKey = envVars['ETCH_THEME_LICENSE_KEY'];
  if (etchThemeKey) {
    anyFound = true;
    const safeKey = etchThemeKey.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const phpSnippet = [
      '$client = new \\Etch_Theme\\SureCart\\Licensing\\Client( "Etch Theme", "pt_7eCsZFuK2NuCXK97jzkennFi", get_stylesheet_directory() . "/functions.php" );',
      '$result = $client->license()->activate( "' + safeKey + '" );',
      'if ( is_wp_error( $result ) ) { echo "ERROR: " . $result->get_error_message(); exit( 1 ); } else { echo "OK"; }'
    ].join(' ');
    await runTask(
      'Activate Etch Theme license on tests-cli',
      ['run', 'tests-cli', 'wp', 'eval', phpSnippet],
      0
    );
  }

  if (!anyFound) {
    console.log('  No license keys found in .env — skipping');
  }
}

// =============================================================================
// Bricks asset import
// =============================================================================

/**
 * Import Bricks settings, theme styles, SQL data, and media into the Bricks
 * (cli) environment.
 *
 * Files are read inside the container via PHP's file_get_contents so no
 * shell-escaping of JSON content is needed.
 *
 * Expected layout (all optional — silently skipped if absent):
 *   local-backups/bricks_import.sql          → full DB import (WPVivid export)
 *   local-backups/uploads/uploads/           → media files → wp-content/uploads/
 *   local-plugins/_assets_bricks-settings-acss.json  → bricks_global_settings
 *   local-plugins/_assets_bricks-theme-style-acss.json → bricks_theme_styles
 *
 * The SQL import runs first; URL search-replace follows immediately so the DB
 * reflects the local dev URL before settings and media are applied.
 * Uploads are copied last so they don't get wiped by the SQL import.
 */
async function importBricksAssets() {
  console.log('\nBricks asset import...');

  // SQL import — runs first so subsequent option writes are not overwritten.
  // Looks for bricks_import.sql in local-backups/, falling back to
  // bricks-data.sql in local-plugins/ for backwards compatibility.
  const sqlCandidates = [
    { host: join(LOCAL_BACKUPS_DIR, 'bricks_import.sql'),  container: `${CONTAINER_LOCAL_BACKUPS_DIR}/bricks_import.sql` },
    { host: join(LOCAL_PLUGINS_DIR, 'bricks-data.sql'),    container: `${CONTAINER_LOCAL_PLUGINS_DIR}/bricks-data.sql` }
  ];
  const sqlFile = sqlCandidates.find((c) => existsSync(c.host));
  if (sqlFile) {
    await runTask(
      'Import Bricks SQL data on cli',
      ['run', 'cli', 'wp', 'db', 'import', sqlFile.container],
      0
    );
    // Replace the live site URL with the local dev URL throughout all tables.
    // WPVivid backups use https://bricks.getframes.io — must become http://localhost:8888.
    await runTask(
      'Replace URLs in Bricks DB on cli',
      ['run', 'cli', 'wp', 'search-replace', 'https://bricks.getframes.io', 'http://localhost:8888', '--all-tables'],
      0
    );
    // The SQL import replaces the whole DB, wiping plugin/theme activation.
    // Re-activate Bricks theme and migration plugin so the environment stays
    // functional without requiring a full npm run dev cycle.
    await runTask('Re-activate Bricks theme after SQL import',    ['run', 'cli', 'wp', 'theme',  'activate', 'bricks'],                 0);
    await runTask('Re-activate migration plugin after SQL import', ['run', 'cli', 'wp', 'plugin', 'activate', 'etch-fusion-suite'],        0);
    await runTask('Re-activate ACSS after SQL import',             ['run', 'cli', 'wp', 'plugin', 'activate', 'automaticcss-plugin'],      0);
    await runTask('Re-activate Frames after SQL import',           ['run', 'cli', 'wp', 'plugin', 'activate', 'frames-plugin'],            0);
  } else {
    console.log('  No Bricks SQL file found — skipping SQL import');
    console.log('  Expected: local-backups/bricks_import.sql');
  }

  // Bricks global settings — strip the _last_updated meta key before storing.
  const settingsHostPath = join(LOCAL_PLUGINS_DIR, '_assets_bricks-settings-acss.json');
  if (existsSync(settingsHostPath)) {
    const settingsContainerPath = `${CONTAINER_LOCAL_PLUGINS_DIR}/_assets_bricks-settings-acss.json`;
    // Merge over existing settings so keys from the SQL import (e.g. customCss)
    // are not wiped — the JSON file only provides the base/default values.
    const phpSnippet = [
      '$raw = file_get_contents( "' + settingsContainerPath + '" );',
      '$new = json_decode( $raw, true );',
      'unset( $new["_last_updated"] );',
      '$existing = get_option( "bricks_global_settings", [] );',
      '$merged = array_merge( $new, $existing );',
      'update_option( "bricks_global_settings", $merged );',
      'echo "OK";'
    ].join(' ');
    await runTask(
      'Import Bricks settings on cli',
      ['run', 'cli', 'wp', 'eval', phpSnippet],
      0
    );
  } else {
    console.log('  No _assets_bricks-settings-acss.json found — skipping');
  }

  // Bricks theme styles — stored as an associative array keyed by style ID.
  // The JSON file contains a single style object with a top-level "id" field.
  const themeStyleHostPath = join(LOCAL_PLUGINS_DIR, '_assets_bricks-theme-style-acss.json');
  if (existsSync(themeStyleHostPath)) {
    const themeStyleContainerPath = `${CONTAINER_LOCAL_PLUGINS_DIR}/_assets_bricks-theme-style-acss.json`;
    const phpSnippet = [
      '$raw = file_get_contents( "' + themeStyleContainerPath + '" );',
      '$style = json_decode( $raw, true );',
      '$id = $style["id"] ?? "standard";',
      // Store the full style object (including id) keyed by its ID, matching
      // the format Bricks uses in BRICKS_DB_THEME_STYLES.
      '$existing = get_option( BRICKS_DB_THEME_STYLES, [] );',
      '$existing[ $id ] = $style;',
      'update_option( BRICKS_DB_THEME_STYLES, $existing );',
      'echo "OK";'
    ].join(' ');
    await runTask(
      'Import Bricks theme styles on cli',
      ['run', 'cli', 'wp', 'eval', phpSnippet],
      0
    );
  } else {
    console.log('  No _assets_bricks-theme-style-acss.json found — skipping');
  }

  // Uploads copy — runs last so the SQL import cannot wipe freshly copied files.
  // WPVivid extracts to uploads/uploads/ (the ZIP contains an uploads/ subfolder).
  // We copy the contents into the container's wp-content/uploads/ directory.
  const uploadsHostPath = join(LOCAL_BACKUPS_DIR, 'uploads', 'uploads');
  if (existsSync(uploadsHostPath)) {
    const uploadsSrc = `${CONTAINER_LOCAL_BACKUPS_DIR}/uploads/uploads/.`;
    const uploadsDst = '/var/www/html/wp-content/uploads/';
    await runTask(
      'Copy Bricks uploads into wp-content/uploads on cli',
      ['run', 'cli', 'bash', '-c', `cp -r ${uploadsSrc} ${uploadsDst}`],
      0
    );
  } else {
    console.log('  No uploads found — skipping media copy');
    console.log('  Expected: local-backups/uploads/uploads/');
  }
}

main().catch((error) => {
  console.error('Plugin activation failed:', error.message);
  process.exit(1);
});
