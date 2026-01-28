import { test, expect } from '@playwright/test';

/**
 * E2E tests for dining room blinds control buttons.
 * These buttons are an optional feature that only appears when
 * BLINDS_FEATURE_ENABLED=true in the backend config.
 */

test.describe('Blinds Control Buttons', () => {
	test.beforeEach(async ({ page }) => {
		// Login as admin
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test('displays blinds buttons when feature is enabled', async ({ page }) => {
		// The blinds buttons should be visible when the feature is enabled
		const openBlindsButton = page.getByRole('button', { name: 'Blinds Up' });
		const closeBlindsButton = page.getByRole('button', { name: 'Blinds Down' });

		await expect(openBlindsButton).toBeVisible();
		await expect(closeBlindsButton).toBeVisible();
	});

	test('blinds buttons appear on their own row with 2 columns', async ({ page }) => {
		// Verify the blinds buttons are in a 2-column grid (half-width each)
		const openBlindsButton = page.getByRole('button', { name: 'Blinds Up' });
		const closeBlindsButton = page.getByRole('button', { name: 'Blinds Down' });

		// Both buttons should be visible
		await expect(openBlindsButton).toBeVisible();
		await expect(closeBlindsButton).toBeVisible();

		// Get their bounding boxes to verify layout
		const openBox = await openBlindsButton.boundingBox();
		const closeBox = await closeBlindsButton.boundingBox();

		expect(openBox).not.toBeNull();
		expect(closeBox).not.toBeNull();

		if (openBox && closeBox) {
			// They should be on the same row (similar Y position)
			expect(Math.abs(openBox.y - closeBox.y)).toBeLessThan(10);

			// Open should be on the left, Close on the right
			expect(openBox.x).toBeLessThan(closeBox.x);
		}
	});

	test('open blinds button triggers API call', async ({ page }) => {
		const openBlindsButton = page.getByRole('button', { name: 'Blinds Up' });
		await expect(openBlindsButton).toBeVisible();

		// Click the button
		await openBlindsButton.click({ force: true });

		// Should show success message
		await expect(page.getByText('Blinds opening')).toBeVisible({ timeout: 5000 });
	});

	test('close blinds button triggers API call', async ({ page }) => {
		const closeBlindsButton = page.getByRole('button', { name: 'Blinds Down' });
		await expect(closeBlindsButton).toBeVisible();

		// Click the button
		await closeBlindsButton.click({ force: true });

		// Should show success message
		await expect(page.getByText('Blinds closing')).toBeVisible({ timeout: 5000 });
	});

	test('blinds buttons have purple/accent styling and compact size', async ({ page }) => {
		const openBlindsButton = page.getByRole('button', { name: 'Blinds Up' });
		await expect(openBlindsButton).toBeVisible();

		// Verify the button has the accent (purple) styling and compact size (p-1)
		await expect(openBlindsButton).toHaveClass(/text-purple-400/);
		await expect(openBlindsButton).toHaveClass(/p-1/);
	});
});
