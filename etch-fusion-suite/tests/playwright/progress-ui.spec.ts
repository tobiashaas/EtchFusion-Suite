import { test, expect } from '@playwright/test';
import { createCrossSiteContexts } from './fixtures';
import {
  fillConnectionStep,
  generateMigrationUrl,
  selectPostTypeMappings,
  setupCrossSiteMigration,
  startMigrationFromPreview,
  waitForDiscoveryComplete,
  waitForMigrationComplete,
} from './migration-test-utils';
import { MOCK_POST_TYPE_MAPPINGS } from './test-data';

test.describe('Progress UI', () => {
  test('takeover displays during migration with correct progress', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeVisible();
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-percent]')).toContainText('%', { timeout: 20_000 });
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-status]')).not.toHaveText('', { timeout: 20_000 });
      await expect(env.bricksPage.locator('[data-efs-wizard-items]')).toContainText('Items processed');

      const width = await env.bricksPage
        .locator('[data-efs-wizard-progress-fill]')
        .evaluate((el) => el.getAttribute('style') || '');
      expect(width).toMatch(/width:\s*\d+%/i);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('banner appears when minimized and shows progress', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await env.bricksPage.locator('[data-efs-minimize-progress]').click({ force: true });
      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeHidden();
      await expect(env.bricksPage.locator('[data-efs-progress-banner]')).toBeVisible();
      await expect(env.bricksPage.locator('[data-efs-banner-text]')).toContainText('Migration in progress');
      await expect(env.bricksPage.locator('[data-efs-banner-text]')).toContainText(/\d+%/);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('progress chip enables re-entry after full dismiss', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await env.bricksPage.locator('[data-efs-minimize-progress]').click({ force: true });
      await env.bricksPage.evaluate(() => {
        const banner = document.querySelector<HTMLElement>('[data-efs-progress-banner]');
        if (banner) {
          banner.hidden = true;
        }
      });

      const chip = env.bricksPage.locator('[data-efs-progress-chip]');
      await expect(chip).toBeVisible();
      await expect(chip).toContainText(/Migration running \(\d+%\)/);
      await expect(chip.locator('.efs-wizard-progress-chip__icon, .efs-pulse-dot')).toBeVisible();

      await chip.click();
      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeVisible();
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('tab title shows percentage during migration', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await expect
        .poll(async () => env.bricksPage.title())
        .toMatch(/\d+%\s+[-–]\s+Migrating\s+[-–]\s+EtchFusion Suite/i);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('tab title resets on completion', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await waitForMigrationComplete(env.bricksPage);
      await expect.poll(async () => env.bricksPage.title()).toMatch(/EtchFusion Suite(?:\s+[-–]\s+Dashboard)?$/);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('progress UI persists across page reloads', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await env.bricksPage.reload({ waitUntil: 'networkidle' });

      await expect
        .poll(async () => {
          const panelVisible = await env.bricksPage.locator('[data-efs-step-panel="4"]').isVisible().catch(() => false);
          const takeoverVisible = await env.bricksPage.locator('[data-efs-progress-takeover]').isVisible().catch(() => false);
          return panelVisible || takeoverVisible;
        })
        .toBe(true);
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-percent]')).toContainText('%', { timeout: 15_000 });
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });
});
