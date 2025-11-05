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

    // Handle stdin input if provided
    if (options.input && child.stdin) {
      child.stdin.write(options.input);
      child.stdin.end();
    }

    child.on('error', reject);

    child.on('exit', (code) => {
      resolve({ code, stdout, stderr });
    });
  });
}

function runWpEnv(args) {
  return runCommand(WP_ENV_CMD, args);
}

function loadManifest() {
  const manifestPath = path.join(__dirname, '..', 'backups', 'manifest.json');
  
  if (fs.existsSync(manifestPath)) {
    try {
      return JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    } catch (error) {
      console.warn('Warning: Could not read manifest file:', error.message);
    }
  }
  
  return { backups: [] };
}

function findBackup(identifier) {
  const manifest = loadManifest();
  
  // Try to find by name or timestamp
  let backup = manifest.backups.find(b => b.name === identifier);
  
  if (!backup) {
    backup = manifest.backups.find(b => b.timestamp.includes(identifier));
  }
  
  if (!backup) {
    // Try to find by partial match
    backup = manifest.backups.find(b => 
      b.name.toLowerCase().includes(identifier.toLowerCase()) ||
      b.timestamp.includes(identifier)
    );
  }
  
  return backup;
}

function decompressFile(compressedPath, outputPath) {
  return new Promise((resolve, reject) => {
    const input = fs.createReadStream(compressedPath);
    const output = fs.createWriteStream(outputPath);
    const gunzip = zlib.createGunzip();
    
    input
      .pipe(gunzip)
      .pipe(output)
      .on('finish', () => {
        resolve(outputPath);
      })
      .on('error', reject);
  });
}

async function restoreDatabase(environment, sqlFile, dryRun = false) {
  console.log(`🔄 Restoring ${environment} database...`);
  
  if (dryRun) {
    console.log(`   🔍 DRY RUN: Would restore from ${sqlFile}`);
    return { success: true, message: 'Dry run - no changes made' };
  }
  
  try {
    // Check if SQL file exists
    if (!fs.existsSync(sqlFile)) {
      throw new Error(`SQL file not found: ${sqlFile}`);
    }
    
    // Handle compressed files
    let finalSqlFile = sqlFile;
    if (sqlFile.endsWith('.gz')) {
      console.log(`   🗜️ Decompressing ${sqlFile}...`);
      finalSqlFile = sqlFile.replace('.gz', '.tmp.sql');
      await decompressFile(sqlFile, finalSqlFile);
    }
    
    // Copy SQL file to container
    const containerFileName = `restore-${environment}.sql`;
    const containerPath = `wp-content/${containerFileName}`;
    
    const sqlContent = fs.readFileSync(finalSqlFile, 'utf8');
    
    // Map logical environment to wp-env target
    const wpEnvTarget = environment === 'bricks' ? 'cli' : 'tests-cli';
    
    // Write to container
    const writeResult = await runWpEnv(['run', wpEnvTarget, 'sh', '-c', `cat > ${containerPath}`], {
      input: sqlContent
    });
    
    if (writeResult.code !== 0) {
      throw new Error(`Failed to copy SQL file to container: ${writeResult.stderr}`);
    }
    
    // Import database
    console.log(`   📥 Importing database...`);
    const importResult = await runWpEnv(['run', wpEnvTarget, 'wp', 'db', 'import', containerPath]);
    
    if (importResult.code !== 0) {
      throw new Error(`Database import failed: ${importResult.stderr}`);
    }
    
    // Clean up
    await runWpEnv(['run', wpEnvTarget, 'rm', containerPath]);
    
    // Clean up temporary decompressed file
    if (finalSqlFile !== sqlFile && fs.existsSync(finalSqlFile)) {
      fs.unlinkSync(finalSqlFile);
    }
    
    console.log(`   ✅ ${environment} database restored successfully`);
    
    return { success: true, message: 'Database restored successfully' };
    
  } catch (error) {
    // Clean up temporary files on error
    const tempFile = sqlFile.replace('.gz', '.tmp.sql');
    if (fs.existsSync(tempFile)) {
      fs.unlinkSync(tempFile);
    }
    
    throw error;
  }
}

async function confirmAction(message) {
  if (process.argv.includes('--yes')) {
    return true;
  }
  
  console.log(`\\n⚠️  ${message}`);
  console.log('❓ Continue? [y/N]');
  
  // For automation purposes, we'll assume confirmation
  // In a real implementation, you'd use readline or similar
  return process.argv.includes('--yes');
}

async function clearCaches() {
  console.log('🧹 Clearing WordPress caches...');
  
  try {
    await Promise.all([
      runWpEnv(['run', 'cli', 'wp', 'cache', 'flush']),
      runWpEnv(['run', 'tests-cli', 'wp', 'cache', 'flush'])
    ]);
    console.log('   ✅ Caches cleared');
  } catch (error) {
    console.warn('   ⚠️ Could not clear caches:', error.message);
  }
}

async function restoreBackup(identifier, options = {}) {
  const { 
    bricks = null, 
    etch = null, 
    all = null, 
    dryRun = false, 
    skipConfirmation = false 
  } = options;
  
  console.log('🚀 Starting database restore...\\n');
  
  try {
    let backup;
    let environments = [];
    
    // Determine which backup to use
    if (all) {
      backup = findBackup(all);
      if (!backup) {
        throw new Error(`Backup not found: ${all}`);
      }
      environments = ['bricks', 'etch'];
    } else if (bricks) {
      backup = findBackup(bricks);
      if (!backup) {
        throw new Error(`Backup not found: ${bricks}`);
      }
      environments = ['bricks'];
    } else if (etch) {
      backup = findBackup(etch);
      if (!backup) {
        throw new Error(`Backup not found: ${etch}`);
      }
      environments = ['etch'];
    } else {
      throw new Error('Must specify --all, --bricks, or --etch');
    }
    
    // Display backup information
    console.log('📋 Backup Information:');
    console.log(`   🏷️  Name: ${backup.name}`);
    console.log(`   🕒 Created: ${new Date(backup.timestamp).toLocaleString()}`);
    console.log(`   📊 Size: ${formatBytes(backup.totalSize || 0)}`);
    console.log(`   🗜️ Compressed: ${backup.compressed ? 'Yes' : 'No'}`);
    
    if (backup.environments.bricks.wordpressVersion) {
      console.log(`   📦 Bricks WordPress: ${backup.environments.bricks.wordpressVersion}`);
    }
    if (backup.environments.etch.wordpressVersion) {
      console.log(`   📦 Etch WordPress: ${backup.environments.etch.wordpressVersion}`);
    }
    
    console.log(`\\n🎯 Environments to restore: ${environments.join(', ')}`);
    
    // Confirmation
    if (!dryRun && !skipConfirmation) {
      const confirmed = await confirmAction(
        'This will overwrite the current database(s) with the selected backup.'
      );
      
      if (!confirmed) {
        console.log('❌ Restore cancelled by user');
        return { cancelled: true };
      }
    }
    
    // Create automatic backup of current state
    if (!dryRun) {
      console.log('\\n💾 Creating automatic backup of current state...');
      const { backupAllDatabases } = require('./backup-db');
      const autoBackup = await backupAllDatabases('pre-restore', false);
      console.log(`   ✅ Automatic backup created: ${autoBackup.name}`);
    }
    
    // Restore databases
    const results = {};
    
    for (const env of environments) {
      const envData = backup.environments[env];
      const sqlFile = envData.filePath;
      
      if (!fs.existsSync(sqlFile)) {
        console.error(`❌ SQL file not found for ${env}: ${sqlFile}`);
        results[env] = { success: false, error: 'SQL file not found' };
        continue;
      }
      
      try {
        results[env] = await restoreDatabase(env, sqlFile, dryRun);
      } catch (error) {
        console.error(`❌ Failed to restore ${env}: ${error.message}`);
        results[env] = { success: false, error: error.message };
      }
    }
    
    // Clear caches
    if (!dryRun && Object.values(results).some(r => r.success)) {
      await clearCaches();
    }
    
    // Display summary
    console.log('\\n📊 Restore Summary:');
    
    for (const [env, result] of Object.entries(results)) {
      const status = result.success ? '✅' : '❌';
      console.log(`   ${status} ${env}: ${result.message || result.error}`);
    }
    
    const successful = Object.values(results).filter(r => r.success).length;
    const failed = Object.values(results).filter(r => !r.success).length;
    
    console.log(`\\n🎉 Restore completed: ${successful} successful, ${failed} failed`);
    
    if (!dryRun && successful > 0) {
      console.log('\\n🔧 Post-restore suggestions:');
      console.log('   • Run npm run activate to ensure plugins are active');
      console.log('   • Check WordPress admin pages for proper functionality');
      console.log('   • Run npm run health to verify environment status');
    }
    
    return { results, successful, failed };
    
  } catch (error) {
    throw new Error(`Database restore failed: ${error.message}`);
  }
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function showUsage() {
  console.log(`
Usage: node restore-db.js <option> <backup-identifier> [options]

Options:
  --all <name>        Restore both databases from backup
  --bricks <file>     Restore only Bricks database from backup
  --etch <file>       Restore only Etch database from backup
  --dry-run           Show what would be restored without making changes
  --yes               Skip confirmation prompts (for automation)
  --help              Show this help

Arguments:
  backup-identifier   Backup name, timestamp, or partial match

Examples:
  node restore-db.js --all 2023-12-01T15-30-00     # Restore both from specific backup
  node restore-db.js --bricks pre-migration        # Restore only Bricks from named backup
  node restore-db.js --all latest --dry-run        # Preview restore of latest backup
  node restore-db.js --etch backup1 --yes          # Restore Etch without confirmation

Backup identifiers:
  • Full backup name (e.g., "pre-migration")
  • Timestamp (e.g., "2023-12-01T15-30-00") 
  • Partial match (e.g., "migration" or "2023-12-01")

Safety features:
  • Automatic backup created before restore
  • Confirmation prompts (unless --yes used)
  • Cache clearing after restore
  • Detailed error reporting
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help') || args.length < 2) {
    showUsage();
    process.exit(0);
  }
  
  const allIndex = args.indexOf('--all');
  const bricksIndex = args.indexOf('--bricks');
  const etchIndex = args.indexOf('--etch');
  
  const options = {
    dryRun: args.includes('--dry-run'),
    skipConfirmation: args.includes('--yes')
  };
  
  if (allIndex !== -1 && args[allIndex + 1]) {
    options.all = args[allIndex + 1];
  } else if (bricksIndex !== -1 && args[bricksIndex + 1]) {
    options.bricks = args[bricksIndex + 1];
  } else if (etchIndex !== -1 && args[etchIndex + 1]) {
    options.etch = args[etchIndex + 1];
  } else {
    console.error('Error: Must specify --all, --bricks, or --etch with a backup identifier');
    process.exit(1);
  }
  
  try {
    const result = await restoreBackup(null, options);
    
    if (result.cancelled) {
      process.exit(0);
    }
    
    if (result.failed > 0) {
      process.exit(1);
    }
    
  } catch (error) {
    console.error('❌', error.message);
    process.exit(1);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Database restore failed:', error.message);
    process.exit(1);
  });
}

module.exports = { restoreBackup };
