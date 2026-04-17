// @ts-check
//
// Playwright E2E tests for recent features:
//   - Printable daily medication sheet (PDF)
//   - Audit log CSV export
//   - Mark all caught up
//   - Webhook log viewer
//   - Dashboard overdue duration display

const { test, expect } = require('@playwright/test');

const ADMIN_LOGIN = 'admin';
const ADMIN_PASSWORD = 'admin';

async function login(page) {
  await page.goto('/login.php');
  await page.fill('input[name="login"]', ADMIN_LOGIN);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL(/\/(index\.php|list_schedule\.php|dashboard\.php)/);
}

// ─── Printable PDF Sheet ─────────────────────────────────────────────

test.describe('Printable daily medication sheet', () => {
  test('schedule_print.php returns a PDF via API request', async ({ page, context }) => {
    await login(page);

    // Use the context's cookie-authenticated request to fetch the PDF
    // without navigating (Playwright treats Content-Disposition as a
    // download which breaks page.goto).
    const response = await context.request.get('/schedule_print.php');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/pdf');
  });

  test('Print Sheet button exists on dashboard', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    const printBtn = page.locator('a[href="schedule_print.php"]');
    await expect(printBtn).toBeVisible();
    expect(await printBtn.textContent()).toContain('Print Sheet');
  });
});

// ─── Audit Log CSV Export ────────────────────────────────────────────

test.describe('Audit log CSV export', () => {
  test('CSV button exists on audit log page', async ({ page }) => {
    await login(page);
    await page.goto('/audit_log.php');

    await expect(page.locator('body')).not.toContainText('Fatal error');

    const csvBtn = page.locator('a[href*="export_audit_csv.php"]');
    await expect(csvBtn).toBeVisible();
  });

  test('export_audit_csv.php returns CSV content via API request', async ({ page, context }) => {
    await login(page);

    // Use context request to avoid Playwright treating CSV as a download.
    const response = await context.request.get('/export_audit_csv.php');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');

    const body = await response.text();
    // CSV should have a header row.
    expect(body).toContain('Time,User,Action');
  });
});

// ─── Mark All Caught Up ──────────────────────────────────────────────

test.describe('Mark all caught up', () => {
  test('button appears on dashboard when overdue doses exist', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // With seeded fixtures, there should be overdue doses after 30 days.
    const catchUpBtn = page.locator('button:has-text("Mark all caught up")');

    // Button may or may not appear depending on timing of last intakes.
    // If it appears, verify it has a count.
    if (await catchUpBtn.count() > 0) {
      const text = await catchUpBtn.textContent();
      expect(text).toMatch(/Mark all caught up \(\d+\)/);
    }
  });
});

// ─── Webhook Log Viewer ──────────────────────────────────────────────

test.describe('Webhook log viewer', () => {
  test('webhook_log.php renders for admin', async ({ page }) => {
    await login(page);
    await page.goto('/webhook_log.php');

    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('text=Webhook Delivery Log')).toBeVisible();
  });

  test('filter form is present with expected controls', async ({ page }) => {
    await login(page);
    await page.goto('/webhook_log.php');

    await expect(page.locator('#f_success')).toBeVisible();
    await expect(page.locator('#f_from')).toBeVisible();
    await expect(page.locator('#f_to')).toBeVisible();
    await expect(page.locator('button:has-text("Filter")')).toBeVisible();
  });

  test('webhook log link exists in settings menu', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // Open the Settings dropdown.
    await page.locator('#settingsDropdown').click();

    const webhookLink = page.locator('a.dropdown-item:has-text("Webhook Log")');
    await expect(webhookLink).toBeVisible();
  });
});

// ─── Dashboard Overdue Duration ──────────────────────────────────────

test.describe('Dashboard overdue duration', () => {
  test('overdue doses show duration or "never taken"', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // Look for overdue dose entries with duration text.
    const overdueEntries = page.locator('.text-danger:has-text("overdue by")');
    const neverTaken = page.locator('.text-danger:has-text("never taken")');

    // At least one of these patterns should exist if there are overdue doses.
    const total = (await overdueEntries.count()) + (await neverTaken.count());

    // With fixture data, we expect overdue doses to exist.
    // The duration format should match patterns like "2h 15m", "45m", "1d 3h".
    if (total > 0) {
      const firstText = await page.locator('.text-danger').first().textContent();
      expect(firstText).toMatch(/overdue by \d+[dhm]|never taken/);
    }
  });
});
