import { test, expect, Page } from '@playwright/test';
import { spawnSync } from 'child_process';
import path from 'path';
import fs from 'fs';
import os from 'os';
import { createBricksAdminContext, createEtchAdminContext } from './fixtures';

const projectRoot = path.resolve(__dirname, '../..');

type WpCliTarget = 'wp' | 'wp:etch';

const runWpCliCommand = (target: WpCliTarget, args: string[]) => {
  const result = spawnSync('npm', ['run', target, '--', ...args], {
    cwd: projectRoot,
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });

  if (result.status !== 0) {
    throw new Error(`WP-CLI command failed: npm run ${target} -- ${args.join(' ')}`);
  }
};

const writeJsonTempFile = (payload: unknown): { directory: string; filePath: string } => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'efs-cli-'));
  const filePath = path.join(directory, 'payload.json');
  fs.writeFileSync(filePath, JSON.stringify(payload), 'utf8');
  return { directory, filePath };
};

const updateFeatureFlags = (flags: Record<string, boolean>) => {
  const { directory, filePath } = writeJsonTempFile(flags);
  try {
    runWpCliCommand('wp:etch', ['option', 'update', 'efs_feature_flags', `@${filePath}`, '--format=json']);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
};

const resetFeatureFlags = () => {
  updateFeatureFlags({ template_extractor: true });
};

const resetSettings = () => {
  runWpCliCommand('wp', ['option', 'delete', 'efs_settings']);
  runWpCliCommand('wp:etch', ['option', 'delete', 'efs_settings']);
};

const getActiveElementSelector = async (page: Page, selectors: string[]) =>
  page.evaluate((keys) => {
    const focusable = keys
      .map((key) => document.querySelector<HTMLElement>(key))
      .filter((element): element is HTMLElement => Boolean(element));
    const active = document.activeElement as HTMLElement | null;
    const target = focusable.find((element) => element.matches(':focus')) ?? null;
    return {
      activeMatchesTarget: !!active && !!target && active === target,
      targetExists: focusable.length === keys.length,
    };
  }, selectors);

test.describe.configure({ mode: 'serial' });

test.describe('Admin Dashboard UI', () => {
  test.afterEach(() => {
    resetFeatureFlags();
    resetSettings();
  });

  test('Connection Settings card renders by default', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const form = page.locator('[data-efs-settings-form]');
      await expect(form).toBeVisible();
      await expect(page.locator('h3:has-text("Connection Settings")')).toBeVisible();
      await expect(form.locator('input[name="target_url"]')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Migration Key card renders expected fields', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const section = page.locator('#efs-migration-key-section');
      await expect(section).toBeVisible();
      await expect(section.locator('h3')).toContainText(/Migration Key/i);
      await expect(section.locator('#efs-migration-key')).toBeVisible();
      await expect(section.locator('[data-efs-migration-key]')).toHaveCount(1);
    } finally {
      await context.close();
    }
  });

  test('Migration Start card displays controls without toggles', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const startSection = page.locator('#efs-start-migration');
      await expect(startSection).toBeVisible();
      await expect(startSection.locator('h3')).toContainText(/Start Migration/i);
      await expect(startSection.locator('#efs-migration-token')).toBeVisible();
      await expect(startSection.locator('button:has-text("Start Migration")')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Application Password guidance visible by default', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const applicationPassword = page.locator('#efs-application-password');
      await expect(applicationPassword).toBeVisible();
      await expect(applicationPassword.locator('h3')).toContainText(/Application Password/i);
      await expect(applicationPassword.locator('ol li')).toHaveCount(4);
    } finally {
      await context.close();
    }
  });

  test('Feature Flags section renders form controls', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const section = page.locator('#efs-feature-flags');
      await expect(section).toBeVisible();
      await expect(section.locator('form[data-efs-feature-flags]')).toBeVisible();
      await expect(section.locator('#efs-feature-template-extractor')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('All Etch setup sections render correctly', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      await expect(page.locator('#efs-application-password')).toBeVisible();
      await expect(page.locator('#efs-site-url-section')).toBeVisible();
      await expect(page.locator('#efs-migration-key-section')).toBeVisible();
      await expect(page.locator('#efs-feature-flags')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Enter key activates focused primary action', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const startButton = page.locator('#efs-start-migration [data-efs-start-migration]');
      await startButton.focus();
      await page.keyboard.press('Enter');
      await expect(startButton).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Space key activates focused button', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const testButton = page.locator('[data-efs-test-connection-trigger]');
      await testButton.focus();
      await page.keyboard.press('Space');
      await expect(testButton).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Arrow Down moves between form inputs', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const focusOrder = ['input[name="target_url"]', 'input[name="api_key"]', '#efs-migration-key'];
      await page.locator(focusOrder[0]).focus();
      await page.keyboard.press('ArrowDown');
      const result = await getActiveElementSelector(page, focusOrder);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Arrow Up moves back between inputs', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const focusOrder = ['input[name="target_url"]', 'input[name="api_key"]', '#efs-migration-key'];
      await page.locator(focusOrder[2]).focus();
      await page.keyboard.press('ArrowUp');
      const result = await getActiveElementSelector(page, focusOrder);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Home key focuses first primary field', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const focusOrder = ['#efs-target-url', '#efs-api-key', '#efs-migration-key'];
      await page.locator(focusOrder[2]).focus();
      await page.keyboard.press('Home');
      const result = await getActiveElementSelector(page, focusOrder);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('End key focuses last primary field', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const focusOrder = ['#efs-target-url', '#efs-api-key', '#efs-migration-key'];
      await page.locator(focusOrder[0]).focus();
      await page.keyboard.press('End');
      const result = await getActiveElementSelector(page, focusOrder);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Template Extractor tab shows disabled state when feature is off', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const disabledTab = page.locator('[data-efs-tab="templates"]');
      await expect(disabledTab).toHaveClass(/is-disabled/);
      await expect(disabledTab).toHaveAttribute('data-efs-feature-disabled', 'true');
      await expect(disabledTab).toHaveAttribute('aria-disabled', 'true');
      await expect(disabledTab.locator('.efs-tab__lock')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Clicking disabled Template Extractor tab shows toast and scrolls to Feature Flags', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const disabledTab = page.locator('[data-efs-tab="templates"]');
      await disabledTab.click();
      const toast = page.locator('.efs-toast');
      await expect(toast).toContainText(/disabled/i);

      const featureFlagsSection = page.locator('#efs-feature-flags');
      await expect(featureFlagsSection).toBeVisible();

      const scrollPosition = await page.evaluate(() => window.scrollY);
      await expect(scrollPosition).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Enable in Feature Flags button reveals Feature Flags section', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const disabledTab = page.locator('[data-efs-tab="templates"]');
      await disabledTab.click();
      const message = page.locator('[data-efs-feature-disabled-message]');
      await expect(message).toBeVisible();

      const enableButton = page.locator('[data-efs-open-feature-flags]');
      await enableButton.click();

      const featureFlagsSection = page.locator('#efs-feature-flags');
      await expect(featureFlagsSection).toBeVisible();
      await expect(page.locator('#efs-feature-template-extractor')).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Enabling Template Extractor feature removes disabled state', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const featureFlagsSection = page.locator('#efs-feature-flags');
      await expect(featureFlagsSection).toBeVisible();

      const enableButton = page.locator('[data-efs-open-feature-flags]');
      await enableButton.click();

      await page.locator('[data-efs-feature-flags] button[type="submit"]').click();
      await page.waitForLoadState('networkidle');

      const templateTab = page.locator('[data-efs-tab="templates"]');
      await expect(templateTab).not.toHaveClass(/is-disabled/);
      await templateTab.click();
      await expect(page.locator('#efs-tab-templates')).toHaveClass(/is-active/);
    } finally {
      await context.close();
    }
  });

  test('Migration key component renders in Bricks context', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const migrationSection = page.locator('#efs-migration-key-section');
      await expect(migrationSection).toBeVisible();
      const component = page.locator('[data-efs-migration-key-component][data-context="bricks"]');
      await expect(component).toBeVisible();
      await expect(component.locator('h3')).toContainText(/from Etch site/i);
      await expect(component.locator('form[data-efs-generate-key]')).toBeVisible();
      const hiddenFields = component.locator('input[type="hidden"]');
      await expect(hiddenFields.locator('[name="nonce"]')).toHaveCount(1);
      await expect(hiddenFields.locator('[name="context"]').first()).toHaveValue('bricks');
      await expect(hiddenFields.locator('[name="target_url"]').first()).toBeVisible();
      await expect(hiddenFields.locator('[name="api_key"]').first()).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Migration key component renders in Etch context', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const migrationSection = page.locator('#efs-migration-key-section');
      await expect(migrationSection).toBeVisible();
      const component = page.locator('[data-efs-migration-key-component][data-context="etch"]');
      await expect(component).toBeVisible();
      await expect(component.locator('h3')).toContainText(/for Bricks site/i);
      await expect(component.locator('input[name="api_key"]')).toHaveCount(0);
    } finally {
      await context.close();
    }
  });

  test('Generate Migration Key button triggers AJAX request', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const migrationSection = page.locator('#efs-migration-key-section');
      await expect(migrationSection).toBeVisible();
      const generateButton = page.locator('[data-efs-generate-key] button[type="submit"], [data-efs-generate-key] button');

      const responsePromise = page.waitForResponse((response) => {
        const requestData = response.request().postData() ?? '';
        return response
          .url()
          .includes('admin-ajax.php') && requestData.includes('efs_generate_migration_key');
      });

      await generateButton.click();
      const response = await responsePromise;
      await expect(response.ok()).toBeTruthy();

      await expect(page.locator('[data-efs-migration-key-output], textarea[data-efs-migration-key]')).not.toHaveValue('');
    } finally {
      await context.close();
    }
  });

  test('Connection Settings form has grouped action buttons', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const actions = page.locator('[data-section="connection"] .efs-actions.efs-actions--inline');
      await expect(actions.locator('button')).toHaveCount(2);
      await expect(actions.locator('button:has-text("Save Connection Settings")')).toBeVisible();
      await expect(actions.locator('button:has-text("Test Connection")')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Test Connection button triggers AJAX without form submission', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const connectionSection = page.locator('[data-efs-settings-form]');
      await connectionSection.locator('input[name="target_url"]').fill('http://example.com');
      await connectionSection.locator('input[name="api_key"]').fill('abcd efgh ijkl mnop qrst uvwx');

      const ajaxPromise = page.waitForResponse((response) => {
        const requestData = response.request().postData() ?? '';
        return response.url().includes('admin-ajax.php') && requestData.includes('efs_test_connection');
      });

      await page.locator('[data-efs-test-connection-trigger]').click();
      await ajaxPromise;
      await expect(page).not.toHaveURL(/efs-target-url/);
    } finally {
      await context.close();
    }
  });

  test('Save Connection Settings submits form and shows success message', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const connectionSection = page.locator('[data-efs-settings-form]');
      await connectionSection.locator('input[name="target_url"]').fill('http://example.com');
      await connectionSection.locator('input[name="api_key"]').fill('abcd efgh ijkl mnop qrst uvwx');

      const responsePromise = page.waitForResponse((response) => {
        const requestData = response.request().postData() ?? '';
        return response.url().includes('admin-ajax.php') && requestData.includes('efs_save_settings');
      });

      await connectionSection.locator('button:has-text("Save Connection Settings")').click();
      await responsePromise;
      await expect(page.locator('.efs-toast')).toContainText(/saved/i);
    } finally {
      await context.close();
    }
  });

  test('Dashboard tabs switch panels with updated selectors', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const logsTab = page.locator('[data-efs-tab="logs"]');
      await logsTab.click();
      await expect(page.locator('#efs-tab-logs')).toHaveClass(/is-active/);
      await expect(page.locator('#efs-tab-progress')).not.toHaveClass(/is-active/);

      const progressTab = page.locator('[data-efs-tab="progress"]');
      await progressTab.click();
      await expect(page.locator('#efs-tab-progress')).toHaveClass(/is-active/);
    } finally {
      await context.close();
    }
  });

  test('Template Extractor tab activates when enabled', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const templateTab = page.locator('[data-efs-tab="templates"]');
      await templateTab.click();
      await expect(page.locator('#efs-tab-templates')).toHaveClass(/is-active/);
      await expect(page.locator('#efs-tab-templates')).not.toHaveAttribute('hidden', '');
    } finally {
      await context.close();
    }
  });
});
