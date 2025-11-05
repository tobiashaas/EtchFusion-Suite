#!/usr/bin/env node

const http = require('http');

function waitForWordPress({ port, maxAttempts = 60, interval = 2000, timeout = 120, quiet = false, json = false }) {
  let attempt = 0;
  const startTime = Date.now();
  
  function log(message) {
    if (!quiet && !json) {
      console.log(message);
    }
  }
  
  function logJson(status, message, details = {}) {
    if (json) {
      console.log(JSON.stringify({
        status,
        message,
        port,
        attempt,
        timestamp: new Date().toISOString(),
        ...details
      }));
    }
  }

  return new Promise((resolve, reject) => {
    const check = () => {
      attempt += 1;
      const elapsed = Math.floor((Date.now() - startTime) / 1000);
      
      if (elapsed > timeout) {
        const error = `WordPress on port ${port} not ready after ${timeout} seconds (timeout).`;
        logJson('timeout', error, { elapsed, maxAttempts: attempt });
        reject(new Error(error));
        return;
      }
      
      const options = {
        host: 'localhost',
        port,
        path: '/wp-admin/',
        timeout: 5000
      };

      const req = http.request(options, (res) => {
        const { statusCode } = res;
        let data = '';
        
        res.on('data', chunk => {
          data += chunk;
        });
        
        res.on('end', () => {
          if (statusCode === 200 || statusCode === 302) {
            // Verify WordPress-specific markers
            const isWordPress = data.includes('wp-admin') || 
                               data.includes('WordPress') || 
                               res.headers['x-powered-by']?.includes('WordPress') ||
                               res.headers.server?.includes('nginx'); // Common in wp-env
            
            if (isWordPress) {
              log(`✓ WordPress ready on port ${port} (attempt ${attempt}, ${elapsed}s)`);
              logJson('ready', 'WordPress is ready', { 
                statusCode, 
                elapsed, 
                totalAttempts: attempt,
                responseSize: data.length
              });
              resolve({
                port,
                statusCode,
                attempts: attempt,
                elapsed,
                responseSize: data.length
              });
            } else {
              retry(`Response doesn't appear to be WordPress (status ${statusCode})`);
            }
          } else {
            retry(`Received status ${statusCode}`);
          }
        });
      });

      req.on('error', (err) => {
        if (err.code === 'ECONNREFUSED') {
          retry('Connection refused (Docker not running or port not exposed)');
        } else if (err.code === 'ETIMEDOUT') {
          retry('Connection timed out (WordPress starting but slow)');
        } else {
          retry(err.message);
        }
      });
      
      req.on('timeout', () => {
        req.destroy();
        retry('Request timeout');
      });

      req.end();
    };

    const retry = (reason) => {
      if (attempt >= maxAttempts) {
        const error = `WordPress on port ${port} not ready after ${maxAttempts} attempts. Last error: ${reason}`;
        logJson('failed', error, { reason, totalAttempts: attempt });
        reject(new Error(error));
        return;
      }

      log(`Waiting for WordPress on port ${port}... (attempt ${attempt}/${maxAttempts}) - ${reason}`);
      logJson('waiting', 'Waiting for WordPress', { reason, attempt, maxAttempts });
      setTimeout(check, interval);
    };

    logJson('started', 'Starting WordPress health check', { maxAttempts, interval, timeout });
    check();
  });
}

// Support multiple ports
function waitForMultiplePorts(ports, options = {}) {
  const promises = ports.map(port => waitForWordPress({ ...options, port }));
  return Promise.all(promises);
}

// CLI interface
if (require.main === module) {
  const args = process.argv.slice(2);
  const options = {};
  
  // Parse arguments
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--timeout' && args[i + 1]) {
      options.timeout = parseInt(args[++i]);
    } else if (arg === '--interval' && args[i + 1]) {
      options.interval = parseInt(args[++i]);
    } else if (arg === '--quiet') {
      options.quiet = true;
    } else if (arg === '--json') {
      options.json = true;
    } else if (arg.startsWith('--port=')) {
      options.port = parseInt(arg.split('=')[1]);
    } else if (!isNaN(parseInt(arg))) {
      options.port = parseInt(arg);
    }
  }
  
  if (!options.port) {
    console.error('Usage: node wait-for-wordpress.js [--port=8888] [--timeout=120] [--interval=2000] [--quiet] [--json]');
    process.exit(1);
  }
  
  waitForWordPress(options)
    .then(() => process.exit(0))
    .catch(error => {
      console.error(error.message);
      process.exit(1);
    });
}

module.exports = { waitForWordPress, waitForMultiplePorts };
