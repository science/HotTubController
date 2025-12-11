import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E test configuration.
 *
 * Timer settings for testing (shorter than production):
 * - VITE_MAX_TIMER_WINDOW_MS = 90000 (90 seconds) - jobs within 90s get immediate timers
 * - VITE_RECHECK_INTERVAL_MS = 30000 (30 seconds) - sliding window recheck every 30s
 * - VITE_REFRESH_BUFFER_MS = 5000 (5 seconds) - wait 5s after scheduled time before refresh
 *
 * Note: HTML time input only has minute resolution (HH:MM), so tests must schedule
 * jobs at least 1 minute in the future.
 */
export default defineConfig({
	testDir: './e2e',
	fullyParallel: false, // Run tests sequentially for scheduler tests
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [['html'], ['list']],
	timeout: 180000, // 3 minute timeout for scheduler tests (jobs need time to "execute")
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
			// Frontend dev server with test config (shorter timer windows)
			command: 'npm run dev -- --port 5174',
			url: 'http://localhost:5174',
			reuseExistingServer: !process.env.CI,
			timeout: 30000,
			env: {
				VITE_REFRESH_BUFFER_MS: '5000', // 5 seconds
				VITE_MAX_TIMER_WINDOW_MS: '90000', // 90 seconds
				VITE_RECHECK_INTERVAL_MS: '30000', // 30 seconds
			},
		},
	],
});
