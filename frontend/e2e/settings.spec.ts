import { test, expect } from '@playwright/test';

/**
 * E2E tests for the Settings panel feature.
 * Tests auto heat-off settings and temp refresh on heater-off.
 */

test.describe('Settings Panel Feature', () => {
	test.beforeEach(async ({ page }) => {
		// Login first
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test.describe('Settings Panel Display', () => {
		test('displays Settings section header', async ({ page }) => {
			await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
		});

		test('displays auto heat-off checkbox', async ({ page }) => {
			await expect(page.getByText('Enable auto heat-off')).toBeVisible();
		});

		test('displays auto heat-off minutes input', async ({ page }) => {
			await expect(page.getByText('Turn off after')).toBeVisible();
			await expect(page.locator('#autoHeatOffMinutes')).toBeVisible();
		});

		test('displays refresh temp on heater-off checkbox', async ({ page }) => {
			await expect(page.getByText('Refresh temperature when heater turns off')).toBeVisible();
		});
	});

	test.describe('Settings Panel Position', () => {
		test('settings panel is below the Schedule panel', async ({ page }) => {
			const scheduleHeading = page.getByRole('heading', { name: 'Schedule', exact: true });
			const settingsHeading = page.getByRole('heading', { name: 'Settings' });

			await expect(scheduleHeading).toBeVisible({ timeout: 10000 });
			await expect(settingsHeading).toBeVisible({ timeout: 10000 });

			const scheduleBox = await scheduleHeading.boundingBox();
			const settingsBox = await settingsHeading.boundingBox();

			// Settings panel should be below schedule panel
			expect(settingsBox!.y).toBeGreaterThan(scheduleBox!.y);
		});
	});

	test.describe('Auto Heat-Off Settings', () => {
		test('auto heat-off checkbox can be toggled', async ({ page }) => {
			const checkbox = page.getByLabel('Enable auto heat-off');
			await expect(checkbox).toBeVisible();

			const initialChecked = await checkbox.isChecked();

			// Toggle the checkbox
			await checkbox.click();

			// Verify it toggled
			const newChecked = await checkbox.isChecked();
			expect(newChecked).toBe(!initialChecked);

			// Toggle back to original state
			await checkbox.click();
			expect(await checkbox.isChecked()).toBe(initialChecked);
		});

		test('auto heat-off minutes can be changed', async ({ page }) => {
			const input = page.locator('#autoHeatOffMinutes');
			await expect(input).toBeVisible();

			// Clear and set a new value
			await input.fill('60');
			await input.blur();

			// Verify the value was set
			await expect(input).toHaveValue('60');
		});
	});

	test.describe('Refresh Temp on Heater-Off Setting', () => {
		test('refresh temp checkbox can be toggled', async ({ page }) => {
			const checkbox = page.getByLabel('Refresh temperature when heater turns off');
			await expect(checkbox).toBeVisible();

			const initialChecked = await checkbox.isChecked();

			// Toggle the checkbox
			await checkbox.click();

			// Verify it toggled
			const newChecked = await checkbox.isChecked();
			expect(newChecked).toBe(!initialChecked);

			// Toggle back to original state
			await checkbox.click();
			expect(await checkbox.isChecked()).toBe(initialChecked);
		});
	});

	test.describe('Settings Persistence', () => {
		test('auto heat-off setting persists after page reload', async ({ page }) => {
			const checkbox = page.getByLabel('Enable auto heat-off');
			await expect(checkbox).toBeVisible();

			// Get current state and toggle it
			const initialChecked = await checkbox.isChecked();
			await checkbox.click();
			const newState = !initialChecked;

			// Reload the page
			await page.reload();

			// Wait for the page to load
			await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible({ timeout: 10000 });

			// Verify the setting persisted
			const checkboxAfterReload = page.getByLabel('Enable auto heat-off');
			expect(await checkboxAfterReload.isChecked()).toBe(newState);

			// Restore original state
			if (await checkboxAfterReload.isChecked() !== initialChecked) {
				await checkboxAfterReload.click();
			}
		});

		test('refresh temp setting persists after page reload', async ({ page }) => {
			const checkbox = page.getByLabel('Refresh temperature when heater turns off');
			await expect(checkbox).toBeVisible();

			// Get current state and toggle it
			const initialChecked = await checkbox.isChecked();
			await checkbox.click();
			const newState = !initialChecked;

			// Reload the page
			await page.reload();

			// Wait for the page to load
			await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible({ timeout: 10000 });

			// Verify the setting persisted
			const checkboxAfterReload = page.getByLabel('Refresh temperature when heater turns off');
			expect(await checkboxAfterReload.isChecked()).toBe(newState);

			// Restore original state
			if (await checkboxAfterReload.isChecked() !== initialChecked) {
				await checkboxAfterReload.click();
			}
		});
	});

	test.describe('Visual regression', () => {
		test('captures screenshot of settings panel', async ({ page }) => {
			// Wait for settings panel to load
			await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible({ timeout: 10000 });

			// Take a screenshot for visual inspection
			await page.screenshot({ path: '/tmp/settings-panel-ui.png', fullPage: true });

			// Basic sanity check - page should have the settings section
			await expect(page.getByText('Settings')).toBeVisible();
		});
	});
});
