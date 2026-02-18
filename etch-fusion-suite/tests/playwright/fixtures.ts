import { expect, Page, Browser, BrowserContext } from '@playwright/test';
import * as path from 'path';
import {
  MOCK_DISCOVERY_DATA,
  MOCK_PROGRESS_SEQUENCE,
  MOCK_RECEIVING_SEQUENCE,
} from './test-data';

const DEFAULT_ADMIN_USERNAME = process.env.EFS_ADMIN_USER ?? 'admin';
const DEFAULT_ADMIN_PASSWORD = process.env.EFS_ADMIN_PASS ?? 'password';
export const BRICKS_URL = process.env.BRICKS_URL ?? 'http://localhost:8888';
export const ETCH_URL = process.env.ETCH_URL ?? 'http://localhost:8889';

const BRICKS_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/bricks.json');
const ETCH_AUTH_FILE = path.join(__dirname, '../../.playwright-auth/etch.json');

type AjaxPayload = Record<string, unknown>;

const jsonSuccess = (payload: AjaxPayload = {}) => ({
  status: 200,
  contentType: 'application/json',
  body: JSON.stringify({
    success: true,
    data: payload,
  }),
});

const jsonError = (status: number, message: string, code = 'request_failed') => ({
  status,
  contentType: 'application/json',
  body: JSON.stringify({
    success: false,
    data: {
      message,
      code,
    },
  }),
});

const getAjaxAction = (request: import('@playwright/test').Request): string | null => {
  const isAjax = request.url().includes('/wp-admin/admin-ajax.php');
  if (!isAjax) {
    return null;
  }

  const postData = request.postData() ?? '';
  const params = new URLSearchParams(postData);
  const action = params.get('action') ?? '';
  return action;
};

export interface LoginOptions {
  username?: string;
  password?: string;
}

export const loginToWordPress = async (
  page: Page,
  options: LoginOptions = {}
): Promise<void> => {
  const username = options.username ?? DEFAULT_ADMIN_USERNAME;
  const password = options.password ?? DEFAULT_ADMIN_PASSWORD;

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  const adminBar = page.locator('#wpadminbar');
  if (await adminBar.isVisible()) {
    return;
  }

  const currentUrl = page.url();
  const origin = new URL(currentUrl).origin;

  const loginResponse = await page.request.post(`${origin}/wp-login.php`, {
    form: {
      log: username,
      pwd: password,
      'rememberme': 'forever',
      redirect_to: `${origin}/wp-admin/`,
      testcookie: '1',
      'wp-submit': 'Log In',
    },
  });

  if (!loginResponse.ok()) {
    throw new Error(`WordPress login request failed with status ${loginResponse.status()}`);
  }

  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });

  if (page.url().includes('wp-login.php')) {
    const loginError = await page.locator('#login_error').innerText({ timeout: 2_000 }).catch(() => '');
    throw new Error(`WordPress login failed${loginError ? `: ${loginError.trim()}` : ''}`);
  }

  await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 10_000 });
};

export const openPluginDashboard = async (page: Page): Promise<void> => {
  await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', {
    waitUntil: 'domcontentloaded',
  });

  if (page.url().includes('wp-login.php')) {
    await loginToWordPress(page);
    await page.goto('/wp-admin/admin.php?page=etch-fusion-suite', {
      waitUntil: 'networkidle',
    });
  } else {
    await page.waitForLoadState('networkidle');
  }

  await expect(page.locator('.efs-admin-wrap')).toBeVisible();
};

type ContextOverrides = Parameters<Browser['newContext']>[0];

export const createEtchAdminContext = async (
  browser: Browser,
  overrides: ContextOverrides = {}
): Promise<{ context: BrowserContext; page: Page }> => {
  const context = await browser.newContext({
    baseURL: ETCH_URL,
    storageState: ETCH_AUTH_FILE,
    ...overrides,
  });
  const page = await context.newPage();
  await openPluginDashboard(page);
  return { context, page };
};

export const createBricksAdminContext = async (
  browser: Browser,
  overrides: ContextOverrides = {}
): Promise<{ context: BrowserContext; page: Page }> => {
  const context = await browser.newContext({
    baseURL: BRICKS_URL,
    storageState: BRICKS_AUTH_FILE,
    ...overrides,
  });
  const page = await context.newPage();
  await openPluginDashboard(page);
  return { context, page };
};

export const createCrossSiteContexts = async (
  browser: Browser,
): Promise<{
  bricksContext: BrowserContext;
  bricksPage: Page;
  etchContext: BrowserContext;
  etchPage: Page;
  cleanup: () => Promise<void>;
}> => {
  const [bricks, etch] = await Promise.all([
    createBricksAdminContext(browser),
    createEtchAdminContext(browser),
  ]);

  return {
    bricksContext: bricks.context,
    bricksPage: bricks.page,
    etchContext: etch.context,
    etchPage: etch.page,
    cleanup: async () => {
      await Promise.all([
        bricks.context.close().catch(() => undefined),
        etch.context.close().catch(() => undefined),
      ]);
    },
  };
};

export const waitForAjaxIdle = async (page: Page, timeout = 15_000): Promise<void> => {
  await page.waitForLoadState('networkidle', { timeout });
  await page.waitForTimeout(250);
};

type ProgressStep = {
  percentage?: number;
  status?: string;
  current_phase_name?: string;
  items_processed?: number;
  items_total?: number;
  message?: string;
};

type WizardMockOverrides = {
  savedState?: Record<string, unknown>;
  discoveryData?: typeof MOCK_DISCOVERY_DATA;
  targetPostTypes?: Array<{ slug: string; label: string }>;
  startMigrationError?: { status?: number; message: string; code?: string };
  progressSteps?: ProgressStep[];
};

export const mockWizardAjaxWithDefaults = async (
  page: Page,
  overrides: WizardMockOverrides = {},
): Promise<() => Promise<void>> => {
  const progressSteps = overrides.progressSteps ?? MOCK_PROGRESS_SEQUENCE;
  const targetPostTypes = overrides.targetPostTypes ?? [
    { slug: 'post', label: 'Posts' },
    { slug: 'page', label: 'Pages' },
    { slug: 'etch_template', label: 'Etch Templates' },
  ];

  let progressIndex = 0;

  const handler = async (route: import('@playwright/test').Route) => {
    const request = route.request();
    const action = getAjaxAction(request);

    if (!action) {
      await route.fallback();
      return;
    }

    switch (action) {
      case 'efs_wizard_get_state':
        await route.fulfill(
          jsonSuccess({
            state: {
              current_step: 1,
              migration_url: '',
              discovery_data: overrides.discoveryData ?? MOCK_DISCOVERY_DATA,
              selected_post_types: ['post', 'page', 'bricks_template'],
              post_type_mappings: {
                post: 'post',
                page: 'page',
                bricks_template: 'etch_template',
              },
              include_media: true,
              batch_size: 50,
              ...(overrides.savedState ?? {}),
            },
          }),
        );
        return;
      case 'efs_wizard_save_state':
      case 'efs_wizard_clear_state':
      case 'efs_cancel_migration':
        await route.fulfill(jsonSuccess({}));
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
      case 'efs_get_target_post_types':
        await route.fulfill(
          jsonSuccess({
            post_types: targetPostTypes,
          }),
        );
        return;
      case 'efs_get_bricks_posts':
        await route.fulfill(
          jsonSuccess({
            posts: [
              { id: 1, title: 'Post 1', type: 'post', has_bricks: true },
              { id: 2, title: 'Post 2', type: 'post', has_bricks: true },
              { id: 3, title: 'Page 1', type: 'page', has_bricks: true },
              { id: 4, title: 'Template 1', type: 'bricks_template', has_bricks: true },
            ],
            count: 4,
            bricks_count: 6,
            gutenberg_count: 1,
            media_count: 4,
          }),
        );
        return;
      case 'efs_start_migration':
        if (overrides.startMigrationError) {
          const status = overrides.startMigrationError.status ?? 400;
          await route.fulfill(jsonError(status, overrides.startMigrationError.message, overrides.startMigrationError.code));
          return;
        }
        await route.fulfill(
          jsonSuccess({
            migrationId: 'migration-1',
            progress: progressSteps[0] ?? MOCK_PROGRESS_SEQUENCE[0],
            steps: [
              { slug: 'prepare', label: 'Preparing', active: true, completed: false },
              { slug: 'posts', label: 'Posts', active: false, completed: false },
              { slug: 'complete', label: 'Complete', active: false, completed: false },
            ],
            completed: false,
          }),
        );
        return;
      case 'efs_get_migration_progress': {
        const payload = progressSteps[Math.min(progressIndex, progressSteps.length - 1)] ?? MOCK_PROGRESS_SEQUENCE[0];
        const percentage = Number(payload.percentage ?? 0);
        progressIndex += 1;
        await route.fulfill(
          jsonSuccess({
            migrationId: 'migration-1',
            progress: payload,
            steps: [
              { slug: 'prepare', label: 'Preparing', active: percentage === 10, completed: percentage > 10 },
              { slug: 'posts', label: 'Posts', active: percentage > 10 && percentage < 100, completed: percentage >= 100 },
              { slug: 'complete', label: 'Complete', active: percentage >= 100, completed: percentage >= 100 },
            ],
            completed: String(payload.status || '').toLowerCase() === 'completed' || percentage >= 100,
          }),
        );
        return;
      }
      default:
        await route.fallback();
    }
  };

  await page.route('**/wp-admin/admin-ajax.php', handler);

  return async () => {
    await page.unroute('**/wp-admin/admin-ajax.php', handler);
  };
};

type ReceivingStatus = {
  status: string;
  source_site: string;
  migration_id: string;
  current_phase: string;
  items_received: number;
  last_activity: string;
  is_stale: boolean;
};

export const mockEtchReceivingAjax = async (
  page: Page,
  statusSequence: ReceivingStatus[] = MOCK_RECEIVING_SEQUENCE,
): Promise<() => Promise<void>> => {
  let sequenceIndex = 0;

  const handler = async (route: import('@playwright/test').Route) => {
    const action = getAjaxAction(route.request());
    if (action !== 'efs_get_receiving_status') {
      await route.fallback();
      return;
    }

    const payload = statusSequence[Math.min(sequenceIndex, statusSequence.length - 1)] ?? statusSequence[0];
    sequenceIndex += 1;
    await route.fulfill(jsonSuccess(payload));
  };

  await page.route('**/wp-admin/admin-ajax.php', handler);

  return async () => {
    await page.unroute('**/wp-admin/admin-ajax.php', handler);
  };
};
