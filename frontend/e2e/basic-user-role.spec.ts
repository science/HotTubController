import { test, expect } from '@playwright/test';

/**
 * E2E tests for the "basic" user role.
 * Tests that basic users see simplified UI with only:
 * - Hardware control buttons
 * - Equipment status bar
 * - Temperature panel
 *
 * And NOT:
 * - Quick Schedule panel
 * - Full Schedule panel
 * - Settings panel
 */

// Use a fixed username for this test suite (timestamp would change between beforeAll and tests)
const basicUsername = 'basic_e2e_test';
const basicPassword = 'basicpass123';

test.describe('Basic User Role', () => {
	test.beforeAll(async ({ browser }) => {
		// Create a basic user using admin account
		const page = await browser.newPage();

		// Login as admin
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 15000 });

		// Navigate to user management
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({ timeout: 15000 });

		// Check if basic user already exists (from previous test run)
		const existingUser = page.locator(`li:has-text("${basicUsername}")`);
		if (await existingUser.isVisible({ timeout: 2000 }).catch(() => false)) {
			// Delete existing user first
			page.on('dialog', (dialog) => dialog.accept());
			await existingUser.locator('button:has-text("Delete")').click({ force: true });
			await expect(existingUser).not.toBeVisible({ timeout: 5000 });
		}

		// Create a basic user
		await page.fill('#username', basicUsername);
		await page.fill('#password', basicPassword);
		await page.selectOption('#role', 'basic');
		await page.click('button:has-text("Create User")', { force: true });

		// Wait for success modal and close it
		await expect(page.locator('text=User Created Successfully')).toBeVisible({ timeout: 15000 });
		await page.click('button:has-text("Done")', { force: true });

		await page.close();
	});

	test.beforeEach(async ({ page }) => {
		// Login as basic user before each test
		await page.goto('/tub/login');
		await page.fill('#username', basicUsername);
		await page.fill('#password', basicPassword);
		await page.press('#password', 'Enter');

		// Wait for main page - note: basic users won't see Schedule heading
		await expect(page.getByRole('heading', { name: 'HOT TUB CONTROL' })).toBeVisible({ timeout: 15000 });
	});

	test('basic user sees control buttons', async ({ page }) => {
		// All three compact control buttons should be visible
		await expect(page.getByRole('button', { name: 'Heat On' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Heat/Pump Off' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Pump (2h)' })).toBeVisible();
	});

	test('basic user sees equipment status bar', async ({ page }) => {
		// Equipment status bar should show status with relative time
		await expect(page.locator('text=Status:')).toBeVisible();
	});

	test('basic user sees temperature panel', async ({ page }) => {
		// Temperature panel should be visible (use heading to be more specific)
		await expect(page.getByRole('heading', { name: 'Temperature' })).toBeVisible();
	});

	test('basic user does NOT see Quick Heat On panel', async ({ page }) => {
		// Quick Heat On header should NOT be visible
		await expect(page.locator('text=Quick Heat On')).not.toBeVisible();

		// Quick schedule buttons should NOT be visible
		await expect(page.getByRole('button', { name: '+7.5h' })).not.toBeVisible();
		await expect(page.getByRole('button', { name: '6am' })).not.toBeVisible();
	});

	test('basic user does NOT see Schedule panel', async ({ page }) => {
		// Schedule heading should NOT be visible
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).not.toBeVisible();

		// Schedule form elements should NOT be visible
		await expect(page.locator('text=Schedule action')).not.toBeVisible();
	});

	test('basic user does NOT see Settings panel', async ({ page }) => {
		// Settings header should NOT be visible
		await expect(page.locator('text=Settings')).not.toBeVisible();

		// Auto heat-off checkbox should NOT be visible
		await expect(page.locator('text=Enable auto heat-off')).not.toBeVisible();
	});

	test('basic user does NOT see Users link in header', async ({ page }) => {
		// Users link should only be visible to admin
		await expect(page.locator('a:has-text("Users")')).not.toBeVisible();
	});

	test('basic user can still click control buttons (API access works)', async ({ page }) => {
		// Click Heat/Pump Off (safer test action)
		await page.getByRole('button', { name: 'Heat/Pump Off' }).click({ force: true });

		// Should see success message (basic users have full API access)
		await expect(page.locator('text=Heater and pump turned OFF')).toBeVisible({ timeout: 10000 });
	});

	test('basic user sees their username in header', async ({ page }) => {
		// Username should be displayed
		await expect(page.locator(`text=${basicUsername}`)).toBeVisible();
	});

	test('basic user can logout', async ({ page }) => {
		// Click logout
		await page.click('button:has-text("Logout")', { force: true });

		// Should be on login page
		await expect(page.locator('#username')).toBeVisible({ timeout: 10000 });
	});

	// Cleanup: delete the basic user after all tests
	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage();

		// Login as admin
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('heading', { name: 'Schedule', exact: true })).toBeVisible({ timeout: 15000 });

		// Navigate to user management
		await page.click('a:has-text("Users")', { force: true });
		await expect(page.getByRole('heading', { name: 'USER MANAGEMENT' })).toBeVisible({ timeout: 15000 });

		// Set up dialog handler for delete confirmation
		page.on('dialog', (dialog) => dialog.accept());

		// Delete the test user if it exists
		const userRow = page.locator(`li:has-text("${basicUsername}")`);
		if (await userRow.isVisible({ timeout: 2000 }).catch(() => false)) {
			await userRow.locator('button:has-text("Delete")').click({ force: true });
			await expect(userRow).not.toBeVisible({ timeout: 5000 });
		}

		await page.close();
	});
});
