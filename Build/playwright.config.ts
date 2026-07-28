import { defineConfig } from '@playwright/test';
import config from './tests/playwright/config';

export default defineConfig({
  testDir: './tests/playwright',
  timeout: 30000,
  expect: { timeout: 10000 },
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: './playwright-report' }]],
  outputDir: './test-results',

  use: {
    baseURL: config.baseUrl,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    // Wide enough for the TYPO3 backend with module menu + content area.
    viewport: { width: 1440, height: 900 },
    launchOptions: {
      // Sandboxed environments ship a system chromium instead of the
      // playwright-managed download; point to it via env when needed.
      executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
    },
  },

  projects: [
    {
      name: 'login setup',
      testMatch: /helper\/login\.setup\.ts/,
    },
    {
      name: 'e2e',
      testMatch: /e2e\/.*\.spec\.ts/,
      dependencies: ['login setup'],
      use: {
        storageState: './tests/playwright/.auth/login.json',
      },
    },
  ],
});
