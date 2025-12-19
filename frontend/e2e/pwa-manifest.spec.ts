import { test, expect } from '@playwright/test';

/**
 * E2E tests for PWA (Progressive Web App) functionality.
 * Verifies the app is installable on mobile devices.
 */

test.describe('PWA Manifest and Installability', () => {
	test.describe('Web App Manifest', () => {
		test('manifest.webmanifest is accessible', async ({ request }) => {
			const response = await request.get('/tub/manifest.webmanifest');
			expect(response.ok()).toBeTruthy();
			expect(response.headers()['content-type']).toContain('application/manifest+json');
		});

		test('manifest contains required fields for installability', async ({ request }) => {
			const response = await request.get('/tub/manifest.webmanifest');
			const manifest = await response.json();

			// Required fields for PWA installability
			expect(manifest.name).toBe('Hot Tub Controller');
			expect(manifest.short_name).toBe('Hot Tub');
			expect(manifest.start_url).toBe('/tub/');
			expect(manifest.display).toBe('standalone');
			expect(manifest.icons).toBeDefined();
			expect(manifest.icons.length).toBeGreaterThanOrEqual(2);
		});

		test('manifest has icons in required sizes (192x192 and 512x512)', async ({ request }) => {
			const response = await request.get('/tub/manifest.webmanifest');
			const manifest = await response.json();

			const sizes = manifest.icons.map((icon: { sizes: string }) => icon.sizes);
			expect(sizes).toContain('192x192');
			expect(sizes).toContain('512x512');
		});

		test('manifest has theme and background colors', async ({ request }) => {
			const response = await request.get('/tub/manifest.webmanifest');
			const manifest = await response.json();

			expect(manifest.theme_color).toBe('#0ea5e9');
			expect(manifest.background_color).toBe('#0f172a');
		});
	});

	test.describe('PWA Meta Tags', () => {
		test('page has manifest link (required for PWA install)', async ({ page }) => {
			await page.goto('/tub/login');
			const manifestLink = await page.locator('link[rel="manifest"]').getAttribute('href');
			expect(manifestLink).toBe('/tub/manifest.webmanifest');
		});

		test('page has theme-color meta tag', async ({ page }) => {
			await page.goto('/tub/login');
			const themeColor = await page.locator('meta[name="theme-color"]').getAttribute('content');
			expect(themeColor).toBe('#0ea5e9');
		});

		test('page has apple-mobile-web-app meta tags', async ({ page }) => {
			await page.goto('/tub/login');

			// Check apple-mobile-web-app-capable
			const capable = await page.locator('meta[name="apple-mobile-web-app-capable"]').getAttribute('content');
			expect(capable).toBe('yes');

			// Check apple-mobile-web-app-title
			const title = await page.locator('meta[name="apple-mobile-web-app-title"]').getAttribute('content');
			expect(title).toBe('Hot Tub');
		});

		test('page has apple-touch-icon link', async ({ page }) => {
			await page.goto('/tub/login');
			const appleIcon = await page.locator('link[rel="apple-touch-icon"]').getAttribute('href');
			expect(appleIcon).toBe('/tub/icons/apple-touch-icon.png');
		});

		test('page has description meta tag', async ({ page }) => {
			await page.goto('/tub/login');
			const description = await page.locator('meta[name="description"]').getAttribute('content');
			expect(description).toBe('Control your hot tub heater and pump');
		});
	});

	test.describe('PWA Icons', () => {
		test('192x192 icon is accessible', async ({ request }) => {
			const response = await request.get('/tub/icons/icon-192.png');
			expect(response.ok()).toBeTruthy();
			expect(response.headers()['content-type']).toContain('image/png');
		});

		test('512x512 icon is accessible', async ({ request }) => {
			const response = await request.get('/tub/icons/icon-512.png');
			expect(response.ok()).toBeTruthy();
			expect(response.headers()['content-type']).toContain('image/png');
		});

		test('apple-touch-icon is accessible', async ({ request }) => {
			const response = await request.get('/tub/icons/apple-touch-icon.png');
			expect(response.ok()).toBeTruthy();
			expect(response.headers()['content-type']).toContain('image/png');
		});
	});
});
