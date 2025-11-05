import { FullConfig } from '@playwright/test';
import { runHealthCheck } from '../../scripts/health-check.js';
import path from 'path';
import fs from 'fs';

async function globalSetup(config: FullConfig) {
  console.log('🚀 Playwright Global Setup - Starting test environment verification...');
  
  // Create .playwright-auth directory if it doesn't exist
  const authDir = path.resolve(__dirname, '..', '..', '.playwright-auth');
  if (!fs.existsSync(authDir)) {
    fs.mkdirSync(authDir, { recursive: true });
    console.log('📁 Created .playwright-auth directory');
  }
  
  // Check if we should skip health check
  const skipHealthCheck = process.env.SKIP_HEALTH_CHECK === 'true';
  
  if (!skipHealthCheck) {
    console.log('🔍 Running health checks on WordPress instances...');
    
    try {
      const healthReport = await runHealthCheck(false, false);
      
      if (healthReport.summary.failed > 0) {
        console.error('❌ Health checks failed:');
        console.error(`   Failed: ${healthReport.summary.failed}`);
        console.error(`   Warnings: ${healthReport.summary.warnings}`);
        console.error('\\n💡 Run npm run health for more details');
        console.error('💡 Ensure WordPress environments are running with npm run dev');
        
        throw new Error('WordPress instances are not healthy. Tests cannot proceed.');
      }
      
      console.log('✅ All health checks passed');
      console.log(`   Passed: ${healthReport.summary.passed}`);
      console.log(`   Warnings: ${healthReport.summary.warnings}`);
      
    } catch (error) {
      console.error('❌ Health check failed:', error instanceof Error ? error.message : 'Unknown error');
      throw error;
    }
  } else {
    console.log('⏭ Skipping health checks (SKIP_HEALTH_CHECK=true)');
  }
  
  // Check auth file freshness
  const bricksAuthFile = path.join(authDir, 'bricks.json');
  const etchAuthFile = path.join(authDir, 'etch.json');
  
  const checkAuthFileFreshness = (filePath: string, name: string) => {
    if (fs.existsSync(filePath)) {
      const stats = fs.statSync(filePath);
      const ageHours = (Date.now() - stats.mtime.getTime()) / (1000 * 60 * 60);
      
      if (ageHours > 24) {
        console.warn(`⚠️ ${name} auth file is ${Math.round(ageHours)} hours old. Consider refreshing authentication.`);
        console.warn('💡 Run npm run test:playwright to refresh auth if needed');
      } else {
        console.log(`✅ ${name} auth file is fresh (${Math.round(ageHours)} hours old)`);
      }
    } else {
      console.log(`📝 ${name} auth file not found - will be created during setup`);
    }
  };
  
  checkAuthFileFreshness(bricksAuthFile, 'Bricks');
  checkAuthFileFreshness(etchAuthFile, 'Etch');
  
  // Log environment information for debugging
  console.log('\\n🌐 Test Environment Information:');
  console.log(`   Node.js: ${process.version}`);
  console.log(`   Platform: ${process.platform}`);
  console.log(`   Timestamp: ${new Date().toISOString()}`);
  
  // Log URLs from config metadata
  const metadata = config.metadata as any;
  if (metadata?.bricksUrl && metadata?.etchUrl) {
    console.log(`   Bricks URL: ${metadata.bricksUrl}`);
    console.log(`   Etch URL: ${metadata.etchUrl}`);
  }
  
  // Check for Framer integration
  if (process.env.EFS_ENABLE_FRAMER) {
    console.log('   🎨 Framer integration: ENABLED');
  } else {
    console.log('   🎨 Framer integration: DISABLED (Framer tests will be skipped)');
  }
  
  console.log('\\n✅ Global setup completed successfully');
  
  // Return config that can be used by tests
  return {
    authDir,
    setupTime: new Date().toISOString(),
    healthCheckSkipped: skipHealthCheck
  };
}

export default globalSetup;
