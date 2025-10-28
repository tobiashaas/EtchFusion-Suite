import { test, expect } from '@playwright/test';
import { createBricksAdminContext, createEtchAdminContext } from './fixtures';

test.describe('EFS admin access', () => {
  test.describe.configure({ mode: 'serial' });

  test('Bricks dashboard is accessible after login', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      await expect(page.locator('h1')).toHaveText(/Etch Fusion Suite/i);
      await expect(page.locator('.efs-environment')).toBeVisible();
      await expect(page.locator('.efs-status-list li')).toHaveCount(3);
    } finally {
      await context.close();
    }
  });

  test('Etch target dashboard is accessible', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      await expect(page.locator('h1')).toHaveText(/Etch Fusion Suite/i);
      await expect(page.locator('.efs-dashboard')).toBeVisible();
    } finally {
      await context.close();
    }
  });
});
