import { test, expect } from '@playwright/test';

/**
 * E2E tests for recurring scheduled jobs.
 * Tests verify that recurring jobs can be created, displayed in
 * the Daily Schedule section, and canceled properly.
 *
 * Note: Tests use serial mode to ensure proper cleanup between tests.
 */

test.describe.serial('Scheduler Recurring Jobs', () => {
	// Clean up all jobs before each test
	test.beforeEach(async ({ page }) => {
		// Disable auto heat-off to ensure predictable test behavior initially
		await page.goto('/tub/login');
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'false');
		});
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Clean up ALL existing scheduled jobs (both recurring and one-off) from previous test runs
		// Loop until no more cancel buttons exist in any section
		let cancelButtons = page.locator('button:has-text("Cancel")');
		let count = await cancelButtons.count();
		let maxAttempts = 20; // Safety limit
		while (count > 0 && maxAttempts > 0) {
			await cancelButtons.first().click({ force: true });
			await page.waitForTimeout(400); // Wait for deletion to complete
			cancelButtons = page.locator('button:has-text("Cancel")');
			count = await cancelButtons.count();
			maxAttempts--;
		}

		// Wait a moment to ensure all cleanup is complete
		await page.waitForTimeout(300);
	});

	test('create a recurring job and verify it appears in Daily Schedule', async ({ page }) => {
		// Check the recurring checkbox using label text
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });

		// Verify helper text appears
		await expect(page.locator('text=Runs every day')).toBeVisible();

		// Date picker should be hidden when recurring is checked
		await expect(page.locator('#date')).not.toBeVisible();

		// Schedule a recurring job for 7:00 AM (unique time)
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '07:15');
		await page.click('button:has-text("Schedule")', { force: true });

		// Verify success message mentions recurring
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Verify Daily Schedule section exists and contains the job
		await expect(page.locator('h3:has-text("Daily Schedule")')).toBeVisible();

		// Verify job appears in Daily Schedule section with correct format
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await expect(dailyJob).toBeVisible();
		await expect(dailyJob).toContainText('Heater ON');
	});

	test('recurring job uses purple styling in Daily Schedule section', async ({ page }) => {
		// Check the recurring checkbox
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });

		// Schedule a recurring job (unique time)
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '06:45');
		await page.click('button:has-text("Schedule")', { force: true });

		// Wait for success
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Verify job has purple styling (bg-purple-900/30 class)
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).first();
		await expect(dailyJob).toHaveClass(/purple/);
	});

	test('cancel a recurring job', async ({ page }) => {
		// Get the initial count of recurring jobs
		const dailyJobsLocator = page.locator('li').filter({ hasText: 'Daily at' });
		const initialCount = await dailyJobsLocator.count();

		// Create a recurring job with a unique time
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '08:30');
		await page.click('button:has-text("Schedule")', { force: true });

		// Wait for success and job to appear
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });
		await expect(dailyJobsLocator).toHaveCount(initialCount + 1, { timeout: 5000 });

		// Find the newly created job (8:30 AM)
		const newJob = page.locator('li').filter({ hasText: '8:30' }).filter({ hasText: 'Daily at' }).first();
		await expect(newJob).toBeVisible();

		// Cancel the newly created job
		await newJob.locator('button:has-text("Cancel")').click({ force: true });

		// Wait for the job to be removed (count should be back to initial)
		await expect(dailyJobsLocator).toHaveCount(initialCount, { timeout: 5000 });
	});

	test('recurring job with auto heat-off creates paired recurring off job', async ({ page }) => {
		// Get the initial count of recurring jobs
		const dailyJobsLocator = page.locator('li').filter({ hasText: 'Daily at' });
		const initialCount = await dailyJobsLocator.count();

		// Enable auto heat-off
		await page.evaluate(() => {
			localStorage.setItem('hotTubAutoHeatOff', 'true');
			localStorage.setItem('hotTubAutoHeatOffMinutes', '150');
		});
		// Reload to apply settings and wait for page to load
		await page.reload();
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Check the recurring checkbox
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });

		// Schedule a recurring heater-on job with unique time
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '05:30');
		await page.click('button:has-text("Schedule")', { force: true });

		// Verify success message mentions auto off
		await expect(page.locator('text=auto off')).toBeVisible({ timeout: 10000 });

		// Verify two NEW jobs appear in Daily Schedule (heater-on and heater-off)
		await expect(dailyJobsLocator).toHaveCount(initialCount + 2, { timeout: 5000 });

		// Verify new jobs are Heater ON at 5:30 and Heater OFF at 8:00 (150 min later)
		await expect(page.locator('li').filter({ hasText: 'Heater ON' }).filter({ hasText: '5:30' }).first()).toBeVisible();
		await expect(page.locator('li').filter({ hasText: 'Heater OFF' }).filter({ hasText: '8:00' }).first()).toBeVisible();
	});

	test('one-off jobs appear in Upcoming section, not Daily Schedule', async ({ page }) => {
		// Ensure recurring checkbox is unchecked (default)
		const recurringCheckbox = page.getByRole('checkbox', { name: 'Recurring (daily)' });
		await expect(recurringCheckbox).not.toBeChecked();

		// Get the initial count of one-off jobs
		const oneOffJobsLocator = page.locator('li.bg-slate-700\\/50');
		const initialCount = await oneOffJobsLocator.count();

		// Schedule a one-off job for tomorrow with unique time
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		const dateStr = tomorrow.toISOString().split('T')[0];

		await page.selectOption('#action', 'heater-on');
		await page.fill('#date', dateStr);
		await page.fill('#time', '09:45');
		await page.click('button:has-text("Schedule")', { force: true });

		// Verify success
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });

		// Verify new job appears (one-off jobs have bg-slate-700/50 class)
		await expect(oneOffJobsLocator).toHaveCount(initialCount + 1, { timeout: 5000 });

		// Find the newly created job (it should NOT have "Daily at" text and has 9:45 time)
		const newJob = page.locator('li').filter({ hasText: 'Heater ON' }).filter({ hasText: '9:45' }).first();
		await expect(newJob).toBeVisible();
		await expect(newJob).not.toContainText('Daily at');
		// Verify it has slate styling (one-off) not purple styling (recurring)
		await expect(newJob).toHaveClass(/slate/);
	});

	test('both recurring and one-off jobs can coexist', async ({ page }) => {
		// Create a recurring job first (unique time)
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });
		await page.selectOption('#action', 'heater-on');
		await page.fill('#time', '04:00');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Recurring job created')).toBeVisible({ timeout: 10000 });

		// Wait for success message to disappear
		await page.waitForTimeout(3500);

		// Uncheck recurring
		await page.locator('label:has-text("Recurring (daily)")').click({ force: true });

		// Create a one-off job (unique time)
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		const dateStr = tomorrow.toISOString().split('T')[0];

		await page.selectOption('#action', 'pump-run');
		await page.fill('#date', dateStr);
		await page.fill('#time', '14:30');
		await page.click('button:has-text("Schedule")', { force: true });
		await expect(page.locator('text=Job scheduled successfully')).toBeVisible({ timeout: 10000 });

		// Verify both sections exist
		await expect(page.locator('h3:has-text("Daily Schedule")')).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Upcoming' })).toBeVisible();

		// Verify recurring job is in Daily Schedule
		const dailyJob = page.locator('li').filter({ hasText: 'Daily at' }).filter({ hasText: 'Heater ON' }).first();
		await expect(dailyJob).toBeVisible();

		// Verify one-off job is in Upcoming (Run Pump doesn't have "Daily at")
		const upcomingJob = page.locator('li').filter({ hasText: 'Run Pump' }).first();
		await expect(upcomingJob).toBeVisible();
		await expect(upcomingJob).not.toContainText('Daily at');
	});
});
