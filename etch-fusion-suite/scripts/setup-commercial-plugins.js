#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.join(__dirname, '..');
const LOCAL_PLUGINS_DIR = path.join(ROOT_DIR, 'local-plugins');
const ENV_PATH = path.join(ROOT_DIR, '.env');

const C = {
  reset: '\x1b[0m',
  red: '\x1b[31m',
  yellow: '\x1b[33m',
  green: '\x1b[32m',
  cyan: '\x1b[36m',
  gray: '\x1b[90m'
};

function useColor() {
  return process.stdout.isTTY && process.env.NO_COLOR !== '1';
}

function color(value, key) {
  if (!useColor()) {
    return value;
  }
  return `${C[key]}${value}${C.reset}`;
}

function info(message) {
  console.log(`${color('[INFO]', 'cyan')} ${message}`);
}

function warn(message) {
  console.log(`${color('[WARN]', 'yellow')} ${message}`);
}

function ok(message) {
  console.log(`${color('[ OK ]', 'green')} ${message}`);
}

function fail(message) {
  console.error(`${color('[FAIL]', 'red')} ${message}`);
}

function parseDotEnv(filePath) {
  if (!fs.existsSync(filePath)) {
    return {};
  }

  const content = fs.readFileSync(filePath, 'utf8');
  const lines = content.split(/\r?\n/);
  const env = {};

  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }
    const idx = trimmed.indexOf('=');
    if (idx === -1) {
      continue;
    }
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    value = value.replace(/^['"]|['"]$/g, '');
    env[key] = value;
  }

  return env;
}

function bytesToHuman(bytes) {
  if (!Number.isFinite(bytes)) {
    return 'n/a';
  }
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unitIdx = 0;
  while (value >= 1024 && unitIdx < units.length - 1) {
    value /= 1024;
    unitIdx += 1;
  }
  return `${value.toFixed(unitIdx === 0 ? 0 : 2)} ${units[unitIdx]}`;
}

function extractVersion(fileName) {
  const base = path.basename(fileName, '.zip');
  const match = base.match(/(\d+(?:\.\d+)*(?:-[0-9a-z.-]+)?)/i);
  return match ? match[1].toLowerCase() : null;
}

function parseVersion(version) {
  if (!version) {
    return null;
  }
  const clean = version.toLowerCase().replace(/^v/, '');
  const match = clean.match(/^(\d+(?:\.\d+)*)(?:-([0-9a-z.-]+))?$/i);
  if (!match) {
    return null;
  }
  const core = match[1].split('.').map((part) => Number.parseInt(part, 10));
  const prerelease = match[2]
    ? match[2].split(/[.-]/).map((part) => (/^\d+$/.test(part) ? Number.parseInt(part, 10) : part))
    : [];
  return { core, prerelease };
}

function compareParsedSemver(a, b) {
  const maxCore = Math.max(a.core.length, b.core.length);
  for (let i = 0; i < maxCore; i += 1) {
    const av = a.core[i] ?? 0;
    const bv = b.core[i] ?? 0;
    if (av !== bv) {
      return av > bv ? 1 : -1;
    }
  }

  const aHasPre = a.prerelease.length > 0;
  const bHasPre = b.prerelease.length > 0;
  if (!aHasPre && !bHasPre) {
    return 0;
  }
  if (!aHasPre && bHasPre) {
    return 1;
  }
  if (aHasPre && !bHasPre) {
    return -1;
  }

  const maxPre = Math.max(a.prerelease.length, b.prerelease.length);
  for (let i = 0; i < maxPre; i += 1) {
    const av = a.prerelease[i];
    const bv = b.prerelease[i];
    if (av === undefined) {
      return -1;
    }
    if (bv === undefined) {
      return 1;
    }
    if (av === bv) {
      continue;
    }
    const avNum = typeof av === 'number';
    const bvNum = typeof bv === 'number';
    if (avNum && bvNum) {
      return av > bv ? 1 : -1;
    }
    if (avNum && !bvNum) {
      return -1;
    }
    if (!avNum && bvNum) {
      return 1;
    }
    return String(av).localeCompare(String(bv));
  }
  return 0;
}

function compareVersionData(a, b) {
  if (a.parsed && b.parsed) {
    return compareParsedSemver(a.parsed, b.parsed);
  }
  if (a.parsed && !b.parsed) {
    return 1;
  }
  if (!a.parsed && b.parsed) {
    return -1;
  }
  if (a.version && b.version) {
    return new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }).compare(a.version, b.version);
  }
  if (a.version && !b.version) {
    return 1;
  }
  if (!a.version && b.version) {
    return -1;
  }
  return 0;
}

const PLUGINS = [
  {
    id: 'bricks',
    label: 'Bricks',
    required: true,
    latestFile: 'bricks-latest.zip',
    envVar: 'BRICKS_LICENSE_KEY',
    matches: (fileName) =>
      /^bricks.*\.zip$/i.test(fileName) &&
      !/^bricks-child.*\.zip$/i.test(fileName) &&
      !/^bricks-latest\.zip$/i.test(fileName)
  },
  {
    id: 'frames',
    label: 'Frames',
    required: false,
    latestFile: 'frames-latest.zip',
    envVar: 'FRAMES_LICENSE_KEY',
    matches: (fileName) => /^frames.*\.zip$/i.test(fileName) && !/^frames-latest\.zip$/i.test(fileName)
  },
  {
    id: 'acss-v3',
    label: 'Automatic.css v3 (Bricks)',
    required: false,
    latestFile: 'acss-v3-latest.zip',
    envVar: 'ACSS_LICENSE_KEY',
    // Only match v3.x files (major version 3).
    matches: (fileName) => {
      if (!/^automatic.*\.zip$/i.test(fileName)) return false;
      if (/^acss-v\d+-latest\.zip$/i.test(fileName)) return false;
      const version = extractVersion(fileName);
      const parsed = parseVersion(version);
      return Boolean(parsed && parsed.core[0] === 3);
    }
  },
  {
    id: 'acss-v4',
    label: 'Automatic.css v4 (Etch)',
    required: false,
    latestFile: 'acss-v4-latest.zip',
    envVar: 'ACSS_LICENSE_KEY',
    // Only match v4.x files (major version 4).
    matches: (fileName) => {
      if (!/^automatic.*\.zip$/i.test(fileName)) return false;
      if (/^acss-v\d+-latest\.zip$/i.test(fileName)) return false;
      const version = extractVersion(fileName);
      const parsed = parseVersion(version);
      return Boolean(parsed && parsed.core[0] === 4);
    }
  },
  {
    id: 'etch',
    label: 'Etch',
    required: true,
    latestFile: 'etch-latest.zip',
    envVar: 'ETCH_LICENSE_KEY',
    matches: (fileName) =>
      /^etch-[0-9].*\.zip$/i.test(fileName) &&
      !/^etch-theme.*\.zip$/i.test(fileName) &&
      !/^etch-latest\.zip$/i.test(fileName)
  },
  {
    id: 'etch-theme',
    label: 'Etch Theme',
    required: true,
    latestFile: 'etch-theme-latest.zip',
    envVar: null,
    matches: (fileName) =>
      /^etch-theme.*\.zip$/i.test(fileName) &&
      !/^etch-theme-latest\.zip$/i.test(fileName)
  }
];

function formatTable(rows) {
  const headers = ['Plugin', 'Required', 'Version', 'Source ZIP', 'Latest ZIP', 'Status'];
  const widths = headers.map((header, index) =>
    Math.max(header.length, ...rows.map((row) => String(row[index]).length))
  );

  const divider = widths.map((w) => '-'.repeat(w)).join('-+-');
  const headerLine = headers.map((h, i) => h.padEnd(widths[i], ' ')).join(' | ');
  const dataLines = rows.map((row) =>
    row.map((value, i) => String(value).padEnd(widths[i], ' ')).join(' | ')
  );
  return [headerLine, divider, ...dataLines].join('\n');
}

function selectLatestCandidate(candidates) {
  const sorted = [...candidates].sort((a, b) => {
    const versionCmp = compareVersionData(a, b);
    if (versionCmp !== 0) {
      return -versionCmp;
    }
    if (a.mtimeMs !== b.mtimeMs) {
      return b.mtimeMs - a.mtimeMs;
    }
    return a.fileName.localeCompare(b.fileName);
  });
  return sorted[0] ?? null;
}

function removeOldLatestZipFiles(dryRun) {
  for (const plugin of PLUGINS) {
    const latestPath = path.join(LOCAL_PLUGINS_DIR, plugin.latestFile);
    if (!fs.existsSync(latestPath)) {
      continue;
    }
    if (dryRun) {
      info(`[dry-run] Would remove ${plugin.latestFile}`);
      continue;
    }
    fs.unlinkSync(latestPath);
    info(`Removed old ${plugin.latestFile}`);
  }
}

function showLicenseStatus() {
  info('Checking license key configuration...');
  if (!fs.existsSync(ENV_PATH)) {
    warn('No .env file found. Copy .env.example to .env and configure license keys.');
    return;
  }

  const env = parseDotEnv(ENV_PATH);
  const licenseChecks = [
    { key: 'BRICKS_LICENSE_KEY', required: true },
    { key: 'ETCH_LICENSE_KEY', required: true },
    { key: 'FRAMES_LICENSE_KEY', required: false },
    { key: 'ACSS_LICENSE_KEY', required: false }
  ];

  for (const entry of licenseChecks) {
    const configured = Boolean(env[entry.key]);
    const status = configured ? color('configured', 'green') : color('not set', 'yellow');
    const requiredLabel = entry.required ? 'required' : 'optional';
    console.log(`  - ${entry.key}: ${status} (${requiredLabel})`);
  }
}

function main() {
  const dryRun = process.argv.includes('--dry-run');

  info('Commercial plugin pre-flight started');
  if (dryRun) {
    warn('Dry-run mode enabled; no files will be changed.');
  }

  if (!fs.existsSync(LOCAL_PLUGINS_DIR)) {
    fail(`Missing directory: ${LOCAL_PLUGINS_DIR}`);
    console.error('Create local-plugins/ and place your plugin archives before running this script.');
    process.exit(1);
  }

  const allEntries = fs.readdirSync(LOCAL_PLUGINS_DIR, { withFileTypes: true });
  const zipFiles = allEntries
    .filter((entry) => entry.isFile() && /\.zip$/i.test(entry.name))
    .map((entry) => entry.name);

  info(`Found ${zipFiles.length} ZIP archive(s) in local-plugins/`);
  removeOldLatestZipFiles(dryRun);

  const results = [];
  const missingRequired = [];

  for (const plugin of PLUGINS) {
    const candidates = zipFiles
      .filter((fileName) => plugin.matches(fileName))
      .map((fileName) => {
        const fullPath = path.join(LOCAL_PLUGINS_DIR, fileName);
        const stats = fs.statSync(fullPath);
        const version = extractVersion(fileName);
        return {
          fileName,
          fullPath,
          bytes: stats.size,
          mtimeMs: stats.mtimeMs,
          version,
          parsed: parseVersion(version)
        };
      });

    const selected = selectLatestCandidate(candidates);
    let status = selected ? 'ready' : plugin.required ? 'missing required' : 'missing optional';

    if (selected) {
      const targetPath = path.join(LOCAL_PLUGINS_DIR, plugin.latestFile);
      if (dryRun) {
        info(`[dry-run] Would copy ${selected.fileName} -> ${plugin.latestFile}`);
      } else {
        fs.copyFileSync(selected.fullPath, targetPath);
        ok(`Created ${plugin.latestFile} from ${selected.fileName} (${bytesToHuman(selected.bytes)})`);
      }
    } else if (plugin.required) {
      missingRequired.push(plugin.label);
    } else {
      warn(`${plugin.label} not found (optional)`);
    }

    results.push({
      plugin: plugin.label,
      required: plugin.required ? 'yes' : 'no',
      version: selected?.version ?? 'n/a',
      source: selected?.fileName ?? 'n/a',
      latest: plugin.latestFile,
      status
    });
  }

  console.log('\nPlugin Detection Report');
  console.log(formatTable(results.map((row) => [row.plugin, row.required, row.version, row.source, row.latest, row.status])));

  console.log('');
  showLicenseStatus();
  console.log('');
  info('License key template: .env.example');

  if (missingRequired.length > 0) {
    fail(`Missing required plugin archives: ${missingRequired.join(', ')}`);
    console.error('Add the required ZIP files to local-plugins/ and rerun npm run setup:commercial-plugins');
    process.exit(1);
  }

  ok('Commercial plugin setup complete.');
  process.exit(0);
}

main();
