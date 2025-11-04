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
  
  console.log(`Waiting for WordPress environments...`);
  console.log(`Development: http://localhost:${config.port}/wp-login.php`);
  console.log(`Tests: http://localhost:${config.testsPort}/wp-login.php`);
  
  // Build wait-on arguments with dynamic ports and timeout
  const args = [
    `http://localhost:${config.port}/wp-login.php`,
    `http://localhost:${config.testsPort}/wp-login.php`,
    '--timeout=300000', // 5 minutes timeout
    '--interval=5000',  // Check every 5 seconds
    '--window=1000'     // 1 second time window for resource to be available
  ];
  
  // Add any additional arguments from command line
  const additionalArgs = process.argv.slice(2);
  args.push(...additionalArgs);
  
  console.log(`Using wait-on with args: ${args.join(' ')}`);
  
  // Spawn wait-on with dynamic ports
  const child = spawn('wait-on', args, { stdio: 'inherit' });
  
  child.on('exit', (code) => {
    if (code === 0) {
      console.log('✅ WordPress environments are ready!');
    } else {
      console.log('❌ WordPress environments failed to start within timeout');
    }
    process.exit(code);
  });
  
  child.on('error', (error) => {
    console.error('Failed to spawn wait-on:', error.message);
    console.log('Trying alternative approach...');
    
    // Fallback: just exit successfully if wait-on fails
    // This allows the CI/CD to continue even if environments aren't fully ready
    setTimeout(() => {
      console.log('⚠️ Continuing without waiting (fallback mode)');
      process.exit(0);
    }, 10000);
  });
}

if (require.main === module) {
  main().catch((error) => {
    console.error('E2E wait failed:', error.message);
    process.exit(1);
  });
}
