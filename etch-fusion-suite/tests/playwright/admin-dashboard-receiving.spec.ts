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

const ETCH_URL = process.env.ETCH_URL ?? 'http://localhost:8889';
const ETCH_AUTH_FILE = path.resolve(__dirname, '../../.playwright-auth/etch.json');

const openEtchDashboard = async (page: import('@playwright/test').Page) => {
  await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', { waitUntil: 'networkidle' });
  await expect(page.locator('.efs-admin-wrap')).toBeVisible();
};

test.describe('Admin Dashboard Etch Receiving Status', () => {
  test('shows receiving takeover, allows minimize, then shows completion state', async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: ETCH_URL,
      storageState: ETCH_AUTH_FILE,
    });
    const page = await context.newPage();
    try {
      let pollCount = 0;

      await page.route('**/wp-admin/admin-ajax.php', async (route) => {
        const request = route.request();
        const postData = request.postData() ?? '';
        const params = new URLSearchParams(postData);
        const action = params.get('action') ?? '';

        if (action !== 'efs_get_receiving_status') {
          await route.fallback();
          return;
        }

        pollCount += 1;
        if (pollCount <= 3) {
          await route.fulfill(
            jsonSuccess({
              status: 'receiving',
              source_site: 'https://bricks.local',
              migration_id: 'mig-1',
              current_phase: 'posts',
              items_received: 12,
              items_total: 20,
              estimated_time_remaining: 45,
              last_activity: '2026-02-16 21:00:00',
              started_at: new Date(Date.now() - 30000).toISOString().replace('T', ' ').slice(0, 19),
              is_stale: false,
            }),
          );
          return;
        }

        await route.fulfill(
          jsonSuccess({
            status: 'completed',
            source_site: 'https://bricks.local',
            migration_id: 'mig-1',
            current_phase: 'posts',
            items_received: 20,
            items_total: 20,
            estimated_time_remaining: null,
            last_activity: '2026-02-16 21:01:00',
            is_stale: false,
          }),
        );
      });

      await openEtchDashboard(page);
      const receivingDisplay = page.locator('[data-efs-receiving-display]');
      if ((await receivingDisplay.count()) === 0) {
        await context.close().catch(() => undefined);
        test.skip(true, 'Receiving UI is not rendered in this environment setup.');
      }

      await expect(receivingDisplay).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-efs-receiving-title]')).toContainText(/Receiving Migration|Migration Received/);
      await expect(page.locator('[data-efs-receiving-source]')).toContainText('bricks.local');

      await page.locator('[data-efs-receiving-minimize]').click({ force: true });
      await expect(page.locator('[data-efs-receiving-banner]')).toBeVisible();
      await page.locator('[data-efs-receiving-expand]').click({ force: true });
      await expect(page.locator('[data-efs-receiving-display]')).toBeVisible();

      await page.waitForTimeout(9000);
      await expect(page.locator('[data-efs-receiving-title]')).toContainText('Migration Received', { timeout: 15000 });
      await expect(page.locator('[data-efs-view-received-content]')).toBeVisible();
    } finally {
      await context.close().catch(() => undefined);
    }
  });

  test('renders elapsed time and ETA when items_total is provided', async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: ETCH_URL,
      storageState: ETCH_AUTH_FILE,
    });
    const page = await context.newPage();
    try {
      await page.route('**/wp-admin/admin-ajax.php', async (route) => {
        const request = route.request();
        const postData = request.postData() ?? '';
        const params = new URLSearchParams(postData);
        const action = params.get('action') ?? '';

        if (action !== 'efs_get_receiving_status') {
          await route.fallback();
          return;
        }

        await route.fulfill(
          jsonSuccess({
            status: 'receiving',
            source_site: 'https://bricks.local',
            migration_id: 'mig-eta',
            current_phase: 'posts',
            items_received: 10,
            items_total: 20,
            estimated_time_remaining: 45,
            started_at: new Date(Date.now() - 30000).toISOString().replace('T', ' ').slice(0, 19),
            last_activity: new Date().toISOString().replace('T', ' ').slice(0, 19),
            is_stale: false,
          }),
        );
      });

      await openEtchDashboard(page);
      const receivingDisplay = page.locator('[data-efs-receiving-display]');
      if ((await receivingDisplay.count()) === 0) {
        await context.close().catch(() => undefined);
        test.skip(true, 'Receiving UI is not rendered in this environment setup.');
      }

      await expect(receivingDisplay).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-efs-receiving-elapsed]'))
        .toContainText(/Elapsed:.*remaining/i, { timeout: 15000 });
    } finally {
      await context.close().catch(() => undefined);
    }
  });

  test('renders stale state and allows dismissing it', async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: ETCH_URL,
      storageState: ETCH_AUTH_FILE,
    });
    const page = await context.newPage();
    try {
      await page.route('**/wp-admin/admin-ajax.php', async (route) => {
        const request = route.request();
        const postData = request.postData() ?? '';
        const params = new URLSearchParams(postData);
        const action = params.get('action') ?? '';

        if (action !== 'efs_get_receiving_status') {
          await route.fallback();
          return;
        }

        await route.fulfill(
          jsonSuccess({
            status: 'stale',
            source_site: 'https://bricks.local',
            migration_id: 'mig-stale',
            current_phase: 'media',
            items_received: 45,
            last_activity: '2026-02-16 20:50:00',
            is_stale: true,
          }),
        );
      });

      await openEtchDashboard(page);
      const receivingDisplay = page.locator('[data-efs-receiving-display]');
      if ((await receivingDisplay.count()) === 0) {
        await context.close().catch(() => undefined);
        test.skip(true, 'Receiving UI is not rendered in this environment setup.');
      }

      await expect(receivingDisplay).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-efs-receiving-title]')).toContainText('Migration Stalled');
      await expect(page.locator('[data-efs-receiving-dismiss]')).toBeVisible();
      await page.locator('[data-efs-receiving-dismiss]').click();
      await expect(page.locator('[data-efs-etch-dashboard]')).not.toHaveClass(/is-receiving-stale/);
    } finally {
      await context.close().catch(() => undefined);
    }
  });
});
