import { test, expect } from '@playwright/test';

/**
 * E2E tests for the Temperature Display feature.
 * Tests the temperature panel with water and ambient temps.
 */

test.describe('Temperature Display Feature', () => {
	test.beforeEach(async ({ page }) => {
		// Login first
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test.describe('Temperature Panel Display', () => {
		test('displays temperature section header', async ({ page }) => {
			// Use heading role to be more specific
			await expect(page.getByRole('heading', { name: 'Temperature' })).toBeVisible();
		});

		test('displays water temperature label', async ({ page }) => {
			// Wait for temperature to load
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });
		});

		test('displays ambient temperature label', async ({ page }) => {
			// Wait for temperature to load
			await expect(page.getByText('Ambient:')).toBeVisible({ timeout: 10000 });
		});

		test('displays temperature values with degrees', async ({ page }) => {
			// Wait for temperature to load and display water temp specifically
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });
			// Check that at least one temperature value is shown
			const waterTemp = page.locator('text=Water:').locator('..').locator('span.font-medium');
			await expect(waterTemp).toBeVisible();
			await expect(waterTemp).toHaveText(/\d+(\.\d)?°F/);
		});

		test('displays refresh button', async ({ page }) => {
			await expect(page.getByRole('button', { name: /refresh temperature/i })).toBeVisible();
		});
	});

	test.describe('Temperature Panel Position', () => {
		test('temperature panel is between Quick Heat On and Schedule', async ({ page }) => {
			const quickPanel = page.locator('text=Quick Heat On');
			const tempPanel = page.getByRole('heading', { name: 'Temperature' });
			const scheduleHeading = page.getByRole('heading', { name: 'Schedule' });

			// Wait for all elements to be visible
			await expect(quickPanel).toBeVisible({ timeout: 10000 });
			await expect(tempPanel).toBeVisible({ timeout: 10000 });
			await expect(scheduleHeading).toBeVisible({ timeout: 10000 });

			// Get vertical positions
			const quickBox = await quickPanel.boundingBox();
			const tempBox = await tempPanel.boundingBox();
			const scheduleBox = await scheduleHeading.boundingBox();

			// Temperature panel should be below quick panel
			expect(tempBox!.y).toBeGreaterThan(quickBox!.y);

			// Temperature panel should be above schedule heading
			expect(tempBox!.y).toBeLessThan(scheduleBox!.y);
		});
	});

	test.describe('Refresh Functionality', () => {
		test('clicking refresh button fetches new temperature', async ({ page }) => {
			// Wait for initial load
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Click refresh
			const refreshButton = page.getByRole('button', { name: /refresh temperature/i });
			await refreshButton.click();

			// Should still display temperature after refresh
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Inline Layout', () => {
		test('water and ambient temps are on the same line when space permits', async ({ page }) => {
			// Wait for temperature to load
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Get the bounding boxes of both temperature readings
			const waterTemp = page.locator('[data-testid="water-temp"]');
			const ambientTemp = page.locator('[data-testid="ambient-temp"]');

			await expect(waterTemp).toBeVisible();
			await expect(ambientTemp).toBeVisible();

			const waterBox = await waterTemp.boundingBox();
			const ambientBox = await ambientTemp.boundingBox();

			// On desktop, they should be on the same row (same Y position, roughly)
			// Allow for small vertical alignment differences
			expect(Math.abs(waterBox!.y - ambientBox!.y)).toBeLessThan(5);

			// Ambient should be to the right of water
			expect(ambientBox!.x).toBeGreaterThan(waterBox!.x);
		});
	});

	test.describe('Visual regression', () => {
		test('captures screenshot of temperature panel', async ({ page }) => {
			// Wait for temperature to load
			await expect(page.getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Take a screenshot for visual inspection
			await page.screenshot({ path: '/tmp/temperature-panel-ui.png', fullPage: true });

			// Basic sanity check - page should have the temperature section
			await expect(page.getByRole('heading', { name: 'Temperature' })).toBeVisible();
		});
	});
});
