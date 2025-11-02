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

const getActiveElementSelector = async (page: Page, targetIndex: number) =>
  page.evaluate((index) => {
    const headers = Array.from(
      document.querySelectorAll<HTMLElement>('[data-efs-accordion-header]')
    );
    const active = document.activeElement as HTMLElement | null;
    const target = headers[index] ?? null;
    return {
      activeMatchesTarget: !!active && !!target && active === target,
      targetExists: !!target,
    };
  }, targetIndex);

test.describe.configure({ mode: 'serial' });

test.describe('Admin Dashboard UI', () => {
  test.afterEach(() => {
    resetFeatureFlags();
    resetSettings();
  });

  test('Connection Settings accordion expands by default', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const section = page.locator('[data-section="connection"]');
      await expect(section).toHaveClass(/is-expanded/);
      await expect(section.locator('[data-efs-accordion-content]')).not.toHaveAttribute('hidden', 'true');
      await expect(section.locator('[data-efs-accordion-header]')).toHaveAttribute('aria-expanded', 'true');
    } finally {
      await context.close();
    }
  });

  test('Migration Key accordion collapses and expands on click', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const section = page.locator('[data-section="migration_key"]');
      const header = section.locator('[data-efs-accordion-header]');
      const content = section.locator('[data-efs-accordion-content]');

      await header.click();
      await expect(section).toHaveClass(/is-expanded/);
      await expect(content).not.toHaveAttribute('hidden', 'true');

      await header.click();
      await expect(section).not.toHaveClass(/is-expanded/);
      await expect(content).toHaveAttribute('hidden', 'true');
    } finally {
      await context.close();
    }
  });

  test('Only one accordion section open at a time (single-section mode)', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const connection = page.locator('[data-section="connection"]');
      const migrationKey = page.locator('[data-section="migration_key"]');
      const startMigration = page.locator('[data-section="migration"]');

      await expect(connection).toHaveClass(/is-expanded/);

      await migrationKey.locator('[data-efs-accordion-header]').click();
      await expect(migrationKey).toHaveClass(/is-expanded/);
      await expect(connection).not.toHaveClass(/is-expanded/);

      await startMigration.locator('[data-efs-accordion-header]').click();
      await expect(startMigration).toHaveClass(/is-expanded/);
      await expect(migrationKey).not.toHaveClass(/is-expanded/);
    } finally {
      await context.close();
    }
  });

  test('Application Password accordion expands by default', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const applicationPassword = page.locator('[data-section="application_password"]');
      await expect(applicationPassword).toHaveClass(/is-expanded/);
      await expect(applicationPassword.locator('[data-efs-accordion-content]')).not.toHaveAttribute('hidden', 'true');
    } finally {
      await context.close();
    }
  });

  test('Feature Flags accordion collapses and expands', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const section = page.locator('[data-section="feature_flags"]');
      const header = section.locator('[data-efs-accordion-header]');
      const content = section.locator('[data-efs-accordion-content]');

      await header.click();
      await expect(section).toHaveClass(/is-expanded/);
      await expect(content).not.toHaveAttribute('hidden', 'true');

      await header.click();
      await expect(section).not.toHaveClass(/is-expanded/);
      await expect(content).toHaveAttribute('hidden', 'true');
    } finally {
      await context.close();
    }
  });

  test('All four Etch accordion sections render correctly', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const expectedSections = ['application_password', 'site_url', 'migration_key', 'feature_flags'];
      for (const section of expectedSections) {
        await expect(page.locator(`[data-section="${section}"]`)).toHaveCount(1);
      }
    } finally {
      await context.close();
    }
  });

  test('Enter key toggles accordion section', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const section = page.locator('[data-section="migration_key"]');
      const header = section.locator('[data-efs-accordion-header]');

      await header.focus();
      await page.keyboard.press('Enter');
      await expect(section).toHaveClass(/is-expanded/);

      await page.keyboard.press('Enter');
      await expect(section).not.toHaveClass(/is-expanded/);
    } finally {
      await context.close();
    }
  });

  test('Space key toggles accordion section', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const section = page.locator('[data-section="migration"]');
      const header = section.locator('[data-efs-accordion-header]');

      await header.focus();
      await page.keyboard.press('Space');
      await expect(section).toHaveClass(/is-expanded/);

      await page.keyboard.press('Space');
      await expect(section).not.toHaveClass(/is-expanded/);
    } finally {
      await context.close();
    }
  });

  test('Arrow Down navigates to next accordion header', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const firstHeader = page.locator('[data-section="connection"] [data-efs-accordion-header]');
      await firstHeader.focus();
      await page.keyboard.press('ArrowDown');
      const result = await getActiveElementSelector(page, 1);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Arrow Up navigates to previous accordion header', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const secondHeader = page.locator('[data-section="migration_key"] [data-efs-accordion-header]');
      await secondHeader.focus();
      await page.keyboard.press('ArrowUp');
      const result = await getActiveElementSelector(page, 0);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Home key focuses first accordion header', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const lastHeader = page.locator('[data-section="migration"] [data-efs-accordion-header]');
      await lastHeader.focus();
      await page.keyboard.press('Home');
      const result = await getActiveElementSelector(page, 0);
      expect(result.targetExists).toBe(true);
      expect(result.activeMatchesTarget).toBe(true);
    } finally {
      await context.close();
    }
  });

  test('End key focuses last accordion header', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const firstHeader = page.locator('[data-section="connection"] [data-efs-accordion-header]');
      await firstHeader.focus();
      await page.keyboard.press('End');
      const headers = page.locator('[data-efs-accordion-header]');
      const count = await headers.count();
      const result = await getActiveElementSelector(page, count - 1);
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

      const featureFlagsSection = page.locator('[data-section="feature_flags"]');
      await expect(featureFlagsSection).toHaveClass(/is-expanded/);

      const scrollPosition = await page.evaluate(() => window.scrollY);
      await expect(scrollPosition).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Enable in Feature Flags button expands Feature Flags accordion', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const disabledTab = page.locator('[data-efs-tab="templates"]');
      await disabledTab.click();
      const message = page.locator('[data-efs-feature-disabled-message]');
      await expect(message).toBeVisible();

      const enableButton = page.locator('[data-efs-open-feature-flags]');
      await enableButton.click();

      const featureFlagsSection = page.locator('[data-section="feature_flags"]');
      await expect(featureFlagsSection).toHaveClass(/is-expanded/);
      await expect(page.locator('#efs-feature-template-extractor')).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Enabling Template Extractor feature removes disabled state', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const featureFlagsSection = page.locator('[data-section="feature_flags"]');
      const header = featureFlagsSection.locator('[data-efs-accordion-header]');
      if (!(await featureFlagsSection.evaluate((el) => el.classList.contains('is-expanded')))) {
        await header.click();
      }

      const checkbox = page.locator('#efs-feature-template-extractor');
      await checkbox.check();
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
      const migrationSection = page.locator('[data-section="migration_key"]');
      await migrationSection.locator('[data-efs-accordion-header]').click();
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
      const migrationSection = page.locator('[data-section="migration_key"]');
      await migrationSection.locator('[data-efs-accordion-header]').click();
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
      const migrationSection = page.locator('[data-section="migration_key"]');
      const header = migrationSection.locator('[data-efs-accordion-header]');
      await header.click();
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
      const connectionSection = page.locator('[data-section="connection"]');
      await connectionSection.locator('#efs-target-url').fill('http://example.com');
      await connectionSection.locator('#efs-api-key').fill('abcd efgh ijkl mnop qrst uvwx');

      const ajaxPromise = page.waitForResponse((response) => {
        const requestData = response.request().postData() ?? '';
        return response.url().includes('admin-ajax.php') && requestData.includes('efs_test_connection');
      });

      await connectionSection.locator('button:has-text("Test Connection")').click();
      await ajaxPromise;
      await expect(page).not.toHaveURL(/efs-target-url/);
    } finally {
      await context.close();
    }
  });

  test('Save Connection Settings submits form and shows success message', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const connectionSection = page.locator('[data-section="connection"]');
      await connectionSection.locator('#efs-target-url').fill('http://example.com');
      await connectionSection.locator('#efs-api-key').fill('abcd efgh ijkl mnop qrst uvwx');

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
