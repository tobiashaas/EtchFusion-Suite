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

function checkDockerContainers() {
  return new Promise(async (resolve) => {
    try {
      const result = await runCommand('docker', ['ps', '--format', '{{json .}}']);
      const lines = result.stdout.split('\n').filter(line => line.trim());
      const containers = lines.map(line => JSON.parse(line));
      const wpEnvContainers = containers.filter(c => 
        c.Names.includes('wordpress') || 
        c.Names.includes('mysql') ||
        c.Names.includes('wp-env')
      );
      
      resolve({
        status: 'pass',
        message: `Found ${wpEnvContainers.length} wp-env containers running`,
        details: wpEnvContainers.map(c => ({
          name: c.Names,
          status: c.Status,
          ports: c.Ports
        }))
      });
    } catch (error) {
      resolve({
        status: 'fail',
        message: `Failed to check Docker containers: ${error.message}`,
        details: null
      });
    }
  });
}

function checkWordPressEndpoint(port, name) {
  return new Promise(async (resolve) => {
    try {
      const http = require('http');
      const startTime = Date.now();
      
      const req = http.request({
        host: 'localhost',
        port,
        path: '/wp-admin/',
        timeout: 5000
      }, (res) => {
        const elapsed = Date.now() - startTime;
        
        if (res.statusCode === 200 || res.statusCode === 302) {
          resolve({
            status: 'pass',
            message: `${name} endpoint responding (${res.statusCode})`,
            details: {
              port,
              statusCode: res.statusCode,
              responseTime: `${elapsed}ms`
            }
          });
        } else {
          resolve({
            status: 'fail',
            message: `${name} endpoint returned ${res.statusCode}`,
            details: {
              port,
              statusCode: res.statusCode,
              responseTime: `${elapsed}ms`
            }
          });
        }
      });

      req.on('error', (error) => {
        resolve({
          status: 'fail',
          message: `${name} endpoint error: ${error.message}`,
          details: { port, error: error.code }
        });
      });

      req.on('timeout', () => {
        req.destroy();
        resolve({
          status: 'fail',
          message: `${name} endpoint timeout`,
          details: { port, error: 'timeout' }
        });
      });

      req.end();
    } catch (error) {
      resolve({
        status: 'fail',
        message: `${name} check failed: ${error.message}`,
        details: { port, error: error.message }
      });
    }
  });
}

function checkDatabaseConnection(environment, name) {
  return new Promise(async (resolve) => {
    try {
      const result = await runWpEnv(['run', environment, 'wp', 'db', 'check']);
      
      if (result.code === 0) {
        resolve({
          status: 'pass',
          message: `${name} database connection OK`,
          details: { environment }
        });
      } else {
        resolve({
          status: 'fail',
          message: `${name} database check failed`,
          details: { environment, error: result.stderr }
        });
      }
    } catch (error) {
      resolve({
        status: 'fail',
        message: `${name} database connection error: ${error.message}`,
        details: { environment, error: error.message }
      });
    }
  });
}

function checkPluginActivation(environment, name, pluginSlug = 'etch-fusion-suite') {
  return new Promise(async (resolve) => {
    try {
      const result = await runWpEnv(['run', environment, 'wp', 'plugin', 'list', '--status=active', '--field=name', '--format=json']);
      
      if (result.code === 0) {
        const activePlugins = JSON.parse(result.stdout);
        const isPluginActive = activePlugins.includes(pluginSlug);
        
        if (isPluginActive) {
          resolve({
            status: 'pass',
            message: `${pluginSlug} is active on ${name}`,
            details: { environment, pluginSlug, activePluginsCount: activePlugins.length }
          });
        } else {
          resolve({
            status: 'fail',
            message: `${pluginSlug} is not active on ${name}`,
            details: { environment, pluginSlug, activePlugins }
          });
        }
      } else {
        resolve({
          status: 'fail',
          message: `Failed to check plugins on ${name}`,
          details: { environment, error: result.stderr }
        });
      }
    } catch (error) {
      resolve({
        status: 'fail',
        message: `Plugin check error on ${name}: ${error.message}`,
        details: { environment, error: error.message }
      });
    }
  });
}

function checkRestApi(port, name) {
  return new Promise(async (resolve) => {
    try {
      const http = require('http');
      
      const req = http.request({
        host: 'localhost',
        port,
        path: '/wp-json/efs/v1/status',
        timeout: 5000,
        headers: {
          'Accept': 'application/json'
        }
      }, (res) => {
        let data = '';
        
        res.on('data', chunk => {
          data += chunk;
        });
        
        res.on('end', () => {
          if (res.statusCode === 200) {
            resolve({
              status: 'pass',
              message: `${name} REST API responding`,
              details: {
                port,
                statusCode: res.statusCode,
                endpoint: '/wp-json/efs/v1/status',
                responseSize: data.length
              }
            });
          } else if (res.statusCode === 401) {
            resolve({
              status: 'pass',
              message: `${name} REST API requires authentication (expected)`,
              details: {
                port,
                statusCode: res.statusCode,
                endpoint: '/wp-json/efs/v1/status'
              }
            });
          } else if (res.statusCode === 404) {
            resolve({
              status: 'warning',
              message: `${name} REST API endpoint not found (plugin may not be active)`,
              details: {
                port,
                statusCode: res.statusCode,
                endpoint: '/wp-json/efs/v1/status'
              }
            });
          } else {
            resolve({
              status: 'fail',
              message: `${name} REST API returned ${res.statusCode}`,
              details: {
                port,
                statusCode: res.statusCode,
                endpoint: '/wp-json/efs/v1/status'
              }
            });
          }
        });
      });

      req.on('error', (error) => {
        resolve({
          status: 'fail',
          message: `${name} REST API error: ${error.message}`,
          details: { port, error: error.code }
        });
      });

      req.on('timeout', () => {
        req.destroy();
        resolve({
          status: 'fail',
          message: `${name} REST API timeout`,
          details: { port, error: 'timeout' }
        });
      });

      req.end();
    } catch (error) {
      resolve({
        status: 'fail',
        message: `${name} REST API check failed: ${error.message}`,
        details: { port, error: error.message }
      });
    }
  });
}

function checkFilePermissions(environment, name) {
  return new Promise(async (resolve) => {
    try {
      const result = await runWpEnv(['run', environment, 'sh', '-c', 'test -w wp-content && echo "writable" || echo "not writable"']);
      
      if (result.code === 0) {
        const isWritable = result.stdout.includes('writable');
        
        if (isWritable) {
          resolve({
            status: 'pass',
            message: `${name} wp-content directory is writable`,
            details: { environment }
          });
        } else {
          resolve({
            status: 'fail',
            message: `${name} wp-content directory is not writable`,
            details: { environment }
          });
        }
      } else {
        resolve({
          status: 'warning',
          message: `Could not check file permissions on ${name}`,
          details: { environment, error: result.stderr }
        });
      }
    } catch (error) {
      resolve({
        status: 'warning',
        message: `File permissions check error on ${name}: ${error.message}`,
        details: { environment, error: error.message }
      });
    }
  });
}

async function runHealthCheck(fixIssues = false, verbose = false, environment = null) {
  const startTime = Date.now();
  const checks = [];
  
  console.log('🔍 Running health checks...\n');
  
  // Determine which checks to run based on environment
  const runBricks = !environment || environment === 'development';
  const runEtch = !environment || environment === 'tests';
  
  // Run checks conditionally
  const checkPromises = [];
  
  if (runBricks && runEtch) {
    // Run all checks
    checkPromises.push(
      checkDockerContainers(),
      checkWordPressEndpoint(8888, 'Bricks'),
      checkWordPressEndpoint(8889, 'Etch'),
      checkDatabaseConnection('cli', 'Bricks'),
      checkDatabaseConnection('tests-cli', 'Etch'),
      checkPluginActivation('cli', 'Bricks'),
      checkPluginActivation('tests-cli', 'Etch'),
      checkRestApi(8888, 'Bricks'),
      checkRestApi(8889, 'Etch'),
      checkFilePermissions('cli', 'Bricks'),
      checkFilePermissions('tests-cli', 'Etch')
    );
  } else if (runBricks) {
    // Run only Bricks checks
    checkPromises.push(
      checkDockerContainers(),
      checkWordPressEndpoint(8888, 'Bricks'),
      checkDatabaseConnection('cli', 'Bricks'),
      checkPluginActivation('cli', 'Bricks'),
      checkRestApi(8888, 'Bricks'),
      checkFilePermissions('cli', 'Bricks')
    );
  } else if (runEtch) {
    // Run only Etch checks
    checkPromises.push(
      checkDockerContainers(),
      checkWordPressEndpoint(8889, 'Etch'),
      checkDatabaseConnection('tests-cli', 'Etch'),
      checkPluginActivation('tests-cli', 'Etch'),
      checkRestApi(8889, 'Etch'),
      checkFilePermissions('tests-cli', 'Etch')
    );
  }
  
  const results = await Promise.all(checkPromises);
  
  // Map results to check objects based on what was run
  if (runBricks && runEtch) {
    checks.push(
      { name: 'Docker Containers', ...results[0] },
      { name: 'Bricks Endpoint', ...results[1] },
      { name: 'Etch Endpoint', ...results[2] },
      { name: 'Bricks Database', ...results[3] },
      { name: 'Etch Database', ...results[4] },
      { name: 'Bricks Plugin', ...results[5] },
      { name: 'Etch Plugin', ...results[6] },
      { name: 'Bricks REST API', ...results[7] },
      { name: 'Etch REST API', ...results[8] },
      { name: 'Bricks File Permissions', ...results[9] },
      { name: 'Etch File Permissions', ...results[10] }
    );
  } else if (runBricks) {
    checks.push(
      { name: 'Docker Containers', ...results[0] },
      { name: 'Bricks Endpoint', ...results[1] },
      { name: 'Bricks Database', ...results[2] },
      { name: 'Bricks Plugin', ...results[3] },
      { name: 'Bricks REST API', ...results[4] },
      { name: 'Bricks File Permissions', ...results[5] }
    );
  } else if (runEtch) {
    checks.push(
      { name: 'Docker Containers', ...results[0] },
      { name: 'Etch Endpoint', ...results[1] },
      { name: 'Etch Database', ...results[2] },
      { name: 'Etch Plugin', ...results[3] },
      { name: 'Etch REST API', ...results[4] },
      { name: 'Etch File Permissions', ...results[5] }
    );
  }
  
  // Display results
  const passed = checks.filter(c => c.status === 'pass').length;
  const failed = checks.filter(c => c.status === 'fail').length;
  const warnings = checks.filter(c => c.status === 'warning').length;
  const elapsed = Date.now() - startTime;
  
  for (const check of checks) {
    const icon = check.status === 'pass' ? '✅' : 
                 check.status === 'fail' ? '❌' : '⚠️';
    console.log(`${icon} ${check.name}: ${check.message}`);
    
    if (verbose && check.details) {
      console.log(`   Details: ${JSON.stringify(check.details, null, 2)}`);
    }
  }
  
  console.log(`\n📊 Health Check Summary:`);
  console.log(`   ✅ Passed: ${passed}`);
  console.log(`   ❌ Failed: ${failed}`);
  console.log(`   ⚠️ Warnings: ${warnings}`);
  console.log(`   ⏱️ Completed in ${elapsed}ms`);
  
  // Attempt fixes if requested
  if (fixIssues && failed > 0) {
    console.log(`\n🔧 Attempting to fix issues...`);
    
    for (const check of checks) {
      if (check.status === 'fail') {
        console.log(`   🔄 Attempting to fix: ${check.name}`);
        
        try {
          if (check.name.includes('Plugin')) {
            // Try to reactivate plugin
            const env = check.name.includes('Bricks') ? 'cli' : 'tests-cli';
            await runWpEnv(['run', env, 'wp', 'plugin', 'activate', 'etch-fusion-suite']);
            console.log(`      ✅ Plugin reactivated`);
          } else if (check.name.includes('Database')) {
            // Try to restart containers
            await runWpEnv(['stop']);
            await new Promise(resolve => setTimeout(resolve, 2000));
            await runWpEnv(['start']);
            console.log(`      ✅ Containers restarted`);
          }
        } catch (error) {
          console.log(`      ❌ Fix failed: ${error.message}`);
        }
      }
    }
  }
  
  const report = {
    timestamp: new Date().toISOString(),
    summary: { passed, failed, warnings, elapsed },
    checks
  };
  
  // Save report if requested
  if (process.argv.includes('--save-report')) {
    const reportPath = path.join(__dirname, '..', `health-report-${new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)}.json`);
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));
    console.log(`\n📄 Report saved to: ${reportPath}`);
  }
  
  return report;
}

async function main() {
  const args = process.argv.slice(2);
  const json = args.includes('--json');
  const verbose = args.includes('--verbose');
  const quiet = args.includes('--quiet');
  const fixIssues = args.includes('--fix');
  const environment = args.find(arg => arg.startsWith('--environment='))?.split('=')[1];
  
  if (quiet) {
    // Suppress console output for JSON mode
    console.log = () => {};
  }
  
  const report = await runHealthCheck(fixIssues, verbose, environment);
  
  if (json) {
    console.log(JSON.stringify(report, null, 2));
  }
  
  // Exit codes
  if (report.summary.failed > 0) {
    process.exit(1);
  } else if (report.summary.warnings > 0) {
    process.exit(2);
  } else {
    process.exit(0);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Health check failed:', error.message);
    process.exit(1);
  });
}

module.exports = { runHealthCheck };
