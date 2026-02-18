import { expect, Page } from '@playwright/test';
import { promisify } from 'node:util';
import { execFile as execFileCb } from 'node:child_process';
import { openPluginDashboard } from './fixtures';

const execFile = promisify(execFileCb);

type Site = 'bricks' | 'etch';

export type TestContentInput = {
  title: string;
  content: string;
  post_type: string;
  meta?: Record<string, string>;
  categories?: string[];
  tags?: string[];
  featured_image?: boolean;
};

const runWpCli = async (site: Site, args: string[]): Promise<string> => {
  const command = process.platform === 'win32' ? 'npm.cmd' : 'npm';
  const script = site === 'bricks' ? 'wp:bricks' : 'wp:etch';
  const result = await execFile(command, ['run', script, '--', ...args], {
    cwd: process.cwd(),
    timeout: 60_000,
  });
  return `${result.stdout ?? ''}`.trim();
};

const ensureTermIds = async (
  page: Page,
  taxonomy: 'categories' | 'tags',
  names: string[],
): Promise<number[]> => {
  const ids: number[] = [];
  for (const name of names) {
    const createRes = await page.request.post(`/wp-json/wp/v2/${taxonomy}`, {
      data: { name },
      failOnStatusCode: false,
    });

    if (createRes.ok()) {
      const body = await createRes.json();
      ids.push(Number(body.id));
      continue;
    }

    const lookupRes = await page.request.get(`/wp-json/wp/v2/${taxonomy}?search=${encodeURIComponent(name)}`, {
      failOnStatusCode: false,
    });
    if (!lookupRes.ok()) {
      continue;
    }
    const rows = await lookupRes.json() as Array<{ id: number; name: string }>;
    const existing = rows.find((row) => row.name.toLowerCase() === name.toLowerCase());
    if (existing) {
      ids.push(Number(existing.id));
    }
  }
  return ids;
};

const createFeaturedImage = async (page: Page, title: string): Promise<number | null> => {
  const bytes = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgA3n9N4AAAAASUVORK5CYII=',
    'base64',
  );
  const response = await page.request.post('/wp-json/wp/v2/media', {
    multipart: {
      file: {
        name: `${title.replace(/\s+/g, '-').toLowerCase()}.png`,
        mimeType: 'image/png',
        buffer: bytes,
      },
      title,
      status: 'publish',
    },
    failOnStatusCode: false,
  });

  if (!response.ok()) {
    return null;
  }

  const media = await response.json();
  return Number(media.id);
};

export const createTestContentOnBricks = async (
  bricksPage: Page,
  content: TestContentInput[],
): Promise<number[]> => {
  const createdIds: number[] = [];

  for (const entry of content) {
    if (entry.post_type === 'post' || entry.post_type === 'page') {
      const endpoint = entry.post_type === 'post' ? 'posts' : 'pages';
      const categoryIds = entry.categories?.length
        ? await ensureTermIds(bricksPage, 'categories', entry.categories)
        : [];
      const tagIds = entry.tags?.length ? await ensureTermIds(bricksPage, 'tags', entry.tags) : [];
      const featuredMediaId = entry.featured_image
        ? await createFeaturedImage(bricksPage, `${entry.title} Image`)
        : null;

      const response = await bricksPage.request.post(`/wp-json/wp/v2/${endpoint}`, {
        data: {
          title: entry.title,
          content: entry.content,
          status: 'publish',
          categories: categoryIds,
          tags: tagIds,
          featured_media: featuredMediaId ?? undefined,
        },
      });
      expect(response.ok()).toBeTruthy();
      const post = await response.json();
      const postId = Number(post.id);
      createdIds.push(postId);

      if (entry.meta && Object.keys(entry.meta).length > 0) {
        for (const [metaKey, metaValue] of Object.entries(entry.meta)) {
          await runWpCli('bricks', ['post', 'meta', 'update', String(postId), metaKey, metaValue]);
        }
      }

      if (featuredMediaId) {
        createdIds.push(featuredMediaId);
      }

      continue;
    }

    const stdout = await runWpCli('bricks', [
      'post',
      'create',
      `--post_type=${entry.post_type}`,
      '--post_status=publish',
      `--post_title=${entry.title}`,
      `--post_content=${entry.content}`,
      '--porcelain',
    ]);
    const postId = Number(stdout.split(/\r?\n/).filter(Boolean).pop() ?? '0');
    if (Number.isFinite(postId) && postId > 0) {
      createdIds.push(postId);
      if (entry.meta && Object.keys(entry.meta).length > 0) {
        for (const [metaKey, metaValue] of Object.entries(entry.meta)) {
          await runWpCli('bricks', ['post', 'meta', 'update', String(postId), metaKey, metaValue]);
        }
      }
    }
  }

  return createdIds;
};

export const queryEtchDatabase = async (etchPage: Page, sql: string): Promise<Array<Record<string, string>>> => {
  const normalizedSql = sql.trim();

  try {
    const ajaxResults = await etchPage.evaluate(async (query) => {
      const payload = new URLSearchParams({
        action: 'efs_query_db',
        sql: query,
      });
      const response = await fetch('/wp-admin/admin-ajax.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: payload.toString(),
      });
      if (!response.ok) {
        throw new Error(`efs_query_db failed with status ${response.status}`);
      }
      const json = await response.json();
      if (Array.isArray(json)) {
        return json as Array<Record<string, string>>;
      }
      if (json && typeof json === 'object' && Array.isArray((json as { data?: unknown[] }).data)) {
        return (json as { data: Array<Record<string, string>> }).data;
      }
      throw new Error('efs_query_db returned an unexpected payload');
    }, normalizedSql);
    return ajaxResults;
  } catch {
    const sqlBase64 = Buffer.from(normalizedSql, 'utf8').toString('base64');
    const php = `$sql = base64_decode('${sqlBase64}'); global $wpdb; $rows = $wpdb->get_results($sql, ARRAY_A); echo wp_json_encode($rows);`;
    const stdout = await runWpCli('etch', ['eval', php]);
    const jsonStart = stdout.indexOf('[');
    const parsed = jsonStart >= 0 ? stdout.slice(jsonStart) : '[]';
    return JSON.parse(parsed) as Array<Record<string, string>>;
  }
};

export const cleanupTestContent = async (page: Page, postIds: number[]): Promise<void> => {
  const uniqueIds = [...new Set(postIds.filter((id) => Number.isFinite(id) && id > 0))];
  const endpoints = ['posts', 'pages', 'bricks_template', 'etch_template', 'media'];

  await Promise.all(
    uniqueIds.map(async (postId) => {
      await Promise.all(
        endpoints.map(async (endpoint) => {
          await page.request
            .delete(`/wp-json/wp/v2/${endpoint}/${postId}?force=true`, { failOnStatusCode: false })
            .catch(() => undefined);
        }),
      );
    }),
  );
};

export const waitForRealMigrationComplete = async (
  bricksPage: Page,
  timeout = 90_000,
  options: { requireComplete?: boolean } = {},
): Promise<Record<string, unknown>> => {
  const requireComplete = options.requireComplete ?? true;
  const startedAt = Date.now();
  let intervalMs = 1_000;

  const wizardState = await bricksPage.evaluate(() => {
    const root = document.querySelector<HTMLElement>('[data-efs-bricks-wizard]');
    return {
      nonce: root?.getAttribute('data-efs-state-nonce') || '',
      migrationId:
        (window as unknown as { efsWizardState?: { migrationId?: string } }).efsWizardState?.migrationId || '',
    };
  });

  while (Date.now() - startedAt <= timeout) {
    const response = await bricksPage.request.post('/wp-admin/admin-ajax.php', {
      form: {
        action: 'efs_get_migration_progress',
        nonce: wizardState.nonce,
        migration_id: wizardState.migrationId,
      },
      failOnStatusCode: false,
    });

    if (response.ok()) {
      const body = await response.json() as { success?: boolean; data?: Record<string, unknown> };
      const payload = body.data ?? {};
      const progress = (payload.progress ?? {}) as Record<string, unknown>;
      const percentage = Number(progress.percentage ?? 0);
      const itemsProcessed = Number(progress.items_processed ?? 0);
      const status = String(progress.status ?? '').toLowerCase();
      const completed = !!payload.completed || status === 'completed' || percentage >= 100;
      const hasActivity = itemsProcessed > 0 || percentage > 0 || status === 'running' || Boolean(payload.migrationId);

      if (completed || (!requireComplete && hasActivity)) {
        return payload;
      }
    }

    await bricksPage.waitForTimeout(intervalMs);
    intervalMs = Math.min(3_000, intervalMs + 500);
  }

  throw new Error(`Timed out waiting for real migration completion after ${timeout}ms`);
};

export const generateRealMigrationUrl = async (etchPage: Page): Promise<string> => {
  await openPluginDashboard(etchPage);

  const urlInput = etchPage.locator('[data-efs-generated-migration-url]');
  const generateButton = etchPage.locator('[data-efs-generate-migration-url]');

  const dialogHandler = async (dialog: { message: () => string; accept: () => Promise<void> }) => {
    const message = String(dialog.message() || '').toLowerCase();
    if (message.includes('generate a new migration key')) {
      await dialog.accept();
      return;
    }
    await dialog.accept();
  };
  etchPage.on('dialog', dialogHandler);

  await expect(generateButton).toBeVisible({ timeout: 10_000 });
  try {
    await generateButton.click({ force: true });

    await expect(urlInput).toBeVisible({ timeout: 15_000 });
    await expect.poll(async () => (await urlInput.inputValue()).trim(), {
      timeout: 15_000,
    }).not.toBe('');
  } finally {
    etchPage.off('dialog', dialogHandler);
  }

  const migrationUrl = (await urlInput.inputValue()).trim();
  const parsed = new URL(migrationUrl);
  const token = parsed.searchParams.get('token')
    || parsed.searchParams.get('migration_key')
    || parsed.searchParams.get('key');
  if (!token) {
    throw new Error('Generated migration URL does not include a token.');
  }
  return migrationUrl;
};
