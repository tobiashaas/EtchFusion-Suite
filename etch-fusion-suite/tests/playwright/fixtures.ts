import { expect, Page, Browser, BrowserContext } from '@playwright/test';
import * as path from 'path';

const DEFAULT_ADMIN_USERNAME = process.env.EFS_ADMIN_USER ?? 'admin';
const DEFAULT_ADMIN_PASSWORD = process.env.EFS_ADMIN_PASS ?? 'password';
const DEFAULT_BRICKS_URL = process.env.BRICKS_URL ?? 'http://localhost:8888';
const DEFAULT_ETCH_URL = process.env.ETCH_URL ?? 'http://localhost:8889';

const BRICKS_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/bricks.json');
const ETCH_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/etch.json');

export interface LoginOptions {
  username?: string;
  password?: string;
}

export const loginToWordPress = async (
  page: Page,
  options: LoginOptions = {}
): Promise<void> => {
  const username = options.username ?? DEFAULT_ADMIN_USERNAME;
  const password = options.password ?? DEFAULT_ADMIN_PASSWORD;

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  const adminBar = page.locator('#wpadminbar');
  if (await adminBar.isVisible()) {
    return;
  }

  const currentUrl = page.url();
  const origin = new URL(currentUrl).origin;

  const loginResponse = await page.request.post(`${origin}/wp-login.php`, {
    form: {
      log: username,
      pwd: password,
      'rememberme': 'forever',
      redirect_to: `${origin}/wp-admin/`,
      testcookie: '1',
      'wp-submit': 'Log In',
    },
  });

  if (!loginResponse.ok()) {
    throw new Error(`WordPress login request failed with status ${loginResponse.status()}`);
  }

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  if (page.url().includes('wp-login.php')) {
    const loginError = await page.locator('#login_error').innerText({ timeout: 2_000 }).catch(() => '');
    throw new Error(`WordPress login failed${loginError ? `: ${loginError.trim()}` : ''}`);
  }

  await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 10_000 });
};

export const openPluginDashboard = async (page: Page): Promise<void> => {
  await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', {
    waitUntil: 'domcontentloaded',
  });

  if (page.url().includes('wp-login.php')) {
    await loginToWordPress(page);
    await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', {
      waitUntil: 'networkidle',
    });
  } else {
    await page.waitForLoadState('networkidle');
  }

  await expect(page.locator('.efs-admin-wrap')).toBeVisible();
};

type ContextOverrides = Parameters<Browser['newContext']>[0];

export const createEtchAdminContext = async (
  browser: Browser,
  overrides: ContextOverrides = {}
): Promise<{ context: BrowserContext; page: Page }> => {
  const context = await browser.newContext({
    baseURL: DEFAULT_ETCH_URL,
    storageState: ETCH_AUTH_FILE,
    ...overrides,
  });
  const page = await context.newPage();
  await openPluginDashboard(page);
  return { context, page };
};

export const createBricksAdminContext = async (
  browser: Browser,
  overrides: ContextOverrides = {}
): Promise<{ context: BrowserContext; page: Page }> => {
  const context = await browser.newContext({
    baseURL: DEFAULT_BRICKS_URL,
    storageState: BRICKS_AUTH_FILE,
    ...overrides,
  });
  const page = await context.newPage();
  await openPluginDashboard(page);
  return { context, page };
};
