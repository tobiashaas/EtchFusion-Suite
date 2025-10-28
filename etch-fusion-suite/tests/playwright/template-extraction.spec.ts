import { test, expect } from '@playwright/test';
import { openPluginDashboard, createEtchAdminContext } from './fixtures';

test.describe('Template extraction workflow', () => {
  test('URL extraction updates progress UI and preview', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      await openPluginDashboard(page);

      const templateTab = page.locator('[data-efs-tab="templates"]');
      await templateTab.click();

      const urlForm = page.locator('[data-efs-extract-url-form]');
      await urlForm.locator('input[name="framer_url"]').fill('https://example.framer.website/');

      await Promise.all([
        page.waitForResponse((response) =>
          response.url().includes('admin-ajax.php') && response.request().method() === 'POST'
        ),
        urlForm.locator('button[type="submit"]').click(),
      ]);

      await expect(page.locator('[data-efs-template-progress]')).toBeVisible();
      await expect(page.locator('[data-efs-template-preview]')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Saved templates list hydrates on Etch site', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const templateTab = page.locator('[data-efs-tab="templates"]');
      await templateTab.click();

      const savedList = page.locator('[data-efs-saved-templates] .efs-saved-template');
      await expect(savedList.first()).toBeVisible();
    } finally {
      await context.close();
    }
  });
});
