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
		await page.click('button[type="submit"]');
		await expect(page.getByRole('heading', { name: 'Schedule' })).toBeVisible({ timeout: 10000 });
	});

	test('admin can see Users link in header', async ({ page }) => {
		// Admin should see the Users link
		await expect(page.locator('a:has-text("Users")')).toBeVisible();
	});

	test('admin can navigate to users page', async ({ page }) => {
		// Click the Users link
		await page.click('a:has-text("Users")');

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
		await page.click('a:has-text("Users")');
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Should see admin user in the list with "(you)" label
		await expect(page.locator('li:has-text("admin")').locator('text=(you)')).toBeVisible();
	});

	test('admin can create a new user', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")');
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
		await page.click('button:has-text("Create User")');

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
		await page.click('button:has-text("Done")');

		// New user should appear in the list
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).toBeVisible();

		// Clean up - delete the test user
		await page.locator(`li:has-text("${uniqueUsername}")`).locator('button:has-text("Delete")').click();

		// Handle the confirmation dialog
		page.on('dialog', (dialog) => dialog.accept());
	});

	test('admin can delete a user', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")');
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// First create a user to delete
		const uniqueUsername = `deletetest_${Date.now()}`;
		await page.fill('#username', uniqueUsername);
		await page.fill('#password', 'deletepass');
		await page.click('button:has-text("Create User")');

		// Wait for modal and close it
		await expect(page.locator('text=User Created Successfully')).toBeVisible({ timeout: 10000 });
		await page.click('button:has-text("Done")');

		// Verify user exists
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).toBeVisible();

		// Set up dialog handler before clicking delete
		page.on('dialog', (dialog) => dialog.accept());

		// Delete the user
		await page.locator(`li:has-text("${uniqueUsername}")`).locator('button:has-text("Delete")').click();

		// Wait for the user to be removed from the list
		await expect(page.locator(`li:has-text("${uniqueUsername}")`)).not.toBeVisible({ timeout: 5000 });
	});

	test('admin cannot delete themselves', async ({ page }) => {
		// Navigate to users page
		await page.click('a:has-text("Users")');
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
		await page.click('a:has-text("Users")');
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({
			timeout: 10000
		});

		// Click back link
		await page.click('a:has-text("Back to Controls")');

		// Should be back on main page
		await expect(page.getByRole('heading', { name: 'HOT TUB CONTROL' })).toBeVisible({
			timeout: 10000
		});
	});
});
