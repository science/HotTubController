import { test, expect } from '@playwright/test';

/**
 * E2E tests for the Quick Schedule feature.
 * Tests the compact control buttons and quick schedule panel.
 */

test.describe('Quick Schedule Feature', () => {
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

	test.describe('Compact Control Buttons', () => {
		test('displays compact ON, OFF, and PUMP buttons', async ({ page }) => {
			// Verify all three compact buttons are visible
			await expect(page.getByRole('button', { name: 'ON' })).toBeVisible();
			await expect(page.getByRole('button', { name: 'OFF' })).toBeVisible();
			await expect(page.getByRole('button', { name: 'PUMP' })).toBeVisible();
		});

		test('compact buttons are in a 3-column layout', async ({ page }) => {
			const onButton = page.getByRole('button', { name: 'ON' });
			const offButton = page.getByRole('button', { name: 'OFF' });
			const pumpButton = page.getByRole('button', { name: 'PUMP' });

			// Get bounding boxes
			const onBox = await onButton.boundingBox();
			const offBox = await offButton.boundingBox();
			const pumpBox = await pumpButton.boundingBox();

			// All buttons should be roughly on the same row (same Y position)
			expect(onBox!.y).toBeCloseTo(offBox!.y, 0);
			expect(offBox!.y).toBeCloseTo(pumpBox!.y, 0);

			// Buttons should be in left-to-right order
			expect(onBox!.x).toBeLessThan(offBox!.x);
			expect(offBox!.x).toBeLessThan(pumpBox!.x);
		});
	});

	test.describe('Quick Schedule Panel', () => {
		test('displays Quick Heat On header and all schedule buttons', async ({ page }) => {
			// Verify header
			await expect(page.locator('text=Quick Heat On')).toBeVisible();

			// Verify all 6 quick schedule buttons
			await expect(page.getByRole('button', { name: '+7.5h' })).toBeVisible();
			await expect(page.getByRole('button', { name: '6am' })).toBeVisible();
			await expect(page.getByRole('button', { name: '6:30' })).toBeVisible();
			await expect(page.getByRole('button', { name: '7am' })).toBeVisible();
			await expect(page.getByRole('button', { name: '7:30' })).toBeVisible();
			await expect(page.getByRole('button', { name: '8am' })).toBeVisible();
		});

		test('quick schedule panel is between controls and schedule form', async ({ page }) => {
			const onButton = page.getByRole('button', { name: 'ON' });
			const quickPanel = page.locator('text=Quick Heat On');
			const scheduleHeading = page.getByRole('heading', { name: 'Schedule' });

			// Get vertical positions
			const onBox = await onButton.boundingBox();
			const quickBox = await quickPanel.boundingBox();
			const scheduleBox = await scheduleHeading.boundingBox();

			// Quick panel should be below control buttons
			expect(quickBox!.y).toBeGreaterThan(onBox!.y);

			// Quick panel should be above schedule heading
			expect(quickBox!.y).toBeLessThan(scheduleBox!.y);
		});

		test('clicking +7.5h creates a scheduled job', async ({ page }) => {
			// Click the +7.5h button
			await page.getByRole('button', { name: '+7.5h' }).click();

			// Wait for success message
			await expect(page.locator('text=Heat scheduled for')).toBeVisible({ timeout: 10000 });

			// Verify job appears in the schedule list
			const jobItem = page.locator('ul li').filter({ hasText: 'Heater ON' }).first();
			await expect(jobItem).toBeVisible({ timeout: 5000 });

			// Clean up - cancel the job
			await jobItem.locator('button:has-text("Cancel")').click();
			await expect(page.locator('text=No scheduled jobs')).toBeVisible({ timeout: 5000 });
		});

		test('clicking 6am creates a scheduled job for 6:00 AM', async ({ page }) => {
			// Click the 6am button
			await page.getByRole('button', { name: '6am' }).click();

			// Wait for success message
			await expect(page.locator('text=Heat scheduled for')).toBeVisible({ timeout: 10000 });

			// Verify job appears in the schedule list with correct action
			const jobItem = page.locator('ul li').filter({ hasText: 'Heater ON' }).first();
			await expect(jobItem).toBeVisible({ timeout: 5000 });

			// The job should show 6:00 in some format
			await expect(jobItem).toContainText(/6:00|6 AM/i);

			// Clean up - cancel the job
			await jobItem.locator('button:has-text("Cancel")').click();
		});
	});

	test.describe('Visual regression', () => {
		test('captures screenshot of new UI layout', async ({ page }) => {
			// Take a screenshot for visual inspection
			await page.screenshot({ path: '/tmp/quick-schedule-ui.png', fullPage: true });

			// Basic sanity check - page should have the main heading
			await expect(page.getByRole('heading', { name: 'HOT TUB CONTROL' })).toBeVisible();
		});
	});
});
