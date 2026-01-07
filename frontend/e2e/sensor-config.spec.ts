import { test, expect } from '@playwright/test';

/**
 * E2E tests for the ESP32 Sensor Configuration panel.
 * Tests sensor role assignment and persistence.
 */

test.describe('ESP32 Sensor Configuration Panel', () => {
	test.beforeEach(async ({ page }) => {
		// Login as admin (required to see sensor config panel)
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test.describe('Panel Display', () => {
		test('displays ESP32 Sensor Configuration header for admin', async ({ page }) => {
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });
		});

		test('displays detected sensors from ESP32', async ({ page }) => {
			// Wait for sensors to load
			const sensorPanel = page.locator('text=ESP32 Sensor Configuration').locator('..');
			await expect(sensorPanel).toBeVisible({ timeout: 10000 });

			// Should show at least one sensor with its address (hex format)
			// Sensor addresses look like "28:F6:DD:87:00:88:1E:E8"
			await expect(page.locator('text=/28:[A-F0-9]{2}:[A-F0-9]{2}/').first()).toBeVisible({ timeout: 10000 });
		});

		test('displays role dropdown for each sensor', async ({ page }) => {
			// Wait for sensor config panel to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Should have at least one role dropdown
			const roleSelect = page.locator('select').filter({ hasText: /Water Temperature|Ambient Temperature|Unassigned/ }).first();
			await expect(roleSelect).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Role Assignment', () => {
		test('can change sensor role from dropdown', async ({ page }) => {
			// Wait for sensor config panel to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Find role dropdown by looking for selects that have 'water' option (unique to sensor config)
			const roleSelect = page.locator('select:has(option[value="water"])').first();
			await expect(roleSelect).toBeVisible({ timeout: 10000 });

			// Get current value
			const initialValue = await roleSelect.inputValue();

			// Change to a different value
			const newValue = initialValue === 'water' ? 'ambient' : 'water';
			await roleSelect.selectOption(newValue);

			// Wait for save to complete (panel might show loading state)
			await page.waitForTimeout(1000);

			// Verify the dropdown shows new value
			await expect(roleSelect).toHaveValue(newValue);
		});

		test('role change persists after page reload', async ({ page }) => {
			// Wait for sensor config panel to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Find role dropdown by looking for selects that have 'water' option
			const roleSelect = page.locator('select:has(option[value="water"])').first();
			await expect(roleSelect).toBeVisible({ timeout: 10000 });

			// Get current value and pick a different one
			const initialValue = await roleSelect.inputValue();
			const newValue = initialValue === 'unassigned' ? 'water' : 'unassigned';

			// Change the role
			await roleSelect.selectOption(newValue);

			// Wait for save to complete (watch for network request)
			await page.waitForResponse(response =>
				response.url().includes('/api/esp32/sensors/') &&
				response.request().method() === 'PUT'
			);

			// Small delay for UI to update
			await page.waitForTimeout(500);

			// Verify value changed in UI before reload
			await expect(roleSelect).toHaveValue(newValue);

			// Set up wait for sensors response BEFORE reload
			const sensorsResponsePromise = page.waitForResponse(response =>
				response.url().includes('/api/esp32/sensors') &&
				response.request().method() === 'GET'
			);

			// Reload the page
			await page.reload();

			// Wait for sensors response
			await sensorsResponsePromise;

			// Wait for page and sensor config to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Find role dropdown after reload
			const roleSelectAfterReload = page.locator('select:has(option[value="water"])').first();
			await expect(roleSelectAfterReload).toBeVisible({ timeout: 10000 });

			// Role should persist after reload
			await expect(roleSelectAfterReload).toHaveValue(newValue);

			// Restore original value for cleanup
			await roleSelectAfterReload.selectOption(initialValue);
			await page.waitForTimeout(1000);
		});
	});

	test.describe('Role Change Network Request', () => {
		test('changing role sends PUT request to backend', async ({ page }) => {
			// Wait for sensor config panel to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Set up request listener
			const requestPromise = page.waitForRequest(request =>
				request.url().includes('/api/esp32/sensors/') &&
				request.method() === 'PUT'
			);

			// Find role dropdown by looking for selects that have 'water' option
			const roleSelect = page.locator('select:has(option[value="water"])').first();
			await expect(roleSelect).toBeVisible({ timeout: 10000 });

			// Get current value and change to something different
			const initialValue = await roleSelect.inputValue();
			const newValue = initialValue === 'water' ? 'ambient' : 'water';

			// Change the role
			await roleSelect.selectOption(newValue);

			// Wait for the PUT request
			const request = await requestPromise;

			// Verify request was made correctly
			expect(request.method()).toBe('PUT');
			expect(request.url()).toContain('/api/esp32/sensors/');

			// Verify request body contains the role
			const postData = request.postDataJSON();
			expect(postData).toHaveProperty('role', newValue);
		});

		test('backend returns success for role update', async ({ page }) => {
			// Wait for sensor config panel to load
			await expect(page.getByRole('heading', { name: 'ESP32 Sensor Configuration' })).toBeVisible({ timeout: 10000 });

			// Set up response listener
			const responsePromise = page.waitForResponse(response =>
				response.url().includes('/api/esp32/sensors/') &&
				response.request().method() === 'PUT'
			);

			// Find role dropdown by looking for selects that have 'water' option
			const roleSelect = page.locator('select:has(option[value="water"])').first();
			const initialValue = await roleSelect.inputValue();
			const newValue = initialValue === 'water' ? 'ambient' : 'water';

			// Change the role
			await roleSelect.selectOption(newValue);

			// Wait for the response
			const response = await responsePromise;

			// Verify response is successful
			expect(response.status()).toBe(200);

			const responseBody = await response.json();
			expect(responseBody).toHaveProperty('sensor');
			expect(responseBody.sensor).toHaveProperty('role', newValue);
		});
	});
});
