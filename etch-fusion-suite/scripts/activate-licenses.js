#!/usr/bin/env node

const { spawn } = require('child_process');
const { existsSync } = require('fs');
const { join } = require('path');
const { parseDotEnv } = require('./lib/dotenv');

const ROOT_DIR = join(__dirname, '..');
const ENV_PATH = join(ROOT_DIR, '.env');
const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function getWpEnvPath() {
  if (process.platform !== 'win32') {
    return WP_ENV_CMD;
  }
  const local = join(__dirname, '..', 'node_modules', '.bin', 'wp-env.cmd');
  return existsSync(local) ? local : WP_ENV_CMD;
}

function spawnWpEnv(args) {
  const wpEnv = getWpEnvPath();
  if (process.platform === 'win32') {
    const commandLine = [wpEnv, ...args]
      .map((a) => (/[\s"&|<>^]/.test(a) ? `"${String(a).replace(/"/g, '""')}"` : a))
      .join(' ');
    return spawn(commandLine, [], { stdio: 'pipe', shell: true });
  }
  return spawn(wpEnv, args, { stdio: 'pipe' });
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

function runWpCommand(environment, wpArgs) {
  return runWpEnv(['run', environment, 'wp', ...wpArgs]);
}

function runWpEval(environment, phpCode) {
  const encoded = Buffer.from(phpCode, 'utf8').toString('base64');
  return runWpCommand(environment, ['eval', `eval(base64_decode('${encoded}'));`]);
}

function buildEddActivationSnippet({ license, storeUrl, storeItemId, storeItemName, licenseOption, statusOption }) {
  const licenseJson = JSON.stringify(license);
  const storeUrlJson = JSON.stringify(storeUrl);
  const storeNameJson = JSON.stringify(storeItemName);
  return `
$license = json_decode(${licenseJson}, true);
if (!is_string($license) || '' === $license) {
  exit(0);
}
$store_url = json_decode(${storeUrlJson}, true);
$item_name = rawurlencode(json_decode(${storeNameJson}, true));
$api_params = [
  'edd_action'  => 'activate_license',
  'license'     => $license,
  'item_id'     => ${storeItemId},
  'item_name'   => $item_name,
  'url'         => site_url(),
  'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
];
$response = wp_remote_post(
  $store_url,
  [
    'timeout'   => 15,
    'sslverify' => true,
    'body'      => $api_params,
  ]
);
if (is_wp_error($response)) {
  fwrite(STDERR, $response->get_error_message());
  exit(1);
}
$body = wp_remote_retrieve_body($response);
if ('' === $body) {
  fwrite(STDERR, 'License server response was empty.');
  exit(1);
}
$license_data = json_decode($body);
if (null === $license_data || !isset($license_data->success) || !isset($license_data->license)) {
  fwrite(STDERR, 'Unexpected license response.');
  exit(1);
}
if (false === $license_data->success) {
  $message = isset($license_data->message)
    ? $license_data->message
    : (isset($license_data->error) ? $license_data->error : 'License activation failed.');
  fwrite(STDERR, $message);
  exit(1);
}
update_option('${licenseOption}', $license);
update_option('${statusOption}', $license_data->license);
echo 'License activated';
`;
}

async function activateBricks(environment, license) {
  const php = `
$license = json_decode(${JSON.stringify(license)}, true);
if (!is_string($license) || '' === $license) {
  exit(0);
}
update_option('bricks_license_key', $license);
if (!class_exists('Bricks\\\\License')) {
  fwrite(STDERR, 'Bricks\\\\License class unavailable.');
  exit(1);
}
Bricks\\License::$license_key = $license;
$status = Bricks\\License::activate_license();
if (!$status) {
  fwrite(STDERR, 'Bricks license activation failed.');
  exit(1);
}
echo 'License activated: ' . $status;
`;
  const result = await runWpEval(environment, php);
  if (result.code !== 0) {
    throw new Error(result.stderr.trim() || result.stdout.trim() || 'Bricks license activation failed.');
  }
}

async function activateEtch(environment, license) {
  const php = `
$license = json_decode(${JSON.stringify(license)}, true);
if (!is_string($license) || '' === $license) {
  exit(0);
}
update_option('etch_license_key', $license);
try {
  \\Etch\\WpAdmin\\License::get_instance()->activate_license($license);
  echo 'License activated';
  exit(0);
} catch (\\Throwable $error) {
  fwrite(STDERR, $error->getMessage());
  exit(1);
}
`;
  const result = await runWpEval(environment, php);
  if (result.code !== 0) {
    throw new Error(result.stderr.trim() || result.stdout.trim() || 'Etch license activation failed.');
  }
}

async function activateEddLicense(environment, license, config) {
  const php = buildEddActivationSnippet({
    license,
    storeUrl: config.storeUrl,
    storeItemId: config.storeItemId,
    storeItemName: config.storeItemName,
    licenseOption: config.licenseOption,
    statusOption: config.statusOption
  });
  const result = await runWpEval(environment, php);
  if (result.code !== 0) {
    throw new Error(result.stderr.trim() || result.stdout.trim() || 'License activation failed.');
  }
}

const LICENSE_TASKS = [
  {
    label: 'Bricks theme (development)',
    env: 'cli',
    licenseKey: 'BRICKS_LICENSE_KEY',
    runner: activateBricks
  },
  {
    label: 'Frames plugin (development)',
    env: 'cli',
    licenseKey: 'FRAMES_LICENSE_KEY',
    runner: (environment, license) =>
      activateEddLicense(environment, license, {
        storeUrl: 'https://getframes.io/',
        storeItemId: 176,
        storeItemName: 'Frames (Bricks Builder)',
        licenseOption: 'frames_license_key',
        statusOption: 'frames_license_status'
      })
  },
  {
    label: 'Automatic.css (development)',
    env: 'cli',
    licenseKey: 'ACSS_LICENSE_KEY',
    runner: (environment, license) =>
      activateEddLicense(environment, license, {
        storeUrl: 'https://automaticcss.com/',
        storeItemId: 164,
        storeItemName: 'Automatic.css',
        licenseOption: 'automatic_css_license_key',
        statusOption: 'automatic_css_license_status'
      })
  },
  {
    label: 'Automatic.css (tests)',
    env: 'tests-cli',
    licenseKey: 'ACSS_LICENSE_KEY',
    runner: (environment, license) =>
      activateEddLicense(environment, license, {
        storeUrl: 'https://automaticcss.com/',
        storeItemId: 164,
        storeItemName: 'Automatic.css',
        licenseOption: 'automatic_css_license_key',
        statusOption: 'automatic_css_license_status'
      })
  },
  {
    label: 'Etch plugin (tests)',
    env: 'tests-cli',
    licenseKey: 'ETCH_LICENSE_KEY',
    runner: activateEtch
  }
];

async function main() {
  const envVars = parseDotEnv(ENV_PATH);
  const configuredLicenses = [];
  const errors = [];

  for (const task of LICENSE_TASKS) {
    const rawKey = envVars[task.licenseKey];
    if (!rawKey) {
      console.log(`- Skipping ${task.label} (no ${task.licenseKey} configured)`);
      continue;
    }
    console.log(`- Activating ${task.label} in ${task.env}...`);
    try {
      await task.runner(task.env, rawKey);
      configuredLicenses.push(task.label);
      console.log(`  ✓ ${task.label} license activated`);
    } catch (error) {
      errors.push({ label: task.label, message: error.message });
      console.warn(`  ⚠ ${task.label} activation failed: ${error.message}`);
    }
  }

  if (configuredLicenses.length === 0) {
    console.log('No commercial license keys were processed.');
  } else {
    console.log('');
    console.log(`Processed licenses: ${configuredLicenses.join(', ')}`);
  }

  if (errors.length > 0) {
    console.warn('');
    console.warn('Some license activations failed; check the logs above or the WordPress license pages.');
  }
}

main().catch((error) => {
  console.error('License activation script encountered a fatal error:', error.message);
  process.exit(1);
});
