import { test, expect } from '@playwright/test';
import { createBricksAdminContext, createEtchAdminContext } from './fixtures';

test.describe('Dashboard tabs', () => {
  test('Bricks dashboard tabs switch panels', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const tabList = page.locator('[data-efs-tabs] .efs-tab');
      await expect(tabList).toHaveCount(2);

      const logsTab = page.locator('[data-efs-tab="logs"]');
      await logsTab.click();
      await expect(page.locator('#efs-tab-logs')).toHaveAttribute('hidden', '');
      await expect(page.locator('#efs-tab-logs')).not.toBeHidden();
      await expect(page.locator('#efs-tab-progress')).toBeHidden();

      const progressTab = page.locator('[data-efs-tab="progress"]');
      await progressTab.click();
      await expect(page.locator('#efs-tab-progress')).not.toBeHidden();
    } finally {
      await context.close();
    }
  });

  test('Template tab visible and loads on Etch instance', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const templateTab = page.locator('[data-efs-tab="templates"]');
      await expect(templateTab).toBeVisible();
      await templateTab.click();

      const templatePanel = page.locator('[data-efs-tab-panel="templates"]');
      await expect(templatePanel).toHaveAttribute('class', /is-active/);
      await expect(templatePanel).not.toHaveAttribute('hidden', '');
    } finally {
      await context.close();
    }
  });
});
