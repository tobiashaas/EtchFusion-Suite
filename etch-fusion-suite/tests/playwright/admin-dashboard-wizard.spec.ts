import { test, expect } from '@playwright/test';
import path from 'path';

type AjaxPayload = Record<string, unknown>;

const jsonSuccess = (payload: AjaxPayload = {}) => ({
  status: 200,
  contentType: 'application/json',
  body: JSON.stringify({
    success: true,
    data: payload,
  }),
});

const BRICKS_URL = process.env.BRICKS_URL ?? 'http://localhost:8888';
const BRICKS_AUTH_FILE = path.resolve(__dirname, '../../.playwright-auth/bricks.json');

const openBricksDashboard = async (page: import('@playwright/test').Page) => {
  await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', { waitUntil: 'networkidle' });
  await expect(page.locator('.efs-admin-wrap')).toBeVisible();
};

const ensureWizardIdle = async (page: import('@playwright/test').Page) => {
  await page.evaluate(() => {
    const startNew = document.querySelector<HTMLElement>('[data-efs-start-new]');
    const progressCancel = document.querySelector<HTMLElement>('[data-efs-progress-cancel]');
    if (startNew) {
      startNew.click();
    }
    if (progressCancel) {
      progressCancel.click();
    }
  });
  await expect(page.locator('[data-efs-step-panel="1"]')).toBeVisible({ timeout: 10000 });
};

type WizardMockOptions = {
  savedState?: Record<string, unknown>;
  startMigrationError?: string;
};

const setupWizardAjaxMocks = async (
  page: import('@playwright/test').Page,
  options: WizardMockOptions = {},
) => {
  let progressCalls = 0;

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const request = route.request();
    const postData = request.postData() ?? '';
    const params = new URLSearchParams(postData);
    const action = params.get('action') ?? '';

    switch (action) {
      case 'efs_wizard_get_state':
        await route.fulfill(
          jsonSuccess({
            state: {
              current_step: 1,
              migration_url: '',
              discovery_data: {
                postTypes: [
                  {
                    slug: 'post',
                    label: 'Post',
                    count: 1,
                    customFields: 0,
                    hasBricks: true,
                  },
                ],
                summary: {
                  grade: 'green',
                  label: 'High convertibility detected (Green)',
                  breakdown: [
                    { label: 'Bricks entries', value: 1 },
                    { label: 'Non-Bricks entries', value: 0 },
                    { label: 'Media items', value: 0 },
                  ],
                },
              },
              selected_post_types: ['post'],
              post_type_mappings: { post: 'post' },
              include_media: true,
              batch_size: 50,
              ...(options.savedState ?? {}),
            },
          }),
        );
        return;
      case 'efs_wizard_save_state':
        await route.fulfill(jsonSuccess({ state: {} }));
        return;
      case 'efs_wizard_clear_state':
        await route.fulfill(jsonSuccess({}));
        return;
      case 'efs_cancel_migration':
        await route.fulfill(jsonSuccess({ cancelled: true }));
        return;
      case 'efs_wizard_validate_url':
        await route.fulfill(
          jsonSuccess({
            valid: true,
            normalized_url: 'https://etch.local/wp-json/efs/v1/migrate?token=fake-token',
            host: 'etch.local',
            is_https: true,
            https_warning: false,
            warning: '',
          }),
        );
        return;
      case 'efs_validate_migration_token':
        await route.fulfill(jsonSuccess({ valid: true }));
        return;
      case 'efs_get_bricks_posts':
        await route.fulfill(
          jsonSuccess({
            posts: [
              { id: 1, title: 'Post 1', type: 'post', has_bricks: true },
              { id: 2, title: 'Page 1', type: 'page', has_bricks: true },
            ],
            count: 2,
            bricks_count: 2,
            gutenberg_count: 0,
            media_count: 1,
          }),
        );
        return;
      case 'efs_start_migration':
        if (options.startMigrationError) {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              success: false,
              data: {
                message: options.startMigrationError,
              },
            }),
          });
          return;
        }
        await route.fulfill(
          jsonSuccess({
            migrationId: 'migration-1',
            progress: {
              percentage: 10,
              status: 'running',
              current_phase_name: 'Preparing',
              items_processed: 0,
              items_total: 2,
            },
            steps: [{ slug: 'prepare', label: 'Preparing', active: true, completed: false }],
            completed: false,
          }),
        );
        return;
      case 'efs_get_migration_progress':
        progressCalls += 1;
        await route.fulfill(
          jsonSuccess({
            migrationId: 'migration-1',
            progress: {
              percentage: progressCalls > 1 ? 100 : 80,
              status: progressCalls > 1 ? 'completed' : 'running',
              current_phase_name: progressCalls > 1 ? 'Completed' : 'Posts',
              items_processed: progressCalls > 1 ? 2 : 1,
              items_total: 2,
            },
            steps: [
              { slug: 'prepare', label: 'Preparing', active: false, completed: true },
              { slug: 'posts', label: 'Posts', active: false, completed: true },
            ],
            completed: progressCalls > 1,
          }),
        );
        return;
      default:
        await route.fallback();
    }
  });
};

const clickWizardNext = async (page: import('@playwright/test').Page) => {
  await page.locator('[data-efs-wizard-next]').click({ force: true });
};

test.describe('Admin Dashboard Bricks Wizard', () => {
  test('requires migration URL before moving to step 2', async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: BRICKS_URL,
      storageState: BRICKS_AUTH_FILE,
    });
    const page = await context.newPage();
    try {
      await setupWizardAjaxMocks(page);
      await openBricksDashboard(page);
      await ensureWizardIdle(page);

      const nextButton = page.locator('[data-efs-wizard-next]');
      await expect(nextButton).toBeDisabled();
      await expect(page.locator('[data-efs-step-panel="1"]')).toBeVisible();
      await expect(page.locator('[data-efs-progress-takeover]')).toBeHidden();
    } finally {
      await context.close().catch(() => undefined);
    }
  });

  test('connects and opens select/map step with discovery UI', async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: BRICKS_URL,
      storageState: BRICKS_AUTH_FILE,
    });
    const page = await context.newPage();
    try {
      await setupWizardAjaxMocks(page);
      await openBricksDashboard(page);
      await ensureWizardIdle(page);

      await page.locator('[data-efs-wizard-url]').fill('https://etch.local/wp-json/efs/v1/migrate?token=fake-token');
      await clickWizardNext(page);

      await expect(page.locator('[data-efs-step-panel="2"]')).toBeVisible();
      await expect(page.locator('[data-efs-discovery-summary]')).toBeVisible();
      await expect(page.locator('[data-efs-wizard-next]')).toBeEnabled();
    } finally {
      await context.close().catch(() => undefined);
    }
  });

});
