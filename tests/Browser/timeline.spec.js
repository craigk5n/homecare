// @ts-check
//
// Playwright E2E tests for the patient timeline page.
//
// Requires E2E fixtures seeded by bin/seed_e2e_fixtures.php which
// includes intakes, weight history, and inventory for patients
// Bella (100) and Mochi (101).

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

test.describe('Patient timeline', () => {
  test('renders timeline with events and date headers', async ({ page }) => {
    await login(page);
    await page.goto(`/patient_timeline.php?patient_id=${BELLA_ID}`);

    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('h1')).toContainText('Bella');
    await expect(page.locator('h1')).toContainText('Timeline');

    // Should have date separator headers.
    const dateHeaders = page.locator('strong.text-muted.small.text-uppercase');
    expect(await dateHeaders.count()).toBeGreaterThan(0);

    // Should have event rows.
    const eventRows = page.locator('.d-flex.py-2.border-bottom');
    expect(await eventRows.count()).toBeGreaterThan(0);
  });

  test('shows event type badges', async ({ page }) => {
    await login(page);
    await page.goto(`/patient_timeline.php?patient_id=${BELLA_ID}`);

    // With fixture data, Bella should have dose intakes and weight entries.
    const doseBadges = page.locator('.badge-success:has-text("Dose")');
    expect(await doseBadges.count()).toBeGreaterThan(0);

    // Weight badges (seeded weight history for Bella).
    const weightBadges = page.locator('.badge-info:has-text("Weight")');
    expect(await weightBadges.count()).toBeGreaterThan(0);
  });

  test('date filter narrows results', async ({ page }) => {
    await login(page);
    await page.goto(`/patient_timeline.php?patient_id=${BELLA_ID}`);

    // Get total count from the "Showing X-Y of Z" text.
    const summaryBefore = await page.locator('.text-muted.small').first().textContent();
    const totalBefore = parseInt(summaryBefore.match(/of (\d+)/)?.[1] ?? '0', 10);

    // Filter to a narrow range that should have fewer events.
    await page.fill('#tf_from', '2026-04-15');
    await page.fill('#tf_to', '2026-04-15');
    await page.click('button:has-text("Filter")');

    await expect(page).toHaveURL(/date_from=2026-04-15/);

    const summaryAfter = await page.locator('.text-muted.small').first().textContent();
    const totalAfter = parseInt(summaryAfter.match(/of (\d+)/)?.[1] ?? '0', 10);

    // Filtered count should be less than or equal to unfiltered.
    expect(totalAfter).toBeLessThanOrEqual(totalBefore);
  });

  test('reset button clears filters', async ({ page }) => {
    await login(page);
    await page.goto(`/patient_timeline.php?patient_id=${BELLA_ID}&date_from=2026-04-15&date_to=2026-04-15`);

    await page.click('a:has-text("Reset")');

    await expect(page).toHaveURL(new RegExp(`patient_timeline\\.php\\?patient_id=${BELLA_ID}$`));
  });

  test('timeline link exists in Reports menu', async ({ page }) => {
    await login(page);
    await page.goto(`/list_schedule.php?patient_id=${BELLA_ID}`);

    await page.locator('#reportsDropdown').click();

    const timelineLink = page.locator('a.dropdown-item:has-text("Timeline")');
    await expect(timelineLink).toBeVisible();
  });

  test('timeline link exists on dashboard per patient', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    const timelineLinks = page.locator('a[href*="patient_timeline.php"]:has-text("timeline")');
    expect(await timelineLinks.count()).toBeGreaterThan(0);
  });
});
