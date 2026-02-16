#!/usr/bin/env node

// DEPRECATED: WPvivid is no longer part of the standard dev environment.
// This script is kept for users who still have WPvivid backups to import.
// It will be removed in a future release.
console.warn('[DEPRECATED] import-wpvivid-backup.js — WPvivid has been removed from the dev environment.');
console.warn('This script is provided for legacy backup imports only.\n');

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');

const ROOT_DIR = path.join(__dirname, '..');
const BACKUP_DIR = path.join(ROOT_DIR, 'local-backups');
const ENV_PATH = path.join(ROOT_DIR, '.env');
const CONTAINER_IMPORT_DIR = '/tmp/wpvivid-import';

function parseDotEnv(filePath) {
  if (!fs.existsSync(filePath)) {
    return {};
  }
  const env = {};
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
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

function run(command, args, options = {}) {
  return new Promise((resolve) => {
    const child = spawn(command, args, {
      shell: process.platform === 'win32',
      stdio: 'pipe',
      ...options
    });

    let stdout = '';
    let stderr = '';

    child.stdout?.on('data', (chunk) => {
      stdout += chunk.toString();
    });
    child.stderr?.on('data', (chunk) => {
      stderr += chunk.toString();
    });
    child.on('close', (code) => resolve({ code, stdout, stderr }));
  });
}

function parseWpvividPart(fileName) {
  const regex =
    /^(?<site>.+?)_wpvivid-(?<backupId>[^_]+)_(?<dateRaw>\d{4}-\d{2}-\d{2}(?:-\d{2}-\d{2}(?:-\d{2})?)?)_backup_(?<scope>.+?)\.part(?<part>\d+)\.zip$/i;
  const match = fileName.match(regex);
  if (!match || !match.groups) {
    return null;
  }
  return {
    fileName,
    site: match.groups.site,
    backupId: match.groups.backupId,
    dateRaw: match.groups.dateRaw,
    scope: match.groups.scope,
    partNumber: Number.parseInt(match.groups.part, 10)
  };
}

function parseBackupDate(dateRaw) {
  const parts = dateRaw.split('-').map((p) => Number.parseInt(p, 10));
  if (parts.length >= 5) {
    const [year, month, day, hour, minute, second = 0] = parts;
    return new Date(Date.UTC(year, month - 1, day, hour, minute, second));
  }
  if (parts.length === 3) {
    const [year, month, day] = parts;
    return new Date(Date.UTC(year, month - 1, day, 0, 0, 0));
  }
  return new Date(0);
}

function bytesToHuman(bytes) {
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let index = 0;
  while (value >= 1024 && index < units.length - 1) {
    value /= 1024;
    index += 1;
  }
  return `${value.toFixed(index === 0 ? 0 : 2)} ${units[index]}`;
}

function scanBackups() {
  if (!fs.existsSync(BACKUP_DIR)) {
    return [];
  }

  const files = fs
    .readdirSync(BACKUP_DIR, { withFileTypes: true })
    .filter((entry) => entry.isFile() && /\.zip$/i.test(entry.name))
    .map((entry) => entry.name);

  const groups = new Map();
  for (const fileName of files) {
    const parsed = parseWpvividPart(fileName);
    if (!parsed) {
      continue;
    }
    const fullPath = path.join(BACKUP_DIR, fileName);
    const stats = fs.statSync(fullPath);
    const key = `${parsed.site}|${parsed.backupId}|${parsed.dateRaw}|${parsed.scope}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        site: parsed.site,
        backupId: parsed.backupId,
        dateRaw: parsed.dateRaw,
        scope: parsed.scope,
        date: parseBackupDate(parsed.dateRaw),
        files: []
      });
    }
    groups.get(key).files.push({
      fileName,
      fullPath,
      partNumber: parsed.partNumber,
      size: stats.size
    });
  }

  const backups = Array.from(groups.values()).map((group) => {
    const filesSorted = [...group.files].sort((a, b) => a.partNumber - b.partNumber);
    const partNumbers = filesSorted.map((f) => f.partNumber);
    const minPart = Math.min(...partNumbers);
    const maxPart = Math.max(...partNumbers);
    const missingParts = [];
    for (let p = minPart; p <= maxPart; p += 1) {
      if (!partNumbers.includes(p)) {
        missingParts.push(p);
      }
    }
    const zeroByteParts = filesSorted.filter((file) => file.size === 0).map((file) => file.partNumber);
    return {
      ...group,
      files: filesSorted,
      minPart,
      maxPart,
      missingParts,
      zeroByteParts,
      complete: minPart === 1 && missingParts.length === 0 && zeroByteParts.length === 0,
      totalSize: filesSorted.reduce((sum, f) => sum + f.size, 0)
    };
  });

  backups.sort((a, b) => {
    if (a.date.getTime() !== b.date.getTime()) {
      return b.date.getTime() - a.date.getTime();
    }
    return a.backupId.localeCompare(b.backupId);
  });

  return backups;
}

function parseArgs() {
  const args = process.argv.slice(2);
  const parsed = { selector: null, index: null, dryRun: false };
  for (const arg of args) {
    if (arg === '--dry-run') {
      parsed.dryRun = true;
      continue;
    }
    if (arg.startsWith('--id=')) {
      parsed.selector = arg.slice('--id='.length);
      continue;
    }
    if (arg.startsWith('--index=')) {
      parsed.index = Number.parseInt(arg.slice('--index='.length), 10);
      continue;
    }
    if (!arg.startsWith('--') && !parsed.selector) {
      parsed.selector = arg;
    }
  }
  return parsed;
}

function selectBackup(backups, args) {
  if (Number.isFinite(args.index) && args.index >= 1 && args.index <= backups.length) {
    return backups[args.index - 1];
  }
  if (!args.selector) {
    return backups[0] ?? null;
  }
  return (
    backups.find((backup) => backup.backupId === args.selector) ||
    backups.find((backup) => backup.key === args.selector) ||
    backups.find((backup) => backup.files.some((file) => file.fileName === args.selector)) ||
    null
  );
}

async function detectCliContainer() {
  const result = await run('docker', ['ps', '--format', '{{.Names}}']);
  if (result.code !== 0) {
    return null;
  }
  const names = result.stdout
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  return (
    names.find((name) => name === 'bricks-cli') ||
    names.find((name) => /bricks.*cli/i.test(name)) ||
    names.find((name) => /wp-env.*cli/i.test(name)) ||
    names.find((name) => /wpenv.*cli/i.test(name)) ||
    names.find((name) => /etch.*cli/i.test(name)) ||
    null
  );
}

async function copyBackupToContainer(containerName, backup, dryRun) {
  const targetDir = `${CONTAINER_IMPORT_DIR}/${backup.backupId}`;
  if (dryRun) {
    console.log(`[dry-run] docker exec ${containerName} mkdir -p ${targetDir}`);
    for (const part of backup.files) {
      console.log(`[dry-run] docker cp "${part.fullPath}" "${containerName}:${targetDir}/${part.fileName}"`);
    }
    return true;
  }

  const mkdirResult = await run('docker', ['exec', containerName, 'mkdir', '-p', targetDir]);
  if (mkdirResult.code !== 0) {
    console.error(`Failed to prepare container import directory: ${mkdirResult.stderr || mkdirResult.stdout}`);
    return false;
  }

  for (const part of backup.files) {
    process.stdout.write(`Copying ${part.fileName} ... `);
    const copyResult = await run('docker', ['cp', part.fullPath, `${containerName}:${targetDir}/${part.fileName}`]);
    if (copyResult.code !== 0) {
      console.log('FAILED');
      console.error(copyResult.stderr || copyResult.stdout);
      return false;
    }
    console.log('OK');
  }

  return true;
}

async function wpvividCliAvailable() {
  const result = await run('npx', ['wp-env', 'run', 'cli', 'wp', 'help', 'wpvivid']);
  return result.code === 0;
}

async function attemptAutoImport(backup) {
  const firstPart = backup.files[0]?.fileName;
  if (!firstPart) {
    return false;
  }

  const candidates = [
    ['wpvivid', 'restore', firstPart],
    ['wpvivid', 'import', firstPart]
  ];

  for (const commandArgs of candidates) {
    const result = await run('npx', ['wp-env', 'run', 'cli', 'wp', ...commandArgs]);
    if (result.code === 0) {
      console.log(`Automated import command succeeded: wp ${commandArgs.join(' ')}`);
      return true;
    }
  }

  return false;
}

async function main() {
  const args = parseArgs();
  const env = parseDotEnv(ENV_PATH);
  const autoImport = String(env.WPVIVID_AUTO_IMPORT || 'false').toLowerCase() === 'true';

  console.log('Scanning local-backups/ for WPvivid multipart backups...');
  if (!fs.existsSync(BACKUP_DIR)) {
    console.error(`Directory not found: ${BACKUP_DIR}`);
    process.exit(1);
  }

  const backups = scanBackups();
  if (backups.length === 0) {
    console.log('No matching backups found.');
    console.log('Expected pattern: *_wpvivid-*_*_backup_*.part*.zip');
    process.exit(0);
  }

  console.log(`Detected ${backups.length} backup set(s):`);
  backups.forEach((backup, index) => {
    const marker = index === 0 ? '*' : ' ';
    console.log(
      `${marker} [${index + 1}] ${backup.site} | ${backup.backupId} | ${backup.dateRaw} | parts=${backup.files.length} | size=${bytesToHuman(backup.totalSize)} | ${backup.complete ? 'complete' : 'incomplete'}`
    );
  });

  const selected = selectBackup(backups, args);
  if (!selected) {
    console.error('Unable to select backup. Use --id=<backupId> or --index=<N>.');
    process.exit(1);
  }

  console.log('\nSelected backup:');
  console.log(`- Site: ${selected.site}`);
  console.log(`- Backup ID: ${selected.backupId}`);
  console.log(`- Date: ${selected.dateRaw}`);
  console.log(`- Scope: ${selected.scope}`);
  console.log(`- Parts: ${selected.files.length} (range ${selected.minPart}-${selected.maxPart})`);
  console.log(`- Size: ${bytesToHuman(selected.totalSize)}`);

  if (!selected.complete) {
    console.error('\nValidation failed: backup is incomplete or contains invalid part files.');
    if (selected.minPart !== 1) {
      console.error(`- Expected part sequence to start at 1 but starts at ${selected.minPart}.`);
    }
    if (selected.missingParts.length > 0) {
      console.error(`- Missing parts: ${selected.missingParts.join(', ')}`);
    }
    if (selected.zeroByteParts.length > 0) {
      console.error(`- Zero-byte parts: ${selected.zeroByteParts.join(', ')}`);
    }
    console.error('Troubleshooting: redownload all backup parts and verify the naming pattern.');
    process.exit(1);
  }

  const containerName = await detectCliContainer();
  if (!containerName) {
    console.warn('\nCould not detect a running wp-env CLI container. Skipping copy step.');
  } else {
    console.log(`\nUsing container: ${containerName}`);
    const copied = await copyBackupToContainer(containerName, selected, args.dryRun);
    if (!copied) {
      console.error('Copy step failed. You can rerun with --dry-run to inspect commands.');
      process.exit(1);
    }
    console.log(`Backup parts copied to ${containerName}:${CONTAINER_IMPORT_DIR}/${selected.backupId}`);
  }

  const cliAvailable = await wpvividCliAvailable();
  console.log(`\nWPvivid CLI support detected: ${cliAvailable ? 'yes' : 'no'}`);

  if (autoImport && cliAvailable && !args.dryRun) {
    console.log('WPVIVID_AUTO_IMPORT=true detected. Attempting automated import...');
    const autoImported = await attemptAutoImport(selected);
    if (!autoImported) {
      console.warn('Automated import attempt did not succeed. Continue with manual admin import.');
    }
  } else if (autoImport && !cliAvailable) {
    console.warn('WPVIVID_AUTO_IMPORT=true is set, but no WPvivid WP-CLI command was detected.');
  }

  console.log('\nManual import steps:');
  console.log('1. Ensure WPvivid plugin is installed and active in the Bricks environment.');
  console.log('2. Open http://localhost:8888/wp-admin/admin.php?page=WPvivid in your browser.');
  console.log('3. Navigate to Backup & Restore and locate/import the copied backup files.');
  console.log('4. Restore only the intended custom content backup set.');
  console.log('\nHelpful commands:');
  console.log('- npm run backup:list');
  console.log('- npm run backup:info -- <backup-id>');
  console.log('- npx wp-env run cli wp plugin list');
  if (containerName) {
    console.log(`- docker exec ${containerName} sh -lc "ls -lh ${CONTAINER_IMPORT_DIR}/${selected.backupId}"`);
  }
}

main().catch((error) => {
  console.error(`Unexpected error: ${error.message}`);
  process.exit(1);
});
