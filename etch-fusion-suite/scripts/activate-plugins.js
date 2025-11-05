#!/usr/bin/env node

const { spawn } = require('child_process');
const { existsSync } = require('fs');
const { join } = require('path');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function runTask(label, args, retries = 1, force = false) {
  return new Promise((resolve) => {
    const attempt = async (attemptCount = 0) => {
      const child = spawn(WP_ENV_CMD, args, { stdio: 'pipe' });

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
          console.log(`✓ ${label}`);
          resolve({ success: true, output });
        } else {
          const message = errorOutput.trim() || output.trim() || `Exit code ${code}`;
          
          // Check if this is a "plugin not found" vs "activation failed"
          const isNotFound = message.toLowerCase().includes('not found') || 
                           message.toLowerCase().includes('does not exist');
          
          if (attemptCount < retries && !isNotFound) {
            console.warn(`⚠ ${label} failed, retrying in 2 seconds... (${attemptCount + 1}/${retries})`);
            await new Promise(resolve => setTimeout(resolve, 2000));
            return attempt(attemptCount + 1);
          }
          
          if (isNotFound) {
            console.warn(`⚠ ${label} — Plugin not found`);
          } else {
            console.warn(`⚠ ${label} — ${message}`);
          }
          
          resolve({ success: false, output: message, isNotFound });
        }
      });

      child.on('error', async (error) => {
        if (attemptCount < retries) {
          console.warn(`⚠ ${label} failed, retrying in 2 seconds... (${attemptCount + 1}/${retries})`);
          await new Promise(resolve => setTimeout(resolve, 2000));
          return attempt(attemptCount + 1);
        }
        console.error(`✗ ${label} — ${error.message}`);
        resolve({ success: false, output: error.message });
      });
    };
    
    attempt();
  });
}

function getInstalledPlugins(environment) {
  return new Promise((resolve) => {
    const args = ['run', environment, 'wp', 'plugin', 'list', '--field=name', '--format=json'];
    const child = spawn(WP_ENV_CMD, args, { stdio: 'pipe' });
    
    let output = '';
    
    child.stdout.on('data', (data) => {
      output += data.toString();
    });
    
    child.on('close', (code) => {
      if (code === 0) {
        try {
          resolve(JSON.parse(output));
        } catch (error) {
          console.warn(`⚠ Failed to parse plugin list for ${environment}`);
          resolve([]);
        }
      } else {
        console.warn(`⚠ Failed to list plugins for ${environment}`);
        resolve([]);
      }
    });
    
    child.on('error', () => {
      resolve([]);
    });
  });
}

function findPluginSlug(installedPlugins, expectedNames) {
  for (const name of expectedNames) {
    const found = installedPlugins.find(plugin => 
      plugin.toLowerCase().includes(name.toLowerCase()) ||
      name.toLowerCase().includes(plugin.toLowerCase())
    );
    if (found) return found;
  }
  return null;
}

function getInstalledThemes(environment) {
  return new Promise((resolve) => {
    const args = ['run', environment, 'wp', 'theme', 'list', '--field=name', '--format=json'];
    const child = spawn(WP_ENV_CMD, args, { stdio: 'pipe' });
    
    let output = '';
    
    child.stdout.on('data', (data) => {
      output += data.toString();
    });
    
    child.on('close', (code) => {
      if (code === 0) {
        try {
          resolve(JSON.parse(output));
        } catch (error) {
          console.warn(`⚠ Failed to parse theme list for ${environment}`);
          resolve([]);
        }
      } else {
        console.warn(`⚠ Failed to list themes for ${environment}`);
        resolve([]);
      }
    });
    
    child.on('error', () => {
      resolve([]);
    });
  });
}

function findThemeSlug(installedThemes, expectedNames) {
  for (const name of expectedNames) {
    const found = installedThemes.find(theme => 
      theme.toLowerCase().includes(name.toLowerCase()) ||
      name.toLowerCase().includes(theme.toLowerCase())
    );
    if (found) return found;
  }
  return null;
}

async function verifyActivePlugins(environment, expectedSlugs) {
  try {
    const activePlugins = await new Promise((resolve) => {
      const args = ['run', environment, 'wp', 'plugin', 'list', '--status=active', '--field=name', '--format=json'];
      const child = spawn(WP_ENV_CMD, args, { stdio: 'pipe' });
      
      let output = '';
      
      child.stdout.on('data', (data) => {
        output += data.toString();
      });
      
      child.on('close', (code) => {
        if (code === 0) {
          try {
            resolve(JSON.parse(output));
          } catch {
            resolve([]);
          }
        } else {
          resolve([]);
        }
      });
      
      child.on('error', () => {
        resolve([]);
      });
    });
    
    return expectedSlugs.filter(slug => !activePlugins.includes(slug));
  } catch (error) {
    return expectedSlugs; // Assume none are active if verification fails
  }
}

async function main() {
  const args = process.argv.slice(2);
  const force = args.includes('--force');
  const dryRun = args.includes('--dry-run');
  const verbose = args.includes('--verbose');
  const skipVendorCheck = args.includes('--skip-vendor-check');
  
  if (dryRun) {
    console.log('🔍 DRY RUN MODE - No changes will be made\n');
  }
  
  // Check if vendor/autoload.php exists before activating migration plugin
  const vendorPath = join(__dirname, '..', 'vendor', 'autoload.php');
  const hasVendor = existsSync(vendorPath) || skipVendorCheck;
  
  if (!hasVendor && !skipVendorCheck) {
    console.warn('⚠ vendor/autoload.php not found. Skipping migration plugin activation.');
    console.warn('  Run Composer install first to activate the migration plugin.');
    console.warn('  Use --skip-vendor-check to attempt activation anyway.');
  } else if (skipVendorCheck) {
    console.log('⚠ Skipping vendor check per --skip-vendor-check flag');
  }

  console.log('▶ Discovering installed plugins and themes...');
  const [devPlugins, testPlugins, devThemes, testThemes] = await Promise.all([
    getInstalledPlugins('cli'),
    getInstalledPlugins('tests-cli'),
    getInstalledThemes('cli'),
    getInstalledThemes('tests-cli')
  ]);

  if (verbose) {
    console.log(`📦 Found ${devPlugins.length} dev plugins, ${testPlugins.length} test plugins`);
    console.log(`🎨 Found ${devThemes.length} dev themes, ${testThemes.length} test themes`);
  }

  const tasks = [];
  const expectedActivations = { cli: [], 'tests-cli': [] };
  
  // Development environment plugins
  const bricksSlug = findPluginSlug(devPlugins, ['bricks']);
  if (bricksSlug) {
    tasks.push({ label: 'Activate Bricks on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', bricksSlug], env: 'cli', slug: bricksSlug });
    expectedActivations.cli.push(bricksSlug);
  } else {
    console.warn('⚠ Bricks plugin not found in development environment');
  }
  
  const framesSlug = findPluginSlug(devPlugins, ['frames']);
  if (framesSlug) {
    tasks.push({ label: 'Activate Frames on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', framesSlug], env: 'cli', slug: framesSlug });
    expectedActivations.cli.push(framesSlug);
  } else {
    console.warn('⚠ Frames plugin not found in development environment');
  }
  
  const acssDevSlug = findPluginSlug(devPlugins, ['automatic-css', 'automatic.css', 'automattic-css']);
  if (acssDevSlug) {
    tasks.push({ label: 'Activate Automatic.css on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', acssDevSlug], env: 'cli', slug: acssDevSlug });
    expectedActivations.cli.push(acssDevSlug);
  } else {
    console.warn('⚠ Automatic.css plugin not found in development environment');
  }
  
  // Development environment themes
  const bricksChildSlug = findThemeSlug(devThemes, ['bricks-child']);
  if (bricksChildSlug) {
    tasks.push({ label: 'Activate Bricks Child on development', args: ['run', 'cli', 'wp', 'theme', 'activate', bricksChildSlug], env: 'cli', slug: bricksChildSlug });
  } else {
    console.warn('⚠ Bricks Child theme not found in development environment');
  }
  
  // Test environment plugins
  const etchSlug = findPluginSlug(testPlugins, ['etch']);
  if (etchSlug) {
    tasks.push({ label: 'Activate Etch on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', etchSlug], env: 'tests-cli', slug: etchSlug });
    expectedActivations['tests-cli'].push(etchSlug);
  } else {
    console.warn('⚠ Etch plugin not found in test environment');
  }
  
  const acssTestSlug = findPluginSlug(testPlugins, ['automatic-css', 'automatic.css', 'automattic-css']);
  if (acssTestSlug) {
    tasks.push({ label: 'Activate Automatic.css on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', acssTestSlug], env: 'tests-cli', slug: acssTestSlug });
    expectedActivations['tests-cli'].push(acssTestSlug);
  } else {
    console.warn('⚠ Automatic.css plugin not found in test environment');
  }
  
  // Test environment themes
  const etchThemeSlug = findThemeSlug(testThemes, ['etch-theme', 'etch']);
  if (etchThemeSlug) {
    tasks.push({ label: 'Activate Etch Theme on tests', args: ['run', 'tests-cli', 'wp', 'theme', 'activate', etchThemeSlug], env: 'tests-cli', slug: etchThemeSlug });
  } else {
    console.warn('⚠ Etch Theme not found in test environment');
  }
  
  // Only add migration plugin activation if vendor exists
  if (hasVendor) {
    const migrationDevSlug = findPluginSlug(devPlugins, ['etch-fusion-suite']);
    const migrationTestSlug = findPluginSlug(testPlugins, ['etch-fusion-suite']);
    
    if (migrationDevSlug) {
      tasks.push({ label: 'Activate migration plugin on development', args: ['run', 'cli', 'wp', 'plugin', 'activate', migrationDevSlug], env: 'cli', slug: migrationDevSlug });
      expectedActivations.cli.push(migrationDevSlug);
    }
    
    if (migrationTestSlug) {
      tasks.push({ label: 'Activate migration plugin on tests', args: ['run', 'tests-cli', 'wp', 'plugin', 'activate', migrationTestSlug], env: 'tests-cli', slug: migrationTestSlug });
      expectedActivations['tests-cli'].push(migrationTestSlug);
    }
  }
  
  // Handle force mode - deactivate first
  if (force && !dryRun) {
    console.log('\n🔄 Force mode: Deactivating plugins first...');
    for (const env of ['cli', 'tests-cli']) {
      for (const slug of expectedActivations[env]) {
        try {
          await runTask(`Deactivate ${slug} on ${env}`, ['run', env, 'wp', 'plugin', 'deactivate', slug], 0, false);
        } catch (error) {
          // Ignore deactivation errors
        }
      }
    }
  }

  console.log(`\n▶ Activating ${tasks.length} plugins and themes...\n`);
  
  const results = [];
  for (const task of tasks) {
    if (dryRun) {
      console.log(`🔍 Would activate: ${task.label}`);
      results.push({ ...task, success: true, output: 'DRY RUN' });
    } else {
      const result = await runTask(task.label, task.args, 1, force);
      results.push({ ...task, ...result });
      
      if (verbose && result.output) {
        console.log(`   Output: ${result.output.substring(0, 100)}${result.output.length > 100 ? '...' : ''}`);
      }
    }
  }
  
  // Summary
  const successful = results.filter(r => r.success).length;
  const failed = results.filter(r => !r.success).length;
  const notFound = results.filter(r => r.isNotFound).length;
  
  console.log(`\n📊 Activation Summary:`);
  console.log(`   ✅ Successful: ${successful}`);
  console.log(`   ❌ Failed: ${failed}`);
  if (notFound > 0) {
    console.log(`   🔍 Not found: ${notFound}`);
  }
  
  // Verify activation if not dry run
  if (!dryRun && successful > 0) {
    console.log(`\n🔍 Verifying activation...`);
    
    for (const env of ['cli', 'tests-cli']) {
      const envName = env === 'cli' ? 'development' : 'tests';
      const missing = await verifyActivePlugins(env, expectedActivations[env]);
      
      if (missing.length === 0) {
        console.log(`   ✅ All expected plugins active on ${envName}`);
      } else {
        console.log(`   ⚠ Missing plugins on ${envName}: ${missing.join(', ')}`);
      }
    }
  }
}

main().catch((error) => {
  console.error('Plugin activation failed:', error.message);
  process.exit(1);
});
