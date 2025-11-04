#!/usr/bin/env node

const { join } = require('path');
const fs = require('fs');
const { spawn } = require('child_process');

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

async function main() {
  const config = loadWpEnvConfig();
  
  // Build wait-on arguments with dynamic ports
  const args = [
    `http://localhost:${config.port}/wp-login.php`,
    `http://localhost:${config.testsPort}/wp-login.php`
  ];
  
  // Add any additional arguments from command line
  const additionalArgs = process.argv.slice(2);
  args.push(...additionalArgs);
  
  // Spawn wait-on with dynamic ports
  const child = spawn('wait-on', args, { stdio: 'inherit' });
  
  child.on('exit', (code) => {
    process.exit(code);
  });
  
  child.on('error', (error) => {
    console.error('Failed to spawn wait-on:', error.message);
    process.exit(1);
  });
}

if (require.main === module) {
  main().catch((error) => {
    console.error('E2E wait failed:', error.message);
    process.exit(1);
  });
}
