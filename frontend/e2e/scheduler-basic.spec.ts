import { test, expect } from '@playwright/test';

/**
 * Basic E2E tests for the scheduler.
 * These tests verify core functionality without waiting for timers.
 */

test.describe('Scheduler Basic Flow', () => {
	// Clean up all jobs before each test
	test.beforeEach(async ({ page }) => {
		// Disable auto heat-off to ensure predictable test behavior
		// (default is enabled, which creates 2 jobs for heater-on and changes success message)
		await page.goto('/tub/login');
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'false');
		});
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Wait for all initial data to load (including job list)
		await page.waitForLoadState('networkidle');

		// Clean up any existing scheduled jobs (both recurring and one-off) from previous test runs
		const cancelButtons = page.locator('button:has-text("Cancel")');
		let count = await cancelButtons.count();
		while (count > 0) {
			await cancelButtons.first().click({ force: true });
			await page.waitForTimeout(300);
			count = await cancelButtons.count();
		}
	});

	test('login and view schedule panel', async ({ page }) => {
		// Already logged in from beforeEach

		// Schedule panel should show "No upcoming jobs" (we cleaned up in beforeEach)
		await expect(page.locator('text=No upcoming jobs')).toBeVisible();
	});

	test('edit temperature on a heat-to-target job', async ({ page }) => {
		// Create a heat-to-target job via API (not available in UI dropdown)
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		tomorrow.setHours(10, 30, 0, 0);

		const response = await page.request.post('/tub/backend/public/api/schedule', {
			data: {
				action: 'heat-to-target',
				scheduledTime: tomorrow.toISOString(),
				target_temp_f: 103,
			},
		});
		expect(response.ok()).toBeTruthy();

		// Refresh the page to pick up the new job
		await page.reload();
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
		await page.waitForLoadState('networkidle');

		// Verify heat-to-target job appears with editable temp
		const editableTemp = page.getByTestId('editable-temp');
		await expect(editableTemp).toBeVisible({ timeout: 10000 });
		await expect(editableTemp).toContainText('Heat to 103°F');

		// Click to enter edit mode
		await editableTemp.click();

		// Wait for the input to appear
		const tempInput = page.getByTestId('edit-temp-input');
		await expect(tempInput).toBeVisible({ timeout: 5000 });

		// Change the temperature
		await tempInput.fill('106');
		await tempInput.press('Enter');

		// Verify the display updates with new temp
		await expect(page.getByTestId('editable-temp')).toContainText('Heat to 106°F', { timeout: 10000 });

		// Clean up
		const jobItem = page.locator('ul li').filter({ hasText: 'Heat to 106°F' }).first();
		await jobItem.locator('button:has-text("Cancel")').click({ force: true });
	});

	test('schedule and cancel a job', async ({ page }) => {
		// Already logged in from beforeEach

		// Schedule a job for tomorrow (unique time to avoid collisions)
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		tomorrow.setHours(10, 30, 0, 0);

		const dateStr = tomorrow.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '10:30');
		await page.click('button:has-text("Schedule")', { force: true });

		// Verify success
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });

		// Verify job appears - use first() since there should be exactly one after cleanup
		const jobItem = page.locator('ul li').filter({ hasText: 'Heater ON' }).first();
		await expect(jobItem).toBeVisible();

		// Cancel the job
		await jobItem.locator('button:has-text("Cancel")').click({ force: true });

		// Verify job is removed from the pending list
		await expect(page.locator('text=No upcoming jobs')).toBeVisible({ timeout: 5000 });
	});
});
