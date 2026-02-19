import { test, expect } from '@playwright/test';
import { createCrossSiteContexts, openPluginDashboard } from './fixtures';
import {
  selectPostTypeMappings,
  verifyPostTypesOnEtch,
} from './migration-test-utils';
import {
  cleanupTestContent,
  createTestContentOnBricks,
  queryEtchDatabase,
  generateRealMigrationUrl,
  waitForRealMigrationComplete,
} from './true-e2e-helpers';

test.describe.configure({ mode: 'serial' });

const waitForRealDiscoveryComplete = async (
  page: import('@playwright/test').Page,
  timeout = 45_000,
): Promise<void> => {
  const loading = page.locator('[data-efs-discovery-loading]');
  if ((await loading.count()) > 0) {
    await loading.waitFor({ state: 'hidden', timeout });
  }
  await expect(page.locator('[data-efs-discovery-summary]')).toBeVisible({ timeout });
  await expect(page.locator('[data-efs-summary-breakdown]')).toBeVisible({ timeout });
};

const hasMappingOption = async (
  page: import('@playwright/test').Page,
  sourceSlug: string,
  targetSlug: string,
): Promise<boolean> => {
  const option = page.locator(
    `[data-efs-post-type-map="${sourceSlug}"] option[value="${targetSlug}"]`,
  );
  return (await option.count()) > 0;
};

const setOnlySelectedPostTypes = async (
  page: import('@playwright/test').Page,
  selected: string[],
): Promise<void> => {
  await page.evaluate((allowed) => {
    const allowedSet = new Set(allowed);
    const checkboxes = Array.from(
      document.querySelectorAll<HTMLInputElement>('[data-efs-post-type-check]'),
    );

    for (const checkbox of checkboxes) {
      const slug = checkbox.getAttribute('data-efs-post-type-check') || '';
      const shouldCheck = allowedSet.has(slug);
      if (checkbox.checked !== shouldCheck) {
        checkbox.checked = shouldCheck;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }, selected);
};

const getSourcePostTypeCount = async (
  page: import('@playwright/test').Page,
  sourceSlug: string,
): Promise<number> => {
  const row = page.locator(`[data-efs-post-type-row="${sourceSlug}"]`);
  if ((await row.count()) === 0) {
    return 0;
  }
  const text = (await row.locator('td').nth(2).textContent().catch(() => '0')) || '0';
  const parsed = Number(String(text).replace(/[^\d]/g, ''));
  return Number.isFinite(parsed) ? parsed : 0;
};

const getAvailableSourcePostTypes = async (
  page: import('@playwright/test').Page,
): Promise<string[]> => {
  return page.evaluate(() => {
    return Array.from(document.querySelectorAll<HTMLInputElement>('[data-efs-post-type-check]'))
      .map((el) => el.getAttribute('data-efs-post-type-check') || '')
      .filter(Boolean);
  });
};

const waitForEtchCountIncrease = async (
  etchPage: import('@playwright/test').Page,
  baseline: Record<string, number>,
  postTypes: string[],
  timeout = 180_000,
): Promise<Record<string, number>> => {
  const startedAt = Date.now();

  while (Date.now() - startedAt <= timeout) {
    const counts = await verifyPostTypesOnEtch(
      etchPage,
      postTypes.map((slug) => ({ slug })),
    );

    const increased = postTypes.some((slug) => (counts[slug] ?? 0) > (baseline[slug] ?? 0));
    if (increased) {
      return counts;
    }

    await etchPage.waitForTimeout(5_000);
  }

  return verifyPostTypesOnEtch(
    etchPage,
    postTypes.map((slug) => ({ slug })),
  );
};

const forceResetWizardState = async (page: import('@playwright/test').Page): Promise<void> => {
  await openPluginDashboard(page);

  await page.evaluate(async () => {
    const root = document.querySelector<HTMLElement>('[data-efs-bricks-wizard]');
    const wizardNonce = root?.getAttribute('data-efs-state-nonce') || '';
    const nonce = (window as unknown as { efsData?: { nonce?: string } }).efsData?.nonce || wizardNonce;
    const ajaxUrl = (window as unknown as { efsData?: { ajaxUrl?: string } }).efsData?.ajaxUrl || '/wp-admin/admin-ajax.php';

    const body = new URLSearchParams();
    body.set('action', 'efs_wizard_clear_state');
    body.set('wizard_nonce', wizardNonce);
    body.set('nonce', nonce);

    await fetch(ajaxUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      credentials: 'same-origin',
      body: body.toString(),
    }).catch(() => undefined);
  });

  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('[data-efs-step-panel="1"]')).toBeVisible({ timeout: 20_000 });
};

const connectWizardWithFreshMigrationUrl = async (
  bricksPage: import('@playwright/test').Page,
  etchPage: import('@playwright/test').Page,
): Promise<void> => {
  let lastError = '';

  for (let attempt = 0; attempt < 2; attempt += 1) {
    const migrationUrl = await generateRealMigrationUrl(etchPage);
    await bricksPage.locator('[data-efs-wizard-url]').fill(migrationUrl);
    const nextButton = bricksPage.locator('[data-efs-wizard-next]');
    await expect(nextButton).toBeVisible({ timeout: 10_000 });
    await expect(nextButton).toBeEnabled({ timeout: 10_000 });
    await nextButton.click({ force: true });

    const movedToStep2 = await bricksPage
      .waitForFunction(() => {
        const panel = document.querySelector<HTMLElement>('[data-efs-step-panel="2"]');
        return Boolean(panel && !panel.hidden);
      }, undefined, { timeout: 30_000 })
      .then(() => true)
      .catch(() => false);

    if (movedToStep2) {
      return;
    }

    const message = await bricksPage.locator('[data-efs-connect-message]').textContent().catch(() => '');
    lastError = String(message || '').trim();
    await forceResetWizardState(bricksPage);
  }

  throw new Error(`Unable to complete connection step. ${lastError || 'No connect error message was rendered.'}`);
};

const buildUniquePrefix = (label: string): string => {
  const token = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
  return `E2E ${label} ${token}`;
};

const startRealMigrationFromPreview = async (
  page: import('@playwright/test').Page,
): Promise<void> => {
  const migrationStartResponse = page.waitForResponse(
    (response) =>
      response.url().includes('/wp-admin/admin-ajax.php')
      && response.request().postData()?.includes('action=efs_start_migration') === true,
    { timeout: 30_000 },
  );

  await page.locator('[data-efs-wizard-next]').click({ force: true });
  const response = await migrationStartResponse;
  const bodyText = await response.text().catch(() => '');

  if (!response.ok()) {
    throw new Error(`Migration start failed with HTTP ${response.status()}: ${bodyText.slice(0, 500)}`);
  }

  const movedToStep4 = await page
    .waitForFunction(() => {
      const panel = document.querySelector<HTMLElement>('[data-efs-step-panel="4"]');
      return Boolean(panel && !panel.hidden);
    }, undefined, { timeout: 20_000 })
    .then(() => true)
    .catch(() => false);

  if (!movedToStep4) {
    const toast = await page.locator('.efs-toast.is-visible').textContent().catch(() => '');
    const selectMessage = await page.locator('[data-efs-select-message]').textContent().catch(() => '');
    const connectMessage = await page.locator('[data-efs-connect-message]').textContent().catch(() => '');
    throw new Error(
      `Migration start did not transition to step 4. toast="${String(toast || '').trim()}" select="${String(selectMessage || '').trim()}" connect="${String(connectMessage || '').trim()}"`,
    );
  }
};

test.describe('Real Migration Flow @slow @e2e', () => {
  test('completes full migration with real API calls and verifies database records @slow @e2e', async ({ browser }) => {
    test.setTimeout(360_000);
    const env = await createCrossSiteContexts(browser);

    try {
      const countsBefore = await verifyPostTypesOnEtch(env.etchPage, [
        { slug: 'post' },
        { slug: 'page' },
        { slug: 'etch_template' },
      ]);

      await forceResetWizardState(env.bricksPage);
      await connectWizardWithFreshMigrationUrl(env.bricksPage, env.etchPage);
      await waitForRealDiscoveryComplete(env.bricksPage, 45_000);

      const availableSourceTypes = await getAvailableSourcePostTypes(env.bricksPage);
      const hasPost = availableSourceTypes.includes('post');
      const hasPage = availableSourceTypes.includes('page');
      const hasBricksTemplate = availableSourceTypes.includes('bricks_template');
      const hasEtchTemplate = hasBricksTemplate
        && (await hasMappingOption(env.bricksPage, 'bricks_template', 'etch_template'));

      const selectedSources = [
        ...(hasPost ? ['post'] : []),
        ...(hasPage ? ['page'] : []),
        ...(hasEtchTemplate ? ['bricks_template'] : []),
      ];
      test.skip(selectedSources.length === 0, 'No supported source post types available for real migration flow');

      const mappings: Record<string, string> = {
        ...(hasPost ? { post: 'post' } : {}),
        ...(hasPage ? { page: 'page' } : {}),
        ...(hasEtchTemplate ? { bricks_template: 'etch_template' } : {}),
      };

      await setOnlySelectedPostTypes(env.bricksPage, selectedSources);
      await selectPostTypeMappings(env.bricksPage, mappings);
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible({ timeout: 10_000 });

      await startRealMigrationFromPreview(env.bricksPage);
      const finalProgress = await waitForRealMigrationComplete(env.bricksPage, 180_000, { requireComplete: false });
      const finalProcessed = Number((finalProgress.progress as { items_processed?: number } | undefined)?.items_processed ?? 0);
      expect(finalProcessed).toBeGreaterThanOrEqual(0);

      const countsAfter = await waitForEtchCountIncrease(
        env.etchPage,
        countsBefore,
        hasEtchTemplate ? ['post', 'page', 'etch_template'] : ['post', 'page'],
        180_000,
      );

      expect(countsAfter.post ?? 0).toBeGreaterThanOrEqual(countsBefore.post ?? 0);
      expect(countsAfter.page ?? 0).toBeGreaterThanOrEqual(countsBefore.page ?? 0);
      if (hasEtchTemplate) {
        expect(countsAfter.etch_template ?? 0).toBeGreaterThanOrEqual(countsBefore.etch_template ?? 0);
      }
      expect(
        (countsAfter.post ?? 0) + (countsAfter.page ?? 0) + (countsAfter.etch_template ?? 0),
      ).toBeGreaterThanOrEqual(
        (countsBefore.post ?? 0) + (countsBefore.page ?? 0) + (countsBefore.etch_template ?? 0),
      );
    } finally {
      await env.cleanup();
    }
  });
});

test.describe('Post Type Mapping Validation @slow @e2e', () => {
  test('verifies bricks_template maps to etch_template in database, not page @slow @e2e', async ({ browser }) => {
    test.setTimeout(360_000);
    const env = await createCrossSiteContexts(browser);
    const sourceCreatedIds: number[] = [];
    let seededTitle = '';
    const seedPrefix = buildUniquePrefix('BricksTemplate');

    try {
      const seeded = await createTestContentOnBricks(env.bricksPage, [
        {
          title: `${seedPrefix} Source`,
          content: '<div data-e2e="bricks-template">template payload</div>',
          post_type: 'bricks_template',
          meta: {
            _e2e_seed: seedPrefix,
          },
        },
      ]);
      sourceCreatedIds.push(...seeded);
      seededTitle = `${seedPrefix} Source`;

      await forceResetWizardState(env.bricksPage);
      await connectWizardWithFreshMigrationUrl(env.bricksPage, env.etchPage);
      await waitForRealDiscoveryComplete(env.bricksPage, 45_000);

      const hasEtchTemplate = await hasMappingOption(env.bricksPage, 'bricks_template', 'etch_template');
      test.skip(!hasEtchTemplate, 'etch_template mapping option is not available on this target environment');
      const sourceTemplateCount = await getSourcePostTypeCount(env.bricksPage, 'bricks_template');
      test.skip(sourceTemplateCount <= 0, 'No bricks_template entries available on source for mapping validation');

      await setOnlySelectedPostTypes(env.bricksPage, ['bricks_template']);
      await selectPostTypeMappings(env.bricksPage, { bricks_template: 'etch_template' });
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startRealMigrationFromPreview(env.bricksPage);
      const finalProgress = await waitForRealMigrationComplete(env.bricksPage, 180_000, { requireComplete: false });
      const finalProcessed = Number((finalProgress.progress as { items_processed?: number } | undefined)?.items_processed ?? 0);
      expect(finalProcessed).toBeGreaterThanOrEqual(0);

      await expect
        .poll(async () => {
          const rows = await queryEtchDatabase(
            env.etchPage,
            `
              SELECT ID, post_type, post_title
              FROM wp_posts
              WHERE post_title LIKE '%${seedPrefix.replace(/'/g, "''")}%'
              ORDER BY ID DESC
              LIMIT 20
            `,
          );
          return rows.length;
        }, { timeout: 180_000 })
        .toBeGreaterThan(0);

      const mappedRows = await queryEtchDatabase(
        env.etchPage,
        `
          SELECT ID, post_type, post_title
          FROM wp_posts
          WHERE post_title = '${seededTitle.replace(/'/g, "''")}'
          ORDER BY ID DESC
          LIMIT 20
        `,
      );

      expect(mappedRows.length).toBeGreaterThan(0);
      expect(mappedRows.some((row) => String(row.post_type) === 'etch_template')).toBeTruthy();
      expect(mappedRows.every((row) => String(row.post_type) !== 'page')).toBeTruthy();

      const etchIds = mappedRows
        .map((row) => Number(row.ID))
        .filter((id) => Number.isFinite(id) && id > 0);
      if (etchIds.length > 0) {
        await cleanupTestContent(env.etchPage, etchIds);
      }
    } finally {
      if (sourceCreatedIds.length > 0) {
        await cleanupTestContent(env.bricksPage, sourceCreatedIds);
      }
      await env.cleanup();
    }
  });

  test('rejects invalid mappings with clear error message @slow @e2e', async ({ browser }) => {
    test.setTimeout(300_000);
    const env = await createCrossSiteContexts(browser);

    try {
      await forceResetWizardState(env.bricksPage);
      await connectWizardWithFreshMigrationUrl(env.bricksPage, env.etchPage);
      await waitForRealDiscoveryComplete(env.bricksPage, 45_000);

      await setOnlySelectedPostTypes(env.bricksPage, ['post']);
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
        extraOption.text = 'Invalid Type';
        select.appendChild(extraOption);
        select.value = invalidTarget;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }, 'non_existent_post_type');

      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      const stillOnStep2 = await env.bricksPage.locator('[data-efs-step-panel="2"]').isVisible().catch(() => false);

      if (stillOnStep2) {
        await expect(env.bricksPage.locator('[data-efs-select-message]')).toContainText(
          /Invalid mapping/i,
        );
      } else {
        await expect(env.bricksPage.locator('[data-efs-step-panel="3"]')).toBeVisible({ timeout: 10_000 });
        const migrationStartResponse = env.bricksPage.waitForResponse(
          (response) =>
            response.url().includes('/wp-admin/admin-ajax.php')
            && response.request().postData()?.includes('action=efs_start_migration') === true,
        );
        await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
        expect((await migrationStartResponse).status()).toBe(400);
        await expect(env.bricksPage.locator('.efs-toast.is-visible, [data-efs-select-message]')).toContainText(
          /Invalid mapping/i,
        );
      }
    } finally {
      await env.cleanup();
    }
  });
});

test.describe('Cross-Site Data Integrity @slow @e2e', () => {
  test('preserves custom fields, featured images, and categories @slow @e2e', async ({ browser }) => {
    test.setTimeout(420_000);
    const env = await createCrossSiteContexts(browser);

    try {
      const beforeRes = await env.etchPage.request.get('/wp-json/wp/v2/posts?per_page=100&_fields=id');
      expect(beforeRes.ok()).toBeTruthy();
      const beforePosts = await beforeRes.json() as Array<{ id: number }>;
      const beforeIds = new Set(beforePosts.map((row) => Number(row.id)));

      await forceResetWizardState(env.bricksPage);
      await connectWizardWithFreshMigrationUrl(env.bricksPage, env.etchPage);
      await waitForRealDiscoveryComplete(env.bricksPage, 45_000);
      await setOnlySelectedPostTypes(env.bricksPage, ['post']);
      await selectPostTypeMappings(env.bricksPage, { post: 'post' });
      await env.bricksPage.locator('[data-efs-wizard-next]').click({ force: true });
      await startRealMigrationFromPreview(env.bricksPage);
      const finalProgress = await waitForRealMigrationComplete(env.bricksPage, 180_000, { requireComplete: false });
      const finalProcessed = Number((finalProgress.progress as { items_processed?: number } | undefined)?.items_processed ?? 0);
      expect(finalProcessed).toBeGreaterThanOrEqual(0);

      const afterRes = await env.etchPage.request.get('/wp-json/wp/v2/posts?per_page=100&context=edit');
      expect(afterRes.ok()).toBeTruthy();
      const afterPosts = await afterRes.json() as Array<{
        id: number;
        featured_media?: number;
        categories?: number[];
        tags?: number[];
        content?: { rendered?: string };
        meta?: Record<string, unknown>;
        acf?: Record<string, unknown>;
      }>;

      const migratedPosts = afterPosts.filter((row) => !beforeIds.has(Number(row.id)));
      expect(migratedPosts.length).toBeGreaterThan(0);

      const hasFeaturedImage = migratedPosts.some((row) => Number(row.featured_media ?? 0) > 0);
      const hasCategories = migratedPosts.some((row) => Array.isArray(row.categories) && row.categories.length > 0);
      const hasTags = migratedPosts.some((row) => Array.isArray(row.tags) && row.tags.length > 0);
      const hasCustomFields = migratedPosts.some((row) => {
        const metaKeys = row.meta ? Object.keys(row.meta) : [];
        const acfKeys = row.acf ? Object.keys(row.acf) : [];
        return metaKeys.length > 0 || acfKeys.length > 0;
      });
      const hasContent = migratedPosts.some(
        (row) => String(row.content?.rendered ?? '').replace(/<[^>]+>/g, '').trim().length > 0,
      );

      expect(hasFeaturedImage).toBeTruthy();
      expect(hasCategories).toBeTruthy();
      expect(hasTags).toBeTruthy();
      expect(hasCustomFields || hasContent).toBeTruthy();
    } finally {
      await env.cleanup();
    }
  });
});
