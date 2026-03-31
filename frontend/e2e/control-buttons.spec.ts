import { test, expect } from '@playwright/test';

/**
 * E2E tests for primary control buttons.
 * Verifies button labels and core heat-on/off functionality.
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

	test.describe('Heat-to-target flow', () => {
		test.beforeEach(async ({ page }) => {
			// Enable heat-to-target with static 103°F
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: true, target_temp_f: 103, dynamic_mode: false }
			});
			// Cancel any active session from previous tests
			await page.request.delete('/tub/backend/public/api/equipment/heat-to-target');
		});

		test.afterEach(async ({ page }) => {
			// Clean up: cancel any active heat-to-target session
			await page.request.delete('/tub/backend/public/api/equipment/heat-to-target');
			// Reset settings
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});
		});

		test('pressing Heat button starts heat-to-target session', async ({ page }) => {
			// Reload to pick up the enabled setting
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

			// Button should show target temp
			const heatButton = page.getByRole('button', { name: /Heat to 103/ });
			await expect(heatButton).toBeVisible({ timeout: 5000 });

			// Click to start heating
			await heatButton.click();

			// Should see success message
			await expect(page.getByText(/Heating to 103/)).toBeVisible({ timeout: 5000 });

			// Verify session is active via API
			const response = await page.request.get('/tub/backend/public/api/equipment/heat-to-target');
			const status = await response.json();
			expect(status.active).toBe(true);
			expect(status.target_temp_f).toBe(103);
		});

		test('ETA bar appears when heat-to-target session is active', async ({ page }) => {
			// Reload to pick up settings
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

			// Start heating
			await page.getByRole('button', { name: /Heat to 103/ }).click();
			await expect(page.getByText(/Heating to 103/)).toBeVisible({ timeout: 5000 });

			// ETA display should appear (polling fetches status)
			await expect(page.getByTestId('eta-display')).toBeVisible({ timeout: 10000 });
			// Should show target temp and time
			await expect(page.getByTestId('eta-display')).toContainText('103°F');
			await expect(page.getByTestId('eta-display')).toContainText('min');
		});

		test('projected ETA bar shows when heater is off but target is configured', async ({ page }) => {
			// Reload to pick up settings (heat-to-target enabled but heater off)
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

			// Projected ETA should appear without pressing heat button
			await expect(page.getByTestId('eta-display')).toBeVisible({ timeout: 10000 });
			await expect(page.getByTestId('eta-display')).toContainText('Heat now');
			await expect(page.getByTestId('eta-display')).toContainText('103°F');
		});

		test('ETA switches from projected to active style immediately on heat-on', async ({ page }) => {
			// Reload to pick up settings
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

			// Should show projected ETA first (grey "Heat now" text)
			await expect(page.getByTestId('eta-display')).toContainText('Heat now', { timeout: 10000 });

			// Start heating
			await page.getByRole('button', { name: /Heat to 103/ }).click();
			await expect(page.getByText(/Heating to 103/)).toBeVisible({ timeout: 5000 });

			// ETA should switch to active style (orange "Target" text) within 3 seconds
			// NOT waiting for the 60s poll interval — should update immediately
			await expect(page.getByTestId('eta-display')).toContainText('Target', { timeout: 3000 });
			await expect(page.getByTestId('eta-display')).not.toContainText('Heat now');
		});

		test('pressing Heat Off cancels active heat-to-target', async ({ page }) => {
			// Reload and start heating
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
			await page.getByRole('button', { name: /Heat to 103/ }).click();
			await expect(page.getByText(/Heating to 103/)).toBeVisible({ timeout: 5000 });

			// Press Heat/Pump Off
			await page.getByRole('button', { name: 'Heat/Pump Off' }).click();
			await expect(page.getByText(/turned OFF/)).toBeVisible({ timeout: 5000 });

			// Session should be cancelled
			const response = await page.request.get('/tub/backend/public/api/equipment/heat-to-target');
			const status = await response.json();
			expect(status.active).toBe(false);
		});
	});
});
