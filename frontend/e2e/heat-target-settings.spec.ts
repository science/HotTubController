import { test, expect } from '@playwright/test';

/**
 * E2E tests for the shared heat-target settings feature.
 *
 * Test Specifications:
 * 1. Admin users can see and modify heat-target settings
 * 2. Non-admin users cannot see heat-target settings section
 * 3. Button label reflects current heat-target settings state
 * 4. Settings persist in backend and load on page refresh
 */

test.describe('Heat Target Settings (Admin Only)', () => {
	test.describe('Admin user - UI visibility', () => {
		test.beforeEach(async ({ page }) => {
			// Reset settings to known state before each test
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});

			// Login as admin
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});
		});

		test('displays Target Temperature section for admin', async ({ page }) => {
			await expect(page.getByText('Target Temperature (Global Setting)')).toBeVisible({
				timeout: 10000
			});
		});

		test('displays enable heat to target checkbox', async ({ page }) => {
			await expect(page.getByLabel('Enable heat to target')).toBeVisible({ timeout: 10000 });
		});

		test('displays target temperature slider', async ({ page }) => {
			await expect(page.getByLabel('Target temp', { exact: true })).toBeVisible({ timeout: 10000 });
		});

		test('displays target temperature input', async ({ page }) => {
			await expect(page.getByLabel('Target temp input')).toBeVisible({ timeout: 10000 });
		});
	});

	test.describe('Admin user - interactions', () => {
		test.beforeEach(async ({ page }) => {
			// Reset settings to known state
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});

			// Login as admin
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});
		});

		test('checkbox click shows Save button (dirty state)', async ({ page }) => {
			const checkbox = page.getByLabel('Enable heat to target');
			await expect(checkbox).toBeVisible({ timeout: 10000 });

			// Toggle the checkbox
			await checkbox.click({ force: true });

			// Should show Save button when dirty
			await expect(page.getByRole('button', { name: 'Save Settings' })).toBeVisible({ timeout: 5000 });
		});

		test('slider change shows Save button (dirty state)', async ({ page }) => {
			const slider = page.getByLabel('Target temp', { exact: true });
			await expect(slider).toBeVisible({ timeout: 10000 });

			// Change slider value
			await slider.fill('105');

			// Should show Save button when dirty
			await expect(page.getByRole('button', { name: 'Save Settings' })).toBeVisible({ timeout: 5000 });
		});

		test('input change shows Save button (dirty state)', async ({ page }) => {
			const input = page.getByLabel('Target temp input');
			await expect(input).toBeVisible({ timeout: 10000 });

			// Clear and set new value
			await input.fill('106');
			await input.blur();

			// Should show Save button when dirty
			await expect(page.getByRole('button', { name: 'Save Settings' })).toBeVisible({ timeout: 5000 });
		});

		test('Save button sends PUT request to backend', async ({ page }) => {
			// Set up request listener before making changes
			const requestPromise = page.waitForRequest(
				(request) =>
					request.url().includes('/api/settings/heat-target') && request.method() === 'PUT'
			);

			// Make a change to trigger dirty state
			const checkbox = page.getByLabel('Enable heat to target');
			await expect(checkbox).toBeVisible({ timeout: 10000 });
			await checkbox.click({ force: true });

			// Click save
			const saveButton = page.getByRole('button', { name: 'Save Settings' });
			await expect(saveButton).toBeVisible({ timeout: 5000 });
			await saveButton.click({ force: true });

			// Wait for the PUT request
			const request = await requestPromise;
			expect(request.method()).toBe('PUT');

			// Verify request body contains expected fields
			const postData = request.postDataJSON();
			expect(postData).toHaveProperty('enabled');
			expect(postData).toHaveProperty('target_temp_f');
		});
	});

	test.describe('Backend persistence', () => {
		test('settings saved via API are reflected in UI after reload', async ({ page }) => {
			// Login first (required for API access)
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Set specific settings via API (now authenticated)
			const response = await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: true, target_temp_f: 105 }
			});
			expect(response.ok()).toBeTruthy();

			// Reload to get fresh state from health endpoint
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Verify UI reflects the settings
			const checkbox = page.getByLabel('Enable heat to target');
			await expect(checkbox).toBeChecked({ timeout: 10000 });

			const input = page.getByLabel('Target temp input');
			await expect(input).toHaveValue('105');
		});

		test('settings changed via UI persist after page reload', async ({ page }) => {
			// Login first
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Reset to known state via API
			const resetResponse = await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});
			expect(resetResponse.ok()).toBeTruthy();

			// Reload to get fresh state
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Make changes via UI
			const checkbox = page.getByLabel('Enable heat to target');
			await expect(checkbox).toBeVisible({ timeout: 10000 });
			await checkbox.click({ force: true });

			const input = page.getByLabel('Target temp input');
			await input.fill('107');
			await input.blur();

			// Save and wait for response
			const saveButton = page.getByRole('button', { name: 'Save Settings' });
			await expect(saveButton).toBeVisible({ timeout: 5000 });

			const responsePromise = page.waitForResponse(
				(response) =>
					response.url().includes('/api/settings/heat-target') &&
					response.request().method() === 'PUT' &&
					response.status() === 200
			);
			await saveButton.click({ force: true });
			await responsePromise;

			// Reload page
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Verify settings persisted
			const checkboxAfter = page.getByLabel('Enable heat to target');
			await expect(checkboxAfter).toBeChecked({ timeout: 10000 });

			const inputAfter = page.getByLabel('Target temp input');
			await expect(inputAfter).toHaveValue('107');
		});
	});

	test.describe('Non-admin user', () => {
		test('does not display Target Temperature section for non-admin', async ({ page }) => {
			const testUsername = `testuser_heat_settings_${Date.now()}`;

			// Create test user via API (no auth required for this test setup)
			// First login as admin to create the user
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Create user via API
			const createResponse = await page.request.post('/tub/backend/public/api/users', {
				data: {
					username: testUsername,
					password: 'testpass123',
					role: 'user'
				}
			});
			expect(createResponse.ok()).toBeTruthy();

			// Logout and login as regular user
			await page.request.post('/tub/backend/public/api/auth/logout');
			await page.goto('/tub/login');
			await page.fill('#username', testUsername);
			await page.fill('#password', 'testpass123');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Target Temperature section should NOT be visible for regular users
			await expect(page.getByText('Target Temperature (Global Setting)')).not.toBeVisible();

			// Cleanup: delete the test user via API
			await page.request.post('/tub/backend/public/api/auth/logout');
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});
			await page.request.delete(`/tub/backend/public/api/users/${testUsername}`);
		});
	});

	test.describe('Button label reflects settings', () => {
		test('Heat button shows "Heat On" when target temp disabled', async ({ page }) => {
			// Login first (required for API access)
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Ensure target temp is disabled via API
			const response = await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});
			expect(response.ok()).toBeTruthy();

			// Reload to get fresh state from health endpoint
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Button should show "Heat On"
			await expect(page.getByRole('button', { name: 'Heat On' })).toBeVisible({ timeout: 5000 });
		});

		test('Heat button shows target temperature when enabled', async ({ page }) => {
			// Login first (required for API access)
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Enable target temp via API with specific temperature
			const response = await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: true, target_temp_f: 104 }
			});
			expect(response.ok()).toBeTruthy();

			// Reload to get fresh state from health endpoint
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Button should show "Heat to 104°F"
			await expect(page.getByRole('button', { name: /Heat to 104/ })).toBeVisible({ timeout: 5000 });
		});
	});

	test.describe('Dynamic mode', () => {
		test.beforeEach(async ({ page }) => {
			// Reset to known state with dynamic mode off
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: {
					enabled: true,
					target_temp_f: 103,
					dynamic_mode: false,
					calibration_points: {
						cold:    { ambient_f: 45, water_target_f: 104 },
						comfort: { ambient_f: 60, water_target_f: 102 },
						hot:     { ambient_f: 75, water_target_f: 100.5 }
					}
				}
			});

			// Login as admin
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});
		});

		test('displays target mode radio buttons for admin', async ({ page }) => {
			await expect(page.getByLabel('Static target')).toBeVisible({ timeout: 10000 });
			await expect(page.getByLabel('Dynamic (ambient-adjusted)')).toBeVisible();
		});

		test('static mode is selected by default', async ({ page }) => {
			const staticRadio = page.getByLabel('Static target');
			await expect(staticRadio).toBeChecked({ timeout: 10000 });
		});

		test('switching to dynamic mode hides slider and shows calibration inputs', async ({ page }) => {
			// Initially slider should be visible
			await expect(page.getByLabel('Target temp', { exact: true })).toBeVisible({ timeout: 10000 });

			// Switch to dynamic
			await page.getByLabel('Dynamic (ambient-adjusted)').click();

			// Slider should be hidden, calibration inputs should appear
			await expect(page.getByLabel('Target temp', { exact: true })).not.toBeVisible();
			await expect(page.getByLabel('Cold ambient temp')).toBeVisible();
			await expect(page.getByLabel('Comfort ambient temp')).toBeVisible();
			await expect(page.getByLabel('Hot ambient temp')).toBeVisible();
		});

		test('switching to dynamic mode marks settings as dirty', async ({ page }) => {
			await expect(page.getByLabel('Static target')).toBeVisible({ timeout: 10000 });
			await page.getByLabel('Dynamic (ambient-adjusted)').click();
			await expect(page.getByRole('button', { name: 'Save Settings' })).toBeVisible();
		});

		test('dynamic mode persists after save and reload', async ({ page }) => {
			await expect(page.getByLabel('Static target')).toBeVisible({ timeout: 10000 });

			// Switch to dynamic and save
			await page.getByLabel('Dynamic (ambient-adjusted)').click();
			await page.getByRole('button', { name: 'Save Settings' }).click();

			// Wait for save to complete (button disappears when not dirty)
			await expect(page.getByRole('button', { name: 'Save Settings' })).not.toBeVisible({ timeout: 5000 });

			// Reload and verify
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});
			await expect(page.getByLabel('Dynamic (ambient-adjusted)')).toBeChecked({ timeout: 5000 });
		});

		test('calibration point values persist after save and reload', async ({ page }) => {
			await expect(page.getByLabel('Static target')).toBeVisible({ timeout: 10000 });

			// Enable dynamic mode via API with custom calibration
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: {
					enabled: true,
					target_temp_f: 103,
					dynamic_mode: true,
					calibration_points: {
						cold:    { ambient_f: 40, water_target_f: 105 },
						comfort: { ambient_f: 55, water_target_f: 103 },
						hot:     { ambient_f: 70, water_target_f: 101 }
					}
				}
			});

			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Verify dynamic mode is active
			await expect(page.getByLabel('Dynamic (ambient-adjusted)')).toBeChecked({ timeout: 5000 });

			// Verify calibration values loaded
			await expect(page.getByLabel('Cold ambient temp')).toHaveValue('40');
			await expect(page.getByLabel('Cold water target')).toHaveValue('105');
		});

		test('button shows "Heat (Dynamic)" when dynamic mode is enabled', async ({ page }) => {
			// Enable dynamic mode via API
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: true, target_temp_f: 103, dynamic_mode: true }
			});

			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			await expect(page.getByRole('button', { name: 'Heat (Dynamic)' })).toBeVisible({ timeout: 5000 });
		});
	});

	test.describe('ETA display', () => {
		test('ETA is not visible when heat-to-target is disabled', async ({ page }) => {
			// Login first so API calls are authenticated
			await page.goto('/tub/login');
			await page.fill('#username', 'admin');
			await page.fill('#password', 'password');
			await page.press('#password', 'Enter');
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// Disable heat-to-target (must be after login for admin auth)
			const response = await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});
			expect(response.ok()).toBeTruthy();

			// Reload to pick up the disabled setting
			await page.reload();
			await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({
				timeout: 10000
			});

			// ETA display should not exist when feature is disabled
			await expect(page.getByTestId('eta-display')).not.toBeVisible();
		});
	});
});
