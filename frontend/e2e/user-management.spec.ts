import { test, expect } from '@playwright/test';

/**
 * E2E tests for user management functionality.
 * Tests admin-only access to create, list, and delete users.
 */

test.describe('User Management', () => {
	test.beforeEach(async ({ page }) => {
		// Login as admin
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		// Login lands on the v2 home (root); these specs exercise the v1 interface.
		await page.waitForURL(/\/tub\/?$/);
		await page.goto('/tub/v1/');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 10000 });
	});

	test('admin can see Users link in header', async ({ page }) => {
		// Admin should see the Users link
		await expect(page.locator('a:has-text("Users")')).toBeVisible();
	});

	test('admin can navigate to users page', async ({ page }) => {
		// Click the Users link
		await page.click('a:has-text("Users")', { force: true });

		// Should see the user management heading
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Should see the "Add New User" section
		await expect(page.locator('text=Add New User')).toBeVisible();

		// Should see "Existing Users" section
		await expect(page.locator('text=Existing Users')).toBeVisible();
	});

	test('admin can see themselves in user list', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Should see admin user in the list with "(you)" label
		await expect(page.locator('li:has-text("admin")').locator('text=(you)')).toBeVisible();
	});

	test('admin can create a new user', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Generate unique username to avoid conflicts
		const uniqueUsername = `testuser_${Date.now()}`;

		// Fill in the create user form
		await page.fill('#username', uniqueUsername);
		await page.fill('#password', 'testpassword123');
		await page.selectOption('#role', 'user');

		// Click create
		await page.click('button:has-text("Create User")', { force: true });

		// Should see the credentials modal
		await expect(page.locator('text=User Created Successfully')).toBeVisible({ timeout: 10000 });

		// Modal should show the login URL
		await expect(page.locator('text=Login URL:')).toBeVisible();
		await expect(page.locator('code:has-text("/tub/login")')).toBeVisible();

		// Modal should show the credentials
		await expect(page.locator(`code:has-text("${uniqueUsername}")`)).toBeVisible();
		await expect(page.locator('code:has-text("testpassword123")')).toBeVisible();

		// Should have a single "Copy" button (not individual copy buttons)
		const copyButtons = page.locator('button:has-text("Copy")');
		await expect(copyButtons).toHaveCount(1);

		// Close the modal
		await page.click('button:has-text("Done")', { force: true });

		// New user should appear in the list
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).toBeVisible();

		// Set up dialog handler BEFORE clicking delete
		page.on('dialog', (dialog) => dialog.accept());

		// Clean up - delete the test user
		await page.locator(`li:has-text("${uniqueUsername}")`).locator('button:has-text("Delete")').click({ force: true });

		// Wait for user to be removed
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).not.toBeVisible({ timeout: 5000 });
	});

	test('admin can delete a user', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// First create a user to delete
		const uniqueUsername = `deletetest_${Date.now()}`;
		await page.fill('#username', uniqueUsername);
		await page.fill('#password', 'deletepass');
		await page.click('button:has-text("Create User")', { force: true });

		// Wait for modal and close it
		await expect(page.locator('text=User Created Successfully')).toBeVisible({ timeout: 10000 });
		await page.click('button:has-text("Done")', { force: true });

		// Verify user exists
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).toBeVisible();

		// Set up dialog handler before clicking delete
		page.on('dialog', (dialog) => dialog.accept());

		// Delete the user
		await page.locator(`li:has-text("${uniqueUsername}")`).locator('button:has-text("Delete")').click({ force: true });

		// Wait for the user to be removed from the list
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).not.toBeVisible({ timeout: 5000 });
	});

	test('admin can reset a user password', async ({ page, request }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Create a user whose password we'll reset
		const uniqueUsername = `testuser_pwreset_${Date.now()}`;
		await page.fill('#username', uniqueUsername);
		await page.fill('#password', 'originalpass123');
		await page.click('button:has-text("Create User")', { force: true });
		await expect(page.locator('text=User Created Successfully')).toBeVisible({ timeout: 10000 });
		await page.click('button:has-text("Done")', { force: true });

		// Open the reset-password form for that user
		await page
			.locator(`li:has-text("${uniqueUsername}")`)
			.locator('button:has-text("Reset")')
			.click({ force: true });

		// Enter the new password and confirm
		await page.fill('#reset-password', 'newpass456');
		await page.click('button:has-text("Reset Password")', { force: true });

		// The credentials modal shows the new password for sharing
		await expect(page.locator('text=Password Reset Successfully')).toBeVisible({ timeout: 10000 });
		await expect(page.locator(`code:has-text("${uniqueUsername}")`)).toBeVisible();
		await expect(page.locator('code:has-text("newpass456")')).toBeVisible();
		await page.click('button:has-text("Done")', { force: true });

		// The old password no longer works; the new one does. The `request` fixture
		// has its own cookie jar, so these logins don't clobber the admin session.
		const oldLogin = await request.post('/tub/backend/public/api/auth/login', {
			data: { username: uniqueUsername, password: 'originalpass123' }
		});
		expect(oldLogin.status()).toBe(401);

		const newLogin = await request.post('/tub/backend/public/api/auth/login', {
			data: { username: uniqueUsername, password: 'newpass456' }
		});
		expect(newLogin.ok()).toBe(true);

		// Clean up
		page.on('dialog', (dialog) => dialog.accept());
		await page
			.locator(`li:has-text("${uniqueUsername}")`)
			.locator('button:has-text("Delete")')
			.click({ force: true });
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).not.toBeVisible({
			timeout: 5000
		});
	});

	test('admin cannot delete themselves', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Admin user should show "(you)" instead of delete button
		const adminRow = page.locator('li:has-text("admin")').first();
		await expect(adminRow.locator('text=(you)')).toBeVisible();
		await expect(adminRow.locator('button:has-text("Delete")')).not.toBeVisible();
	});

	test('back link returns to main page', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Click back link
		await page.click('a:has-text("Back to Controls")', { force: true });

		// Should land on the default interface (v2 Home at the root), not /v1
		await expect(page.getByTestId('v2-home')).toBeVisible({
			timeout: 10000
		});
	});
});
