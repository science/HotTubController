import { test, expect } from '@playwright/test';

/**
 * E2E tests for the "Skip Next Occurrence" feature.
 * Tests verify that recurring jobs can be skipped and unskipped,
 * with correct UI state transitions.
 */

test.describe.serial('Scheduler Skip Next Occurrence', () => {
	// Clean up all jobs before each test via API, then log in
	test.beforeEach(async ({ page, request }) => {
		// Log in via API to get auth cookie for cleanup
		const loginResp = await request.post('/tub/backend/public/api/auth/login', {
			data: { username: 'admin', password: 'password' }
		});
		expect(loginResp.ok()).toBeTruthy();

		// Cancel all existing jobs via API
		const listResp = await request.get('/tub/backend/public/api/schedule');
		if (listResp.ok()) {
			const { jobs } = await listResp.json();
			for (const job of jobs) {
				await request.delete(`/tub/backend/public/api/schedule/${job.jobId}`);
			}
		}

		// Now navigate and log in for the UI test
		await page.goto('/tub/login');
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'false');
		});
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test('recurring job shows "Skip next" button', async ({ page }) => {
		// Create a recurring job
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '07:00');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Verify "Skip next" button is visible
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await expect(dailyJob).toBeVisible();
		await expect(dailyJob.locator('button:has-text("Skip next")')).toBeVisible();
	});

	test('clicking "Skip next" changes to amber/skipped state with Unskip button', async ({ page }) => {
		// Create a recurring job
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '07:15');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Click Skip next
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await dailyJob.locator('button:has-text("Skip next")').click({ force: true });

		// Wait for the UI to update to skipped state
		await expect(page.locator('button:has-text("Unskip")')).toBeVisible({ timeout: 5000 });

		// Verify amber styling
		const skippedJob = page.locator('li').filter({ hasText: 'Unskip' }).first();
		await expect(skippedJob).toHaveClass(/amber/);

		// Verify "Skipped" and "resumes" text appears
		await expect(skippedJob.locator('text=/Skipped/')).toBeVisible();
		await expect(skippedJob.locator('text=/resumes/')).toBeVisible();

		// "Skip next" button should no longer be visible
		expect(await page.locator('button:has-text("Skip next")').count()).toBe(0);
	});

	test('clicking "Unskip" restores normal purple state', async ({ page }) => {
		// Create and skip a recurring job
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '07:30');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Skip it
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await dailyJob.locator('button:has-text("Skip next")').click({ force: true });
		await expect(page.locator('button:has-text("Unskip")')).toBeVisible({ timeout: 5000 });

		// Unskip it
		await page.locator('button:has-text("Unskip")').click({ force: true });

		// Wait for UI to update back to normal state
		await expect(page.locator('button:has-text("Skip next")')).toBeVisible({ timeout: 5000 });

		// Verify purple styling is back
		const normalJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await expect(normalJob).toHaveClass(/purple/);

		// "Unskip" should no longer be visible
		expect(await page.locator('button:has-text("Unskip")').count()).toBe(0);
	});

	test('cancel works on skipped jobs', async ({ page }) => {
		// Create and skip a recurring job
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '07:45');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Skip it
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await dailyJob.locator('button:has-text("Skip next")').click({ force: true });
		await expect(page.locator('button:has-text("Unskip")')).toBeVisible({ timeout: 5000 });

		// Cancel the skipped job
		const skippedJob = page.locator('li').filter({ hasText: 'Unskip' }).first();
		await skippedJob.locator('button:has-text("Cancel")').click({ force: true });

		// Job should be removed
		await expect(page.locator('li').filter({ hasText: 'Unskip' })).toHaveCount(0, { timeout: 5000 });
		await expect(page.locator('li').filter({ hasText: 'Daily at' })).toHaveCount(0, { timeout: 5000 });
	});

	test('one-off jobs do not show Skip next button', async ({ page }) => {
		// Create a one-off job
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		const dateStr = tomorrow.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '09:00');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });

		// Verify no "Skip next" button on one-off jobs
		const oneOffJob = page.locator('li').filter({ hasText: 'Heater ON' }).first();
		await expect(oneOffJob).toBeVisible();
		expect(await oneOffJob.locator('button:has-text("Skip next")').count()).toBe(0);
	});
});
