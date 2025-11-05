import { defineConfig, devices } from '@playwright/test';
import path from 'path';

type UrlConfig = {
  urlEnv: string;
  hostEnv: string;
  portEnv: string;
  protocolEnv: string;
  fallbackPortEnv?: string;
  defaultProtocol: string;
  defaultHost: string;
  defaultPort: string;
};

const resolveUrl = ({
  urlEnv,
  hostEnv,
  portEnv,
  protocolEnv,
  fallbackPortEnv,
  defaultProtocol,
  defaultHost,
  defaultPort,
}: UrlConfig): string => {
  const explicitUrl = process.env[urlEnv];
  if (explicitUrl) {
    return explicitUrl;
  }

  const protocol = process.env[protocolEnv] ?? defaultProtocol;
  const host = process.env[hostEnv] ?? defaultHost;
  const port =
    process.env[portEnv] ?? (fallbackPortEnv ? process.env[fallbackPortEnv] : undefined) ?? defaultPort;

  const trimmedPort = typeof port === 'string' ? port.trim() : '';
  const portSuffix = trimmedPort && !['80', '443'].includes(trimmedPort) ? `:${trimmedPort}` : '';

  return `${protocol}://${host}${portSuffix}`;
};

const authDir = path.resolve(__dirname, '.playwright-auth');
const bricksAuthFile = path.join(authDir, 'bricks.json');
const etchAuthFile = path.join(authDir, 'etch.json');

const bricksUrl = resolveUrl({
  urlEnv: 'BRICKS_URL',
  hostEnv: 'BRICKS_HOST',
  portEnv: 'BRICKS_PORT',
  fallbackPortEnv: 'WP_ENV_PORT',
  protocolEnv: 'BRICKS_PROTOCOL',
  defaultProtocol: 'http',
  defaultHost: 'localhost',
  defaultPort: '8888',
});

const etchUrl = resolveUrl({
  urlEnv: 'ETCH_URL',
  hostEnv: 'ETCH_HOST',
  portEnv: 'ETCH_PORT',
  fallbackPortEnv: 'WP_ENV_TESTS_PORT',
  protocolEnv: 'ETCH_PROTOCOL',
  defaultProtocol: 'http',
  defaultHost: 'localhost',
  defaultPort: '8889',
});

export default defineConfig({
  testDir: './tests/playwright',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: true,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI
    ? [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
      ]
    : [['html', { outputFolder: 'playwright-report', open: 'on-demand' }]],
  globalSetup: require.resolve('./tests/playwright/global-setup.ts'),
  globalTeardown: require.resolve('./tests/playwright/global-teardown.ts'),
  use: {
    actionTimeout: 0,
    baseURL: bricksUrl,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  metadata: {
    bricksUrl,
    etchUrl,
    bricksAuthFile,
    etchAuthFile,
    nodeVersion: process.version,
    timestamp: new Date().toISOString(),
    // URL resolution logic documented for reference:
    // Bricks: BRICKS_URL > BRICKS_HOST:BRICKS_PORT > localhost:8888
    // Etch: ETCH_URL > ETCH_HOST:ETCH_PORT > localhost:8889
    // Override with environment variables as needed
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
      use: {
        storageState: undefined,
      },
      retries: process.env.CI ? 2 : 0, // Increased retries for auth setup
    },
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: bricksAuthFile,
      },
      dependencies: ['setup'],
      // Skip Framer tests if EFS_ENABLE_FRAMER is not set
      testIgnore: process.env.EFS_ENABLE_FRAMER ? undefined : /.*framer.*\.spec\.ts/,
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
        storageState: bricksAuthFile,
      },
      dependencies: ['setup'],
      testIgnore: process.env.EFS_ENABLE_FRAMER ? undefined : /.*framer.*\.spec\.ts/,
    },
    {
      name: 'webkit',
      use: {
        ...devices['Desktop Safari'],
        storageState: bricksAuthFile,
      },
      dependencies: ['setup'],
      testIgnore: process.env.EFS_ENABLE_FRAMER ? undefined : /.*framer.*\.spec\.ts/,
    },
    {
      name: 'mobile-chrome',
      use: {
        ...devices['Pixel 5'],
        storageState: bricksAuthFile,
      },
      dependencies: ['setup'],
      testIgnore: process.env.EFS_ENABLE_FRAMER ? undefined : /.*framer.*\.spec\.ts/,
    },
    {
      name: 'mobile-safari',
      use: {
        ...devices['iPhone 12'],
        storageState: bricksAuthFile,
      },
      dependencies: ['setup'],
      testIgnore: process.env.EFS_ENABLE_FRAMER ? undefined : /.*framer.*\.spec\.ts/,
    },
  ],
});
