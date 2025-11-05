#!/usr/bin/env node

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function runCommand(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { stdio: 'pipe', ...options });
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

function runWpEnv(args) {
  return runCommand(WP_ENV_CMD, args);
}

function loadWpEnvConfig() {
  try {
    const configPath = path.join(__dirname, '../.wp-env.json');
    const overridePath = path.join(__dirname, '../.wp-env.override.json');
    
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
    
    // Merge env-specific configs
    if (config.env || override.env) {
      merged.env = {
        ...(config.env || {}),
        ...(override.env || {})
      };
      
      // Deep merge environment-specific configs
      for (const env of ['development', 'tests']) {
        if (config.env?.[env] || override.env?.[env]) {
          merged.env[env] = {
            ...(config.env?.[env] || {}),
            ...(override.env?.[env] || {}),
            config: {
              ...(config.env?.[env]?.config || {}),
              ...(override.env?.[env]?.config || {})
            }
          };
        }
      }
    }
    
    return merged;
  } catch (error) {
    console.warn('Warning: Could not load wp-env configuration:', error.message);
    return {};
  }
}

function getDockerStats() {
  return new Promise((resolve) => {
    const child = spawn('docker', ['stats', '--no-stream', '--format', '{{json .}}'], { stdio: 'pipe' });
    let output = '';
    
    child.stdout?.on('data', (data) => {
      output += data.toString();
    });
    
    child.on('close', (code) => {
      if (code === 0) {
        try {
          const lines = output.split('\n').filter(line => line.trim());
          const stats = lines.map(line => JSON.parse(line));
          resolve(stats);
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
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function formatPercentage(percentage) {
  return parseFloat(percentage).toFixed(2) + '%';
}

async function getRunningEnvironmentInfo() {
  const info = {
    wordpress: {},
    plugins: {},
    themes: {},
    database: {},
    containers: {}
  };
  
  try {
    // WordPress versions
    const bricksVersion = await runWpEnv(['run', 'cli', 'wp', 'core', 'version']);
    const etchVersion = await runWpEnv(['run', 'tests-cli', 'wp', 'core', 'version']);
    
    info.wordpress = {
      bricks: bricksVersion.code === 0 ? bricksVersion.stdout.trim() : 'Unknown',
      etch: etchVersion.code === 0 ? etchVersion.stdout.trim() : 'Unknown'
    };
    
    // Active plugins
    const bricksPlugins = await runWpEnv(['run', 'cli', 'wp', 'plugin', 'list', '--status=active', '--format=json']);
    const etchPlugins = await runWpEnv(['run', 'tests-cli', 'wp', 'plugin', 'list', '--status=active', '--format=json']);
    
    info.plugins = {
      bricks: bricksPlugins.code === 0 ? JSON.parse(bricksPlugins.stdout) : [],
      etch: etchPlugins.code === 0 ? JSON.parse(etchPlugins.stdout) : []
    };
    
    // Active themes
    const bricksTheme = await runWpEnv(['run', 'cli', 'wp', 'theme', 'list', '--status=active', '--format=json']);
    const etchTheme = await runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'list', '--status=active', '--format=json']);
    
    info.themes = {
      bricks: bricksTheme.code === 0 ? JSON.parse(bricksTheme.stdout) : [],
      etch: etchTheme.code === 0 ? JSON.parse(etchTheme.stdout) : []
    };
    
    // Database info
    const bricksDbSize = await runWpEnv(['run', 'cli', 'wp', 'db', 'size']);
    const etchDbSize = await runWpEnv(['run', 'tests-cli', 'wp', 'db', 'size']);
    
    info.database = {
      bricks: bricksDbSize.code === 0 ? bricksDbSize.stdout.trim() : 'Unknown',
      etch: etchDbSize.code === 0 ? etchDbSize.stdout.trim() : 'Unknown'
    };
    
    // Container stats
    const dockerStats = await getDockerStats();
    const wpEnvStats = dockerStats.filter(stat => 
      stat.Name.includes('wordpress') || 
      stat.Name.includes('mysql') ||
      stat.Name.includes('wp-env')
    );
    
    info.containers = wpEnvStats.map(stat => ({
      name: stat.Name,
      cpu: stat.CPUPerc,
      memory: stat.MemUsage,
      netIO: stat.NetIO,
      blockIO: stat.BlockIO
    }));
    
  } catch (error) {
    console.warn('Warning: Could not get complete environment info:', error.message);
  }
  
  return info;
}

function displayEnvironmentInfo(config, runningInfo, compare = false) {
  console.log('🌐 Etch Fusion Suite Environment Information\\n');
  console.log(`Generated: ${new Date().toISOString()}\\n`);
  
  // Configuration section
  console.log('📋 Configuration:');
  console.log(`   WordPress Core: ${config.core || 'Default'}`);
  console.log(`   PHP Version: ${config.phpVersion || 'Default'}`);
  console.log(`   Development Port: ${config.port || 8888}`);
  console.log(`   Tests Port: ${config.testsPort || 8889}`);
  
  if (config.env?.development?.mysqlPort) {
    console.log(`   Bricks MySQL Port: ${config.env.development.mysqlPort}`);
  }
  if (config.env?.tests?.mysqlPort) {
    console.log(`   Etch MySQL Port: ${config.env.tests.mysqlPort}`);
  }
  
  if (config.plugins && config.plugins.length > 0) {
    console.log(`   Plugins: ${config.plugins.join(', ')}`);
  }
  if (config.themes && config.themes.length > 0) {
    console.log(`   Themes: ${config.themes.join(', ')}`);
  }
  
  if (config.config) {
    console.log('   WordPress Config:');
    Object.entries(config.config).forEach(([key, value]) => {
      console.log(`     ${key}: ${value}`);
    });
  }
  
  // Running environment section
  console.log('\\n🏃 Running Environment:');
  console.log(`   Bricks WordPress: ${runningInfo.wordpress.bricks}`);
  console.log(`   Etch WordPress: ${runningInfo.wordpress.etch}`);
  
  console.log(`\\n   Active Plugins:`);
  console.log(`     Bricks: ${runningInfo.plugins.bricks.length} plugins`);
  if (runningInfo.plugins.bricks.length > 0) {
    runningInfo.plugins.bricks.forEach(plugin => {
      console.log(`       - ${plugin.name} (${plugin.version})`);
    });
  }
  
  console.log(`     Etch: ${runningInfo.plugins.etch.length} plugins`);
  if (runningInfo.plugins.etch.length > 0) {
    runningInfo.plugins.etch.forEach(plugin => {
      console.log(`       - ${plugin.name} (${plugin.version})`);
    });
  }
  
  console.log(`\\n   Active Themes:`);
  console.log(`     Bricks: ${runningInfo.themes.bricks.map(t => t.name).join(', ') || 'None'}`);
  console.log(`     Etch: ${runningInfo.themes.etch.map(t => t.name).join(', ') || 'None'}`);
  
  console.log(`\\n   Database Sizes:`);
  console.log(`     Bricks: ${runningInfo.database.bricks}`);
  console.log(`     Etch: ${runningInfo.database.etch}`);
  
  // Container resources
  if (runningInfo.containers.length > 0) {
    console.log('\\n📊 Container Resources:');
    runningInfo.containers.forEach(container => {
      console.log(`   ${container.name}:`);
      console.log(`     CPU: ${container.cpu}`);
      console.log(`     Memory: ${container.memory}`);
      console.log(`     Network I/O: ${container.netIO}`);
    });
  }
  
  // URLs
  console.log('\\n🔗 Access URLs:');
  console.log(`   Bricks Admin: http://localhost:${config.port || 8888}/wp-admin`);
  console.log(`   Etch Admin: http://localhost:${config.testsPort || 8889}/wp-admin`);
  
  if (config.env?.development?.mysqlPort) {
    console.log(`   Bricks MySQL: localhost:${config.env.development.mysqlPort}`);
  }
  if (config.env?.tests?.mysqlPort) {
    console.log(`   Etch MySQL: localhost:${config.env.tests.mysqlPort}`);
  }
  
  // Comparison section
  if (compare) {
    console.log('\\n🔍 Configuration vs Reality:');
    
    if (config.port && config.port !== 8888) {
      const actualPort = config.port;
      console.log(`   Development port: Configured ${actualPort}, Expected 8888`);
    }
    
    if (config.testsPort && config.testsPort !== 8889) {
      const actualPort = config.testsPort;
      console.log(`   Tests port: Configured ${actualPort}, Expected 8889`);
    }
    
    const hasEtchFusionBricks = runningInfo.plugins.bricks.some(p => p.name.includes('etch-fusion'));
    const hasEtchFusionEtch = runningInfo.plugins.etch.some(p => p.name.includes('etch-fusion'));
    
    console.log(`   Etch Fusion Suite: Bricks ${hasEtchFusionBricks ? '✅' : '❌'}, Etch ${hasEtchFusionEtch ? '✅' : '❌'}`);
  }
}

function showUsage() {
  console.log(`
Usage: node env-info.js [options]

Options:
  --json        Output in JSON format
  --compare     Compare configuration with running environment
  --help        Show this help

Examples:
  node env-info.js                           # Show environment information
  node env-info.js --json                   # Output as JSON
  node env-info.js --compare                # Show configuration differences
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help')) {
    showUsage();
    process.exit(0);
  }
  
  const json = args.includes('--json');
  const compare = args.includes('--compare');
  
  try {
    const config = loadWpEnvConfig();
    const runningInfo = await getRunningEnvironmentInfo();
    
    if (json) {
      console.log(JSON.stringify({
        timestamp: new Date().toISOString(),
        configuration: config,
        running: runningInfo
      }, null, 2));
    } else {
      displayEnvironmentInfo(config, runningInfo, compare);
    }
  } catch (error) {
    console.error('❌ Failed to get environment information:', error.message);
    process.exit(1);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Environment info failed:', error.message);
    process.exit(1);
  });
}

module.exports = { getRunningEnvironmentInfo, loadWpEnvConfig };
