import { test, expect } from '@playwright/test';

/**
 * Basic E2E tests for the scheduler.
 * These tests verify core functionality without waiting for timers.
 */

test.describe('Scheduler Basic Flow', () => {
	// Clean up all jobs before each test
	test.beforeEach(async ({ page }) => {
		// Login (app is served at /tub base path)
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule' })).toBeVisible({ timeout: 10000 });

		// Clean up any existing scheduled jobs from previous test runs
		const cancelButtons = page.locator('ul li button:has-text("Cancel")');
		let count = await cancelButtons.count();
		while (count > 0) {
			await cancelButtons.first().click();
			await page.waitForTimeout(300);
			count = await cancelButtons.count();
		}
	});

	test('login and view schedule panel', async ({ page }) => {
		// Already logged in from beforeEach

		// Schedule panel should show "No scheduled jobs" (we cleaned up in beforeEach)
		await expect(page.locator('text=No scheduled jobs')).toBeVisible();
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
		await page.click('button:has-text("Schedule")');

		// Verify success
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });

		// Verify job appears - use first() since there should be exactly one after cleanup
		const jobItem = page.locator('ul li').filter({ hasText: 'Heater ON' }).first();
		await expect(jobItem).toBeVisible();

		// Cancel the job
		await jobItem.locator('button:has-text("Cancel")').click();

		// Verify job is removed from the pending list
		await expect(page.locator('text=No scheduled jobs')).toBeVisible({ timeout: 5000 });
	});
});
