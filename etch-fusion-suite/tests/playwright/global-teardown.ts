import { FullConfig } from '@playwright/test';
import { saveLogs } from '../../scripts/save-logs.js';
import path from 'path';
import fs from 'fs';

async function globalTeardown(config: FullConfig) {
  console.log('\\n🧹 Playwright Global Teardown - Cleaning up after tests...');
  
  const saveLogsOnSuccess = process.env.SAVE_LOGS_ON_SUCCESS === 'true';
  const saveLogsOnFailure = process.env.SAVE_LOGS_ON_FAILURE !== 'false'; // Default to true
  
  // Determine if we should save logs based on test results
  let shouldSaveLogs = false;
  
  if (saveLogsOnSuccess || saveLogsOnFailure) {
    // Try to determine if tests failed by checking for test results
    // This is a heuristic - in a real implementation you might want to pass test status
    const reportDir = path.resolve(__dirname, '..', '..', 'playwright-report');
    const resultsFile = path.join(reportDir, 'results.json');
    
    if (fs.existsSync(resultsFile)) {
      try {
        const results = JSON.parse(fs.readFileSync(resultsFile, 'utf8'));
        const hasFailures = results.suites?.some((suite: any) => 
          suite.specs?.some((spec: any) => 
            spec.tests?.some((test: any) => test.results?.some((result: any) => result.status === 'failed'))
          )
        );
        
        shouldSaveLogs = hasFailures ? saveLogsOnFailure : saveLogsOnSuccess;
        
        if (hasFailures) {
          console.log('❌ Test failures detected, logs will be saved');
        } else {
          console.log('✅ All tests passed');
        }
      } catch (error) {
        console.warn('⚠️ Could not read test results, assuming logs should be saved');
        shouldSaveLogs = true;
      }
    } else {
      // If we can't determine test results, save logs if we're in CI
      shouldSaveLogs = process.env.CI === 'true' || saveLogsOnFailure;
    }
  }
  
  // Save logs if needed
  if (shouldSaveLogs) {
    console.log('📥 Saving test logs for debugging...');
    
    try {
      const logReport = await saveLogs(500, true); // Save last 500 lines, compressed
      
      console.log('✅ Test logs saved:');
      console.log(`   📁 Directory: logs/`);
      console.log(`   📊 Total size: ${logReport.totalSize} MB`);
      console.log(`   🕒 Timestamp: ${logReport.timestamp}`);
      
      // If in CI, also copy logs to artifacts directory
      if (process.env.CI === 'true') {
        const artifactsDir = process.env.ARTIFACTS_DIR || 'test-artifacts';
        
        try {
          if (!fs.existsSync(artifactsDir)) {
            fs.mkdirSync(artifactsDir, { recursive: true });
          }
          
          // Copy log files to artifacts directory
          for (const file of logReport.files) {
            const fileName = path.basename(file.path);
            const artifactPath = path.join(artifactsDir, fileName);
            fs.copyFileSync(file.path, artifactPath);
          }
          
          console.log(`📦 Logs also copied to CI artifacts: ${artifactsDir}/`);
        } catch (error) {
          console.warn('⚠️ Could not copy logs to CI artifacts:', error instanceof Error ? error.message : 'Unknown error');
        }
      }
      
    } catch (error) {
      console.warn('⚠️ Failed to save test logs:', error instanceof Error ? error.message : 'Unknown error');
    }
  } else {
    console.log('📝 Logs not saved (tests passed and SAVE_LOGS_ON_SUCCESS is false)');
  }
  
  // Clean up temporary files
  console.log('🧹 Cleaning up temporary files...');
  
  const tempDirs = [
    path.resolve(__dirname, '..', '..', '.playwright-auth', 'temp'),
    path.resolve(__dirname, '..', '..', 'test-temp'),
    path.resolve(__dirname, '..', '..', 'temp-test-downloads')
  ];
  
  let cleanedFiles = 0;
  for (const tempDir of tempDirs) {
    if (fs.existsSync(tempDir)) {
      try {
        const files = fs.readdirSync(tempDir);
        for (const file of files) {
          const filePath = path.join(tempDir, file);
          const stats = fs.statSync(filePath);
          
          // Remove files older than 1 hour
          if (Date.now() - stats.mtime.getTime() > 60 * 60 * 1000) {
            fs.unlinkSync(filePath);
            cleanedFiles++;
          }
        }
      } catch (error) {
        console.warn(`⚠️ Could not clean temp directory ${tempDir}:`, error instanceof Error ? error.message : 'Unknown error');
      }
    }
  }
  
  if (cleanedFiles > 0) {
    console.log(`   🗑️ Removed ${cleanedFiles} temporary files`);
  }
  
  // Display test execution summary
  const endTime = new Date();
  const startTime = config.metadata?.globalSetup?.setupTime ? new Date(config.metadata.globalSetup.setupTime) : endTime;
  const duration = endTime.getTime() - startTime.getTime();
  const durationMinutes = Math.round(duration / (1000 * 60));
  
  console.log('\\n📊 Test Execution Summary:');
  console.log(`   🕐 Duration: ${durationMinutes} minutes`);
  console.log(`   🏁 End time: ${endTime.toISOString()}`);
  console.log(`   🌐 Platform: ${process.platform}`);
  console.log(`   🔧 Node.js: ${process.version}`);
  
  if (process.env.CI === 'true') {
    console.log(`   🏗️ CI Environment: Yes`);
  }
  
  console.log('\\n✅ Global teardown completed');
}

export default globalTeardown;
