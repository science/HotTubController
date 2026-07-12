import { test, expect } from '@playwright/test';

/**
 * Stage 3 (shell) smoke for the Owner-only v2 Setup tab (/tub/setup).
 *
 * Owner sees the re-homed admin surfaces (heat targets, sensors, users link);
 * a User-role account has no Setup tab and hits the denied message when
 * navigating directly.
 */
test.describe('v2 Setup tab', () => {
	test('owner sees heat targets, sensors, and users sections', async ({ page }) => {
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible({ timeout: 15000 });

		await page.goto('/tub/setup/');
		await expect(page.getByTestId('v2-setup')).toBeVisible({ timeout: 15000 });

		await expect(page.getByTestId('setup-heat-targets')).toBeVisible();
		// The re-homed SettingsPanel renders its admin surface.
		await expect(page.getByText('Target Temperature (Global Setting)')).toBeVisible();
		await expect(page.getByTestId('setup-sensors')).toBeVisible();
		await expect(page.getByTestId('setup-users-link')).toBeVisible();
	});

	test('a User-role account has no Setup tab and is denied on direct navigation', async ({
		page,
		browser
	}) => {
		// Create a user-role account as admin, then sign in as them in a fresh context.
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible({ timeout: 15000 });

		const username = `testuser_${Date.now()}`;
		await page.request.post('/tub/backend/public/api/users', {
			data: { username, password: 'userpass123', role: 'user' }
		});

		const userContext = await browser.newContext();
		const member = await userContext.newPage();
		try {
			await member.goto('/tub/login');
			await member.fill('#username', username);
			await member.fill('#password', 'userpass123');
			await member.press('#password', 'Enter');
			await expect(member.getByRole('button', { name: 'Logout' })).toBeVisible({
				timeout: 15000
			});

			await member.goto('/tub/');
			await expect(member.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });
			await expect(member.getByTestId('tab-schedule')).toBeVisible();
			await expect(member.getByTestId('tab-setup')).toHaveCount(0);

			// The route itself refuses too — tab hiding isn't the only gate.
			await member.goto('/tub/setup/');
			await expect(member.getByTestId('setup-denied')).toBeVisible({ timeout: 15000 });
			await expect(member.getByTestId('setup-heat-targets')).toHaveCount(0);
		} finally {
			await userContext.close();
			await page.request.delete(`/tub/backend/public/api/users/${username}`);
		}
	});
});
