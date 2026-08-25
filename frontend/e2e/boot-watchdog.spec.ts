import { test, expect } from '@playwright/test';

/**
 * The boot watchdog in src/app.html.
 *
 * On 2026-08-25 a deploy left clients holding a cached index.html that named
 * bundles the deploy had removed. The app never started and showed a black
 * screen with no error and no way to recover short of a manual refresh. The
 * watchdog is the backstop: if a cold start does not complete, reload once.
 *
 * Both directions matter. It has to fire when boot genuinely fails, and it has
 * to stay quiet otherwise -- a watchdog that misfires would reload the page out
 * from under someone operating the heater.
 *
 * These use Playwright's fake clock so the 12s timer costs no wall-clock time.
 */
test.describe('boot watchdog', () => {
	// No fake clock here on purpose: installing one stops SvelteKit hydration
	// from completing, so the app never boots and the test would be measuring
	// the clock rather than the watchdog. Asserting the boot signal instead is
	// both faster and closer to the thing that must not regress -- if anyone
	// drops the __tubBooted() call from the root layout, the watchdog would
	// start reloading working pages, and this test goes red.
	test('is told to stand down when the app boots normally', async ({ page }) => {
		await page.goto('/tub/');

		await expect(page.locator('html')).toHaveAttribute('data-booted', '1');

		const retried = await page.evaluate(() => sessionStorage.getItem('tub:boot-retry'));
		expect(retried).toBeNull();
		expect(page.url()).not.toContain('tub_reload');
	});

	test('reloads once when the app never starts, and does not loop', async ({ page }) => {
		// Simulate the incident: the bundles the shell asks for do not arrive.
		await page.route('**/*', (route) =>
			route.request().resourceType() === 'script' ? route.abort() : route.continue()
		);

		let navigations = 0;
		page.on('framenavigated', (frame) => {
			if (frame === page.mainFrame()) navigations++;
		});

		await page.clock.install();
		await page.goto('/tub/');
		const afterInitialLoad = navigations;

		await page.clock.fastForward(13_000);
		await page.waitForURL(/tub_reload=/, { timeout: 10_000 });

		expect(page.url()).toContain('tub_reload');
		expect(navigations - afterInitialLoad).toBe(1);

		// The retry is one-shot: a still-broken build must not reload forever.
		await page.clock.fastForward(60_000);
		await page.waitForTimeout(500);
		expect(navigations - afterInitialLoad).toBe(1);
		expect(page.url().match(/tub_reload=/g)).toHaveLength(1);
	});
});
