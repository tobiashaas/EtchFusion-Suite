#!/usr/bin/env node

/**
 * Bricks Builder Setup Script
 * 
 * Automatically installs Bricks Builder if license file is available
 * This runs after wp-env start to ensure Bricks is available for development
 */

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

function log(message) {
  console.log(`🧱 Bricks Setup: ${message}`);
}

function error(message) {
  console.error(`❌ Bricks Setup: ${message}`);
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
        reject(new Error(`Command failed with code ${code}: ${stderr}`));
      }
    });
  });
}

async function checkBricksLicense() {
  // Check for Bricks license file in common locations
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

async function installBricks() {
  try {
    log('Checking for Bricks Builder installation...');
    
    // Check if Bricks is already installed
    const { stdout: pluginList } = await runCommand('wp-env', ['run', 'cli', 'plugin', 'list', '--status=active', '--format=json']);
    const plugins = JSON.parse(pluginList);
    
    const bricksActive = plugins.some(plugin => plugin.name === 'bricks');
    
    if (bricksActive) {
      log('✅ Bricks Builder is already installed and active');
      return true;
    }

    // Check for license file
    const licensePath = await checkBricksLicense();
    
    if (!licensePath) {
      log('⚠️  No Bricks license file found');
      log('💡 To auto-install Bricks, add your license key to one of these locations:');
      licensePaths.forEach(path => {
        log(`   - ${path}`);
      });
      log('🎯 For now, you can install Bricks manually in the WordPress admin');
      return false;
    }

    // Read license key
    const licenseKey = fs.readFileSync(licensePath, 'utf8').trim();
    
    if (!licenseKey) {
      error('License file is empty');
      return false;
    }

    log('🔧 Installing Bricks Builder...');
    
    // Install Bricks using wp-cli (if available in wp-env)
    try {
      // Try to download and install Bricks from the official source
      await runCommand('wp-env', ['run', 'cli', 'plugin', 'install', 'bricks', '--activate']);
      log('✅ Bricks Builder installed successfully');
      
      // Activate license
      await runCommand('wp-env', ['run', 'cli', 'bricks', 'license', 'activate', licenseKey]);
      log('✅ Bricks license activated');
      
      return true;
    } catch (installError) {
      error(`Failed to install Bricks: ${installError.message}`);
      log('💡 You may need to install Bricks manually from the WordPress admin');
      return false;
    }

  } catch (error) {
    error(`Setup failed: ${error.message}`);
    return false;
  }
}

async function main() {
  log('Starting Bricks Builder setup...');
  
  try {
    const success = await installBricks();
    
    if (success) {
      log('🎉 Bricks Builder setup completed successfully!');
      process.exit(0);
    } else {
      log('⚠️  Bricks Builder setup completed with warnings');
      log('🎯 Your development environment is ready, but Bricks may need manual installation');
      process.exit(0);
    }
  } catch (error) {
    console.error(`❌ Bricks Setup: Setup failed: ${error.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = { installBricks, checkBricksLicense };
