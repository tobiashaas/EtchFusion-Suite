import { test, expect } from '@playwright/test';
import { createCrossSiteContexts } from './fixtures';
import {
  fillConnectionStep,
  generateMigrationUrl,
  selectPostTypeMappings,
  setupCrossSiteMigration,
  startMigrationFromPreview,
  waitForMigrationComplete,
  verifyPostTypesOnEtch,
  waitForDiscoveryComplete,
} from './migration-test-utils';
import {
  MOCK_INVALID_MAPPINGS,
  MOCK_MIGRATION_ERRORS,
} from './test-data';

test.describe('Post type mapping', () => {
  test('frontend validation blocks invalid mappings in Step 2', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);

      await env.bricksPage.evaluate((invalidTarget) => {
        const source = 'post';
        const checkbox = document.querySelector<HTMLInputElement>(`[data-efs-post-type-check="${source}"]`);
        if (checkbox) {
          checkbox.checked = true;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const select = document.querySelector<HTMLSelectElement>(`[data-efs-post-type-map="${source}"]`);
        if (!select) {
          return;
        }

        const extraOption = document.createElement('option');
        extraOption.value = invalidTarget;
        extraOption.text = 'Custom Type';
        select.appendChild(extraOption);
        select.value = invalidTarget;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }, MOCK_INVALID_MAPPINGS.unavailableTarget.post);

      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await expect(env.bricksPage.locator('[data-efs-select-message]')).toContainText(
        'Invalid mapping – please choose an available Etch post type',
      );
      await expect(env.bricksPage.locator('[data-efs-step-panel="2"]')).toBeVisible();
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });

  test('backend validation returns 400 for invalid mappings', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await env.bricksPage.evaluate((invalidTarget) => {
        const source = 'post';
        const checkbox = document.querySelector<HTMLInputElement>(`[data-efs-post-type-check="${source}"]`);
        if (checkbox) {
          checkbox.checked = true;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const select = document.querySelector<HTMLSelectElement>(`[data-efs-post-type-map="${source}"]`);
        if (!select) {
          return;
        }

        const extraOption = document.createElement('option');
        extraOption.value = invalidTarget;
        extraOption.text = 'Invalid target';
        select.appendChild(extraOption);
        select.value = invalidTarget;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }, MOCK_INVALID_MAPPINGS.unavailableTarget.post);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });

      await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible();
      const migrationStartResponse = env.bricksPage.waitForResponse(
        (response) =>
          response.url().includes('/wp-admin/admin-ajax.php')
          && response.request().postData()?.includes('action=efs_start_migration') === true,
      );
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await expect((await migrationStartResponse).status()).toBe(400);

      await expect(env.bricksPage.locator('.efs-toast.is-visible')).toContainText(
        MOCK_MIGRATION_ERRORS.invalidMapping.message,
      );
      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeHidden();
      await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible();
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });

  test('content service rejects missing mappings without silent fallback', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const countsBefore = await verifyPostTypesOnEtch(env.etchPage, [{ slug: 'post' }]);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);
    const missingMappingHandler = async (route: import('@playwright/test').Route) => {
      const request = route.request();
      const params = new URLSearchParams(request.postData() ?? '');
      if (params.get('action') !== 'efs_start_migration') {
        await route.fallback();
        return;
      }

      await route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          data: {
            message: MOCK_MIGRATION_ERRORS.missingMapping.message,
            code: MOCK_MIGRATION_ERRORS.missingMapping.code,
          },
        }),
      });
    };
    await env.bricksPage.route('**/wp-admin/admin-ajax.php', missingMappingHandler);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, {
        post: 'post',
      });
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible();
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });

      await expect(env.bricksPage.locator('.efs-toast.is-visible')).toContainText(
        MOCK_MIGRATION_ERRORS.missingMapping.message,
      );

      const counts = await verifyPostTypesOnEtch(env.etchPage, [{ slug: 'post' }]);
      expect(counts.post).toBe(countsBefore.post);
    } finally {
      await env.bricksPage.unroute('**/wp-admin/admin-ajax.php', missingMappingHandler);
      await cleanupMocks();
      await env.cleanup();
    }
  });

  test('correct post types are created on Etch target site', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanupMocks = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const countsBefore = await verifyPostTypesOnEtch(env.etchPage, [
        { slug: 'post' },
        { slug: 'page' },
        { slug: 'etch_template' },
      ]);

      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);

      await selectPostTypeMappings(env.bricksPage, {
        post: 'post',
        page: 'page',
        bricks_template: 'etch_template',
      });
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);
      await waitForMigrationComplete(env.bricksPage, 60_000);

      const countsAfter = await verifyPostTypesOnEtch(env.etchPage, [
        { slug: 'post' },
        { slug: 'page' },
        { slug: 'etch_template' },
        { slug: 'custom_type' },
      ]);

      expect(countsAfter.post).toBeGreaterThanOrEqual(countsBefore.post);
      expect(countsAfter.page).toBeGreaterThanOrEqual(countsBefore.page);
      expect(countsAfter.etch_template).toBeGreaterThanOrEqual(countsBefore.etch_template);
      expect(countsAfter.custom_type).toBe(0);
      expect(
        countsAfter.post + countsAfter.page + countsAfter.etch_template,
      ).toBeGreaterThan(countsBefore.post + countsBefore.page + countsBefore.etch_template);
    } finally {
      await cleanupMocks();
      await env.cleanup();
    }
  });
});
