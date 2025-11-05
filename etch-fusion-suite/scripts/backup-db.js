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

function createBackupsDirectory() {
  const backupsDir = path.join(__dirname, '..', 'backups');
  if (!fs.existsSync(backupsDir)) {
    fs.mkdirSync(backupsDir, { recursive: true });
  }
  return backupsDir;
}

function getTimestamp() {
  return new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
}

function getBackupName(name, timestamp) {
  return name || timestamp;
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

function saveManifest(manifest) {
  const manifestPath = path.join(__dirname, '..', 'backups', 'manifest.json');
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
}

function addToManifest(backupInfo) {
  const manifest = loadManifest();
  manifest.backups.push(backupInfo);
  
  // Keep only last 50 backups in manifest
  if (manifest.backups.length > 50) {
    manifest.backups = manifest.backups.slice(-50);
  }
  
  saveManifest(manifest);
  return manifest;
}

function getFileSize(filePath) {
  try {
    const stats = fs.statSync(filePath);
    return stats.size;
  } catch (error) {
    return 0;
  }
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function validateSqlFile(filePath) {
  try {
    const content = fs.readFileSync(filePath, 'utf8');
    
    // Basic SQL validation - check for common SQL patterns
    const sqlPatterns = [
      /CREATE TABLE/i,
      /INSERT INTO/i,
      /DROP TABLE/i,
      /-- MySQL dump/i,
      /-- Host:/i,
      /-- Server version/i
    ];
    
    const hasSqlPatterns = sqlPatterns.some(pattern => pattern.test(content));
    
    if (!hasSqlPatterns) {
      throw new Error('File does not appear to be a valid SQL dump');
    }
    
    // Check if file is not empty
    if (content.length < 1000) {
      throw new Error('SQL file appears to be empty or truncated');
    }
    
    return true;
  } catch (error) {
    throw new Error(`SQL validation failed: ${error.message}`);
  }
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
        // Remove original file after successful compression
        fs.unlinkSync(filePath);
        resolve(compressedPath);
      })
      .on('error', reject);
  });
}

async function backupDatabase(environment, name, timestamp) {
  const backupName = getBackupName(name, timestamp);
  const fileName = `${environment}-${backupName}.sql`;
  const filePath = path.join(createBackupsDirectory(), fileName);
  
  console.log(`📦 Backing up ${environment} database...`);
  
  try {
    // Map logical environment to wp-env target using normalization
    const wpEnvTarget = normalizeEnvironmentToTarget(environment);
    
    // Export database using wp-cli
    const result = await runWpEnv(['run', wpEnvTarget, 'wp', 'db', 'export', `wp-content/${fileName}`]);
    
    if (result.code !== 0) {
      throw new Error(`Database export failed: ${result.stderr}`);
    }
    
    // Copy the exported file from container to host
    const copyResult = await runWpEnv(['run', wpEnvTarget, 'cat', `wp-content/${fileName}`]);
    
    if (copyResult.code !== 0) {
      throw new Error(`Failed to read exported database: ${copyResult.stderr}`);
    }
    
    // Write to host filesystem
    fs.writeFileSync(filePath, copyResult.stdout);
    
    // Clean up the file from container
    await runWpEnv(['run', wpEnvTarget, 'rm', `wp-content/${fileName}`]);
    
    // Validate the SQL file
    validateSqlFile(filePath);
    
    const fileSize = getFileSize(filePath);
    
    console.log(`   ✅ ${environment} database backed up successfully`);
    console.log(`   📄 File: ${filePath}`);
    console.log(`   📊 Size: ${formatBytes(fileSize)}`);
    
    return {
      environment,
      fileName,
      filePath,
      fileSize,
      timestamp
    };
    
  } catch (error) {
    // Clean up partial file if it exists
    if (fs.existsSync(filePath)) {
      fs.unlinkSync(filePath);
    }
    throw error;
  }
}

function normalizeEnvironmentToTarget(environment) {
  // Accept both logical names and direct targets
  if (environment === 'bricks' || environment === 'cli') {
    return 'cli';
  }
  if (environment === 'etch' || environment === 'tests-cli') {
    return 'tests-cli';
  }
  throw new Error(`Unknown environment label: ${environment}. Expected 'bricks', 'etch', 'cli', or 'tests-cli'`);
}

async function getWordPressVersion(environment) {
  try {
    const wpEnvTarget = normalizeEnvironmentToTarget(environment);
    
    // Debug logging when verbose mode is enabled
    if (process.argv.includes('--verbose') || process.env.EFS_DEBUG) {
      console.log(`🔍 Getting WordPress version from environment: ${environment} -> target: ${wpEnvTarget}`);
    }
    
    const result = await runWpEnv(['run', wpEnvTarget, 'wp', 'core', 'version']);
    return result.code === 0 ? result.stdout.trim() : 'unknown';
  } catch (error) {
    return 'unknown';
  }
}

async function getPluginVersion(environment) {
  try {
    const wpEnvTarget = normalizeEnvironmentToTarget(environment);
    
    // Debug logging when verbose mode is enabled
    if (process.argv.includes('--verbose') || process.env.EFS_DEBUG) {
      console.log(`🔍 Getting plugin version from environment: ${environment} -> target: ${wpEnvTarget}`);
    }
    
    const result = await runWpEnv(['run', wpEnvTarget, 'wp', 'plugin', 'get', 'etch-fusion-suite', '--field=version']);
    return result.code === 0 ? result.stdout.trim() : 'unknown';
  } catch (error) {
    return 'unknown';
  }
}

async function backupAllDatabases(name = null, compress = false) {
  const timestamp = getTimestamp();
  const backupsDir = createBackupsDirectory();
  
  console.log('🚀 Starting database backup...\\n');
  
  try {
    // Get version information using logical names for clarity
    const [bricksWpVersion, etchWpVersion, bricksPluginVersion, etchPluginVersion] = await Promise.all([
      getWordPressVersion('bricks'),
      getWordPressVersion('etch'),
      getPluginVersion('bricks'),
      getPluginVersion('etch')
    ]);
    
    // Backup both databases
    const [bricksBackup, etchBackup] = await Promise.all([
      backupDatabase('bricks', name, timestamp),
      backupDatabase('etch', name, timestamp)
    ]);
    
    // Compress if requested
    if (compress) {
      console.log('\\n🗜️ Compressing backup files...');
      
      bricksBackup.filePath = await compressFile(bricksBackup.filePath);
      bricksBackup.fileName += '.gz';
      bricksBackup.fileSize = getFileSize(bricksBackup.filePath);
      
      etchBackup.filePath = await compressFile(etchBackup.filePath);
      etchBackup.fileName += '.gz';
      etchBackup.fileSize = getFileSize(etchBackup.filePath);
    }
    
    // Create backup info
    const backupInfo = {
      timestamp,
      name: name || timestamp,
      compressed: compress,
      environments: {
        bricks: {
          ...bricksBackup,
          wordpressVersion: bricksWpVersion,
          pluginVersion: bricksPluginVersion
        },
        etch: {
          ...etchBackup,
          wordpressVersion: etchWpVersion,
          pluginVersion: etchPluginVersion
        }
      },
      totalSize: bricksBackup.fileSize + etchBackup.fileSize
    };
    
    // Add to manifest
    addToManifest(backupInfo);
    
    // Display summary
    console.log('\\n📊 Backup Summary:');
    console.log(`   📁 Directory: ${backupsDir}`);
    console.log(`   📦 Bricks: ${bricksBackup.filePath} (${formatBytes(bricksBackup.fileSize)})`);
    console.log(`   📦 Etch: ${etchBackup.filePath} (${formatBytes(etchBackup.fileSize)})`);
    console.log(`   📊 Total Size: ${formatBytes(backupInfo.totalSize)}`);
    console.log(`   🏷️  Name: ${backupInfo.name}`);
    console.log(`   🕒 Timestamp: ${timestamp}`);
    
    if (compress) {
      console.log(`   🗜️ Compressed: Yes`);
    }
    
    console.log('\\n✅ Database backup completed successfully!');
    
    return backupInfo;
    
  } catch (error) {
    throw new Error(`Database backup failed: ${error.message}`);
  }
}

async function listBackups() {
  const manifest = loadManifest();
  
  if (manifest.backups.length === 0) {
    console.log('📭 No backups found.');
    return;
  }
  
  console.log(`📋 Available Backups (${manifest.backups.length}):\\n`);
  
  // Sort by timestamp (newest first)
  const sortedBackups = manifest.backups.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
  
  sortedBackups.forEach((backup, index) => {
    const date = new Date(backup.timestamp).toLocaleString();
    const totalSize = formatBytes(backup.totalSize || 0);
    
    console.log(`${index + 1}. ${backup.name}`);
    console.log(`   🕒 ${date}`);
    console.log(`   📊 Size: ${totalSize}`);
    console.log(`   🗜️ Compressed: ${backup.compressed ? 'Yes' : 'No'}`);
    console.log(`   📦 Files:`);
    console.log(`     - Bricks: ${backup.environments.bricks.fileName}`);
    console.log(`     - Etch: ${backup.environments.etch.fileName}`);
    console.log('');
  });
}

async function cleanOldBackups(maxAgeDays = 30) {
  const backupsDir = createBackupsDirectory();
  const manifest = loadManifest();
  const cutoffTime = Date.now() - (maxAgeDays * 24 * 60 * 60 * 1000);
  
  let deletedCount = 0;
  let deletedSize = 0;
  
  // Filter out old backups from manifest
  const newManifest = {
    backups: manifest.backups.filter(backup => {
      const backupTime = new Date(backup.timestamp).getTime();
      
      if (backupTime < cutoffTime) {
        // Delete files
        try {
          if (fs.existsSync(backup.environments.bricks.filePath)) {
            deletedSize += getFileSize(backup.environments.bricks.filePath);
            fs.unlinkSync(backup.environments.bricks.filePath);
          }
          
          if (fs.existsSync(backup.environments.etch.filePath)) {
            deletedSize += getFileSize(backup.environments.etch.filePath);
            fs.unlinkSync(backup.environments.etch.filePath);
          }
          
          deletedCount++;
          return false; // Remove from manifest
        } catch (error) {
          console.warn(`Warning: Could not delete backup ${backup.name}: ${error.message}`);
          return true; // Keep in manifest if deletion failed
        }
      }
      
      return true; // Keep in manifest
    })
  };
  
  // Save updated manifest
  saveManifest(newManifest);
  
  console.log(`🧹 Cleanup completed:`);
  console.log(`   🗑️  Deleted backups: ${deletedCount}`);
  console.log(`   💾 Freed space: ${formatBytes(deletedSize)}`);
  console.log(`   📅 Max age: ${maxAgeDays} days`);
  
  return { deletedCount, deletedSize };
}

function showUsage() {
  console.log(`
Usage: node backup-db.js [options]

Options:
  --name <name>       Custom backup name (default: timestamp)
  --compress          Compress backup files with gzip
  --list              List all available backups
  --clean <days>      Remove backups older than specified days (default: 30)
  --verbose           Show debug information including which targets are being queried
  --help              Show this help

Examples:
  node backup-db.js                        # Backup with timestamp name
  node backup-db.js --name "pre-migration" # Backup with custom name
  node backup-db.js --compress             # Backup and compress
  node backup-db.js --list                 # List all backups
  node backup-db.js --clean 7              # Remove backups older than 7 days

Backup files are saved in the 'backups/' directory:
  - bricks-<name>.sql[.gz]     - Bricks (development) database
  - etch-<name>.sql[.gz]       - Etch (tests) database
  - manifest.json              - Backup metadata and index
`);
}

async function main() {
  const args = process.argv.slice(2);
  
  if (args.includes('--help')) {
    showUsage();
    process.exit(0);
  }
  
  if (args.includes('--list')) {
    await listBackups();
    return;
  }
  
  const cleanIndex = args.indexOf('--clean');
  if (cleanIndex !== -1) {
    const days = args[cleanIndex + 1] ? parseInt(args[cleanIndex + 1]) : 30;
    
    if (isNaN(days) || days < 1) {
      console.error('Error: --clean requires a positive number of days');
      process.exit(1);
    }
    
    await cleanOldBackups(days);
    return;
  }
  
  const nameIndex = args.indexOf('--name');
  const name = nameIndex !== -1 ? args[nameIndex + 1] : null;
  const compress = args.includes('--compress');
  
  try {
    await backupAllDatabases(name, compress);
  } catch (error) {
    console.error('❌', error.message);
    process.exit(1);
  }
}

if (require.main === module) {
  main().catch((error) => {
    console.error('Database backup failed:', error.message);
    process.exit(1);
  });
}

module.exports = { backupAllDatabases, listBackups, cleanOldBackups };
