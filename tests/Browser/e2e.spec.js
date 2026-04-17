// @ts-check
//
// HomeCare Playwright smoke tests (HC-078).
//
// These run against the docker-compose stack stood up by
// .github/workflows/e2e.yml. Only the admin user is seeded (no
// patients, no medicines), so the flows we can honestly assert on
// here are limited to login / landing / logout. Richer flows
// (record intake, merge medicines, adherence chart) need a fixture
// loader that seeds a patient + schedule + intake history; that's
// a follow-up.

const { test, expect } = require('@playwright/test');

const ADMIN_LOGIN = 'admin';
const ADMIN_PASSWORD = 'admin';

async function login(page) {
  await page.goto('/login.php');
  await page.fill('input[name="login"]', ADMIN_LOGIN);
  await page.fill('input[name="password"]', ADMIN_PASSWORD);
  // The login form uses <button type="submit"> not <input type="submit">.
  await page.click('button[type="submit"]');
}

test.describe('HomeCare smoke', () => {
  test('login page renders with expected fields', async ({ page }) => {
    await page.goto('/login.php');
    await expect(page).toHaveTitle(/HomeCare/);
    await expect(page.locator('input[name="login"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('unauthenticated request to protected page redirects to login', async ({ page }) => {
    await page.goto('/list_schedule.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('admin can log in, sees index, and logs out', async ({ page }) => {
    await login(page);

    // After login, the router lands on: dashboard.php (2+ patients),
    // list_schedule.php (1 patient), or index.php (0 patients).
    await expect(page).toHaveURL(/\/(index\.php|list_schedule\.php|dashboard\.php)/);

    // Page should at least render without a PHP fatal.
    const body = await page.locator('body').textContent();
    expect(body).not.toMatch(/Fatal error/i);
    expect(body).not.toMatch(/Parse error/i);

    await page.goto('/logout.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('invalid credentials are rejected', async ({ page }) => {
    await page.goto('/login.php');
    await page.fill('input[name="login"]', ADMIN_LOGIN);
    await page.fill('input[name="password"]', 'wrong-password');
    await page.click('button[type="submit"]');
    // Should remain on login.php with an error (message text varies).
    await expect(page).toHaveURL(/login\.php/);
  });
});
