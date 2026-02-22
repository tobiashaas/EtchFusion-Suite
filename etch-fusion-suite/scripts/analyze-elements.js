#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const CWD = path.resolve(__dirname, '..');
const FACTORY_PATH = path.resolve(CWD, 'includes/converters/class-element-factory.php');
const BRICKS_META_KEY = '_bricks_page_content_2';
const SUPPORTED_ICON = 'OK';
const UNSUPPORTED_ICON = 'NO';
function runWpEnv(args, allowFailure = false) {
  const isWin = process.platform === 'win32';
  const result = spawnSync(
    isWin ? 'cmd' : 'npx',
    isWin ? ['/c', 'npx', 'wp-env', ...args] : ['wp-env', ...args],
    { encoding: 'utf8', cwd: CWD }
  );

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0 && !allowFailure) {
    throw new Error(result.stderr || result.stdout || `Command failed: ${args.join(' ')}`);
  }

  return {
    status: result.status ?? 1,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim()
  };
}

function runWpCli(environmentArgs, commandArgs, allowFailure = false) {
  return runWpEnv(['run', ...environmentArgs, 'wp', ...commandArgs], allowFailure);
}

function safeJsonParse(value) {
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

function extractFirstJsonValue(raw) {
  const input = String(raw || '').trim();
  if (!input) {
    return null;
  }

  const direct = safeJsonParse(input);
  if (direct !== null) {
    return direct;
  }

  const starts = [input.indexOf('{'), input.indexOf('[')].filter((index) => index >= 0);
  if (starts.length === 0) {
    return null;
  }

  const start = Math.min(...starts);
  const stack = [];
  let inString = false;
  let escaping = false;

  for (let i = start; i < input.length; i += 1) {
    const char = input[i];

    if (inString) {
      if (escaping) {
        escaping = false;
      } else if (char === '\\') {
        escaping = true;
      } else if (char === '"') {
        inString = false;
      }
      continue;
    }

    if (char === '"') {
      inString = true;
      continue;
    }

    if (char === '{' || char === '[') {
      stack.push(char);
      continue;
    }

    if (char === '}' || char === ']') {
      const expected = char === '}' ? '{' : '[';
      if (stack.length === 0 || stack[stack.length - 1] !== expected) {
        return null;
      }
      stack.pop();
      if (stack.length === 0) {
        return safeJsonParse(input.slice(start, i + 1));
      }
    }
  }

  return null;
}

function loadTypeMap() {
  const source = fs.readFileSync(FACTORY_PATH, 'utf8');
  const match = source.match(/TYPE_MAP\s*=\s*array\(([\s\S]*?)\);\s*/);
  if (!match || !match[1]) {
    throw new Error('Unable to locate TYPE_MAP in class-element-factory.php');
  }

  const typeMap = new Map();
  const rowPattern = /'([^']+)'\s*=>\s*([A-Za-z0-9_\\]+)::class\s*,/g;
  let row = rowPattern.exec(match[1]);
  while (row) {
    typeMap.set(row[1], row[2]);
    row = rowPattern.exec(match[1]);
  }

  return typeMap;
}

function normalizePostTypes(postTypes) {
  if (!Array.isArray(postTypes) || postTypes.length === 0) {
    return ['post', 'page'];
  }

  const normalized = postTypes
    .map((value) => String(value || '').trim())
    .filter(Boolean);

  return normalized.length > 0 ? normalized : ['post', 'page'];
}

function listPostsWithContentCandidates(postTypes = ['post', 'page'], limit = null) {
  const normalizedPostTypes = normalizePostTypes(postTypes);

  const result = runWpCli(
    ['cli'],
    [
      'post',
      'list',
      `--post_type=${normalizedPostTypes.join(',')}`,
      '--posts_per_page=-1',
      '--format=json',
      '--fields=ID,post_type,post_title'
    ]
  );

  const posts = extractFirstJsonValue(result.stdout);
  if (!Array.isArray(posts)) {
    throw new Error(`Unable to parse wp post list output: ${result.stdout || result.stderr}`);
  }

  const normalizedPosts = posts
    .map((post) => ({
      id: Number.parseInt(post.ID, 10),
      postType: String(post.post_type || ''),
      title: String(post.post_title || '')
    }))
    .filter((post) => Number.isFinite(post.id) && post.id > 0);

  if (Number.isFinite(limit) && limit > 0) {
    return normalizedPosts.slice(0, limit);
  }

  return normalizedPosts;
}

function loadBricksMeta(postId) {
  const result = runWpCli(['cli'], ['post', 'meta', 'get', String(postId), BRICKS_META_KEY, '--format=json'], true);

  if (result.status !== 0) {
    return null;
  }

  return extractFirstJsonValue(result.stdout);
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function walkBricksTree(node, context) {
  const { countByType, postIdsByType, currentPostId, seen } = context;

  if (!node) {
    return 0;
  }

  if (Array.isArray(node)) {
    let count = 0;
    for (const child of node) {
      count += walkBricksTree(child, context);
    }
    return count;
  }

  if (!isObject(node)) {
    return 0;
  }

  if (seen.has(node)) {
    return 0;
  }
  seen.add(node);

  let nodesFound = 0;

  if (typeof node.name === 'string' && node.name.trim() !== '') {
    const type = node.name.trim();
    countByType.set(type, (countByType.get(type) || 0) + 1);
    if (!postIdsByType.has(type)) {
      postIdsByType.set(type, new Set());
    }
    postIdsByType.get(type).add(currentPostId);
    nodesFound += 1;
  }

  for (const value of Object.values(node)) {
    if (Array.isArray(value) || isObject(value)) {
      nodesFound += walkBricksTree(value, context);
    }
  }

  return nodesFound;
}

function toRows(countByType, postIdsByType, typeMap) {
  return Array.from(countByType.entries())
    .map(([elementType, count]) => {
      const converterClass = typeMap.get(elementType) || '-';
      const supported = typeMap.has(elementType);
      const postIds = Array.from(postIdsByType.get(elementType) || []).sort((a, b) => a - b);
      return {
        elementType,
        count,
        supportStatus: supported ? SUPPORTED_ICON : UNSUPPORTED_ICON,
        supported,
        converterClass,
        postIds
      };
    })
    .sort((a, b) => b.count - a.count || a.elementType.localeCompare(b.elementType));
}

function pad(value, width) {
  const text = String(value);
  return text.length >= width ? text : `${text}${' '.repeat(width - text.length)}`;
}

function renderTable(rows) {
  const typeWidth = Math.max('Element Type'.length, ...rows.map((row) => row.elementType.length));
  const countWidth = Math.max('Count'.length, ...rows.map((row) => String(row.count).length));
  const statusWidth = Math.max('Status'.length, ...rows.map((row) => row.supportStatus.length));
  const converterWidth = Math.max('Converter Class'.length, ...rows.map((row) => row.converterClass.length));

  const header = `${pad('Element Type', typeWidth)} | ${pad('Count', countWidth)} | ${pad('Status', statusWidth)} | ${pad('Converter Class', converterWidth)}`;
  const divider = `${'-'.repeat(typeWidth)}-+-${'-'.repeat(countWidth)}-+-${'-'.repeat(statusWidth)}-+-${'-'.repeat(converterWidth)}`;
  const lines = [header, divider];

  for (const row of rows) {
    lines.push(
      `${pad(row.elementType, typeWidth)} | ${pad(row.count, countWidth)} | ${pad(row.supportStatus, statusWidth)} | ${pad(row.converterClass, converterWidth)}`
    );
  }

  return lines.join('\n');
}

function summarize(rows, postsScanned, postsWithBricksData, postsWithParseErrors) {
  const totalElements = rows.reduce((sum, row) => sum + row.count, 0);
  const supportedOccurrences = rows.filter((row) => row.supported).reduce((sum, row) => sum + row.count, 0);
  const unsupportedOccurrences = totalElements - supportedOccurrences;
  const supportedTypeCount = rows.filter((row) => row.supported).length;
  const unsupportedTypeCount = rows.length - supportedTypeCount;
  const coverageRate = totalElements > 0 ? (supportedOccurrences / totalElements) * 100 : 100;

  return {
    postsScanned,
    postsWithBricksData,
    postsWithoutBricksData: postsScanned - postsWithBricksData,
    postsWithParseErrors,
    totalElements,
    distinctElementTypes: rows.length,
    supportedTypeCount,
    unsupportedTypeCount,
    supportedOccurrences,
    unsupportedOccurrences,
    coverageRate,
    // Compatibility aliases for older callers.
    supportedTypes: supportedTypeCount,
    unsupportedTypes: unsupportedTypeCount,
    supportedElements: supportedOccurrences,
    unsupportedElements: unsupportedOccurrences,
    supportedPercentage: coverageRate
  };
}

function parseArgs(argv) {
  const parsed = {
    postTypes: ['post', 'page'],
    limit: null,
    quiet: false,
    help: false,
    json: false
  };

  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') {
      parsed.help = true;
      continue;
    }
    if (arg === '--quiet') {
      parsed.quiet = true;
      continue;
    }
    if (arg === '--json') {
      parsed.json = true;
      continue;
    }
    if (arg.startsWith('--post-types=')) {
      const value = arg.slice('--post-types='.length).trim();
      const postTypes = value
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
      if (postTypes.length > 0) {
        parsed.postTypes = postTypes;
      }
      continue;
    }
    if (arg.startsWith('--limit=')) {
      const value = Number.parseInt(arg.slice('--limit='.length), 10);
      if (Number.isFinite(value) && value > 0) {
        parsed.limit = value;
      }
    }
  }

  return parsed;
}

function printHelp() {
  console.log('Analyze Bricks element usage from _bricks_page_content_2.\n');
  console.log('Usage: node scripts/analyze-elements.js [options]\n');
  console.log('Options:');
  console.log('  --post-types=post,page   Comma-separated post types (default: post,page)');
  console.log('  --limit=100              Limit analyzed posts/pages');
  console.log('  --json                   Print JSON output');
  console.log('  --quiet                  Suppress table output');
  console.log('  --help, -h               Show this help');
}

function analyzeElements(options = {}) {
  const normalizedOptions = {
    postTypes: normalizePostTypes(options.postTypes),
    limit: Number.isFinite(options.limit) && options.limit > 0 ? options.limit : null
  };

  const typeMap = loadTypeMap();
  const posts = listPostsWithContentCandidates(normalizedOptions.postTypes, normalizedOptions.limit);
  const countByType = new Map();
  const postIdsByType = new Map();
  let postsWithBricksData = 0;
  let postsWithParseErrors = 0;

  for (const post of posts) {
    const meta = loadBricksMeta(post.id);
    if (meta === null) {
      continue;
    }

    postsWithBricksData += 1;

    const nodesFound = walkBricksTree(meta, {
      countByType,
      postIdsByType,
      currentPostId: post.id,
      seen: new WeakSet()
    });

    if (nodesFound === 0) {
      postsWithParseErrors += 1;
    }
  }

  const rows = toRows(countByType, postIdsByType, typeMap);
  const unsupportedTypes = rows
    .filter((row) => !row.supported)
    .map((row) => ({
      type: row.elementType,
      count: row.count,
      postIds: row.postIds
    }));
  const summary = summarize(rows, posts.length, postsWithBricksData, postsWithParseErrors);

  return {
    rows,
    unsupportedTypes,
    summary
  };
}

function printSummary(summary) {
  console.log('\nSummary');
  console.log(`- Posts scanned: ${summary.postsScanned}`);
  console.log(`- Posts with Bricks data: ${summary.postsWithBricksData}`);
  console.log(`- Posts without Bricks data: ${summary.postsWithoutBricksData}`);
  console.log(`- Posts with parse errors: ${summary.postsWithParseErrors}`);
  console.log(`- Total element occurrences: ${summary.totalElements}`);
  console.log(`- Distinct element types: ${summary.distinctElementTypes}`);
  console.log(`- Supported element types used: ${summary.supportedTypeCount}`);
  console.log(`- Unsupported element types used: ${summary.unsupportedTypeCount}`);
  console.log(`- Supported element occurrences: ${summary.supportedOccurrences}`);
  console.log(`- Unsupported element occurrences: ${summary.unsupportedOccurrences}`);
  console.log(`- Supported percentage: ${summary.coverageRate.toFixed(2)}%`);
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    printHelp();
    process.exit(0);
  }

  try {
    const result = analyzeElements({
      postTypes: args.postTypes,
      limit: args.limit
    });

    if (args.json) {
      console.log(JSON.stringify(result, null, 2));
      process.exit(0);
    }

    const { rows, summary } = result;

    if (!args.quiet) {
      console.log('Bricks Element Type Distribution\n');
      if (rows.length === 0) {
        console.log(`No Bricks elements found in ${BRICKS_META_KEY}.`);
      } else {
        console.log(renderTable(rows));
      }
    }

    printSummary(summary);
    process.exit(0);
  } catch (error) {
    console.error(`ERROR analyze-elements failed: ${error.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = {
  analyzeElements,
  loadTypeMap,
  parseArgs
};
