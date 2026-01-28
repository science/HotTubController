import { test, expect } from '@playwright/test';

/**
 * Tests for auth state recovery when there's a mismatch between
 * frontend state and backend authentication.
 *
 * Bug scenario: User's auth cookie expires/clears but sessionStorage
 * has 'sveltekit_redirect_handled' set, blocking the redirect to login.
 * Result: User sees full UI but all API calls fail with 401, and there's
 * no logout button to recover.
 */
test.describe('Auth State Recovery', () => {
	test('redirects to login when user is not authenticated', async ({ page }) => {
		// Simulate the bug: set the redirect-handled flag before visiting
		// This mimics what happens when browser restores sessionStorage
		// but auth cookie is lost
		await page.goto('/tub/login');
		await page.evaluate(() => {
			sessionStorage.setItem('sveltekit_redirect_handled', 'true');
		});

		// Visit main page without valid auth
		await page.goto('/tub/');
		await page.waitForTimeout(1000);

		// Should redirect to login page, NOT show the dashboard
		await expect(page).toHaveURL(/\/tub\/login/);
	});

	test('shows login page when auth cookie is missing', async ({ page }) => {
		// Clear any existing auth state
		await page.context().clearCookies();

		// Visit main page directly
		await page.goto('/tub/');
		await page.waitForTimeout(1000);

		// Should show login page
		await expect(page.locator('text=Sign in to continue')).toBeVisible();
	});

	test('does not show dashboard UI when unauthenticated', async ({ page }) => {
		// Set the redirect-handled flag (simulating browser restore)
		await page.goto('/tub/login');
		await page.evaluate(() => {
			sessionStorage.setItem('sveltekit_redirect_handled', 'true');
		});

		// Visit main page without auth
		await page.goto('/tub/');
		await page.waitForTimeout(1000);

		// Either we're on login page, OR if on dashboard, we must have logout button
		const isOnLoginPage = await page.locator('text=Sign in to continue').isVisible();
		if (!isOnLoginPage) {
			// If not on login page, there MUST be a way to logout
			await expect(page.locator('button:has-text("Logout")')).toBeVisible();
		}
	});

	test('provides recovery path when in broken auth state', async ({ page }) => {
		// Simulate broken state
		await page.goto('/tub/login');
		await page.evaluate(() => {
			sessionStorage.setItem('sveltekit_redirect_handled', 'true');
		});

		await page.goto('/tub/');
		await page.waitForTimeout(1000);

		// User should have a way to recover - either:
		// 1. Automatically redirected to login
		// 2. Logout button visible
		// 3. Error message with login link

		const isOnLoginPage = await page.locator('text=Sign in to continue').isVisible();
		const hasLogoutButton = await page.locator('button:has-text("Logout")').isVisible();
		const hasLoginLink = await page.locator('a:has-text("Login")').isVisible();

		const hasRecoveryPath = isOnLoginPage || hasLogoutButton || hasLoginLink;
		expect(hasRecoveryPath).toBe(true);
	});

	test('after login, shows logout button', async ({ page }) => {
		// Login as admin
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		// Use keyboard Enter for more reliable form submission
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });

		// Should show username and logout button
		await expect(page.locator('text=admin')).toBeVisible();
		await expect(page.locator('button:has-text("Logout")')).toBeVisible();
	});
});
