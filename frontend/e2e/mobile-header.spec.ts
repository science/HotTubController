import { test, expect } from '@playwright/test';

/**
 * E2E tests for mobile responsive header layout.
 * Verifies that header elements don't overlap on narrow screens.
 */

test.describe('Mobile Header Layout', () => {
	test.beforeEach(async ({ page }) => {
		// Set mobile viewport
		await page.setViewportSize({ width: 375, height: 667 });

		// Login
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule' })).toBeVisible({ timeout: 10000 });
	});

	test('header elements do not overlap on mobile', async ({ page }) => {
		// Get the title element
		const title = page.locator('h1:has-text("HOT TUB CONTROL")');
		await expect(title).toBeVisible();

		// Get the user info container (username + logout)
		const userInfo = page.locator('header .text-slate-400');
		await expect(userInfo).toBeVisible();

		// Get bounding boxes
		const titleBox = await title.boundingBox();
		const userInfoBox = await userInfo.boundingBox();

		expect(titleBox).not.toBeNull();
		expect(userInfoBox).not.toBeNull();

		if (titleBox && userInfoBox) {
			// Check that the elements don't overlap horizontally
			// Either userInfo should be completely to the right of title,
			// OR userInfo should be below the title (wrapped to next line)
			const horizontalOverlap =
				titleBox.x < userInfoBox.x + userInfoBox.width &&
				titleBox.x + titleBox.width > userInfoBox.x;

			const verticalOverlap =
				titleBox.y < userInfoBox.y + userInfoBox.height &&
				titleBox.y + titleBox.height > userInfoBox.y;

			const elementsOverlap = horizontalOverlap && verticalOverlap;

			// Take screenshot for debugging
			await page.screenshot({ path: '/tmp/mobile-header-test.png' });

			expect(elementsOverlap, 'Header title and user info should not overlap').toBe(false);
		}
	});
});
