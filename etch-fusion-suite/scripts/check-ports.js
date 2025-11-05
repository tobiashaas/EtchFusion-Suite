#!/usr/bin/env node

const net = require('net');
const { spawn } = require('child_process');

const DEFAULT_PORTS = [8888, 8889];

function checkPortAvailability(port) {
  return new Promise((resolve) => {
    const server = net.createServer();
    
    server.listen(port, () => {
      server.once('close', () => {
        resolve({ port, available: true, process: null });
      });
      server.close();
    });
    
    server.on('error', () => {
      resolve({ port, available: false, process: null });
    });
  });
}

function getProcessUsingPort(port) {
  return new Promise((resolve) => {
    let command, args;
    
    if (process.platform === 'darwin' || process.platform === 'linux') {
      command = 'lsof';
      args = ['-i', `:${port}`];
    } else if (process.platform === 'win32') {
      command = 'cmd.exe';
      args = ['/c', 'netstat', '-ano', '|', 'findstr', `:${port}`];
    } else {
      resolve({ port, available: false, process: 'Unknown platform' });
      return;
    }
    
    const child = spawn(command, args, { stdio: 'pipe' });
    let output = '';
    
    child.stdout?.on('data', (data) => {
      output += data.toString();
    });
    
    child.on('close', (code) => {
      if (code === 0 && output.trim()) {
        resolve({ port, available: false, process: output.trim() });
      } else {
        resolve({ port, available: false, process: 'Process not found' });
      }
    });
    
    child.on('error', () => {
      resolve({ port, available: false, process: 'Could not determine process' });
    });
  });
}

function killProcessUsingPort(port) {
  return new Promise(async (resolve) => {
    const portInfo = await getProcessUsingPort(port);
    
    if (!portInfo.process || portInfo.process === 'Process not found') {
      resolve({ port, killed: false, message: 'No process found' });
      return;
    }
    
    let pid = null;
    
    // Extract PID from output
    if (process.platform === 'darwin' || process.platform === 'linux') {
      const lines = portInfo.process.split('\n');
      for (const line of lines) {
        if (line.includes(`:${port}`)) {
          const parts = line.trim().split(/\s+/);
          if (parts.length > 1) {
            pid = parts[1];
            break;
          }
        }
      }
    } else if (process.platform === 'win32') {
      // Windows netstat output format: TCP    127.0.0.1:8888    0.0.0.0:0    LISTENING    1234
      const lines = portInfo.process.split('\n');
      for (const line of lines) {
        if (line.includes(`:${port}`)) {
          const parts = line.trim().split(/\s+/);
          // PID is typically the last column
          if (parts.length > 0) {
            pid = parts[parts.length - 1];
            break;
          }
        }
      }
    }
    
    if (!pid) {
      resolve({ port, killed: false, message: 'Could not extract PID' });
      return;
    }
    
    try {
      let killCommand, killArgs;
      
      if (process.platform === 'win32') {
        killCommand = 'taskkill';
        killArgs = ['/PID', pid, '/F'];
      } else {
        killCommand = 'kill';
        killArgs = [pid];
      }
      
      const killResult = spawn(killCommand, killArgs, { stdio: 'pipe' });
      
      killResult.on('close', (code) => {
        if (code === 0) {
          resolve({ port, killed: true, pid, message: 'Process killed successfully' });
        } else {
          resolve({ port, killed: false, pid, message: 'Failed to kill process' });
        }
      });
      
      killResult.on('error', () => {
        resolve({ port, killed: false, pid, message: 'Kill command failed' });
      });
      
    } catch (error) {
      resolve({ port, killed: false, pid, message: `Error: ${error.message}` });
    }
  });
}

async function checkPorts(ports, kill = false, wait = false) {
  console.log('🔍 Checking port availability...\\n');
  
  const results = [];
  let allAvailable = true;
  
  for (const port of ports) {
    const result = await checkPortAvailability(port);
    
    if (!result.available) {
      allAvailable = false;
      const processInfo = await getProcessUsingPort(port);
      result.process = processInfo.process;
    }
    
    results.push(result);
  }
  
  // Display results
  for (const result of results) {
    const status = result.available ? '✅ Available' : '❌ In use';
    console.log(`${status} Port ${result.port}`);
    
    if (!result.available && result.process) {
      console.log(`   Process: ${result.process}`);
    }
  }
  
  // Handle kill option
  if (kill && !allAvailable) {
    console.log('\\n🔨 Attempting to kill processes using required ports...');
    
    for (const result of results) {
      if (!result.available) {
        console.log(`   🔄 Port ${result.port}:`);
        
        // Ask for confirmation unless --yes flag is used
        const confirmKill = process.argv.includes('--yes');
        
        if (!confirmKill) {
          console.log(`      ⚠️  This will terminate the process using port ${result.port}`);
          console.log(`      📋 Process: ${result.process}`);
          console.log(`      ❓ Kill this process? [y/N]`);
          
          // For automation, we'll just show what would happen
          console.log(`      💡 Use --yes flag to auto-kill processes`);
          continue;
        }
        
        const killResult = await killProcessUsingPort(result.port);
        
        if (killResult.killed) {
          console.log(`      ✅ Killed process ${killResult.pid}`);
        } else {
          console.log(`      ❌ Failed to kill: ${killResult.message}`);
        }
      }
    }
    
    // Re-check ports after killing
    if (process.argv.includes('--yes')) {
      console.log('\\n🔄 Re-checking ports...');
      await new Promise(resolve => setTimeout(resolve, 1000)); // Wait a second
      
      for (let i = 0; i < results.length; i++) {
        const recheck = await checkPortAvailability(results[i].port);
        results[i].available = recheck.available;
      }
      
      console.log('\\n📊 Final status:');
      for (const result of results) {
        const status = result.available ? '✅ Available' : '❌ Still in use';
        console.log(`   ${status} Port ${result.port}`);
      }
    }
  }
  
  // Handle wait option
  if (wait && !allAvailable) {
    console.log('\\n⏳ Waiting for ports to become available...');
    
    const maxWaitTime = 60000; // 1 minute
    const checkInterval = 2000; // 2 seconds
    const startTime = Date.now();
    
    while (Date.now() - startTime < maxWaitTime) {
      let allNowAvailable = true;
      
      for (const port of ports) {
        const check = await checkPortAvailability(port);
        if (!check.available) {
          allNowAvailable = false;
          break;
        }
      }
      
      if (allNowAvailable) {
        console.log('✅ All ports are now available!');
        allAvailable = true;
        break;
      }
      
      process.stdout.write('.');
      await new Promise(resolve => setTimeout(resolve, checkInterval));
    }
    
    if (!allAvailable) {
      console.log('\\n⏰ Timeout: Ports are still in use');
    }
  }
  
  return { results, allAvailable };
}

function parsePorts(portsArg) {
  if (!portsArg) return DEFAULT_PORTS;
  
  try {
    return portsArg.split(',').map(p => parseInt(p.trim())).filter(p => !isNaN(p) && p > 0);
  } catch (error) {
    console.error('Error: Invalid ports format. Use comma-separated numbers like "8888,8889,13306"');
    process.exit(1);
  }
}

function showUsage() {
  console.log(`
Usage: node check-ports.js [options]

Options:
  --ports <ports>     Comma-separated list of ports to check (default: 8888,8889,13306,13307)
  --kill              Attempt to kill processes using required ports
  --wait              Wait for ports to become available (useful in CI)
  --yes               Auto-confirm killing processes (for automation)
  --help              Show this help

Examples:
  node check-ports.js                           # Check default ports
  node check-ports.js --ports 8888,8889        # Check specific ports
  node check-ports.js --kill --yes              # Kill processes using ports
  node check-ports.js --wait                    # Wait for ports to become available

Exit codes:
  0 - All ports available
  1 - Some ports are in use
  2 - Error occurred
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help')) {
    showUsage();
    process.exit(0);
  }
  
  const portsIndex = args.indexOf('--ports');
  const portsArg = portsIndex !== -1 ? args[portsIndex + 1] : null;
  const kill = args.includes('--kill');
  const wait = args.includes('--wait');
  
  const ports = parsePorts(portsArg);
  
  if (ports.length === 0) {
    console.error('Error: No valid ports specified');
    process.exit(2);
  }
  
  try {
    const { allAvailable } = await checkPorts(ports, kill, wait);
    
    if (allAvailable) {
      console.log('\\n✅ All required ports are available!');
      process.exit(0);
    } else {
      console.log('\\n❌ Some ports are in use');
      console.log('💡 Solutions:');
      console.log('   • Stop the services using these ports');
      console.log('   • Use --kill flag to terminate processes (with --yes for automation)');
      console.log('   • Use --wait flag to wait for ports to become available');
      console.log('   • Change ports in .wp-env.override.json');
      process.exit(1);
    }
  } catch (error) {
    console.error('❌ Port check failed:', error.message);
    process.exit(2);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Port checking failed:', error.message);
    process.exit(2);
  });
}

module.exports = { checkPorts };
