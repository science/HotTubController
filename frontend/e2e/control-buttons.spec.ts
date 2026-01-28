import { test, expect } from '@playwright/test';

/**
 * E2E tests for primary control buttons.
 * Verifies button labels and basic functionality.
 */

test.describe('Control Buttons', () => {
	test.beforeEach(async ({ page }) => {
		// Disable auto heat-off to ensure predictable test behavior
		await page.goto('/tub/login');
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'false');
		});
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test('displays correct button labels', async ({ page }) => {
		// Verify the three primary control buttons have correct labels
		const heatOnButton = page.getByRole('button', { name: 'Heat On' });
		const heatOffButton = page.getByRole('button', { name: 'Heat/Pump Off' });
		const pumpButton = page.getByRole('button', { name: 'Pump (2h)' });

		await expect(heatOnButton).toBeVisible();
		await expect(heatOffButton).toBeVisible();
		await expect(pumpButton).toBeVisible();
	});
});
