import { test as setup, expect } from '@playwright/test';
import * as path from 'path';
import { promises as fs } from 'fs';

const BRICKS_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/bricks.json');
const ETCH_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/etch.json');

setup('authenticate on Bricks instance', async ({ page }) => {
  const bricksUrl = process.env.BRICKS_URL ?? 'http://localhost:8888';
  const username = process.env.EFS_ADMIN_USER ?? 'admin';
  const password = process.env.EFS_ADMIN_PASS ?? 'password';

  await fs.mkdir(path.dirname(BRICKS_AUTH_FILE), { recursive: true });

  const loginResponse = await page.request.post(`${bricksUrl}/wp-login.php`, {
    form: {
      log: username,
      pwd: password,
      rememberme: 'forever',
      redirect_to: `${bricksUrl}/wp-admin/`,
      testcookie: '1',
      'wp-submit': 'Log In',
    },
  });

  if (!loginResponse.ok()) {
    throw new Error(`Bricks login failed with status ${loginResponse.status()}`);
  }

  await page.goto(`${bricksUrl}/wp-admin/`, { waitUntil: 'domcontentloaded' });

  if (page.url().includes('wp-login.php')) {
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', { timeout: 15_000 });
  }

  await expect(page.locator('#wpadminbar')).toBeVisible();

  await page.context().storageState({ path: BRICKS_AUTH_FILE });
});

setup('authenticate on Etch instance', async ({ page }) => {
  const etchUrl = process.env.ETCH_URL ?? 'http://localhost:8889';
  const username = process.env.EFS_ADMIN_USER ?? 'admin';
  const password = process.env.EFS_ADMIN_PASS ?? 'password';

  await fs.mkdir(path.dirname(ETCH_AUTH_FILE), { recursive: true });

  const loginResponse = await page.request.post(`${etchUrl}/wp-login.php`, {
    form: {
      log: username,
      pwd: password,
      rememberme: 'forever',
      redirect_to: `${etchUrl}/wp-admin/`,
      testcookie: '1',
      'wp-submit': 'Log In',
    },
  });

  if (!loginResponse.ok()) {
    throw new Error(`Etch login failed with status ${loginResponse.status()}`);
  }

  await page.goto(`${etchUrl}/wp-admin/`, { waitUntil: 'domcontentloaded' });

  if (page.url().includes('wp-login.php')) {
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**', { timeout: 15_000 });
  }

  await expect(page.locator('#wpadminbar')).toBeVisible();

  await page.context().storageState({ path: ETCH_AUTH_FILE });
});
