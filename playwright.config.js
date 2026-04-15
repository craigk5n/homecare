module.exports = {
  timeout: 30000,
  use: {
    baseURL: 'http://localhost:' + (process.env.HC_WEB_PORT || '8081'),
    headless: true,
    viewport: { width: 1280, height: 720 },
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...require('@playwright/test').devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...require('@playwright/test').devices['Desktop Firefox'] },
    },
  ],
};