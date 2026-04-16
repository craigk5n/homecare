import { defineConfig, devices } from '@playwright/test';

/**
 * HC-078 Playwright configuration.
 *
 * Tests live under tests/Browser (not the default ./e2e) so they sit
 * beside the PHPUnit suite under tests/.
 *
 * baseURL is driven by PLAYWRIGHT_BASE_URL (set by .github/workflows/e2e.yml)
 * and falls back to http://localhost:8080 for local `docker compose up`.
 */
export default defineConfig({
  testDir: './tests/Browser',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [['html', { open: 'never' }], ['list']],
  timeout: 30_000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
