import { test, expect } from '@playwright/test';

/**
 * E2E tests for the scheduler auto-refresh feature.
 *
 * Test configuration uses shorter timer windows:
 * - VITE_MAX_TIMER_WINDOW_MS = 90000 (90 seconds) - jobs within 90s get immediate timers
 * - VITE_RECHECK_INTERVAL_MS = 30000 (30 seconds) - sliding window recheck every 30s
 * - VITE_REFRESH_BUFFER_MS = 5000 (5 seconds) - wait 5s after scheduled time
 *
 * Note: HTML time input has minute resolution (HH:MM), so minimum scheduling
 * granularity is 1 minute. Tests are designed around this constraint.
 */

test.describe('Scheduler Auto-Refresh', () => {
	test.beforeEach(async ({ page }) => {
		// Log in first
		await page.goto('/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');

		// Wait for redirect to main page
		await expect(page.locator('h1')).toContainText('HOT TUB CONTROL');

		// Clean up any existing scheduled jobs from previous test runs
		// by cancelling all visible jobs
		const cancelButtons = page.locator('button:has-text("Cancel")');
		const count = await cancelButtons.count();
		for (let i = 0; i < count; i++) {
			await cancelButtons.first().click();
			await page.waitForTimeout(500);
		}
	});

	test('can schedule a job and see it in pending list', async ({ page }) => {
		// Schedule a job for 2 minutes from now
		const futureTime = new Date(Date.now() + 2 * 60 * 1000);
		const dateStr = futureTime.toISOString().split('T')[0];
		const timeStr = futureTime.toTimeString().slice(0, 5);

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', timeStr);
		await page.click('button:has-text("Schedule")');

		// Verify success message
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		// Verify job appears in pending list
		await expect(page.locator('h3:has-text("Pending Jobs")')).toBeVisible();
		await expect(page.locator('li').filter({ hasText: 'Heater ON' })).toBeVisible();
	});

	test('cancelling a job removes it from the list', async ({ page }) => {
		// Schedule a job
		const futureTime = new Date(Date.now() + 5 * 60 * 1000);
		const dateStr = futureTime.toISOString().split('T')[0];
		const timeStr = futureTime.toTimeString().slice(0, 5);

		await page.selectOption('#action', 'heater-off');
		await page.fill('#date', dateStr);
		await page.fill('#time', timeStr);
		await page.click('button:has-text("Schedule")');

		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });
		await expect(page.locator('li').filter({ hasText: 'Heater OFF' })).toBeVisible();

		// Cancel the job
		await page.locator('li').filter({ hasText: 'Heater OFF' }).locator('button:has-text("Cancel")').click();

		// Verify job is removed
		await expect(page.locator('li').filter({ hasText: 'Heater OFF' })).not.toBeVisible({ timeout: 5000 });
	});

	test('frontend makes refresh API call when timer fires', async ({ page }) => {
		// This test verifies the timer mechanism by monitoring API calls
		// Schedule a job 1 minute from now (inside the 90s timer window)

		const futureTime = new Date(Date.now() + 60 * 1000);
		const dateStr = futureTime.toISOString().split('T')[0];
		const timeStr = futureTime.toTimeString().slice(0, 5);

		// Set up API call monitoring
		let refreshCallCount = 0;
		await page.route('**/api/schedule', async (route) => {
			if (route.request().method() === 'GET') {
				refreshCallCount++;
			}
			await route.continue();
		});

		// Schedule the job
		await page.selectOption('#action', 'pump-run');
		await page.fill('#date', dateStr);
		await page.fill('#time', timeStr);
		await page.click('button:has-text("Schedule")');

		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		// Record initial refresh count (includes the refresh after scheduling)
		const initialCount = refreshCallCount;

		// Wait for the timer to fire (1 min scheduled + 5 sec buffer + margin)
		// The timer should fire ~65 seconds after scheduling
		await page.waitForTimeout(70000);

		// Verify additional refresh calls were made
		expect(refreshCallCount).toBeGreaterThan(initialCount);

		// Clean up - cancel the job
		const cancelBtn = page.locator('li').filter({ hasText: 'Run Pump' }).locator('button:has-text("Cancel")');
		if (await cancelBtn.isVisible()) {
			await cancelBtn.click();
		}
	});

	test('sliding window recheck promotes far-future jobs', async ({ page }) => {
		// Schedule a job 2.5 minutes from now (150 seconds)
		// With 90s window, this is initially OUTSIDE the timer window
		// At T=30 (first recheck), job is 120s away - still outside
		// At T=60 (second recheck), job is 90s away - NOW inside, timer set
		// At T=150+5=155, timer fires

		const futureTime = new Date(Date.now() + 150 * 1000); // 2.5 minutes
		const dateStr = futureTime.toISOString().split('T')[0];
		const timeStr = futureTime.toTimeString().slice(0, 5);

		let refreshCallCount = 0;
		await page.route('**/api/schedule', async (route) => {
			if (route.request().method() === 'GET') {
				refreshCallCount++;
			}
			await route.continue();
		});

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', timeStr);
		await page.click('button:has-text("Schedule")');

		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		const initialCount = refreshCallCount;

		// Wait for the full cycle: sliding window promotion + timer fire
		// ~155 seconds + margin
		await page.waitForTimeout(165000);

		// Verify refresh calls happened (from the timer that was set after promotion)
		expect(refreshCallCount).toBeGreaterThan(initialCount);

		// Clean up
		const cancelBtn = page.locator('li').filter({ hasText: 'Heater ON' }).locator('button:has-text("Cancel")');
		if (await cancelBtn.isVisible()) {
			await cancelBtn.click();
		}
	});
});
