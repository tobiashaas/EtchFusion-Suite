#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { analyzeElements } = require('./analyze-elements');

const ROOT_DIR = path.resolve(__dirname, '..');
const DEFAULT_OUTPUT_DIR = path.join(ROOT_DIR, 'reports');

function runWpEnv(args, allowFailure = false) {
  const isWin = process.platform === 'win32';
  const result = spawnSync(
    isWin ? 'cmd' : 'npx',
    isWin ? ['/c', 'npx', 'wp-env', ...args] : ['wp-env', ...args],
    { encoding: 'utf8', cwd: ROOT_DIR }
  );

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0 && !allowFailure) {
    throw new Error(result.stderr || result.stdout || `Command failed: wp-env ${args.join(' ')}`);
  }

  return {
    status: result.status ?? 1,
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim()
  };
}

function runWpCli(environment, args, allowFailure = false) {
  return runWpEnv(['run', environment, 'wp', ...args], allowFailure);
}

function tryParseJson(value) {
  try {
    return { parsed: true, value: JSON.parse(value) };
  } catch (error) {
    return { parsed: false, error };
  }
}

function extractFirstJsonValue(text) {
  const input = (text || '').trim();
  if (!input) {
    return null;
  }

  const direct = tryParseJson(input);
  if (direct.parsed) {
    return direct.value;
  }

  const firstBrace = input.indexOf('{');
  const firstBracket = input.indexOf('[');
  const starts = [firstBrace, firstBracket].filter((index) => index >= 0);
  if (starts.length === 0) {
    return null;
  }

  const start = Math.min(...starts);
  let inString = false;
  let escape = false;
  const stack = [];

  for (let i = start; i < input.length; i += 1) {
    const char = input[i];

    if (inString) {
      if (escape) {
        escape = false;
      } else if (char === '\\') {
        escape = true;
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
        const snippet = input.slice(start, i + 1);
        const parsed = tryParseJson(snippet);
        return parsed.parsed ? parsed.value : null;
      }
    }
  }

  return null;
}

function parseNumberFromOutput(output) {
  const input = (output || '').trim();
  if (!input) {
    return 0;
  }

  const numeric = Number.parseInt(input, 10);
  if (Number.isFinite(numeric)) {
    return numeric;
  }

  const match = input.match(/(-?\d+)/);
  if (!match) {
    return 0;
  }
  return Number.parseInt(match[1], 10);
}

function safePercent(numerator, denominator) {
  if (!Number.isFinite(denominator) || denominator <= 0) {
    return null;
  }
  return (numerator / denominator) * 100;
}

function parseArgs(argv) {
  const parsed = {
    postTypes: ['post', 'page'],
    limit: null,
    outputDir: DEFAULT_OUTPUT_DIR,
    skipElementAnalysis: false,
    help: false
  };

  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') {
      parsed.help = true;
      continue;
    }
    if (arg === '--skip-element-analysis') {
      parsed.skipElementAnalysis = true;
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
      continue;
    }
    if (arg.startsWith('--output-dir=')) {
      const value = arg.slice('--output-dir='.length).trim();
      if (value) {
        parsed.outputDir = path.isAbsolute(value) ? value : path.resolve(ROOT_DIR, value);
      }
    }
  }

  return parsed;
}

function printHelp() {
  console.log('Generate a migration quality report for real Bricks data.\n');
  console.log('Usage: node scripts/migration-quality-report.js [options]\n');
  console.log('Options:');
  console.log('  --post-types=post,page     Post types for element analysis (default: post,page)');
  console.log('  --limit=100                Limit analyzed source posts/pages');
  console.log('  --output-dir=reports       Directory for json/md output files');
  console.log('  --skip-element-analysis    Skip deep element distribution scan');
  console.log('  --help, -h                 Show this help');
}

function getOptionLength(environment, optionName) {
  const result = runWpCli(environment, ['option', 'get', optionName, '--format=json'], true);
  if (result.status !== 0) {
    return 0;
  }
  const parsed = extractFirstJsonValue(result.stdout);
  if (Array.isArray(parsed)) {
    return parsed.length;
  }
  if (parsed && typeof parsed === 'object') {
    return Object.keys(parsed).length;
  }
  return 0;
}

function getPostCount(environment, postTypes) {
  const result = runWpCli(
    environment,
    ['post', 'list', `--post_type=${postTypes.join(',')}`, '--posts_per_page=-1', '--format=count']
  );
  return parseNumberFromOutput(result.stdout);
}

function getWpBlockCount() {
  const result = runWpCli(
    'tests-cli',
    ['post', 'list', '--post_type=wp_block', '--posts_per_page=-1', '--format=count']
  );
  return parseNumberFromOutput(result.stdout);
}

function buildIssues(metrics, elementAnalysis) {
  const issues = [];

  if (metrics.contentSuccessRate !== null && metrics.contentSuccessRate < 95) {
    issues.push({
      severity: 'high',
      category: 'content',
      message: `Post/page migration success rate is below target: ${metrics.contentSuccessRate.toFixed(2)}%`
    });
  }

  if (metrics.cssMappingRate !== null && metrics.cssMappingRate < 95) {
    issues.push({
      severity: 'high',
      category: 'css',
      message: `CSS mapping rate is below target: ${metrics.cssMappingRate.toFixed(2)}%`
    });
  }

  if (metrics.componentMigrationRate !== null && metrics.componentMigrationRate < 100) {
    issues.push({
      severity: 'high',
      category: 'components',
      message: `Component migration rate is below target: ${metrics.componentMigrationRate.toFixed(2)}%`
    });
  }

  if (elementAnalysis && elementAnalysis.summary.unsupportedOccurrences > 0) {
    const topUnsupported = elementAnalysis.unsupportedTypes[0];
    issues.push({
      severity: 'medium',
      category: 'elements',
      message: `${elementAnalysis.summary.unsupportedOccurrences} unsupported element occurrence(s) found`,
      details: topUnsupported
        ? `Top unsupported type: ${topUnsupported.type} (${topUnsupported.count} occurrences)`
        : ''
    });
  }

  if (elementAnalysis && elementAnalysis.summary.postsWithParseErrors > 0) {
    issues.push({
      severity: 'low',
      category: 'analysis',
      message: `${elementAnalysis.summary.postsWithParseErrors} post(s) had Bricks meta but no recognized element nodes`
    });
  }

  return issues;
}

function normalizeElementAnalysis(analysis) {
  if (!analysis || typeof analysis !== 'object') {
    return null;
  }

  const rows = Array.isArray(analysis.rows) ? analysis.rows : [];
  const sourceSummary = analysis.summary && typeof analysis.summary === 'object' ? analysis.summary : {};
  const unsupportedTypes = Array.isArray(analysis.unsupportedTypes)
    ? analysis.unsupportedTypes
    : rows
        .filter((row) => !row.supported)
        .map((row) => ({
          type: row.elementType,
          count: row.count,
          postIds: Array.isArray(row.postIds) ? row.postIds : []
        }));

  const totalElements =
    Number.isFinite(sourceSummary.totalElements)
      ? sourceSummary.totalElements
      : rows.reduce((sum, row) => sum + (Number.isFinite(row.count) ? row.count : 0), 0);

  const supportedOccurrences =
    Number.isFinite(sourceSummary.supportedOccurrences)
      ? sourceSummary.supportedOccurrences
      : Number.isFinite(sourceSummary.supportedElements)
        ? sourceSummary.supportedElements
        : rows.filter((row) => row.supported).reduce((sum, row) => sum + row.count, 0);

  const unsupportedOccurrences =
    Number.isFinite(sourceSummary.unsupportedOccurrences)
      ? sourceSummary.unsupportedOccurrences
      : Number.isFinite(sourceSummary.unsupportedElements)
        ? sourceSummary.unsupportedElements
        : totalElements - supportedOccurrences;

  const unsupportedTypeCount =
    Number.isFinite(sourceSummary.unsupportedTypeCount)
      ? sourceSummary.unsupportedTypeCount
      : Number.isFinite(sourceSummary.unsupportedTypes)
        ? sourceSummary.unsupportedTypes
        : unsupportedTypes.length;

  const coverageRate = Number.isFinite(sourceSummary.coverageRate)
    ? sourceSummary.coverageRate
    : totalElements > 0
      ? (supportedOccurrences / totalElements) * 100
      : 100;

  return {
    ...analysis,
    rows,
    unsupportedTypes,
    summary: {
      ...sourceSummary,
      totalElements,
      supportedOccurrences,
      unsupportedOccurrences,
      unsupportedTypeCount,
      distinctElementTypes:
        Number.isFinite(sourceSummary.distinctElementTypes) ? sourceSummary.distinctElementTypes : rows.length,
      postsWithParseErrors:
        Number.isFinite(sourceSummary.postsWithParseErrors) ? sourceSummary.postsWithParseErrors : 0,
      coverageRate
    }
  };
}

function toPercentDisplay(value) {
  if (value === null) {
    return 'n/a';
  }
  return `${value.toFixed(2)}%`;
}

function buildMarkdown(report) {
  const lines = [];
  lines.push('# Migration Test Report');
  lines.push('');
  lines.push(`Generated: ${report.generatedAt}`);
  lines.push('');
  lines.push('## Statistiken');
  lines.push(`- Bricks Posts/Pages: ${report.metrics.bricksPosts}`);
  lines.push(`- Etch Posts/Pages: ${report.metrics.etchPosts}`);
  lines.push(`- Success Rate: ${toPercentDisplay(report.metrics.contentSuccessRate)}`);
  lines.push(`- Bricks Global Classes: ${report.metrics.bricksGlobalClasses}`);
  lines.push(`- Etch Style Map Entries: ${report.metrics.etchStyleMap}`);
  lines.push(`- CSS Mapping Rate: ${toPercentDisplay(report.metrics.cssMappingRate)}`);
  lines.push(`- Bricks Components: ${report.metrics.bricksComponents}`);
  lines.push(`- Etch Components (wp_block): ${report.metrics.etchComponents}`);
  lines.push(`- Component Migration Rate: ${toPercentDisplay(report.metrics.componentMigrationRate)}`);
  lines.push('');

  if (report.elementAnalysis) {
    lines.push('## Element-Typen');
    lines.push(`- Distinct types: ${report.elementAnalysis.summary.distinctElementTypes}`);
    lines.push(
      `- Supported occurrences: ${report.elementAnalysis.summary.supportedOccurrences}/${report.elementAnalysis.summary.totalElements} (${report.elementAnalysis.summary.coverageRate.toFixed(2)}%)`
    );
    lines.push(`- Unsupported types: ${report.elementAnalysis.summary.unsupportedTypeCount}`);
    lines.push(`- Unsupported occurrences: ${report.elementAnalysis.summary.unsupportedOccurrences}`);

    if (report.elementAnalysis.unsupportedTypes.length > 0) {
      lines.push('');
      lines.push('### Unsupported Details');
      for (const item of report.elementAnalysis.unsupportedTypes.slice(0, 15)) {
        lines.push(`- ${item.type}: ${item.count} occurrence(s), post IDs: ${item.postIds.join(', ')}`);
      }
      if (report.elementAnalysis.unsupportedTypes.length > 15) {
        lines.push(`- ... ${report.elementAnalysis.unsupportedTypes.length - 15} additional unsupported type(s)`);
      }
    }
    lines.push('');
  }

  lines.push('## Probleme');
  if (report.issues.length === 0) {
    lines.push('- Keine kritischen Probleme im automatisierten Report erkannt.');
  } else {
    report.issues.forEach((issue, index) => {
      lines.push(`${index + 1}. [${issue.severity.toUpperCase()}] ${issue.message}`);
      if (issue.details) {
        lines.push(`   - ${issue.details}`);
      }
    });
  }
  lines.push('');
  lines.push('## Empfehlungen');
  if (report.issues.length === 0) {
    lines.push('- Frontend- und Editor-Stichproben manuell durchfuehren, dann freigeben.');
  } else {
    lines.push('- Unsupported element types im Converter-Backlog priorisieren.');
    lines.push('- Etch/Bricks Logs auf "Skipping" und "Warning" Meldungen pruefen.');
    lines.push('- Manuelle Frontend-Vergleiche fuer betroffene Posts durchfuehren.');
  }

  return lines.join('\n');
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help) {
    printHelp();
    process.exit(0);
  }

  try {
    console.log('Collecting migration quality metrics...');
    const bricksPosts = getPostCount('cli', args.postTypes);
    const etchPosts = getPostCount('tests-cli', args.postTypes);
    const bricksGlobalClasses = getOptionLength('cli', 'bricks_global_classes');
    const etchStyleMap = getOptionLength('tests-cli', 'efs_style_map');
    const bricksComponents = getOptionLength('cli', 'bricks_components');
    const etchComponents = getWpBlockCount();

    const metrics = {
      bricksPosts,
      etchPosts,
      contentSuccessRate: safePercent(etchPosts, bricksPosts),
      bricksGlobalClasses,
      etchStyleMap,
      cssMappingRate: safePercent(etchStyleMap, bricksGlobalClasses),
      bricksComponents,
      etchComponents,
      componentMigrationRate: safePercent(etchComponents, bricksComponents)
    };

    let elementAnalysis = null;
    if (!args.skipElementAnalysis) {
      console.log('Running element distribution analysis...');
      elementAnalysis = normalizeElementAnalysis(
        analyzeElements({
          postTypes: args.postTypes,
          limit: args.limit,
          quiet: true
        })
      );
    }

    const issues = buildIssues(metrics, elementAnalysis);
    const report = {
      generatedAt: new Date().toISOString(),
      postTypes: args.postTypes,
      metrics,
      elementAnalysis,
      issues
    };

    fs.mkdirSync(args.outputDir, { recursive: true });
    const stamp = report.generatedAt.replace(/[:.]/g, '-');
    const jsonPath = path.join(args.outputDir, `migration-quality-${stamp}.json`);
    const mdPath = path.join(args.outputDir, `migration-quality-${stamp}.md`);

    fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2), 'utf8');
    fs.writeFileSync(mdPath, buildMarkdown(report), 'utf8');

    console.log(`Report JSON: ${jsonPath}`);
    console.log(`Report Markdown: ${mdPath}`);
    console.log(`Content success rate: ${toPercentDisplay(metrics.contentSuccessRate)}`);
    console.log(`CSS mapping rate: ${toPercentDisplay(metrics.cssMappingRate)}`);
    console.log(`Component migration rate: ${toPercentDisplay(metrics.componentMigrationRate)}`);

    if (issues.length > 0) {
      console.log(`Issues detected: ${issues.length}`);
      issues.forEach((issue) => {
        console.log(`- [${issue.severity}] ${issue.message}`);
      });
      process.exit(2);
    }

    console.log('No blocking issues detected by automated checks.');
    process.exit(0);
  } catch (error) {
    console.error(`ERROR migration-quality-report failed: ${error.message}`);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}
