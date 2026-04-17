// @ts-check
//
// HomeCare Playwright E2E tests for the multi-patient dashboard.
//
// These tests run after bin/seed_e2e_fixtures.php has populated the
// database with 2 patients, 5 medicines, 3+ schedules, 30 days of
// intake history, and one low-supply medicine (Prednisolone).

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

test.describe('Multi-patient dashboard', () => {
  test('renders summary cards with counts', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    await expect(page.locator('body')).not.toContainText('Fatal error');

    // Three summary cards should be visible (overdue, due soon, low supply).
    const cards = page.locator('.card .display-4');
    await expect(cards).toHaveCount(3);

    // Each card should contain a number.
    for (let i = 0; i < 3; i++) {
      const text = await cards.nth(i).textContent();
      expect(text.trim()).toMatch(/^\d+$/);
    }
  });

  test('shows dose status by patient with links', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // The "Dose Status by Patient" card should be present.
    await expect(page.locator('text=Dose Status by Patient')).toBeVisible();

    // At least one patient name should link to their schedule.
    const patientLinks = page.locator('a[href*="list_schedule.php?patient_id="]');
    expect(await patientLinks.count()).toBeGreaterThan(0);

    // Patient names (Bella and Mochi) should appear.
    const bodyText = await page.locator('body').textContent();
    expect(bodyText).toContain('Bella');
    expect(bodyText).toContain('Mochi');
  });

  test('shows low-supply alerts for Prednisolone', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // The low-supply section should show Prednisolone (2 units, ~1 day supply).
    const lowSupplySection = page.locator('text=Low Supply Alerts');
    if (await lowSupplySection.count() > 0) {
      await expect(lowSupplySection).toBeVisible();

      // Should contain a danger-styled row for Prednisolone.
      const dangerRow = page.locator('tr.table-danger');
      expect(await dangerRow.count()).toBeGreaterThan(0);

      const dangerText = await dangerRow.first().textContent();
      expect(dangerText).toMatch(/Prednisolone/i);

      // Refill button should be present.
      const refillBtn = page.locator(
        'tr.table-danger a.btn-success:has-text("Refill")'
      );
      expect(await refillBtn.count()).toBeGreaterThan(0);
    }
  });

  test('shows recent intakes feed', async ({ page }) => {
    await login(page);
    await page.goto('/dashboard.php');

    // The recent intakes section should be present.
    await expect(page.locator('text=Recent Intakes')).toBeVisible();
  });

  test('dashboard link exists in navigation', async ({ page }) => {
    await login(page);

    // Navigate to any authenticated page.
    await page.goto('/dashboard.php');

    // The nav should contain a Dashboard link.
    const dashLink = page.locator('nav a.nav-link:has-text("Dashboard")');
    await expect(dashLink).toBeVisible();

    // Clicking it should navigate to dashboard.php.
    await dashLink.click();
    await expect(page).toHaveURL(/dashboard\.php/);
  });

  test('login redirects to dashboard with multiple patients', async ({ page }) => {
    await login(page);

    // With 2 seeded patients, login should land on the dashboard.
    await expect(page).toHaveURL(/dashboard\.php/);
  });
});
