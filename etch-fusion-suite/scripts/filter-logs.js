#!/usr/bin/env node

const { spawn } = require('child_process');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

// ANSI color codes
const colors = {
  reset: '\x1b[0m',
  red: '\x1b[31m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  cyan: '\x1b[36m',
  magenta: '\x1b[35m',
  gray: '\x1b[90m'
};

function colorize(text, color) {
  if (process.argv.includes('--no-color')) {
    return text;
  }
  return `${colors[color]}${text}${colors.reset}`;
}

function parseLogLevel(line) {
  const lowerLine = line.toLowerCase();
  
  // PHP errors
  if (lowerLine.includes('fatal error') || lowerLine.includes('parse error')) {
    return { level: 'error', color: 'red', priority: 1 };
  }
  if (lowerLine.includes('warning')) {
    return { level: 'warning', color: 'yellow', priority: 2 };
  }
  if (lowerLine.includes('notice') || lowerLine.includes('deprecated')) {
    return { level: 'notice', color: 'blue', priority: 3 };
  }
  
  // WordPress errors
  if (lowerLine.includes('wp-error') || lowerLine.includes('database error')) {
    return { level: 'error', color: 'red', priority: 1 };
  }
  
  // HTTP errors
  if (lowerLine.includes(' 500 ') || lowerLine.includes(' 404 ') || lowerLine.includes(' 403 ')) {
    return { level: 'error', color: 'red', priority: 1 };
  }
  
  // General error patterns
  if (lowerLine.includes('error') || lowerLine.includes('exception') || lowerLine.includes('failed')) {
    return { level: 'error', color: 'red', priority: 1 };
  }
  
  return { level: 'info', color: 'reset', priority: 4 };
}

function addTimestamp(line) {
  if (line.match(/^\d{4}-\d{2}-\d{2}/) || line.match(/^\[\d{2}:\d{2}:\d{2}/)) {
    return line; // Already has timestamp
  }
  
  const timestamp = new Date().toISOString().slice(11, 19);
  return `[${timestamp}] ${line}`;
}

function groupRelatedLines(lines, currentIndex) {
  const currentLine = lines[currentIndex];
  const groupedLines = [currentLine];
  
  // Check if this is a stack trace or multi-line error
  if (currentLine.toLowerCase().includes('stack trace:') || 
      currentLine.toLowerCase().includes('call stack:') ||
      currentLine.trim().endsWith('in') ||
      currentLine.match(/^\s+at\s+.*\s+line\s+\d+/)) {
    
    // Include following indented lines or stack trace lines
    for (let i = currentIndex + 1; i < lines.length; i++) {
      const nextLine = lines[i];
      if (nextLine.match(/^\s+/) || 
          nextLine.match(/^\d+\./) ||
          nextLine.toLowerCase().includes('stack trace') ||
          nextLine.match(/^\s+at\s+.*\s+line\s+\d+/)) {
        groupedLines.push(nextLine);
      } else {
        break;
      }
    }
  }
  
  return groupedLines;
}

function filterLogs(environment, filter, follow = false, since = null, grep = null) {
  return new Promise((resolve, reject) => {
    const args = ['logs', '--environment', environment];
    
    if (follow) {
      args.push('--watch');
    }
    
    // Note: --since is not supported by wp-env, we'll handle it differently if needed
    if (since) {
      console.warn(`Warning: --since is not supported by wp-env. Consider using docker logs directly.`);
    }
    
    const wpEnv = spawn(WP_ENV_CMD, args, { stdio: 'pipe' });
    
    let buffer = '';
    let lineCount = 0;
    const filteredLines = [];
    
    wpEnv.stdout?.on('data', (data) => {
      buffer += data.toString();
      const lines = buffer.split('\n');
      buffer = lines.pop(); // Keep incomplete line in buffer
      
      for (let i = 0; i < lines.length; i++) {
        if (!lines[i].trim()) continue;
        
        const line = addTimestamp(lines[i]);
        const logInfo = parseLogLevel(line);
        
        // Apply filters
        let matchesFilter = false;
        
        if (filter === 'error' && logInfo.level === 'error') {
          matchesFilter = true;
        } else if (filter === 'warning' && (logInfo.level === 'error' || logInfo.level === 'warning')) {
          matchesFilter = true;
        } else if (filter === 'notice' && logInfo.level !== 'info') {
          matchesFilter = true;
        } else if (grep && line.toLowerCase().includes(grep.toLowerCase())) {
          matchesFilter = true;
          logInfo.color = 'cyan'; // Custom grep gets cyan color
        } else if (!filter || filter === 'all') {
          matchesFilter = true;
        }
        
        if (matchesFilter) {
          const groupedLines = groupRelatedLines(lines, i);
          
          for (const groupedLine of groupedLines) {
            const groupLogInfo = parseLogLevel(groupedLine);
            const colorizedLine = colorize(groupedLine, groupLogInfo.color);
            console.log(colorizedLine);
            filteredLines.push(groupedLine);
            lineCount++;
            
            // Skip the grouped lines in the main loop
            if (groupedLines.length > 1) {
              i += groupedLines.length - 1;
            }
          }
        }
      }
    });
    
    wpEnv.stderr?.on('data', (data) => {
      const errorLines = data.toString().split('\n');
      for (const line of errorLines) {
        if (line.trim()) {
          console.error(colorize(line, 'magenta'));
          filteredLines.push(line);
          lineCount++;
        }
      }
    });
    
    wpEnv.on('error', (error) => {
      reject(error);
    });
    
    wpEnv.on('close', (code) => {
      if (!follow) {
        console.log(colorize(`\\n📊 Filtered ${lineCount} lines from ${environment} environment`, 'gray'));
        resolve({ lineCount, lines: filteredLines });
      }
    });
    
    // Handle Ctrl+C for follow mode
    if (follow) {
      process.on('SIGINT', () => {
        wpEnv.kill('SIGINT');
        console.log(colorize('\\n📊 Stopped log streaming', 'gray'));
        resolve({ lineCount, lines: filteredLines });
        process.exit(0);
      });
    }
  });
}

function showUsage() {
  console.log(`
Usage: node filter-logs.js <environment> <filter> [options]

Arguments:
  environment    development, tests, or all
  filter         error, warning, notice, or custom pattern

Options:
  --follow       Follow logs in real-time (tail -f style)
  --since <time> Show logs since specified time (e.g., 5m, 1h, 2023-01-01T10:00:00)
  --grep <pattern> Custom pattern matching (case-insensitive)
  --no-color     Disable color output
  --help         Show this help

Examples:
  node filter-logs.js development error          # Show errors from dev environment
  node filter-logs.js tests warning --follow     # Follow warnings from test environment
  node filter-logs.js all notice --since 10m     # Show notices from last 10 minutes
  node filter-logs.js development "database"     # Custom grep for database-related logs
  node filter-logs.js tests error --no-color     # Errors without color (for CI)

Log Levels:
  error    - Fatal errors, exceptions, HTTP 5xx
  warning  - Warnings, HTTP 4xx, deprecation notices  
  notice   - Notices, info messages
  all      - All logs (no filtering)
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help') || args.length < 2) {
    showUsage();
    process.exit(0);
  }
  
  const environment = args[0];
  const filter = args[1];
  const follow = args.includes('--follow');
  const since = args.includes('--since') ? args[args.indexOf('--since') + 1] : null;
  const grep = args.includes('--grep') ? args[args.indexOf('--grep') + 1] : null;
  
  // Validate environment
  if (!['development', 'tests', 'all'].includes(environment)) {
    console.error('Error: Environment must be "development", "tests", or "all"');
    process.exit(1);
  }
  
  // Handle "all" environment
  if (environment === 'all') {
    console.log(colorize(`🔍 Filtering logs from both environments...\\n`, 'cyan'));
    
    const devPromise = filterLogs('development', filter, follow, since, grep);
    const testPromise = filterLogs('tests', filter, follow, since, grep);
    
    try {
      if (follow) {
        // For follow mode, we can't easily combine streams, so just run dev
        await devPromise;
      } else {
        const [devResult, testResult] = await Promise.all([devPromise, testPromise]);
        console.log(colorize(`\\n📊 Total: ${devResult.lineCount + testResult.lineCount} lines filtered`, 'gray'));
      }
    } catch (error) {
      console.error('Error filtering logs:', error.message);
      process.exit(1);
    }
  } else {
    try {
      console.log(colorize(`🔍 Filtering ${filter} logs from ${environment} environment...\\n`, 'cyan'));
      await filterLogs(environment, filter, follow, since, grep);
    } catch (error) {
      console.error('Error filtering logs:', error.message);
      process.exit(1);
    }
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Log filtering failed:', error.message);
    process.exit(1);
  });
}

module.exports = { filterLogs };
