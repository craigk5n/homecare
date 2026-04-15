// @ts-check
// tests/Browser/e2e.spec.js

const { test, expect } = require('@playwright/test');
const path = require('path');

test.describe('HomeCare E2E Flows', () => {
  test.beforeEach(async ({ page }) => {
    // Ensure clean session
    await page.goto('http://localhost:8080');
    if (await page.locator('text=Login').isVisible()) {
      // Already on login
    } else {
      await page.goto('http://localhost:8080/login.php');
    }
  });

  test('Smoke flow: login → list_schedule → record an intake → log out', async ({ page }) => {
    // Login
    await expect(page).toHaveURL(/.*login\.php/);
    await page.fill('input[name="login"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('input[type="submit"]');
    await expect(page).toHaveURL(/.*list_patients\.php/);

    // Go to first patient's schedule
    await page.click('text=Schedule');
    await expect(page).toHaveURL(/.*list_schedule\.php/);

    // Record intake (assume first dose button exists)
    const recordButton = page.locator('[data-action="record-intake"]').first();
    await recordButton.click();
    await page.waitForSelector('[data-action="record-intake"][aria-label="Recorded"]', { state: 'visible' });

    // Logout
    await page.goto('http://localhost:8080/logout.php');
    await expect(page).toHaveURL(/.*login\.php/);
  }, { timeout: 30000 });

  test('Merge medicines flow: login → merge_medicines → preview → confirm', async ({ page }) => {
    // Login (same as smoke)
    await page.fill('input[name="login"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('input[type="submit"]');

    // Navigate to merge medicines (assume link exists)
    await page.click('a:has-text("Medications")');
    await page.click('a:has-text("Merge Medicines")');
    await expect(page).toHaveURL(/.*merge_medicines\.php/);

    // Select two medicines and preview
    await page.check('input[value="1"]'); // Assume IDs
    await page.check('input[value="2"]');
    await page.click('button:has-text("Preview Merge")');
    await expect(page.locator('text=Preview')).toBeVisible();

    // Confirm merge
    await page.click('button:has-text("Confirm Merge")');
    await expect(page).toHaveURL(/.*list_medications\.php/); // Or success page

    // Logout
    await page.goto('http://localhost:8080/logout.php');
  }, { timeout: 30000 });

  test('Adherence report flow: login → report_adherence → toggle range → assert chart + table', async ({ page }) => {
    // Login
    await page.fill('input[name="login"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    await page.click('input[type="submit"]');

    // Go to adherence report (assume link)
    await page.click('a:has-text("Reports")');
    await page.click('a:has-text("Adherence")');
    await expect(page).toHaveURL(/.*report_adherence\.php/);

    // Toggle custom range
    await page.selectOption('select#range', 'custom');
    await page.fill('input#start-date', '2026-04-01');
    await page.fill('input#end-date', '2026-04-15');
    await page.click('button[type="submit"]');

    // Assert chart renders (canvas element)
    await expect(page.locator('canvas.chartjs-render-monitor')).toBeVisible();

    // Assert table cells have color classes (green/yellow/red)
    await expect(page.locator('.adherence-cell.green')).toBeVisible(); // Assume classes
    await expect(page.locator('table.adherence tbody tr')).toHaveCount(>0);

    // Logout
    await page.goto('http://localhost:8080/logout.php');
  }, { timeout: 30000 });
});