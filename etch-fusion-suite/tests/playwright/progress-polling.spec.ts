import { test, expect } from '@playwright/test';
import { createBricksAdminContext } from './fixtures';

test.describe('Migration progress widget', () => {
  test('renders initial progress state from window data', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const progressTab = page.locator('[data-efs-tab="progress"]');
      await progressTab.click();

      const progressBar = page.locator('[data-efs-progress] .efs-progress-fill');
      await expect(progressBar).toBeVisible();

      const currentStep = page.locator('[data-efs-current-step]');
      await expect(currentStep).toHaveText(/migration|awaiting/i);

      const stepsList = page.locator('[data-efs-steps] li');
      const stepCount = await stepsList.count();
      expect(stepCount).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('cancelling migration stops polling', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const progressTab = page.locator('[data-efs-tab="progress"]');
      await progressTab.click();

      const cancelButton = page.locator('[data-efs-cancel-migration]');
      await cancelButton.click();

      await expect(page.locator('.efs-toast.is-visible')).toContainText(/cancelled/i);
    } finally {
      await context.close();
    }
  });
});
