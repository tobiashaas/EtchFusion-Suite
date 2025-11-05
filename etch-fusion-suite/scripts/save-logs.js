#!/usr/bin/env node

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function runCommand(command, args, options = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { stdio: 'pipe', ...options });
    let stdout = '';
    let stderr = '';

    child.stdout?.on('data', (data) => {
      stdout += data.toString();
    });

    child.stderr?.on('data', (data) => {
      stderr += data.toString();
    });

    child.on('error', reject);

    child.on('exit', (code) => {
      resolve({ code, stdout, stderr });
    });
  });
}

function runWpEnv(args) {
  return runCommand(WP_ENV_CMD, args);
}

function createLogsDirectory() {
  const logsDir = path.join(__dirname, '..', 'logs');
  if (!fs.existsSync(logsDir)) {
    fs.mkdirSync(logsDir, { recursive: true });
  }
  return logsDir;
}

function getTimestamp() {
  return new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
}

async function captureWpEnvLogs(environment, lines = 1000) {
  try {
    // wp-env doesn't support --tail, so we get all logs and truncate
    const args = ['logs', '--environment', environment];
    const result = await runWpEnv(args);
    
    if (result.code === 0) {
      // Split into lines and take last N lines
      const allLines = result.stdout.split('\n');
      const truncatedLines = allLines.slice(-lines);
      return truncatedLines.join('\n');
    } else {
      throw new Error(`wp-env logs failed: ${result.stderr}`);
    }
  } catch (error) {
    throw new Error(`Failed to capture ${environment} logs: ${error.message}`);
  }
}

async function captureWordPressDebugLog(environment, lines = 1000) {
  try {
    const cli = environment === 'development' ? 'cli' : 'tests-cli';
    const args = ['run', cli, 'sh', '-c', `tail -n ${lines} wp-content/debug.log || echo "Debug log not found"`];
    const result = await runWpEnv(args);
    
    if (result.code === 0) {
      return result.stdout;
    } else {
      return `Debug log not available for ${environment}`;
    }
  } catch (error) {
    return `Could not access debug log for ${environment}: ${error.message}`;
  }
}

function createCombinedLog(bricksLogs, etchLogs) {
  const bricksLines = bricksLogs.split('\\n').filter(line => line.trim());
  const etchLines = etchLogs.split('\\n').filter(line => line.trim());
  
  let combined = '# Combined Etch Fusion Suite Logs\\n';
  combined += `# Generated: ${new Date().toISOString()}\\n\\n`;
  
  let brickIndex = 0;
  let etchIndex = 0;
  
  while (brickIndex < bricksLines.length || etchIndex < etchLines.length) {
    if (brickIndex < bricksLines.length) {
      combined += `[BRICKS] ${bricksLines[brickIndex]}\\n`;
      brickIndex++;
    }
    
    if (etchIndex < etchLines.length) {
      combined += `[ETCH]   ${etchLines[etchIndex]}\\n`;
      etchIndex++;
    }
  }
  
  return combined;
}

function compressFile(filePath) {
  return new Promise((resolve, reject) => {
    const compressedPath = `${filePath}.gz`;
    const input = fs.createReadStream(filePath);
    const output = fs.createWriteStream(compressedPath);
    const gzip = zlib.createGzip();
    
    input
      .pipe(gzip)
      .pipe(output)
      .on('finish', () => {
        fs.unlinkSync(filePath); // Remove original file
        resolve(compressedPath);
      })
      .on('error', reject);
  });
}

function cleanupOldLogs(logsDir, maxAgeDays = 7) {
  try {
    const files = fs.readdirSync(logsDir);
    const cutoffTime = Date.now() - (maxAgeDays * 24 * 60 * 60 * 1000);
    let deletedCount = 0;
    
    for (const file of files) {
      const filePath = path.join(logsDir, file);
      const stats = fs.statSync(filePath);
      
      if (stats.isFile() && stats.mtime.getTime() < cutoffTime) {
        fs.unlinkSync(filePath);
        deletedCount++;
      }
    }
    
    return deletedCount;
  } catch (error) {
    console.warn(`Warning: Could not clean up old logs: ${error.message}`);
    return 0;
  }
}

function getFileSize(filePath) {
  try {
    const stats = fs.statSync(filePath);
    const bytes = stats.size;
    
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
  } catch (error) {
    return 'Unknown';
  }
}

async function saveLogs(lines = 1000, compress = false) {
  const timestamp = getTimestamp();
  const logsDir = createLogsDirectory();
  
  console.log('📥 Capturing logs...');
  
  try {
    // Capture logs in parallel
    const [bricksLogs, etchLogs, bricksDebug, etchDebug] = await Promise.all([
      captureWpEnvLogs('development', lines),
      captureWpEnvLogs('tests', lines),
      captureWordPressDebugLog('development', lines),
      captureWordPressDebugLog('tests', lines)
    ]);
    
    // Save individual logs
    const bricksLogPath = path.join(logsDir, `bricks-${timestamp}.log`);
    const etchLogPath = path.join(logsDir, `etch-${timestamp}.log`);
    const bricksDebugPath = path.join(logsDir, `bricks-debug-${timestamp}.log`);
    const etchDebugPath = path.join(logsDir, `etch-debug-${timestamp}.log`);
    
    fs.writeFileSync(bricksLogPath, bricksLogs);
    fs.writeFileSync(etchLogPath, etchLogs);
    fs.writeFileSync(bricksDebugPath, bricksDebug);
    fs.writeFileSync(etchDebugPath, etchDebug);
    
    // Create combined log
    const combinedLog = createCombinedLog(bricksLogs, etchLogs);
    const combinedLogPath = path.join(logsDir, `combined-${timestamp}.log`);
    fs.writeFileSync(combinedLogPath, combinedLog);
    
    const files = [
      { name: 'Bricks wp-env logs', path: bricksLogPath },
      { name: 'Etch wp-env logs', path: etchLogPath },
      { name: 'Bricks debug logs', path: bricksDebugPath },
      { name: 'Etch debug logs', path: etchDebugPath },
      { name: 'Combined logs', path: combinedLogPath }
    ];
    
    // Compress if requested
    if (compress) {
      console.log('🗜️ Compressing log files...');
      for (const file of files) {
        file.path = await compressFile(file.path);
      }
    }
    
    // Cleanup old logs
    const deletedCount = cleanupOldLogs(logsDir);
    if (deletedCount > 0) {
      console.log(`🧹 Cleaned up ${deletedCount} old log files`);
    }
    
    // Display summary
    console.log('\\n📊 Log Capture Summary:');
    let totalSize = 0;
    
    for (const file of files) {
      const size = getFileSize(file.path);
      console.log(`   📄 ${file.name}: ${file.path} (${size})`);
      
      // Calculate total size (approximate)
      const sizeMatch = size.match(/([\\d.]+)/);
      if (sizeMatch) {
        totalSize += parseFloat(sizeMatch[1]);
      }
    }
    
    console.log(`\\n✅ Logs saved successfully!`);
    console.log(`📁 Logs directory: ${logsDir}`);
    console.log(`📈 Approximate total size: ${totalSize.toFixed(1)} MB`);
    console.log(`🕒 Captured last ${lines} lines from each environment`);
    
    if (compress) {
      console.log(`🗜️ Files are compressed (.gz format)`);
    }
    
    return {
      timestamp,
      files: files.map(f => ({ name: f.name, path: f.path, size: getFileSize(f.path) })),
      totalSize: totalSize.toFixed(1),
      lines,
      compressed: compress
    };
    
  } catch (error) {
    throw new Error(`Log capture failed: ${error.message}`);
  }
}

function showUsage() {
  console.log(`
Usage: node save-logs.js [options]

Options:
  --lines <number>    Number of lines to capture (default: 1000)
  --compress          Compress log files with gzip
  --help              Show this help

Examples:
  node save-logs.js                    # Save last 1000 lines
  node save-logs.js --lines 500       # Save last 500 lines
  node save-logs.js --compress         # Save and compress logs

Output files:
  - logs/bricks-YYYY-MM-DD-HHmmss.log     - Bricks wp-env logs
  - logs/etch-YYYY-MM-DD-HOmmss.log       - Etch wp-env logs  
  - logs/bricks-debug-YYYY-MM-DD-HOmmss.log - Bricks WordPress debug log
  - logs/etch-debug-YYYY-MM-DD-HOmmss.log   - Etch WordPress debug log
  - logs/combined-YYYY-MM-DD-HOmmss.log     - Combined logs with source markers
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help')) {
    showUsage();
    process.exit(0);
  }
  
  const linesIndex = args.indexOf('--lines');
  const lines = linesIndex !== -1 && args[linesIndex + 1] ? parseInt(args[linesIndex + 1]) : 1000;
  const compress = args.includes('--compress');
  
  if (isNaN(lines) || lines < 1) {
    console.error('Error: --lines must be a positive number');
    process.exit(1);
  }
  
  try {
    await saveLogs(lines, compress);
  } catch (error) {
    console.error('❌', error.message);
    process.exit(1);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Log saving failed:', error.message);
    process.exit(1);
  });
}

module.exports = { saveLogs };
