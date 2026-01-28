import { test, expect } from '@playwright/test';

/**
 * E2E tests for the Temperature Display feature.
 * Tests the temperature panel with ESP32 water and ambient temps.
 */

test.describe('Temperature Display Feature', () => {
	test.beforeEach(async ({ page }) => {
		// Login first
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Reset ESP32 sensor configuration to ensure water and ambient roles are correctly assigned
		// This prevents test pollution from sensor-config tests
		const waterAddress = '28:F6:DD:87:00:88:1E:E8';
		const ambientAddress = '28:D5:AA:87:00:23:16:34';

		await page.request.put(`/tub/backend/public/api/esp32/sensors/${encodeURIComponent(waterAddress)}`, {
			data: { role: 'water', calibration_offset: 0, name: 'Test Water Sensor' }
		});
		await page.request.put(`/tub/backend/public/api/esp32/sensors/${encodeURIComponent(ambientAddress)}`, {
			data: { role: 'ambient', calibration_offset: 0, name: 'Test Ambient Sensor' }
		});

		// Reload to pick up the reset configuration
		await page.reload();
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test.describe('Temperature Panel Display', () => {
		test('displays temperature section header', async ({ page }) => {
			// Use heading role with exact match to be more specific
			await expect(page.getByRole('heading', { name: 'Temperature', exact: true })).toBeVisible();
		});

		test('displays water temperature label', async ({ page }) => {
			// Wait for temperature to load - use ESP32 section
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });
		});

		test('displays ambient temperature label', async ({ page }) => {
			// Wait for temperature to load - use ESP32 section
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Ambient:')).toBeVisible({ timeout: 10000 });
		});

		test('displays temperature values with degrees', async ({ page }) => {
			// Wait for ESP32 section to load
			const esp32Section = page.locator('[data-testid="esp32-readings"]');
			await expect(esp32Section.getByText('Water:')).toBeVisible({ timeout: 10000 });
			// Check that at least one temperature value is shown
			const waterTemp = esp32Section.locator('text=Water:').locator('..').locator('span.font-medium');
			await expect(waterTemp).toBeVisible();
			await expect(waterTemp).toHaveText(/\d+(\.\d)?°F/);
		});

		test('displays refresh button in the header', async ({ page }) => {
			// Refresh button is in the ESP32 section
			await expect(page.locator('[data-testid="esp32-refresh"]')).toBeVisible({ timeout: 10000 });
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
		test('clicking ESP32 refresh button fetches new temperature', async ({ page }) => {
			// Wait for initial load
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Click ESP32 refresh
			const refreshButton = page.locator('[data-testid="esp32-refresh"]');
			await refreshButton.click({ force: true });

			// Should still display temperature after refresh
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Inline Layout', () => {
		test('water and ambient temps are on the same line when space permits', async ({ page }) => {
			// Wait for temperature to load
			const esp32Section = page.locator('[data-testid="esp32-readings"]');
			await expect(esp32Section.getByText('Water:')).toBeVisible({ timeout: 10000 });

			// Get the bounding boxes of both temperature readings within ESP32 section
			const waterTemp = esp32Section.locator('text=Water:').locator('..');
			const ambientTemp = esp32Section.locator('text=Ambient:').locator('..');

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

	test.describe('ESP32 Timestamps', () => {
		test('ESP32 source displays its own timestamp', async ({ page }) => {
			// Wait for ESP32 section to load
			await expect(page.locator('[data-testid="esp32-readings"]')).toBeVisible({ timeout: 10000 });

			// ESP32 section should have its own timestamp display
			const esp32Timestamp = page.locator('[data-testid="esp32-timestamp"]');
			await expect(esp32Timestamp).toBeVisible();
			// Should contain "Last reading:" label
			await expect(esp32Timestamp).toContainText(/Last reading:/i);
		});
	});

	test.describe('ESP32 Refresh Button', () => {
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
			await esp32Refresh.click({ force: true });

			// ESP32 section should still show temperature after refresh
			await expect(page.locator('[data-testid="esp32-readings"]').getByText('Water:')).toBeVisible({ timeout: 10000 });

			// ESP32 timestamp should still be visible (comes from backend)
			await expect(page.locator('[data-testid="esp32-timestamp"]')).toBeVisible();
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
