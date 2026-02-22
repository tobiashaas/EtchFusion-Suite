#!/usr/bin/env node

const { spawnSync } = require('child_process');
const path = require('path');

const CWD = path.resolve(__dirname, '..');
const ETCH_DEFAULT_OPTIONS = ['etch_styles', 'etch_global_stylesheets', 'etch_loops'];

function runWpEtch(args, { allowFailure = false } = {}) {
  const isWin = process.platform === 'win32';
  const command = isWin ? 'cmd' : 'npx';
  const commandArgs = isWin
    ? ['/c', 'npx', 'wp-env', 'run', 'tests-cli', 'wp', ...args]
    : ['wp-env', 'run', 'tests-cli', 'wp', ...args];
  const result = spawnSync(command, commandArgs, {
    cwd: CWD,
    encoding: 'utf8',
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0 && !allowFailure) {
    const stderr = (result.stderr || '').trim();
    const stdout = (result.stdout || '').trim();
    throw new Error(stderr || stdout || `wp ${args.join(' ')} failed`);
  }

  return {
    status: result.status || 0,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
  };
}

function getEtchPageIds() {
  const { stdout } = runWpEtch(['post', 'list', '--post_type=page', '--format=ids']);
  if (!stdout) {
    return [];
  }

  return stdout
    .split(/\s+/)
    .map((value) => value.trim())
    .filter((value) => /^\d+$/.test(value));
}

function deletePages(pageIds) {
  if (!Array.isArray(pageIds) || pageIds.length === 0) {
    return 0;
  }

  let deleted = 0;
  for (const pageId of pageIds) {
    runWpEtch(['post', 'delete', pageId, '--force']);
    deleted += 1;
  }

  return deleted;
}

function deleteOptions(optionKeys) {
  const removed = [];
  const missing = [];

  for (const optionKey of optionKeys) {
    const result = runWpEtch(['option', 'delete', optionKey], { allowFailure: true });
    const combined = `${result.stdout}\n${result.stderr}`;
    if (result.status === 0 && /Success:/i.test(combined)) {
      removed.push(optionKey);
      continue;
    }

    if (/Could not delete|does not exist|No such option/i.test(combined)) {
      missing.push(optionKey);
      continue;
    }

    if (result.status !== 0) {
      throw new Error(`Failed to delete option "${optionKey}": ${combined.trim()}`);
    }
  }

  return { removed, missing };
}

function main() {
  console.log('[EFS] Resetting Etch migration state...');

  const beforePageIds = getEtchPageIds();
  const deletedCount = deletePages(beforePageIds);
  const afterPageIds = getEtchPageIds();

  const { removed, missing } = deleteOptions(ETCH_DEFAULT_OPTIONS);

  console.log(`[EFS] Pages before: ${beforePageIds.length}`);
  console.log(`[EFS] Pages deleted: ${deletedCount}`);
  console.log(`[EFS] Pages after: ${afterPageIds.length}`);
  console.log(`[EFS] Options removed: ${removed.join(', ') || '(none)'}`);
  console.log(`[EFS] Options already missing: ${missing.join(', ') || '(none)'}`);

  if (afterPageIds.length > 0) {
    console.log(`[EFS] Remaining page IDs: ${afterPageIds.join(' ')}`);
  }
}

main();

