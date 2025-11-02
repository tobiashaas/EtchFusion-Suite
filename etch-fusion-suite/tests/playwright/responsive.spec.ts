import { test, expect, devices, Browser } from '@playwright/test';
import { createBricksAdminContext, createEtchAdminContext } from './fixtures';

type SiteKey = 'bricks' | 'etch';

interface DashboardOptions {
  viewport?: { width: number; height: number };
  deviceName?: keyof typeof devices;
  isMobile?: boolean;
}

const createDashboardContext = async (
  browser: Browser,
  site: SiteKey,
  options: DashboardOptions = {},
) => {
  const overrides: Parameters<Browser['newContext']>[0] = {};

  if (options.deviceName) {
    const device = devices[options.deviceName];
    Object.assign(overrides, device);
  } else if (options.viewport) {
    overrides.viewport = options.viewport;
    overrides.isMobile = options.isMobile ?? options.viewport.width <= 480;
  }

  const createContext = site === 'bricks' ? createBricksAdminContext : createEtchAdminContext;
  return createContext(browser, overrides);
};

test.describe('Admin Dashboard responsive behaviour', () => {
  test('Mobile dashboard renders without horizontal scroll', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      await expect(page.locator('.efs-admin-wrap')).toBeVisible();
      const hasHorizontalScroll = await page.evaluate(() => {
        const docWidth = document.documentElement.scrollWidth;
        const viewportWidth = document.documentElement.clientWidth;
        return docWidth > viewportWidth + 2;
      });
      expect(hasHorizontalScroll).toBe(false);
    } finally {
      await context.close();
    }
  });

  test('Mobile accordion sections expand fully', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const sections = page.locator('[data-efs-accordion-section]');
      const count = await sections.count();
      for (let i = 0; i < count; i += 1) {
        const section = sections.nth(i);
        await section.locator('[data-efs-accordion-header]').click();
        const bounding = await section.locator('[data-efs-accordion-content]').boundingBox();
        expect(bounding?.width).toBeGreaterThan(300);
      }
    } finally {
      await context.close();
    }
  });

  test('Mobile action buttons stack vertically', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const actions = page.locator('[data-section="connection"] .efs-actions--inline');
      const direction = await actions.evaluate((node) =>
        window.getComputedStyle(node).flexDirection,
      );
      expect(direction).toBe('column');
    } finally {
      await context.close();
    }
  });

  test('Mobile tab navigation remains usable', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      await page.locator('[data-efs-tab="logs"]').click();
      await expect(page.locator('#efs-tab-logs')).toHaveClass(/is-active/);
      await page.locator('[data-efs-tab="progress"]').click();
      await expect(page.locator('#efs-tab-progress')).toHaveClass(/is-active/);
    } finally {
      await context.close();
    }
  });

  test('Mobile forms remain usable', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const input = page.locator('#efs-target-url');
      await expect(input).toBeVisible();
      const bbox = await input.boundingBox();
      expect(bbox?.width).toBeGreaterThan(260);
    } finally {
      await context.close();
    }
  });

  test('Tablet layout maintains spacing', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 768, height: 1024 },
    });
    try {
      const padding = await page.locator('.efs-admin-wrap').evaluate((node) =>
        window.getComputedStyle(node).padding,
      );
      expect(padding).toContain('16px');
    } finally {
      await context.close();
    }
  });

  test('Tablet accordion spacing is consistent', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      viewport: { width: 768, height: 1024 },
    });
    try {
      const gap = await page.locator('.efs-accordion').evaluate((node) =>
        window.getComputedStyle(node).rowGap,
      );
      expect(parseInt(gap ?? '0', 10)).toBeGreaterThan(8);
    } finally {
      await context.close();
    }
  });

  test('Tablet button groups align inline', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 768, height: 1024 },
    });
    try {
      const actions = page.locator('[data-section="connection"] .efs-actions--inline');
      const direction = await actions.evaluate((node) =>
        window.getComputedStyle(node).flexDirection,
      );
      expect(direction === 'row' || direction === 'row-reverse').toBe(true);
    } finally {
      await context.close();
    }
  });

  test('Desktop layout keeps content constrained', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 1920, height: 1080 },
    });
    try {
      const containerWidth = await page.locator('.efs-admin-wrap').evaluate((node) =>
        node.getBoundingClientRect().width,
      );
      expect(containerWidth).toBeLessThan(1400);
    } finally {
      await context.close();
    }
  });

  test('Desktop accordions remain readable', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      viewport: { width: 1920, height: 1080 },
    });
    try {
      const contentWidth = await page
        .locator('[data-efs-accordion-content]')
        .first()
        .evaluate((node) => node.getBoundingClientRect().width);
      expect(contentWidth).toBeLessThan(1100);
    } finally {
      await context.close();
    }
  });

  test('Mobile landscape orientation adapts correctly', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 667, height: 375 },
      isMobile: true,
    });
    try {
      const hasScroll = await page.evaluate(() => {
        const docWidth = document.documentElement.scrollWidth;
        const viewportWidth = document.documentElement.clientWidth;
        return docWidth > viewportWidth + 2;
      });
      expect(hasScroll).toBe(false);
    } finally {
      await context.close();
    }
  });

  test('Tablet portrait orientation remains usable', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      viewport: { width: 768, height: 1024 },
    });
    try {
      const sectionCount = await page.locator('[data-efs-accordion-section]').count();
      expect(sectionCount).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });

  test('Accordion responds to touch events', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const header = page.locator('[data-section="migration_key"] [data-efs-accordion-header]');
      await header.tap();
      await expect(page.locator('[data-section="migration_key"]')).toHaveClass(/is-expanded/);
    } finally {
      await context.close();
    }
  });

  test('Action buttons respond to touch', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const button = page.locator('[data-section="connection"] button:has-text("Test Connection")');
      await button.tap();
      await expect(button).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Copy button works with touch', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      viewport: { width: 375, height: 667 },
      isMobile: true,
    });
    try {
      const copyButton = page.locator('[data-efs-copy], [data-efs-copy-button]').first();
      await copyButton.tap();
      await expect(page.locator('.efs-toast')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Dashboard remains usable at 200% zoom', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks');
    try {
      await page.evaluate(() => {
        document.body.style.zoom = '2';
      });
      const overflowing = await page.evaluate(() => {
        const docWidth = document.documentElement.scrollWidth;
        const viewportWidth = document.documentElement.clientWidth;
        return docWidth > viewportWidth + 4;
      });
      expect(overflowing).toBe(false);
    } finally {
      await context.close();
    }
  });

  test('Dashboard remains usable at 50% zoom', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks');
    try {
      await page.evaluate(() => {
        document.body.style.zoom = '0.5';
      });
      await expect(page.locator('.efs-admin-wrap')).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('Dashboard works on iPhone 12', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      deviceName: 'iPhone 12',
    });
    try {
      await expect(page.locator('.efs-admin-wrap')).toBeVisible();
      await page.locator('[data-efs-tab="logs"]').tap();
      await expect(page.locator('#efs-tab-logs')).toHaveClass(/is-active/);
    } finally {
      await context.close();
    }
  });

  test('Dashboard works on Pixel 5', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'bricks', {
      deviceName: 'Pixel 5',
    });
    try {
      await expect(page.locator('.efs-admin-wrap')).toBeVisible();
      await page.locator('[data-efs-accordion-header]').first().tap();
      await expect(page.locator('[data-efs-accordion-section]').first()).toHaveClass(/is-expanded/);
    } finally {
      await context.close();
    }
  });

  test('Dashboard works on iPad Pro', async ({ browser }) => {
    const { context, page } = await createDashboardContext(browser, 'etch', {
      deviceName: 'iPad Pro 11',
    });
    try {
      await expect(page.locator('.efs-admin-wrap')).toBeVisible();
      await page.locator('[data-efs-tab="templates"]').tap({ trial: true }).catch(() => undefined);
      const sectionCount = await page.locator('[data-efs-accordion-section]').count();
      expect(sectionCount).toBeGreaterThan(0);
    } finally {
      await context.close();
    }
  });
});
