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
    return {
      ...group,
      files: filesSorted,
      minPart,
      maxPart,
      missingParts,
      complete: minPart === 1 && missingParts.length === 0,
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

function main() {
  console.log('Scanning local-backups/ for WPvivid backup parts...');

  if (!fs.existsSync(BACKUP_DIR)) {
    console.error(`Directory not found: ${BACKUP_DIR}`);
    process.exit(1);
  }

  const backups = scanBackups();
  if (backups.length === 0) {
    console.log('No WPvivid multipart backups found.');
    console.log('Expected pattern: *_wpvivid-*_*_backup_*.part*.zip');
    process.exit(0);
  }

  console.log(`Found ${backups.length} backup set(s).\n`);
  backups.forEach((backup, index) => {
    const latest = index === 0 ? ' (latest)' : '';
    const status = backup.complete ? 'complete' : `incomplete (missing: ${backup.missingParts.join(', ') || 'none'})`;
    console.log(`[${index + 1}] ${backup.site} | ${backup.backupId}${latest}`);
    console.log(`    Date: ${backup.dateRaw}`);
    console.log(`    Scope: ${backup.scope}`);
    console.log(`    Parts: ${backup.files.length} (range ${backup.minPart}-${backup.maxPart})`);
    console.log(`    Size: ${bytesToHuman(backup.totalSize)}`);
    console.log(`    Status: ${status}`);
  });
}

main();
