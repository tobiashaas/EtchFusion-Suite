import { expect, Page } from '@playwright/test';
import {
  BRICKS_URL,
  ETCH_URL,
  mockEtchReceivingAjax,
  mockWizardAjaxWithDefaults,
  waitForAjaxIdle,
} from './fixtures';
import {
  MOCK_DISCOVERY_DATA,
  MOCK_PROGRESS_SEQUENCE,
  MOCK_RECEIVING_SEQUENCE,
} from './test-data';

type ProgressStep = {
  percentage?: number;
  status?: string;
  current_phase_name?: string;
  items_processed?: number;
  items_total?: number;
  message?: string;
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

type SetupOptions = {
  mockWizard?: boolean;
  mockReceiving?: boolean;
  savedState?: Record<string, unknown>;
  discoveryData?: typeof MOCK_DISCOVERY_DATA;
  targetPostTypes?: Array<{ slug: string; label: string }>;
  startMigrationError?: { status?: number; message: string; code?: string };
  progressSteps?: ProgressStep[];
  receivingSequence?: ReceivingStatus[];
};

const assertLiveCrossSiteTargets = (bricksPage: Page, etchPage: Page): void => {
  const bricksOrigin = new URL(bricksPage.url()).origin;
  const etchOrigin = new URL(etchPage.url()).origin;
  const expectedBricksOrigin = new URL(BRICKS_URL).origin;
  const expectedEtchOrigin = new URL(ETCH_URL).origin;

  if (bricksOrigin !== expectedBricksOrigin) {
    throw new Error(`Bricks context is not using BRICKS_URL. Expected ${expectedBricksOrigin}, got ${bricksOrigin}.`);
  }

  if (etchOrigin !== expectedEtchOrigin) {
    throw new Error(`Etch context is not using ETCH_URL. Expected ${expectedEtchOrigin}, got ${etchOrigin}.`);
  }

  if (bricksOrigin === etchOrigin) {
    throw new Error(`Bricks and Etch contexts must be different live sites. Both resolved to ${bricksOrigin}.`);
  }
};

const ensureChecked = async (page: Page, selector: string): Promise<void> => {
  const checkbox = page.locator(selector);
  await expect(checkbox).toBeVisible();
  if (!(await checkbox.isChecked())) {
    await checkbox.check();
  }
};

export const setupCrossSiteMigration = async (
  bricksPage: Page,
  etchPage: Page,
  options: SetupOptions = {},
): Promise<() => Promise<void>> => {
  const cleanups: Array<() => Promise<void>> = [];

  assertLiveCrossSiteTargets(bricksPage, etchPage);

  if (options.mockWizard ?? false) {
    const wizardCleanup = await mockWizardAjaxWithDefaults(bricksPage, {
      savedState: options.savedState,
      discoveryData: options.discoveryData,
      targetPostTypes: options.targetPostTypes,
      startMigrationError: options.startMigrationError,
      progressSteps: options.progressSteps ?? MOCK_PROGRESS_SEQUENCE,
    });
    cleanups.push(wizardCleanup);
  }

  if (options.mockReceiving ?? false) {
    const receivingCleanup = await mockEtchReceivingAjax(
      etchPage,
      options.receivingSequence ?? MOCK_RECEIVING_SEQUENCE,
    );
    cleanups.push(receivingCleanup);
  }

  return async () => {
    for (const cleanup of cleanups.reverse()) {
      await cleanup();
    }
  };
};

export const resetWizardState = async (page: Page): Promise<void> => {
  const startFresh = page.locator('[data-efs-start-new]');
  if ((await startFresh.count()) > 0) {
    await startFresh.first().click({ force: true });
  }

  const progressCancel = page.locator('[data-efs-progress-cancel]');
  if ((await progressCancel.count()) > 0) {
    await progressCancel.first().click({ force: true }).catch(() => undefined);
  }

  await page.evaluate(() => {
    window.sessionStorage.removeItem('efsReceivingDismissedKeys');
  });

  await expect(page.locator('[data-efs-step-panel="1"]')).toBeVisible({ timeout: 10_000 });
};

export const waitForDiscoveryComplete = async (page: Page, timeout = 15_000): Promise<void> => {
  const loading = page.locator('[data-efs-discovery-loading]');
  if ((await loading.count()) > 0) {
    await loading.waitFor({ state: 'hidden', timeout });
  }

  await expect(page.locator('.efs-toast.is-visible')).toContainText(/Discovery complete/i, { timeout });
  await expect(page.locator('[data-efs-discovery-summary]')).toBeVisible({ timeout });
};

export const fillConnectionStep = async (page: Page, migrationUrl: string): Promise<void> => {
  await page.locator('[data-efs-wizard-url]').fill(migrationUrl);
  await page.locator('[data-efs-wizard-next]').click({ force: true });
  await expect(page.locator('[data-efs-step-panel="2"]')).toBeVisible({ timeout: 10_000 });
};

export const selectPostTypeMappings = async (
  page: Page,
  mappings: Record<string, string>,
): Promise<void> => {
  for (const [source, target] of Object.entries(mappings)) {
    await ensureChecked(page, `[data-efs-post-type-check="${source}"]`);
    const select = page.locator(`[data-efs-post-type-map="${source}"]`);
    await expect(select).toBeVisible();
    await select.selectOption(target);
  }
};

export const startMigrationFromPreview = async (page: Page): Promise<void> => {
  await page.locator('[data-efs-wizard-next]').click({ force: true });
  await expect(page.locator('[data-efs-step-panel="4"]')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('[data-efs-progress-takeover]')).toBeVisible({ timeout: 10_000 });
};

export const waitForMigrationComplete = async (page: Page, timeout = 20_000): Promise<void> => {
  await expect(page.locator('[data-efs-wizard-progress-percent]')).toContainText('100%', { timeout });
  await expect(page.locator('[data-efs-wizard-progress-status]')).toContainText(/completed/i, { timeout });
};

export const verifyPostTypesOnEtch = async (
  etchPage: Page,
  expectedPostTypes: Array<{ slug: string; minCount?: number }>,
): Promise<Record<string, number>> => {
  const counts: Record<string, number> = {};

  for (const entry of expectedPostTypes) {
    const response = await etchPage.request.get(`/wp-json/wp/v2/${entry.slug}?per_page=1`, {
      failOnStatusCode: false,
    });

    if (!response.ok()) {
      counts[entry.slug] = 0;
      continue;
    }

    const totalHeader = response.headers()['x-wp-total'];
    const total = Number(totalHeader || '0');
    counts[entry.slug] = Number.isFinite(total) ? total : 0;

    if (typeof entry.minCount === 'number') {
      expect(counts[entry.slug]).toBeGreaterThanOrEqual(entry.minCount);
    }
  }

  return counts;
};

export const mockProgressPolling = async (
  page: Page,
  progressSteps: ProgressStep[],
): Promise<() => Promise<void>> => {
  return mockWizardAjaxWithDefaults(page, { progressSteps });
};

export const mockReceivingStatus = async (
  page: Page,
  statusSequence: ReceivingStatus[],
): Promise<() => Promise<void>> => {
  return mockEtchReceivingAjax(page, statusSequence);
};

export const generateMigrationUrl = async (etchPage: Page): Promise<string> => {
  const generatedInput = etchPage.locator('[data-efs-generated-migration-url]');
  if ((await generatedInput.count()) > 0 && (await generatedInput.inputValue()).trim()) {
    return (await generatedInput.inputValue()).trim();
  }

  const generateButton = etchPage.locator('[data-efs-generate-migration-url]');
  if ((await generateButton.count()) > 0) {
    await generateButton.click({ force: true });
    await expect(generatedInput).toBeVisible({ timeout: 10_000 });
    const value = (await generatedInput.inputValue()).trim();
    if (value) {
      return value;
    }
  }

  throw new Error(
    'Unable to resolve a real migration URL from Etch. Ensure the generated URL input or generate button is available.',
  );
};

export { waitForAjaxIdle };
