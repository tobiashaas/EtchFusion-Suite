import { test, expect } from '@playwright/test';
import { createCrossSiteContexts } from './fixtures';
import {
  fillConnectionStep,
  generateMigrationUrl,
  resetWizardState,
  selectPostTypeMappings,
  setupCrossSiteMigration,
  startMigrationFromPreview,
  waitForDiscoveryComplete,
} from './migration-test-utils';
import { MOCK_POST_TYPE_MAPPINGS } from './test-data';

test.describe('Wizard flow', () => {
  test('completes full wizard flow from connection to migration start', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await resetWizardState(env.bricksPage);

      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);

      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });

      await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible();
      await expect(env.bricksPage.locator('[data-efs-preview-breakdown]')).toContainText('Total selected items');

      await startMigrationFromPreview(env.bricksPage);

      await expect(env.bricksPage.locator('[data-efs-step-nav="1"]')).toHaveClass(/is-complete/);
      await expect(env.bricksPage.locator('[data-efs-step-nav="2"]')).toHaveClass(/is-complete/);
      await expect(env.bricksPage.locator('[data-efs-step-nav="3"]')).toHaveClass(/is-complete/);
      await expect(env.bricksPage.locator('[data-efs-step-nav="4"]')).toHaveClass(/is-active/);
      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeVisible();
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });

  test('discovery runs automatically and shows prominent indicator', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);

      const indicator = env.bricksPage.locator('[data-efs-discovery-loading]');
      await expect(indicator).toBeVisible();
      await expect(indicator.locator('.efs-wizard-loading__spinner')).toBeVisible();
      await expect(indicator).toContainText('Discovering content');

      const borderColor = await indicator.evaluate((el) => getComputedStyle(el).borderColor);
      expect(borderColor).not.toBe('');

      await waitForDiscoveryComplete(env.bricksPage);

      await expect(indicator).toBeHidden();
      await expect(env.bricksPage.locator('.efs-toast.is-visible')).toContainText(/Discovery complete/i);
      await expect(env.bricksPage.locator('[data-efs-summary-breakdown]')).toBeVisible();
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });

  test('summary displays side-by-side with always-open state', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);

      const summary = env.bricksPage.locator('[data-efs-discovery-summary]');
      await expect(summary).toBeVisible();
      await expect(summary).not.toHaveJSProperty('tagName', 'DETAILS');

      const displayValue = await summary.evaluate((el) => getComputedStyle(el).display);
      expect(displayValue).toBe('grid');

      await expect(summary.locator('.efs-wizard-summary__content')).toBeVisible();
      await expect(summary.locator('.efs-wizard-summary__actions')).toBeVisible();
      await expect(env.bricksPage.locator('[data-efs-run-full-analysis]')).toBeVisible();

      await expect(env.bricksPage.locator('[data-efs-summary-breakdown]')).toContainText('Bricks entries');
      await expect(env.bricksPage.locator('[data-efs-summary-breakdown]')).toContainText('Non-Bricks entries');
      await expect(env.bricksPage.locator('[data-efs-summary-breakdown]')).toContainText('Media items');
      await expect(env.bricksPage.locator('[data-efs-summary-grade]')).toHaveClass(/is-green|is-yellow|is-red/);
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });
});
