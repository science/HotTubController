import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E test configuration.
 *
 * These tests focus on frontend-backend integration, API correctness, and UI behavior.
 * Timer-based auto-refresh behavior (sliding window, refresh on job completion) is
 * tested in unit tests (SchedulePanel.test.ts) using Vitest fake timers.
 *
 * Note: The frontend is served at /tub base path.
 */
export default defineConfig({
	testDir: './e2e',
	fullyParallel: false, // Run tests sequentially for scheduler tests
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [['html'], ['list']],
	timeout: 30000, // 30 second timeout (timer behavior tested in unit tests)
	use: {
		baseURL: 'http://localhost:5174',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
	webServer: [
		{
			// Backend server
			command: 'php -S localhost:8081 -t ../backend/public',
			url: 'http://localhost:8081/api/health',
			reuseExistingServer: !process.env.CI,
			timeout: 10000,
		},
		{
			// Frontend dev server
			command: 'npm run dev -- --port 5174',
			url: 'http://localhost:5174/tub',
			reuseExistingServer: !process.env.CI,
			timeout: 30000,
		},
	],
});
