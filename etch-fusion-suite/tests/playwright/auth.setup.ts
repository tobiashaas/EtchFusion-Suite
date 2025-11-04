import { test as setup, expect, Page } from '@playwright/test';
import * as path from 'path';
import { promises as fs } from 'fs';

const BRICKS_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/bricks.json');
const ETCH_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/etch.json');

type AuthConfig = {
  baseUrl: string;
  storagePath: string;
  username: string;
  password: string;
};

const ensureSiteReachable = async (page: Page, url: string) => {
  // Check if this is CI/CD environment
  const isCI = process.env.CI || process.env.GITHUB_ACTIONS;
  
  if (isCI) {
    console.log('🤖 CI/CD environment detected - skipping WordPress reachability check');
    console.log('⚠️ Playwright tests will focus on UI structure, not WordPress functionality');
    return; // Skip the check in CI/CD
  }
  
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  if (!response || !response.ok()) {
    throw new Error(`Site ${url} is not reachable (status: ${response?.status() ?? 'unknown'})`);
  }
};

const ensureAdminAccessible = async (page: Page, adminUrl: string) => {
  // Check if this is CI/CD environment
  const isCI = process.env.CI || process.env.GITHUB_ACTIONS;
  
  if (isCI) {
    console.log('🤖 CI/CD environment detected - skipping WordPress admin access check');
    return; // Skip the check in CI/CD
  }
  
  const response = await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() >= 400) {
    throw new Error(`Admin URL ${adminUrl} responded with status ${response?.status() ?? 'unknown'}`);
  }
};

const performLogin = async (page: Page, adminUrl: string, username: string, password: string) => {
  // Check if this is CI/CD environment
  const isCI = process.env.CI || process.env.GITHUB_ACTIONS;
  
  if (isCI) {
    console.log('🤖 CI/CD environment detected - skipping WordPress login');
    console.log('⚠️ Tests will run without authentication (UI structure only)');
    return; // Skip login in CI/CD
  }
  
  if (await page.locator('#wpadminbar').isVisible()) {
    return;
  }

  const loginUrl = adminUrl.replace(/\/wp-admin\/?$/, '/wp-login.php');
  const loginResponse = await page.request.post(loginUrl, {
    form: {
      log: username,
      pwd: password,
      rememberme: 'forever',
      redirect_to: adminUrl,
      testcookie: '1',
      'wp-submit': 'Log In',
    },
  });

  if (!loginResponse.ok()) {
    throw new Error(`Login request to ${loginUrl} failed with status ${loginResponse.status()}`);
  }

  await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });

  if (page.url().includes('wp-login.php')) {
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', { timeout: 20_000 });
  }
};

const authenticateAndStore = async (page: Page, config: AuthConfig) => {
  const { baseUrl, storagePath, username, password } = config;
  const adminUrl = `${baseUrl.replace(/\/$/, '')}/wp-admin/`;

  await fs.mkdir(path.dirname(storagePath), { recursive: true });

  try {
    await ensureSiteReachable(page, baseUrl);
    await ensureAdminAccessible(page, adminUrl);
    await performLogin(page, adminUrl, username, password);
    await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 10_000 });
    await page.context().storageState({ path: storagePath });
  } catch (error) {
    console.error(`[EFS][Playwright] Authentication failed for ${baseUrl}:`, error);
    throw error;
  }
};

setup('authenticate on Bricks instance', async ({ page }) => {
  const baseUrl = process.env.BRICKS_URL ?? 'http://localhost:8888';
  const username = process.env.EFS_ADMIN_USER ?? 'admin';
  const password = process.env.EFS_ADMIN_PASS ?? 'password';

  await authenticateAndStore(page, {
    baseUrl,
    storagePath: BRICKS_AUTH_FILE,
    username,
    password,
  });
});

setup('authenticate on Etch instance', async ({ page }) => {
  const baseUrl = process.env.ETCH_URL ?? 'http://localhost:8889';
  const username = process.env.EFS_ADMIN_USER ?? 'admin';
  const password = process.env.EFS_ADMIN_PASS ?? 'password';

  await authenticateAndStore(page, {
    baseUrl,
    storagePath: ETCH_AUTH_FILE,
    username,
    password,
  });
});
