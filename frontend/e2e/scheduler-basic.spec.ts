import { test, expect } from '@playwright/test';

/**
 * Basic E2E tests for the scheduler.
 * These tests verify core functionality without waiting for timers.
 */

test.describe('Scheduler Basic Flow', () => {
	test('login and view schedule panel', async ({ page }) => {
		// Navigate to login
		await page.goto('/login');
		await expect(page.locator('h1')).toContainText('HOT TUB CONTROL');

		// Fill login form
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');

		// Should redirect to main page
		await expect(page.locator('text=Schedule')).toBeVisible({ timeout: 10000 });

		// Schedule panel should show "No scheduled jobs" or job list
		const hasNoJobs = await page.locator('text=No scheduled jobs').isVisible().catch(() => false);
		const hasPendingJobs = await page.locator('text=Pending Jobs').isVisible().catch(() => false);

		expect(hasNoJobs || hasPendingJobs).toBe(true);
	});

	test('schedule and cancel a job', async ({ page }) => {
		// Login
		await page.goto('/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.locator('text=Schedule')).toBeVisible({ timeout: 10000 });

		// Schedule a job for tomorrow
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		tomorrow.setHours(10, 0, 0, 0);

		const dateStr = tomorrow.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '10:00');
		await page.click('button:has-text("Schedule")');

		// Verify success
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });
		await expect(page.locator('text=Heater ON')).toBeVisible();

		// Cancel the job
		await page.locator('li').filter({ hasText: 'Heater ON' }).locator('button:has-text("Cancel")').click();

		// Verify job is removed
		await page.waitForTimeout(1000);
		const heaterOnVisible = await page.locator('li').filter({ hasText: 'Heater ON' }).isVisible().catch(() => false);
		expect(heaterOnVisible).toBe(false);
	});
});
