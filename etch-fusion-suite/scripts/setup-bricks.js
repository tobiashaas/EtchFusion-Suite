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
    
    // Check if Bricks theme is already installed
    const { stdout: themeList } = await runCommand('npx', ['wp-env', 'run', 'cli', 'theme', 'list', '--status=active', '--format=json']);
    const themes = JSON.parse(themeList);
    
    const bricksActive = themes.some(theme => theme.name === 'bricks');
    
    if (bricksActive) {
      log('✅ Bricks Builder is already installed and active');
      return true;
    }

    // Check for license file
    const licensePath = await checkBricksLicense();
    
    if (!licensePath) {
      log('⚠️  No Bricks license file found');
      log('💡 To auto-install Bricks, add your license key to one of these locations:');
      const licensePaths = [
        path.join(process.cwd(), 'bricks-license.txt'),
        path.join(process.cwd(), '.bricks-license'),
        path.join(process.cwd(), 'config', 'bricks-license.txt'),
        path.join(process.env.HOME || '', '.bricks-license'),
        path.join(process.env.HOME || '', 'bricks-license.txt')
      ];
      licensePaths.forEach(path => {
        log(`   - ${path}`);
      });
      log('🎯 For now, you can install Bricks manually in the WordPress admin');
      return false;
    }

    // Read license key
    const licenseKey = fs.readFileSync(licensePath, 'utf8').trim();
    
    if (!licenseKey) {
      console.error('🧱 Bricks Setup: License file is empty');
      return false;
    }

    log('🧱 Bricks Builder is a premium theme and cannot be auto-installed');
    log('📋 Please install Bricks Builder manually:');
    log('   1. Download Bricks from your account');
    log('   2. Upload via WordPress Admin → Appearance → Themes → Add Theme → Upload Theme');
    log('   3. Activate the theme');
    log('   4. Use license key: 8d30611670e6c80bdfdc67ff3110a007');
    
    return false;

  } catch (err) {
    console.error(`🧱 Bricks Setup: Setup failed: ${err.message}`);
    return false;
  }
}

async function main() {
  console.log('🧱 Bricks Setup: Starting Bricks Builder setup...');
  
  try {
    const success = await installBricks();
    
    if (success) {
      console.log('🧱 Bricks Setup: 🎉 Bricks Builder setup completed successfully!');
      process.exit(0);
    } else {
      console.log('🧱 Bricks Setup: ⚠️  Bricks Builder setup completed with warnings');
      console.log('🧱 Bricks Setup: 🎯 Your development environment is ready, but Bricks may need manual installation');
      process.exit(0);
    }
  } catch (err) {
    console.error(`❌ Bricks Setup: Setup failed: ${err.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = { installBricks, checkBricksLicense };
