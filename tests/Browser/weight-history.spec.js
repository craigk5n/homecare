// @ts-check
//
// Playwright E2E tests for the patient weight history page.
//
// Requires E2E fixtures seeded by bin/seed_e2e_fixtures.php which
// includes weight history entries for Bella (patient 100) and Mochi (101).

const { test, expect } = require('@playwright/test');

const ADMIN_LOGIN = 'admin';
const ADMIN_PASSWORD = 'admin';
const BELLA_ID = 100;

async function login(page) {
  await page.goto('/login.php');
  await page.fill('input[name="login"]', ADMIN_LOGIN);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL(/\/(index\.php|list_schedule\.php|dashboard\.php)/);
}

test.describe('Weight history page', () => {
  test('renders chart for patient with weight history', async ({ page }) => {
    await login(page);
    await page.goto(`/report_weight.php?patient_id=${BELLA_ID}`);

    await expect(page.locator('body')).not.toContainText('Fatal error');

    // Page title should include the patient name.
    await expect(page.locator('h1')).toContainText('Bella');
    await expect(page.locator('h1')).toContainText('Weight History');

    // Chart canvas should be rendered (Bella has 4 weight readings).
    await expect(page.locator('#weightChart')).toBeVisible();
  });

  test('shows stats cards with current weight and range', async ({ page }) => {
    await login(page);
    await page.goto(`/report_weight.php?patient_id=${BELLA_ID}`);

    // Current weight card should show 12.50 kg.
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('12.50');

    // Should show reading count.
    expect(bodyText).toMatch(/\d+ *\n*.*Readings/);
  });

  test('shows history table with all readings', async ({ page }) => {
    await login(page);
    await page.goto(`/report_weight.php?patient_id=${BELLA_ID}`);

    await expect(page.locator('text=All Readings')).toBeVisible();

    // Table should have rows for each weight entry.
    const tableRows = page.locator('table tbody tr');
    expect(await tableRows.count()).toBeGreaterThanOrEqual(4);
  });

  test('record weight form is visible and functional', async ({ page }) => {
    await login(page);
    await page.goto(`/report_weight.php?patient_id=${BELLA_ID}`);

    await expect(page.locator('button:has-text("Record Weight")')).toBeVisible();
    await expect(page.locator('#weight_kg')).toBeVisible();
    await expect(page.locator('#recorded_at')).toBeVisible();

    // Submit a new weight.
    await page.fill('#weight_kg', '12.75');
    await page.fill('#note', 'E2E test weight');
    await page.click('button:has-text("Record Weight")');

    // Should see success flash.
    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator('.alert-success')).toContainText('12.75');
  });

  test('weight history link exists in Reports menu', async ({ page }) => {
    await login(page);

    // Navigate to a patient-scoped page so Reports menu appears.
    await page.goto(`/list_schedule.php?patient_id=${BELLA_ID}`);

    // Open Reports dropdown.
    await page.locator('#reportsDropdown').click();

    const weightLink = page.locator('a.dropdown-item:has-text("Weight History")');
    await expect(weightLink).toBeVisible();
  });

  test('dashboard sparklines show adherence percentage', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // The sparkline bars should be rendered as inline elements with percentage text.
    // With fixture data (30 days of ~85% adherence), at least one patient should
    // have a percentage visible.
    const adherenceBars = page.locator('[title*="7-day adherence"]');
    expect(await adherenceBars.count()).toBeGreaterThan(0);

    // The percentage should be a number followed by %.
    const firstTitle = await adherenceBars.first().getAttribute('title');
    expect(firstTitle).toMatch(/7-day adherence: \d+(\.\d+)?%/);
  });
});
