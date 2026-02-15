#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

const ROOT_DIR = path.join(__dirname, '..');
const BACKUP_DIR = path.join(ROOT_DIR, 'local-backups');

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
      size: stats.size,
      modified: stats.mtime
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

function pickBackup(backups, selector) {
  if (!selector) {
    return backups[0] ?? null;
  }

  const normalized = selector.trim();
  const byIndex = Number.parseInt(normalized, 10);
  if (Number.isFinite(byIndex) && byIndex >= 1 && byIndex <= backups.length) {
    return backups[byIndex - 1];
  }

  return (
    backups.find((backup) => backup.backupId === normalized) ||
    backups.find((backup) => backup.key === normalized) ||
    backups.find((backup) => backup.files.some((file) => file.fileName === normalized)) ||
    null
  );
}

function main() {
  if (!fs.existsSync(BACKUP_DIR)) {
    console.error(`Directory not found: ${BACKUP_DIR}`);
    process.exit(1);
  }

  const backups = scanBackups();
  if (backups.length === 0) {
    console.log('No WPvivid backups found in local-backups/.');
    process.exit(0);
  }

  const selector = process.argv.slice(2).join(' ').trim();
  const selected = pickBackup(backups, selector);
  if (!selected) {
    console.error('Backup not found. Provide backup id, index, full key, or a filename.');
    console.error('Example: npm run backup:info -- abc123');
    process.exit(1);
  }

  console.log('WPvivid Backup Details');
  console.log(`Site: ${selected.site}`);
  console.log(`Backup ID: ${selected.backupId}`);
  console.log(`Date: ${selected.dateRaw}`);
  console.log(`Scope: ${selected.scope}`);
  console.log(`Total size: ${bytesToHuman(selected.totalSize)}`);
  console.log(`Part range: ${selected.minPart}-${selected.maxPart}`);
  console.log(`Part count: ${selected.files.length}`);
  console.log(`Validation: ${selected.complete ? 'PASS' : 'WARN'}`);

  if (selected.missingParts.length > 0) {
    console.log(`Missing parts: ${selected.missingParts.join(', ')}`);
  }
  if (selected.zeroByteParts.length > 0) {
    console.log(`Zero-byte parts: ${selected.zeroByteParts.join(', ')}`);
  }

  console.log('\nFiles:');
  for (const file of selected.files) {
    console.log(
      `- part${String(file.partNumber).padStart(3, '0')}: ${file.fileName} (${bytesToHuman(file.size)}, ${file.modified.toISOString()})`
    );
  }

  console.log('\nImport guidance:');
  console.log('1. Ensure wp-env is running: npm run dev');
  console.log('2. Copy parts into the cli container with npm run import:wpvivid');
  console.log('3. In WordPress admin (Bricks env), open WPvivid Backup -> Backup & Restore.');
  console.log('4. Run the restore from the uploaded/detected backup set.');
}

main();
