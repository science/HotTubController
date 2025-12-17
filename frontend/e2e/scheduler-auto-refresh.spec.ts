import { test, expect } from '@playwright/test';

/**
 * E2E tests for scheduler functionality.
 *
 * Note: Timer-based auto-refresh behavior (sliding window, refresh on job completion)
 * is thoroughly tested in unit tests (SchedulePanel.test.ts) using Vitest fake timers.
 *
 * These E2E tests focus on:
 * - Frontend-backend integration
 * - API request correctness
 * - UI state management
 * - Error handling
 */

test.describe('Scheduler Integration', () => {
	test.beforeEach(async ({ page }) => {
		// Disable auto heat-off to ensure predictable test behavior
		// (default is enabled, which creates 2 jobs for heater-on and changes success message)
		await page.goto('/tub/login');
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'false');
		});
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');

		// Wait for redirect to main page
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Clean up any existing scheduled jobs from previous test runs
		const cancelButtons = page.locator('ul li button:has-text("Cancel")');
		let count = await cancelButtons.count();
		while (count > 0) {
			await cancelButtons.first().click();
			await page.waitForTimeout(300);
			count = await cancelButtons.count();
		}
	});

	test('schedule job makes correct API call and updates UI', async ({ page }) => {
		// Monitor API calls
		const apiCalls: { url: string; method: string; body: string }[] = [];
		await page.route('**/api/schedule', async (route) => {
			apiCalls.push({
				url: route.request().url(),
				method: route.request().method(),
				body: route.request().postData() || '',
			});
			await route.continue();
		});

		// Schedule a job for 2 days from now
		const futureDate = new Date();
		futureDate.setDate(futureDate.getDate() + 2);
		const dateStr = futureDate.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '14:30');
		await page.click('button:has-text("Schedule")');

		// Verify success
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		// Verify POST request was made
		const postCall = apiCalls.find((c) => c.method === 'POST');
		expect(postCall).toBeDefined();
		expect(postCall!.body).toContain('heater-on');
		expect(postCall!.body).toContain('14:30');

		// Verify job appears in UI
		await expect(page.locator('ul li').filter({ hasText: 'Heater ON' })).toBeVisible();

		// Clean up
		await page.locator('ul li').filter({ hasText: 'Heater ON' }).first().locator('button:has-text("Cancel")').click();
	});

	test('cancel job makes correct API call and removes from UI', async ({ page }) => {
		// First, schedule a job
		const futureDate = new Date();
		futureDate.setDate(futureDate.getDate() + 2);
		const dateStr = futureDate.toISOString().split('T')[0];

		await page.selectOption('#action', 'pump-run');
		await page.fill('#date', dateStr);
		await page.fill('#time', '09:00');
		await page.click('button:has-text("Schedule")');
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		// Monitor DELETE calls
		let deleteCallMade = false;
		await page.route('**/api/schedule/*', async (route) => {
			if (route.request().method() === 'DELETE') {
				deleteCallMade = true;
			}
			await route.continue();
		});

		// Get the job item
		const jobItem = page.locator('ul li').filter({ hasText: 'Run Pump' }).first();
		await expect(jobItem).toBeVisible();

		// Cancel it
		await jobItem.locator('button:has-text("Cancel")').click();

		// Verify DELETE was called
		await page.waitForTimeout(500);
		expect(deleteCallMade).toBe(true);

		// Verify job is removed from UI
		await expect(page.locator('text=No upcoming jobs')).toBeVisible({ timeout: 5000 });
	});

	test('list jobs loads on page load', async ({ page }) => {
		// Schedule a job first
		const futureDate = new Date();
		futureDate.setDate(futureDate.getDate() + 3);
		const dateStr = futureDate.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-off');
		await page.fill('#date', dateStr);
		await page.fill('#time', '18:00');
		await page.click('button:has-text("Schedule")');
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });

		// Monitor GET calls on a fresh page load
		let getCallMade = false;
		await page.route('**/api/schedule', async (route) => {
			if (route.request().method() === 'GET') {
				getCallMade = true;
			}
			await route.continue();
		});

		// Reload the page
		await page.reload();
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Verify GET was called
		expect(getCallMade).toBe(true);

		// Verify job persisted and is visible
		await expect(page.locator('ul li').filter({ hasText: 'Heater OFF' })).toBeVisible();

		// Clean up
		await page.locator('ul li').filter({ hasText: 'Heater OFF' }).first().locator('button:has-text("Cancel")').click();
	});

	test('displays correct action labels in job list', async ({ page }) => {
		const futureDate = new Date();
		futureDate.setDate(futureDate.getDate() + 5);
		const dateStr = futureDate.toISOString().split('T')[0];

		// Schedule each action type
		const actions = [
			{ value: 'heater-on', label: 'Heater ON' },
			{ value: 'heater-off', label: 'Heater OFF' },
			{ value: 'pump-run', label: 'Run Pump' },
		];

		for (let i = 0; i < actions.length; i++) {
			await page.selectOption('#action', actions[i].value);
			await page.fill('#date', dateStr);
			await page.fill('#time', `${10 + i}:00`);
			await page.click('button:has-text("Schedule")');
			await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });
			await page.waitForTimeout(300);
		}

		// Verify all labels appear correctly
		for (const action of actions) {
			await expect(page.locator('ul li').filter({ hasText: action.label })).toBeVisible();
		}

		// Clean up all
		for (let i = 0; i < 3; i++) {
			await page.locator('ul li button:has-text("Cancel")').first().click();
			await page.waitForTimeout(300);
		}
	});

	test('rejects scheduling job in the past', async ({ page }) => {
		// Try to schedule a job for yesterday
		const yesterday = new Date();
		yesterday.setDate(yesterday.getDate() - 1);
		const dateStr = yesterday.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '10:00');
		await page.click('button:has-text("Schedule")');

		// Verify error message appears
		await expect(page.locator('text=past')).toBeVisible({ timeout: 5000 });
	});

	test('scheduled jobs sort by time', async ({ page }) => {
		const futureDate = new Date();
		futureDate.setDate(futureDate.getDate() + 7);
		const dateStr = futureDate.toISOString().split('T')[0];

		// Schedule jobs in reverse order
		await page.selectOption('#action', 'pump-run');
		await page.fill('#date', dateStr);
		await page.fill('#time', '18:00'); // Later
		await page.click('button:has-text("Schedule")');
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });
		await page.waitForTimeout(300);

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '08:00'); // Earlier
		await page.click('button:has-text("Schedule")');
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 5000 });
		await page.waitForTimeout(300);

		// Verify the first job in the list is the earlier one (Heater ON at 08:00)
		const firstJobText = await page.locator('ul li').first().textContent();
		expect(firstJobText).toContain('Heater ON');
		expect(firstJobText).toContain('8:00');

		// Clean up
		await page.locator('ul li button:has-text("Cancel")').first().click();
		await page.waitForTimeout(300);
		await page.locator('ul li button:has-text("Cancel")').first().click();
	});
});
