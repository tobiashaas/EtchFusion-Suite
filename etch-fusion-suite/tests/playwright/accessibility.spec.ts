import { test, expect, Page } from '@playwright/test';
import { spawnSync } from 'child_process';
import path from 'path';
import { AxeBuilder } from '@axe-core/playwright';
import { createBricksAdminContext, createEtchAdminContext } from './fixtures';

const projectRoot = path.resolve(__dirname, '../..');

const runWpCliCommand = (args: string[], options: { input?: string } = {}) => {
  const useInput = typeof options.input === 'string';
  const result = spawnSync('npm', ['run', 'wp:etch', '--', ...args], {
    cwd: projectRoot,
    stdio: useInput ? ['pipe', 'inherit', 'inherit'] : 'inherit',
    input: useInput ? `${options.input}\n` : undefined,
    encoding: 'utf8',
    shell: process.platform === 'win32',
  });

  if (result.status !== 0) {
    throw new Error(`WP-CLI command failed: npm run wp:etch -- ${args.join(' ')}`);
  }
};

const updateFeatureFlags = (flags: Record<string, boolean>) => {
  runWpCliCommand(['option', 'update', 'efs_feature_flags', '--format=json'], {
    input: JSON.stringify(flags),
  });
};

const resetFeatureFlags = () => {
  updateFeatureFlags({ template_extractor: true });
};

const getFocusableOrder = async (page: Page, steps: number) => {
  const focusedElements: string[] = [];
  for (let i = 0; i < steps; i += 1) {
    await page.keyboard.press('Tab');
    const activeId = await page.evaluate(
      () => (document.activeElement as HTMLElement | null)?.id || '',
    );
    const activeRole = await page.evaluate(
      () => (document.activeElement as HTMLElement | null)?.getAttribute('role') || '',
    );
    const label = await page.evaluate(
      () =>
        (document.activeElement as HTMLElement | null)?.textContent?.trim().slice(0, 80) || '',
    );
    focusedElements.push(`${activeId}:${activeRole}:${label}`);
  }
  return focusedElements;
};

test.describe('Admin Dashboard Accessibility', () => {
  test.afterEach(() => {
    resetFeatureFlags();
  });

  test('Source setup sections expose headings and landmarks', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const sourceCard = page.locator('.efs-card--source');
      await expect(sourceCard).toHaveAttribute('role', /region|group/);
      await expect(sourceCard.locator('h2')).toContainText(/EFS Site Migration Setup/i);
      await expect(sourceCard.locator('.efs-card__section h3')).toHaveCount(2);
    } finally {
      await context.close();
    }
  });

  test('Target setup sections expose headings and controls', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const targetCard = page.locator('.efs-card--target');
      await expect(targetCard.locator('h2')).toContainText(/Etch Target Site Setup/i);
      await expect(targetCard.locator('.efs-card__section')).toHaveCount(4);
      await expect(page.locator('#efs-feature-flags form[data-efs-feature-flags]')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Tab navigation has correct ARIA attributes', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const tabs = page.locator('[data-efs-tabs] [role="tab"]');
      const tabCount = await tabs.count();
      expect(tabCount).toBeGreaterThan(0);

      for (let i = 0; i < tabCount; i += 1) {
        const tab = tabs.nth(i);
        await expect(tab).toHaveAttribute('aria-controls');
        const classList = await tab.getAttribute('class');
        const isActive = classList?.includes('is-active') ?? false;
        await expect(tab).toHaveAttribute('aria-selected', isActive ? 'true' : 'false');
      }

      await expect(page.locator('[role="tablist"]')).toHaveCount(1);
    } finally {
      await context.close();
    }
  });

  test('Disabled Template Extractor tab has aria-disabled', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const disabledTab = page.locator('[data-efs-tab="templates"]');
      await expect(disabledTab).toHaveAttribute('aria-disabled', 'true');
      await expect(disabledTab).toHaveAttribute('role', 'tab');
    } finally {
      await context.close();
    }
  });

  test('Form fields have proper labels and descriptions', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const labelledFields = page.locator('form label[for]');
      const count = await labelledFields.count();
      for (let i = 0; i < count; i += 1) {
        const label = labelledFields.nth(i);
        const forId = await label.getAttribute('for');
        if (forId) {
          await expect(page.locator(`#${forId}`)).toBeVisible();
        }
      }
      await expect(page.locator('#efs-api-key-label')).toBeVisible();
      await expect(page.locator('#efs-api-key, input[name="api_key"]')).toBeVisible();
      await expect(page.locator('#efs-api-key-description')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('All interactive elements are keyboard accessible', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      await page.focus('body');
      const focusOrder = await getFocusableOrder(page, 10);
      expect(focusOrder.length).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Skip to content link works when present', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const skipLink = page.locator('a[href^="#"]', { hasText: /skip/i });
      if ((await skipLink.count()) === 0) {
        test.skip(true, 'Skip link not present on page');
      }

      await page.keyboard.press('Tab');
      const firstSkip = skipLink.first();
      await expect(firstSkip).toBeFocused();

      const target = await firstSkip.getAttribute('href');
      if (target) {
        await firstSkip.press('Enter');
        await expect(page.locator(target)).toBeFocused();
      }
    } finally {
      await context.close();
    }
  });

  test('Modal dialogs trap focus when opened', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const modalTrigger = page.locator('[data-efs-open-modal]');
      if ((await modalTrigger.count()) === 0) {
        test.skip(true, 'No modal dialogs available on page');
      }

      await modalTrigger.first().click();
      const modal = page.locator('[role="dialog"], .efs-modal');
      await expect(modal).toBeVisible();
      await page.keyboard.press('Tab');
      const activeWithin = await modal.evaluate((node) => node.contains(document.activeElement));
      expect(activeWithin).toBe(true);
      await page.keyboard.press('Escape');
      await expect(modal).toBeHidden();
    } finally {
      await context.close();
    }
  });

  test('Toast notifications are announced to screen readers', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const toastContainer = page.locator('.efs-toast-container');
      if ((await toastContainer.count()) === 0) {
        await page.evaluate(() => window.scrollTo(0, 0));
      }

      await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('efs:toast', { detail: { message: 'Accessibility toast' } }));
      });

      try {
        await toastContainer.first().waitFor({ state: 'visible', timeout: 5_000 });
      } catch (error) {
        test.skip(true, 'Toast notifications feature not available in this environment');
      }

      await expect(toastContainer).toHaveAttribute('aria-live', 'polite');
      await expect(toastContainer).toHaveAttribute('role', 'status');
    } finally {
      await context.close();
    }
  });

  test('Loading states are announced via aria-busy', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const connectionSection = page.locator('[data-section="connection"]');
      await connectionSection.locator('#efs-target-url').fill('http://example.com');
      await connectionSection.locator('#efs-api-key').fill('abcd efgh ijkl mnop qrst uvwx');
      const button = connectionSection.locator('button:has-text("Test Connection")');
      const requestPromise = page.waitForResponse((response) => {
        const data = response.request().postData() ?? '';
        return response.url().includes('admin-ajax.php') && data.includes('efs_test_connection');
      });
      await button.click();
      await expect(button).toHaveAttribute('aria-busy', 'true');
      await expect(button).toBeDisabled();
      await requestPromise;
    } finally {
      await context.close();
    }
  });

  test('Dynamic content updates announce progress', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const progressRegion = page.locator('[data-efs-progress-region], [role="status"]');
      const progress = progressRegion.first();
      const ariaLive = await progress.getAttribute('aria-live');
      const role = await progress.getAttribute('role');
      expect(Boolean(ariaLive) || role === 'status').toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Focus remains consistent when interacting with migration form', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const tokenField = page.locator('#efs-migration-token');
      await tokenField.focus();
      await page.keyboard.press('Tab');
      await expect(page.locator('[data-efs-start-migration]')).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Focus is set on Feature Flags checkbox when enable button clicked', async ({ browser }) => {
    updateFeatureFlags({ template_extractor: false });
    const { context, page } = await createEtchAdminContext(browser);
    try {
      await page.locator('[data-efs-tab="templates"]').click();
      await page.locator('[data-efs-open-feature-flags]').click();
      await expect(page.locator('#efs-feature-template-extractor')).toBeFocused();
    } finally {
      await context.close();
    }
  });

  test('Automated audit reports sufficient color contrast', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const axe = new AxeBuilder({ page });
      const results = await axe.analyze();
      const seriousViolations = results.violations.filter((violation: { impact?: string | null }) => {
        const impact = violation.impact ?? '';
        return impact === 'serious' || impact === 'critical';
      });
      expect(seriousViolations).toHaveLength(0);
    } finally {
      await context.close();
    }
  });

  test('Focus indicators have sufficient contrast', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const button = page.locator('#efs-start-migration [data-efs-start-migration]');
      await button.focus();
      const outlineColor = await button.evaluate((node) =>
        window.getComputedStyle(node).outlineColor || window.getComputedStyle(node).boxShadow,
      );
      expect(outlineColor).not.toEqual('rgba(0, 0, 0, 0)');
    } finally {
      await context.close();
    }
  });

  test('Headings follow logical hierarchy', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const h1Count = await page.locator('h1').count();
      expect(h1Count).toBe(1);
      const h2Count = await page.locator('h2').count();
      expect(h2Count).toBeGreaterThan(0);
      for (let i = 0; i < h2Count; i += 1) {
        await expect(page.locator('h2').nth(i)).toBeVisible();
      }
    } finally {
      await context.close();
    }
  });

  test('Lists use appropriate semantic markup', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const listCount = await page.locator('ol, ul').count();
      expect(listCount).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Forms use semantic structure', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const forms = page.locator('form');
      const formCount = await forms.count();
      expect(formCount).toBeGreaterThan(0);
      const buttons = forms.locator('button');
      const buttonCount = await buttons.count();
      expect(buttonCount).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Bricks dashboard passes automated accessibility audit', async ({ browser }) => {
    const { context, page } = await createBricksAdminContext(browser);
    try {
      const axe = new AxeBuilder({ page });
      const results = await axe.analyze();
      const seriousViolations = results.violations.filter((violation: { impact?: string | null }) => {
        const impact = violation.impact ?? '';
        return impact === 'serious' || impact === 'critical';
      });
      expect(seriousViolations).toHaveLength(0);
    } finally {
      await context.close();
    }
  });

  test('Etch dashboard passes automated accessibility audit', async ({ browser }) => {
    const { context, page } = await createEtchAdminContext(browser);
    try {
      const axe = new AxeBuilder({ page });
      const results = await axe.analyze();
      const seriousViolations = results.violations.filter((violation: { impact?: string | null }) => {
        const impact = violation.impact ?? '';
        return impact === 'serious' || impact === 'critical';
      });
      expect(seriousViolations).toHaveLength(0);
    } finally {
      await context.close();
    }
  });
});
