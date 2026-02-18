import { test, expect } from '@playwright/test';
import { createCrossSiteContexts, createEtchAdminContext } from './fixtures';
import {
  fillConnectionStep,
  generateMigrationUrl,
  mockReceivingStatus,
  selectPostTypeMappings,
  setupCrossSiteMigration,
  startMigrationFromPreview,
  waitForDiscoveryComplete,
  waitForMigrationComplete,
} from './migration-test-utils';
import { MOCK_POST_TYPE_MAPPINGS } from './test-data';

const startLiveCrossSiteMigration = async (
  bricksPage: import('@playwright/test').Page,
  etchPage: import('@playwright/test').Page,
): Promise<void> => {
  const migrationUrl = await generateMigrationUrl(etchPage);
  await fillConnectionStep(bricksPage, migrationUrl);
  await waitForDiscoveryComplete(bricksPage);
  await selectPostTypeMappings(bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
  await bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
  await startMigrationFromPreview(bricksPage);
};

test.describe('Receiving status', () => {
  test('receiving UI is shown on Etch during live migration', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      await startLiveCrossSiteMigration(env.bricksPage, env.etchPage);

      await expect
        .poll(async () => {
          const display = await env.etchPage.locator('[data-efs-receiving-display]').isVisible().catch(() => false);
          const banner = await env.etchPage.locator('[data-efs-receiving-banner]').isVisible().catch(() => false);
          return display || banner;
        })
        .toBe(true);
      await expect(env.etchPage.locator('[data-efs-receiving-title]')).toContainText(/Receiving Migration|Migration Received/i);
      await expect(env.etchPage.locator('[data-efs-receiving-source]')).toContainText(new URL(env.bricksPage.url()).origin);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('minimize and expand functionality works correctly with live status', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      await startLiveCrossSiteMigration(env.bricksPage, env.etchPage);

      await expect(env.etchPage.locator('[data-efs-receiving-display]')).toBeVisible({ timeout: 30_000 });
      const baseline = await env.etchPage.locator('[data-efs-receiving-source]').innerText();

      await env.etchPage.locator('[data-efs-receiving-minimize]').click({ force: true });
      await expect(env.etchPage.locator('[data-efs-receiving-display]')).toBeHidden();
      await expect(env.etchPage.locator('[data-efs-receiving-banner]')).toBeVisible();

      await env.etchPage.locator('[data-efs-receiving-expand]').click({ force: true });
      await expect(env.etchPage.locator('[data-efs-receiving-display]')).toBeVisible();
      await expect(env.etchPage.locator('[data-efs-receiving-banner]')).toBeHidden();
      await expect(env.etchPage.locator('[data-efs-receiving-source]')).toHaveText(baseline);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('status updates reflect live migration progress', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      await startLiveCrossSiteMigration(env.bricksPage, env.etchPage);

      await expect(env.etchPage.locator('[data-efs-receiving-phase]')).not.toHaveText('', { timeout: 30_000 });
      await expect(env.etchPage.locator('[data-efs-receiving-items]')).toContainText(/\d+/, { timeout: 30_000 });
      await expect(env.etchPage.locator('[data-efs-receiving-status]')).toContainText(
        /Receiving payload|Migration payload received successfully|Receiving migration/i,
        { timeout: 30_000 },
      );
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('completion state displays correctly after live migration completes', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      await startLiveCrossSiteMigration(env.bricksPage, env.etchPage);
      await waitForMigrationComplete(env.bricksPage, 120_000);

      await expect(env.etchPage.locator('[data-efs-receiving-title]')).toContainText(/Migration Received|Receiving Migration/i, {
        timeout: 30_000,
      });
      await expect(env.etchPage.locator('[data-efs-receiving-phase] .status-badge')).toHaveClass(/is-success|is-active/);
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('stale state shows warning and allows dismissal', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    const cleanup = await mockReceivingStatus(page, [
      {
        status: 'stale',
        source_site: 'https://bricks.local',
        migration_id: 'mig-stale',
        current_phase: 'posts',
        items_received: 9,
        last_activity: '2026-02-17 12:30:00',
        is_stale: true,
      },
    ]);

    try {
      await expect(page.locator('[data-efs-receiving-title]')).toHaveText('Migration Stalled', { timeout: 15_000 });
      await expect(page.locator('[data-efs-receiving-phase] .status-badge')).toHaveClass(/is-warning/);
      await page.locator('[data-efs-receiving-dismiss]').click({ force: true });

      await expect(page.locator('[data-efs-receiving-display]')).toBeHidden();
      await expect(page.locator('[data-efs-receiving-banner]')).toBeHidden();

      const dismissed = await page.evaluate(() => window.sessionStorage.getItem('efsReceivingDismissedKeys') || '');
      expect(dismissed).toContain('migration:mig-stale');
    } finally {
      await cleanup();
      await context.close();
    }
  });
});
