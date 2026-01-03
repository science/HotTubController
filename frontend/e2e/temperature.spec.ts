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
			// Use heading role with exact match to be more specific
			await expect(page.getByRole('heading', { name: 'Temperature', exact: true })).toBeVisible();
		});

		test('displays water temperature label', async ({ page }) => {
			// Wait for temperature to load - use specific section
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });
		});

		test('displays ambient temperature label', async ({ page }) => {
			// Wait for temperature to load - use specific section
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Ambient:')).toBeVisible({ timeout: 10000 });
		});

		test('displays temperature values with degrees', async ({ page }) => {
			// Wait for WirelessTag section to load
			const wirelesstagSection = page.locator('[data-testid="wirelesstag-readings"]');
			await expect(wirelesstagSection.getByText('Water:')).toBeVisible({ timeout: 10000 });
			// Check that at least one temperature value is shown
			const waterTemp = wirelesstagSection.locator('text=Water:').locator('..').locator('span.font-medium');
			await expect(waterTemp).toBeVisible();
			await expect(waterTemp).toHaveText(/\d+(\.\d)?°F/);
		});

		test('displays refresh button in WirelessTag section', async ({ page }) => {
			// Refresh button is now in the WirelessTag section
			await expect(page.locator('[data-testid="wirelesstag-refresh"]')).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Temperature Panel Position', () => {
		test('temperature panel is between Quick Heat On and Schedule', async ({ page }) => {
			const quickPanel = page.locator('text=Quick Heat On');
			const tempPanel = page.getByRole('heading', { name: 'Temperature', exact: true });
			const scheduleHeading = page.getByRole('heading', { name: 'Schedule', exact: true });

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
		test('clicking WirelessTag refresh button fetches new temperature', async ({ page }) => {
			// Wait for initial load
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Click WirelessTag refresh
			const refreshButton = page.locator('[data-testid="wirelesstag-refresh"]');
			await refreshButton.click();

			// Should still display temperature after refresh
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Inline Layout', () => {
		test('water and ambient temps are on the same line when space permits', async ({ page }) => {
			// Wait for temperature to load
			const wirelesstagSection = page.locator('[data-testid="wirelesstag-readings"]');
			await expect(wirelesstagSection.getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Get the bounding boxes of both temperature readings within WirelessTag section
			const waterTemp = wirelesstagSection.locator('text=Water:').locator('..');
			const ambientTemp = wirelesstagSection.locator('text=Ambient:').locator('..');

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
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Take a screenshot for visual inspection
			await page.screenshot({ path: '/tmp/temperature-panel-ui.png', fullPage: true });

			// Basic sanity check - page should have the temperature section
			await expect(page.getByRole('heading', { name: 'Temperature', exact: true })).toBeVisible();
		});
	});

	test.describe('Source-Specific Timestamps', () => {
		test('ESP32 source displays its own timestamp', async ({ page }) => {
			// Wait for ESP32 section to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });

			// ESP32 section should have its own timestamp display
			const esp32Timestamp = page.locator('[data-testid="esp32-timestamp"]');
			await expect(esp32Timestamp).toBeVisible();
			// Should contain "Last reading:" label
			await expect(esp32Timestamp).toContainText(/Last reading:/i);
		});

		test('WirelessTag source displays its own timestamp', async ({ page }) => {
			// Wait for WirelessTag section to load
			await expect(page.locator('[data-testid="wirelesstag-readings"]')).toBeVisible({ timeout: 10000 });

			// WirelessTag section should have its own timestamp display
			const wirelesstagTimestamp = page.locator('[data-testid="wirelesstag-timestamp"]');
			await expect(wirelesstagTimestamp).toBeVisible();
			// Should contain "Last reading:" label (shows when sensor took reading, not when fetched)
			await expect(wirelesstagTimestamp).toContainText(/Last reading:/i);
		});

		test('ESP32 and WirelessTag have separate timestamps', async ({ page }) => {
			// Wait for both sections to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });
			await expect(page.locator('[data-testid="wirelesstag-readings"]')).toBeVisible({ timeout: 10000 });

			// Both should have their own timestamps
			const esp32Timestamp = page.locator('[data-testid="esp32-timestamp"]');
			const wirelesstagTimestamp = page.locator('[data-testid="wirelesstag-timestamp"]');

			await expect(esp32Timestamp).toBeVisible();
			await expect(wirelesstagTimestamp).toBeVisible();
		});
	});

	test.describe('Source-Specific Refresh Buttons', () => {
		test('WirelessTag source has its own refresh button', async ({ page }) => {
			// Wait for WirelessTag section to load
			await expect(page.locator('[data-testid="wirelesstag-readings"]')).toBeVisible({ timeout: 10000 });

			// WirelessTag section should have a refresh button
			const wirelesstagRefresh = page.locator('[data-testid="wirelesstag-refresh"]');
			await expect(wirelesstagRefresh).toBeVisible();
		});

		test('ESP32 source has its own refresh button', async ({ page }) => {
			// Wait for ESP32 section to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });

			// ESP32 section should have a refresh button
			const esp32Refresh = page.locator('[data-testid="esp32-refresh"]');
			await expect(esp32Refresh).toBeVisible();
		});

		test('clicking ESP32 refresh fetches fresh data', async ({ page }) => {
			// Wait for ESP32 section to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });

			// Click ESP32 refresh
			const esp32Refresh = page.locator('[data-testid="esp32-refresh"]');
			await esp32Refresh.click();

			// ESP32 section should still show temperature after refresh
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// ESP32 timestamp should still be visible (comes from backend)
			await expect(page.locator('[data-testid="esp32-timestamp"]')).toBeVisible();
		});

		test('clicking WirelessTag refresh only affects WirelessTag', async ({ page }) => {
			// Wait for both sections to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });
			await expect(page.locator('[data-testid="wirelesstag-readings"]')).toBeVisible({ timeout: 10000 });

			// Click WirelessTag refresh
			const wirelesstagRefresh = page.locator('[data-testid="wirelesstag-refresh"]');
			await wirelesstagRefresh.click();

			// WirelessTag section should show refreshing indicator
			const wirelesstagSection = page.locator('[data-testid="wirelesstag-section"]');
			await expect(wirelesstagSection.locator('.animate-spin')).toBeVisible({ timeout: 5000 });

			// Temperature should still be visible in WirelessTag section after refresh
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 15000 });
		});

		test('clicking ESP32 refresh does NOT update WirelessTag timestamp', async ({ page }) => {
			// Wait for both sections to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });
			await expect(page.locator('[data-testid="wirelesstag-readings"]')).toBeVisible({ timeout: 10000 });

			// Get the initial WirelessTag timestamp
			const wirelesstagTimestamp = page.locator('[data-testid="wirelesstag-timestamp"]');
			await expect(wirelesstagTimestamp).toBeVisible();
			const initialTimestamp = await wirelesstagTimestamp.textContent();

			// Wait a moment to ensure any timestamp would be different
			await page.waitForTimeout(1500);

			// Click ESP32 refresh
			const esp32Refresh = page.locator('[data-testid="esp32-refresh"]');
			await esp32Refresh.click();

			// Wait for refresh to complete
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// WirelessTag timestamp should NOT have changed
			const afterTimestamp = await wirelesstagTimestamp.textContent();
			expect(afterTimestamp).toBe(initialTimestamp);
		});
	});

	test.describe('No Global Refresh Button', () => {
		test('there is no global refresh button in the header', async ({ page }) => {
			// Wait for temperature panel to load
			await expect(page.getByRole('heading', { name: 'Temperature', exact: true })).toBeVisible();
			await expect(page.locator('[data-testid="wirelesstag-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// The header should NOT contain a global refresh button
			// The old global refresh button had aria-label="Refresh temperature"
			const globalRefresh = page.locator('button[aria-label="Refresh temperature"]');
			await expect(globalRefresh).not.toBeVisible();
		});
	});

	test.describe('ESP32 Stale Data Warning', () => {
		test('ESP32 shows no warning when data is fresh', async ({ page }) => {
			// Wait for ESP32 section to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });

			// Fresh data should NOT show stale warning
			const staleWarning = page.locator('[data-testid="esp32-stale-warning"]');
			await expect(staleWarning).not.toBeVisible();
		});
	});
});
