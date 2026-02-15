#!/usr/bin/env node

const { spawnSync } = require('child_process');
const http = require('http');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function runWpEnv(args) {
  const spawnOpts = { encoding: 'utf8' };
  if (process.platform === 'win32') {
    spawnOpts.shell = true;
  }
  const result = spawnSync(WP_ENV_CMD, args, spawnOpts);

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || `Command failed: ${args.join(' ')}`);
  }

  return result.stdout.trim();
}

function ensureApplicationPassword() {
  console.log('▶ Creating application password on Etch instance...');
  
  // Create a unique label with timestamp to avoid conflicts
  const label = `efs-migration-${Date.now()}`;
  
  try {
    const password = runWpEnv([
      'run',
      'tests-cli',
      'wp',
      'user',
      'application-password',
      'create',
      'admin',
      label,
      '--porcelain'
    ]);
    
    if (!password) {
      throw new Error('create --porcelain returned empty password');
    }
    
    return password;
  } catch (error) {
    throw new Error(`Failed to create application password: ${error.message}`);
  }
}

function request(options, body) {
  return new Promise((resolve, reject) => {
    const req = http.request(options, (res) => {
      let data = '';
      res.on('data', (chunk) => {
        data += chunk;
      });
      res.on('end', () => {
        resolve({ statusCode: res.statusCode, headers: res.headers, body: data });
      });
    });

    req.on('error', reject);

    if (body) {
      req.write(body);
    }

    req.end();
  });
}

async function main() {
  const appPassword = ensureApplicationPassword();
  const authHeader = `Basic ${Buffer.from(`admin:${appPassword}`).toString('base64')}`;

  console.log('▶ Testing status endpoint...');
  const statusResponse = await request({
    host: 'localhost',
    port: 8889,
    path: '/wp-json/efs/v1/status',
    method: 'GET',
    headers: {
      Authorization: authHeader
    }
  });

  if (statusResponse.statusCode !== 200) {
    throw new Error(`Status endpoint returned ${statusResponse.statusCode}`);
  }

  console.log('✓ Status endpoint reachable');

  let statusData;
  try {
    statusData = JSON.parse(statusResponse.body);
  } catch (error) {
    throw new Error(`Failed to parse status response JSON: ${error.message}`);
  }

  if (!statusData.version) {
    throw new Error('Status response missing version field');
  }

  console.log(`✓ Etch API reported plugin version ${statusData.version}`);

  console.log('▶ Testing token generation endpoint...');
  const generateResponse = await request({
    host: 'localhost',
    port: 8889,
    path: '/wp-json/efs/v1/generate-key',
    method: 'POST',
    headers: {
      Authorization: authHeader,
      'Content-Type': 'application/json'
    }
  });

  if (generateResponse.statusCode !== 200) {
    throw new Error(`Token endpoint returned ${generateResponse.statusCode}: ${generateResponse.body}`);
  }

  const tokenData = JSON.parse(generateResponse.body);
  if (!tokenData.token) {
    throw new Error('Token response missing token field');
  }

  console.log('✓ Token generation successful');

  if (!generateResponse.headers['access-control-allow-origin']) {
    console.warn('⚠ CORS header missing (Access-Control-Allow-Origin)');
  } else {
    console.log('✓ CORS header present');
  }

  console.log('\n✅ API connection test successful');
}

main().catch((error) => {
  console.error('\n✗ API connection test failed:', error.message);
  process.exit(1);
});
