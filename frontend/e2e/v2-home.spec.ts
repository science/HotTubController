import { test, expect } from '@playwright/test';

/**
 * Smoke test for the Stage 1 v2 Home screen (/tub/v2).
 *
 * Verifies the new tabbed-app Home renders for an authenticated user and that a
 * hardware control works end to end (stub mode). Full per-role tab gating is
 * covered in Stage 4; this only proves the MVP composes and functions.
 */
test.describe('v2 Home (MVP)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		// Wait until authenticated — the Logout button only exists once signed in (the
		// login page shares the "HOT TUB CONTROL" heading, so it can't gate on that).
		await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible({ timeout: 15000 });
		await page.goto('/tub/v2');
		await expect(page.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });
	});

	test('shows the temperature hero and primary controls', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Temperature' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Heat/Pump Off' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Pump (2h)' })).toBeVisible();
	});

	test('owner sees Home, Schedule, and Setup tabs and an Owner label', async ({ page }) => {
		await expect(page.getByTestId('tab-home')).toBeVisible();
		await expect(page.getByTestId('tab-schedule')).toBeVisible();
		await expect(page.getByTestId('tab-setup')).toBeVisible();
		await expect(page.getByTestId('role-label')).toHaveText('Owner');
	});

	test('turning the heater off works end to end (stub mode)', async ({ page }) => {
		await page.getByRole('button', { name: 'Heat/Pump Off' }).click({ force: true });
		await expect(page.getByText('Heater and pump off')).toBeVisible({ timeout: 10000 });
	});
});
