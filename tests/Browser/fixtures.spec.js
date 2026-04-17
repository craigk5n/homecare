// @ts-check
//
// HomeCare Playwright E2E tests for seeded fixture flows (HC-142).
//
// These tests run after bin/seed_e2e_fixtures.php has populated the
// database with 2 patients, 5 medicines, 3+ schedules, and 30 days
// of intake history. They exercise flows that the HC-078 smoke tests
// could not cover without fixture data.

const { test, expect } = require('@playwright/test');

const ADMIN_LOGIN = 'admin';
const ADMIN_PASSWORD = 'admin';

// Patient IDs seeded by bin/seed_e2e_fixtures.php
const BELLA_ID = 100;
const MOCHI_ID = 101;

/**
 * Log in as the admin user and return. Reused across all tests.
 */
async function login(page) {
  await page.goto('/login.php');
  await page.fill('input[name="login"]', ADMIN_LOGIN);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  await page.click('button[type="submit"]');
  // Wait for navigation after login.
  await page.waitForURL(/\/(index\.php|list_schedule\.php)/);
}

// ─── Record Intake Flow ──────────────────────────────────────────────

test.describe('Record intake flow', () => {
  test('can record a dose from the schedule page', async ({ page }) => {
    await login(page);

    // Navigate to Bella's schedule.
    await page.goto(`/list_schedule.php?patient_id=${BELLA_ID}`);
    await expect(page.locator('body')).not.toContainText('Fatal error');

    // Click the first "Record" button (btn-success with "Record" text).
    const recordBtn = page.locator('a.btn-success:has-text("Record")').first();
    await expect(recordBtn).toBeVisible();
    await recordBtn.click();

    // We should be on the record_intake.php page.
    await expect(page).toHaveURL(/record_intake\.php/);

    // The form should show the medication name and a datetime input.
    await expect(page.locator('input[name="taken_time"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();

    // Submit the form (use the pre-filled datetime).
    await page.click('button.btn-success[type="submit"]');

    // Should redirect back to the schedule page.
    await expect(page).toHaveURL(/list_schedule\.php/);
    await expect(page.locator('body')).not.toContainText('Error recording');
  });
});

// ─── Merge Medicines Flow ──────��─────────────────────────────────────

test.describe('Merge medicines flow', () => {
  test('merge page loads and shows medicines with radio and checkbox controls', async ({ page }) => {
    await login(page);

    await page.goto('/merge_medicines.php');
    await expect(page.locator('body')).not.toContainText('Fatal error');

    // The merge form should be present with radio buttons (primary) and
    // checkboxes (duplicates).
    await expect(page.locator('#mergeForm')).toBeVisible();
    await expect(page.locator('input[name="primary_id"]').first()).toBeVisible();
    await expect(page.locator('input[name="duplicate_ids[]"]').first()).toBeVisible();
  });

  test('preview merge shows confirmation panel', async ({ page }) => {
    await login(page);

    await page.goto('/merge_medicines.php');

    // Select the first medicine as primary.
    await page.locator('input[name="primary_id"]').first().check();

    // Check the second medicine as a duplicate.
    const duplicates = page.locator('input[name="duplicate_ids[]"]');
    // The second row's checkbox (cannot be same as primary — the JS
    // disables the one matching the primary radio).
    const count = await duplicates.count();
    if (count >= 2) {
      // Check the last checkbox which won't be the primary.
      await duplicates.nth(count - 1).check();
    }

    // Click "Preview Merge".
    await page.locator('#previewBtn').click();

    // The preview area should appear.
    await expect(page.locator('#previewArea')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('#previewBody')).not.toBeEmpty();

    // A confirm button should be visible (we won't actually submit to
    // avoid destroying test data for other specs).
    await expect(
      page.locator('#confirmForm button[type="submit"]')
    ).toBeVisible();
  });
});

// ─── Adherence Report ────────────────────────────────────────────────

test.describe('Adherence report', () => {
  test('renders chart and colour-coded adherence cells for Bella', async ({ page }) => {
    await login(page);

    await page.goto(`/report_adherence.php?patient_id=${BELLA_ID}`);
    await expect(page.locator('body')).not.toContainText('Fatal error');

    // The Chart.js canvas should be present.
    await expect(page.locator('#adherenceChart')).toBeVisible();

    // The table should contain adherence percentage cells with colour
    // classes. With ~85% adherence, we expect a mix of success/warning.
    const successCells = page.locator('td.table-success');
    const warningCells = page.locator('td.table-warning');
    const dangerCells = page.locator('td.table-danger');

    // At least one coloured cell should exist (the fixture has 30 days
    // of data, so all three columns — 7d, 30d, 90d — should render).
    const totalColoured =
      (await successCells.count()) +
      (await warningCells.count()) +
      (await dangerCells.count());
    expect(totalColoured).toBeGreaterThan(0);
  });

  test('range selector changes displayed data', async ({ page }) => {
    await login(page);

    await page.goto(`/report_adherence.php?patient_id=${BELLA_ID}`);

    // Select 30-day range (auto-submits via data-autosubmit).
    await page.selectOption('#range', '30d');

    // Page should reload with the new range.
    await expect(page).toHaveURL(/range=30d/);
    await expect(page.locator('#adherenceChart')).toBeVisible();
  });
});

// ─── Supply Alert on Inventory Dashboard ──���──────────────────────────

test.describe('Inventory dashboard supply alerts', () => {
  test('low-supply medicine shows danger row', async ({ page }) => {
    await login(page);

    await page.goto('/inventory_dashboard.php');
    await expect(page.locator('body')).not.toContainText('Fatal error');

    // Prednisolone has only 2 units in stock with a 12h schedule
    // (2 doses/day, 1 unit each) → ~1 day supply → table-danger.
    // Check that at least one danger-styled row or card exists.
    const dangerRow = page.locator('tr.table-danger');
    const dangerCard = page.locator('.card.border-danger');

    const dangerCount =
      (await dangerRow.count()) + (await dangerCard.count());
    expect(dangerCount).toBeGreaterThan(0);

    // The danger row/card should mention "Prednisolone".
    const dangerText = await page
      .locator('tr.table-danger, .card.border-danger')
      .first()
      .textContent();
    expect(dangerText).toMatch(/Prednisolone/i);
  });

  test('refill button is accessible from danger row', async ({ page }) => {
    await login(page);

    await page.goto('/inventory_dashboard.php');

    // Click the Refill button on the danger row.
    const refillBtn = page
      .locator('tr.table-danger a.btn-success, .card.border-danger a.btn-success')
      .first();

    if ((await refillBtn.count()) > 0) {
      await refillBtn.click();
      await expect(page).toHaveURL(/inventory_refill\.php/);
      await expect(page.locator('body')).not.toContainText('Fatal error');
    }
  });
});
