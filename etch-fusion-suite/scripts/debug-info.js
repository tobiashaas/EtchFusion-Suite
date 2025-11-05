#!/usr/bin/env node

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const WP_ENV_CMD = process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env';

function runCommand(command, args, options = {}) {
  const result = spawnSync(command, args, { encoding: 'utf8', ...options });

  if (result.error) {
    throw result.error;
  }

  return {
    code: result.status,
    stdout: result.stdout.trim(),
    stderr: result.stderr.trim()
  };
}

function runWpEnv(args) {
  const { code, stdout, stderr } = runCommand(WP_ENV_CMD, args);
  if (code !== 0) {
    throw new Error(stderr || stdout || `wp-env ${args.join(' ')} failed`);
  }
  return stdout;
}

function section(title, content, timestamp = true) {
  const time = timestamp ? ` (${new Date().toISOString().slice(11, 19)})` : '';
  return `## ${title}${time}\n${content}\n`;
}

function capture(name, fn, timestamp = true) {
  try {
    const output = fn();
    return section(name, `
	${output.replace(/\n/g, '\n\t')}`.trim(), timestamp);
  } catch (error) {
    return section(name, `Error: ${error.message}`, timestamp);
  }
}

function checkPortAvailability(port) {
  const net = require('net');
  return new Promise((resolve) => {
    const server = net.createServer();
    server.listen(port, () => {
      server.once('close', () => resolve('available'));
      server.close();
    });
    server.on('error', () => resolve('in use'));
  });
}

function getProcessUsingPort(port) {
  try {
    if (process.platform === 'darwin' || process.platform === 'linux') {
      const result = runCommand('lsof', ['-i', `:${port}`]);
      return result.stdout;
    } else if (process.platform === 'win32') {
      const result = runCommand('netstat', ['-ano', '|', 'findstr', `:${port}`]);
      return result.stdout;
    }
  } catch (error) {
    return 'Could not determine process';
  }
  return 'Unknown platform';
}

function formatAsMarkdown(content) {
  return content;
}

function formatAsJson(content) {
  return JSON.stringify(content, null, 2);
}

function main() {
  const args = process.argv.slice(2);
  const verbose = args.includes('--verbose');
  const format = args.includes('--json') ? 'json' : (args.includes('--markdown') ? 'markdown' : 'text');
  
  const report = [];
  const now = new Date().toISOString();
  const reportData = {
    generated: now,
    sections: {}
  };

  if (format === 'json') {
    report.push(`# Bricks to Etch Debug Report\nGenerated: ${now}\n`);
  } else {
    report.push(`# Bricks to Etch Debug Report\nGenerated: ${now}\n`);
    report.push('## Table of Contents\n');
  }

  // System Information
  const systemInfo = () => {
    const node = process.version;
    const npm = runCommand('npm', ['-v']).stdout;
    const docker = runCommand('docker', ['version', '--format', '{{.Server.Version}}']).stdout || 'Unavailable';
    const wpEnv = runCommand(WP_ENV_CMD, ['--version']).stdout || 'Unknown';
    const platform = `${process.platform} ${process.arch}`;

    return `Node: ${node}\nNPM: ${npm}\nDocker: ${docker}\nwp-env: ${wpEnv}\nPlatform: ${platform}`;
  };
  
  report.push(capture('System Information', systemInfo));
  reportData.sections.system = systemInfo();

  // wp-env Configuration
  const wpEnvConfig = () => {
    const configPath = path.join(__dirname, '../.wp-env.json');
    const overridePath = path.join(__dirname, '../.wp-env.override.json');
    let config = 'Not found';
    let override = 'Not found';
    
    if (fs.existsSync(configPath)) {
      try {
        config = JSON.stringify(JSON.parse(fs.readFileSync(configPath, 'utf8')), null, 2);
      } catch (e) {
        config = `Parse error: ${e.message}`;
      }
    }
    
    if (fs.existsSync(overridePath)) {
      try {
        override = JSON.stringify(JSON.parse(fs.readFileSync(overridePath, 'utf8')), null, 2);
      } catch (e) {
        override = `Parse error: ${e.message}`;
      }
    }
    
    return `Base config:\n${config}\n\nOverride config:\n${override}`;
  };
  
  report.push(capture('wp-env Configuration', wpEnvConfig));
  reportData.sections.wpEnvConfig = wpEnvConfig();

  // Port Availability
  const portCheck = async () => {
    const ports = [8888, 8889, 13306, 13307];
    let results = [];
    
    for (const port of ports) {
      const status = await checkPortAvailability(port);
      const process = status === 'in use' ? getProcessUsingPort(port) : 'N/A';
      results.push(`Port ${port}: ${status}${status === 'in use' ? ` (${process})` : ''}`);
    }
    
    return results.join('\n');
  };
  
  if (verbose) {
    report.push(capture('Port Availability', () => portCheck().catch(() => 'Could not check ports')));
  }

  // Docker Container Health
  const dockerHealth = () => {
    try {
      const ps = runCommand('docker', ['ps', '--format', 'table {{.Names}}\t{{.Status}}\t{{.Ports}}']).stdout;
      const stats = runCommand('docker', ['stats', '--no-stream', '--format', 'table {{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}']).stdout;
      return `Running containers:\n${ps}\n\nResource usage:\n${stats}`;
    } catch (error) {
      return `Could not get Docker info: ${error.message}`;
    }
  };
  
  report.push(capture('Docker Container Health', dockerHealth));
  reportData.sections.dockerHealth = dockerHealth();

  // wp-env Status
  report.push(capture('wp-env Status', () => runCommand(WP_ENV_CMD, ['status']).stdout));
  reportData.sections.wpEnvStatus = runCommand(WP_ENV_CMD, ['status']).stdout;

  // WordPress Versions
  const wpVersions = () => {
    const bricks = runWpEnv(['run', 'cli', 'wp', 'core', 'version']);
    const etch = runWpEnv(['run', 'tests-cli', 'wp', 'core', 'version']);
    return `Bricks: ${bricks}\nEtch: ${etch}`;
  };
  
  report.push(capture('WordPress Versions', wpVersions));
  reportData.sections.wordpressVersions = wpVersions();

  // Active Plugins
  report.push(capture('Active Plugins (Bricks)', () => runWpEnv(['run', 'cli', 'wp', 'plugin', 'list', '--status=active'])));
  report.push(capture('Active Plugins (Etch)', () => runWpEnv(['run', 'tests-cli', 'wp', 'plugin', 'list', '--status=active'])));

  // Active Themes
  const themes = () => {
    const bricks = runWpEnv(['run', 'cli', 'wp', 'theme', 'status']);
    const etch = runWpEnv(['run', 'tests-cli', 'wp', 'theme', 'status']);
    return `Bricks:\n${bricks}\n\nEtch:\n${etch}`;
  };
  
  report.push(capture('Active Themes', themes));
  reportData.sections.themes = themes();

  // PHP Info
  const phpInfo = () => {
    const version = runWpEnv(['run', 'cli', 'php', '-v']);
    const modules = runWpEnv(['run', 'cli', 'php', '-m']);
    return `Version:\n${version}\n\nModules:\n${modules}`;
  };
  
  report.push(capture('PHP Info', phpInfo));
  reportData.sections.phpInfo = phpInfo();

  // REST API Health
  const restApiHealth = () => {
    try {
      const bricks = runWpEnv(['run', 'cli', 'wp', 'curl', '--silent', '--head', 'http://localhost/wp-json/efs/v1/status']);
      const etch = runWpEnv(['run', 'tests-cli', 'wp', 'curl', '--silent', '--head', 'http://localhost/wp-json/efs/v1/status']);
      return `Bricks API:\n${bricks}\n\nEtch API:\n${etch}`;
    } catch (error) {
      return `REST API check failed: ${error.message}`;
    }
  };
  
  if (verbose) {
    report.push(capture('REST API Health', restApiHealth));
  }

  // File Permissions
  const filePerms = () => {
    try {
      const bricks = runWpEnv(['run', 'cli', 'sh', '-c', 'ls -la wp-content/ | grep -E "(drw|wrw)"']);
      const etch = runWpEnv(['run', 'tests-cli', 'sh', '-c', 'ls -la wp-content/ | grep -E "(drw|wrw)"']);
      return `Bricks wp-content permissions:\n${bricks || 'All permissions OK'}\n\nEtch wp-content permissions:\n${etch || 'All permissions OK'}`;
    } catch (error) {
      return `Could not check file permissions: ${error.message}`;
    }
  };
  
  if (verbose) {
    report.push(capture('File Permissions', filePerms));
  }

  // Recent Error Logs
  const errorLogs = () => {
    try {
      const bricks = runWpEnv(['run', 'cli', 'sh', '-c', 'tail -n 50 wp-content/debug.log | grep -i error || echo "No errors found"']);
      const etch = runWpEnv(['run', 'tests-cli', 'sh', '-c', 'tail -n 50 wp-content/debug.log | grep -i error || echo "No errors found"']);
      return `Bricks errors (last 50 lines):\n${bricks}\n\nEtch errors (last 50 lines):\n${etch}`;
    } catch (error) {
      return `Could not fetch error logs: ${error.message}`;
    }
  };
  
  report.push(capture('Recent Error Logs', errorLogs));
  reportData.sections.errorLogs = errorLogs();

  // Plugin Settings
  const pluginSettings = () => {
    try {
      const settings = runWpEnv(['run', 'cli', 'wp', 'option', 'get', 'b2e_migration_settings']);
      const progress = runWpEnv(['run', 'cli', 'wp', 'option', 'get', 'b2e_migration_progress']);
      return `Settings:\n${settings}\n\nProgress:\n${progress}`;
    } catch (error) {
      return `Could not get plugin settings: ${error.message}`;
    }
  };
  
  report.push(capture('Plugin Settings', pluginSettings));
  reportData.sections.pluginSettings = pluginSettings();

  // Composer Packages
  const composerPackages = () => {
    const composerPath = path.resolve(__dirname, '../vendor/composer/installed.json');
    if (!fs.existsSync(composerPath)) {
      return 'Composer dependencies not installed (vendor/composer/installed.json missing).';
    }
    const data = fs.readFileSync(composerPath, 'utf8');
    return data.length > 2000 ? `${data.slice(0, 2000)}... [truncated]` : data;
  };
  
  report.push(capture('Composer Packages', composerPackages));
  reportData.sections.composerPackages = composerPackages();

  // Disk Space
  const diskSpace = () => {
    try {
      const df = runCommand('docker', ['system', 'df']).stdout;
      return `Docker disk usage:\n${df}`;
    } catch (error) {
      return `Could not check disk space: ${error.message}`;
    }
  };
  
  if (verbose) {
    report.push(capture('Disk Space', diskSpace));
  }

  // wp-env Logs (truncated for performance)
  report.push(capture('Recent wp-env Logs', () => {
    try {
      const logs = runCommand(WP_ENV_CMD, ['logs', '--tail', '100']).stdout;
      return logs.length > 5000 ? `${logs.slice(0, 5000)}... [truncated]` : logs;
    } catch (error) {
      return `Could not fetch wp-env logs: ${error.message}`;
    }
  }));

  const reportContent = report.join('\n');
  const outDir = path.resolve(__dirname, '..');
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const extension = format === 'json' ? 'json' : 'txt';
  const outPath = path.join(outDir, `debug-report-${timestamp}.${extension}`);
  
  if (format === 'json') {
    fs.writeFileSync(outPath, formatAsJson(reportData), 'utf8');
  } else {
    fs.writeFileSync(outPath, reportContent, 'utf8');
  }

  console.log(`Debug report written to ${outPath}`);
  
  if (format === 'text') {
    console.log('\n📋 Report Summary:');
    console.log('   • System info collected');
    console.log('   • wp-env configuration analyzed');
    console.log('   • WordPress instances checked');
    console.log('   • Plugin and theme status verified');
    console.log('   • Recent errors captured');
    if (verbose) {
      console.log('   • Port availability checked');
      console.log('   • REST API health verified');
      console.log('   • File permissions examined');
      console.log('   • Disk space analyzed');
    }
  }
}

main().catch((error) => {
  console.error('Failed to collect debug info:', error.message);
  process.exit(1);
});
