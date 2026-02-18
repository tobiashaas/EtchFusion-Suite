import { test, expect } from '@playwright/test';
import { createCrossSiteContexts } from './fixtures';
import {
  fillConnectionStep,
  generateMigrationUrl,
  selectPostTypeMappings,
  setupCrossSiteMigration,
  startMigrationFromPreview,
  waitForDiscoveryComplete,
} from './migration-test-utils';
import {
  MOCK_INVALID_MAPPINGS,
  MOCK_MIGRATION_ERRORS,
  MOCK_POST_TYPE_MAPPINGS,
} from './test-data';

test.describe('Wizard error handling', () => {
  test('invalid connection shows clear error message', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);

    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);
    const invalidUrlHandler = async (route: import('@playwright/test').Route) => {
      const request = route.request();
      const data = new URLSearchParams(request.postData() ?? '');
      if (data.get('action') !== 'efs_wizard_validate_url') {
        await route.fallback();
        return;
      }

      await route.fulfill({
        status: 400,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          data: { message: 'Invalid migration URL format', code: 'invalid_url' },
        }),
      });
    };
    await env.bricksPage.route('**/wp-admin/admin-ajax.php', invalidUrlHandler);

    try {
      await env.bricksPage.locator('[data-efs-wizard-url]').fill('not-a-url');
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });

      await expect(env.bricksPage.locator('[data-efs-connect-message]')).toContainText('Invalid migration URL format');
      await expect(env.bricksPage.locator('[data-efs-step-panel="1"]')).toBeVisible();

      const validUrl = await generateMigrationUrl(env.etchPage);
      await env.bricksPage.locator('[data-efs-wizard-url]').fill(validUrl);
      await env.bricksPage.unroute('**/wp-admin/admin-ajax.php', invalidUrlHandler);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await expect(env.bricksPage.locator('[data-efs-step-panel="2"]')).toBeVisible();
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('invalid mappings blocked with error message', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);

      await env.bricksPage.evaluate((invalidTarget) => {
        const select = document.querySelector<HTMLSelectElement>('[data-efs-post-type-map="post"]');
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

      await expect(env.bricksPage.locator('[data-efs-select-message]')).toContainText(
        'Invalid mapping – please choose an available Etch post type',
      );
      await expect(env.bricksPage.locator('[data-efs-step-panel="2"]')).toBeVisible();

      const logsTab = env.bricksPage.locator('[data-efs-tab="logs"]');
      if ((await logsTab.count()) > 0) {
        await logsTab.first().click({ force: true });
        await expect(env.bricksPage.locator('[data-efs-log-panel]')).toBeVisible();
      }
    } finally {
      await cleanup();
      await env.cleanup();
    }
  });

  test('migration errors display in logs tab', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);
    let injectedError = false;

    const progressErrorHandler = async (route: import('@playwright/test').Route) => {
      const request = route.request();
      const params = new URLSearchParams(request.postData() ?? '');
      if (params.get('action') !== 'efs_get_migration_progress' || injectedError) {
        await route.fallback();
        return;
      }

      injectedError = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            migrationId: 'migration-live',
            progress: {
              percentage: 10,
              status: 'error',
              current_phase_name: 'Posts',
              message: 'Network error while migrating posts',
              items_processed: 1,
              items_total: 10,
            },
            completed: false,
          },
        }),
      });
    };
    await env.bricksPage.route('**/wp-admin/admin-ajax.php', progressErrorHandler);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await expect(env.bricksPage.locator('.efs-toast.is-visible')).toContainText(/Network error while migrating posts|Migration failed/i, {
        timeout: 15_000,
      });
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-status]')).toContainText(/error|failed/i, {
        timeout: 15_000,
      });

      const logsTab = env.bricksPage.locator('[data-efs-tab="logs"]');
      if ((await logsTab.count()) > 0) {
        await logsTab.click({ force: true });
        await expect(env.bricksPage.locator('[data-efs-log-panel]')).toBeVisible();
        await expect(env.bricksPage.locator('[data-efs-log-panel]')).toContainText(/Network error while migrating posts|Migration failed/i);
        await expect(env.bricksPage.locator('[data-efs-log-panel]')).toContainText(/\d{1,2}:\d{2}(:\d{2})?/);
      }
    } finally {
      await env.bricksPage.unroute('**/wp-admin/admin-ajax.php', progressErrorHandler);
      await cleanup();
      await env.cleanup();
    }
  });

  test('retry functionality works for recoverable errors', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    let firstProgressCall = true;

    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    const recoverableErrorHandler = async (route: import('@playwright/test').Route) => {
      const request = route.request();
      const params = new URLSearchParams(request.postData() ?? '');
      const action = params.get('action');

      if (action === 'efs_get_migration_progress' && firstProgressCall) {
        firstProgressCall = false;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            data: {
              migrationId: 'migration-1',
              progress: {
                percentage: 20,
                status: 'error',
                current_phase_name: 'Posts',
                message: MOCK_MIGRATION_ERRORS.recoverableTimeout.message,
                items_processed: 2,
                items_total: 10,
              },
              completed: false,
            },
          }),
        });
        return;
      }

      await route.fallback();
    };

    await env.bricksPage.route('**/wp-admin/admin-ajax.php', recoverableErrorHandler);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await expect(env.bricksPage.locator('[data-efs-retry-migration]')).toBeVisible({ timeout: 15_000 });
      await env.bricksPage.locator('[data-efs-retry-migration]').click({ force: true });

      await expect(env.bricksPage.locator('[data-efs-wizard-progress-percent]')).toContainText('100%', { timeout: 60_000 });
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-status]')).toContainText(/Completed/i, {
        timeout: 60_000,
      });
    } finally {
      await env.bricksPage.unroute('**/wp-admin/admin-ajax.php', recoverableErrorHandler);
      await cleanup();
      await env.cleanup();
    }
  });

  test('network interruption handled gracefully', async ({ browser }) => {
    const env = await createCrossSiteContexts(browser);
    let failureCount = 0;

    const cleanup = await setupCrossSiteMigration(env.bricksPage, env.etchPage);

    const interruptionHandler = async (route: import('@playwright/test').Route) => {
      const request = route.request();
      const params = new URLSearchParams(request.postData() ?? '');
      const action = params.get('action');

      if (action === 'efs_get_migration_progress' && failureCount < 2) {
        failureCount += 1;
        await route.abort('failed');
        return;
      }

      await route.fallback();
    };

    await env.bricksPage.route('**/wp-admin/admin-ajax.php', interruptionHandler);

    try {
      const migrationUrl = await generateMigrationUrl(env.etchPage);
      await fillConnectionStep(env.bricksPage, migrationUrl);
      await waitForDiscoveryComplete(env.bricksPage);
      await selectPostTypeMappings(env.bricksPage, MOCK_POST_TYPE_MAPPINGS.full);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startMigrationFromPreview(env.bricksPage);

      await expect(env.bricksPage.locator('[data-efs-progress-takeover]')).toBeVisible();
      await expect(env.bricksPage.locator('.efs-toast.is-visible, [data-efs-wizard-progress-status]')).toContainText(
        /Connection lost|network/i,
        { timeout: 20_000 },
      );
      await expect(env.bricksPage.locator('[data-efs-wizard-progress-percent]')).toContainText('%', { timeout: 60_000 });
    } finally {
      await env.bricksPage.unroute('**/wp-admin/admin-ajax.php', interruptionHandler);
      await cleanup();
      await env.cleanup();
    }
  });
});
