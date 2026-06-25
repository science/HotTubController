import { test, expect } from '@playwright/test';

/**
 * Smoke test for the Stage 1 v2 Home screen (/tub/v2).
 *
 * Verifies the new tabbed-app Home renders for an authenticated user and that a
 * hardware control works end to end (stub mode). Full per-role tab gating is
 * covered in Stage 4; this only proves the MVP composes and functions.
 */
test.describe('v2 Home (MVP)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		// Wait until authenticated — the Logout button only exists once signed in (the
		// login page shares the "HOT TUB CONTROL" heading, so it can't gate on that).
		await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible({ timeout: 15000 });
		await page.goto('/tub/v2');
		await expect(page.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });
	});

	test('shows the temperature hero and primary controls', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Temperature' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Heat/Pump Off' })).toBeVisible();
		await expect(page.getByRole('button', { name: 'Pump (2h)' })).toBeVisible();
	});

	test('owner sees Home, Schedule, and Setup tabs and an Owner label', async ({ page }) => {
		await expect(page.getByTestId('tab-home')).toBeVisible();
		await expect(page.getByTestId('tab-schedule')).toBeVisible();
		await expect(page.getByTestId('tab-setup')).toBeVisible();
		await expect(page.getByTestId('role-label')).toHaveText('Owner');
	});

	test('turning the heater off works end to end (stub mode)', async ({ page }) => {
		await page.getByRole('button', { name: 'Heat/Pump Off' }).click({ force: true });
		await expect(page.getByText('Heater and pump off')).toBeVisible({ timeout: 10000 });
	});

	test.describe('Home Next-up', () => {
		let jobId = '';

		test.afterEach(async ({ page }) => {
			if (jobId) {
				await page.request.delete(`/tub/backend/public/api/schedule/${jobId}`).catch(() => {});
			}
			jobId = '';
		});

		test('shows upcoming scheduled events', async ({ page }) => {
			// Clear leftover jobs so our event is the only thing upcoming.
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`);
			}

			const res = await page.request.post('/tub/backend/public/api/schedule', {
				data: {
					action: 'heat-to-target',
					scheduledTime: '06:30',
					recurring: true,
					target_temp_f: 101.5,
					timezone: 'America/Los_Angeles'
				}
			});
			expect(res.ok()).toBeTruthy();
			jobId = (await res.json()).jobId;

			await page.reload();
			const nextUp = page.getByTestId('v2-next-up');
			await expect(nextUp).toBeVisible({ timeout: 15000 });
			await expect(nextUp.getByText(/Heat to 101\.5/)).toBeVisible();
		});
	});

	test.describe('Home adjust next run', () => {
		test.afterEach(async ({ page }) => {
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`).catch(() => {});
			}
		});

		test('nudging the next run creates an override; reset returns to the daily default', async ({
			page
		}) => {
			// Clear leftovers, then create a daily event whose time has already passed today
			// (so the skip + override land tomorrow and are always in the future).
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`);
			}
			const tz = await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone);
			const past = new Date(Date.now() - 2 * 3600 * 1000);
			const hhmm = `${String(past.getHours()).padStart(2, '0')}:${String(past.getMinutes()).padStart(2, '0')}`;
			const res = await page.request.post('/tub/backend/public/api/schedule', {
				data: {
					action: 'heat-to-target',
					scheduledTime: hhmm,
					recurring: true,
					target_temp_f: 102,
					timezone: tz
				}
			});
			expect(res.ok()).toBeTruthy();

			await page.reload();
			const adjust = page.getByTestId('next-adjust');
			await expect(adjust).toBeVisible({ timeout: 15000 });
			// Daily event — not yet overridden.
			await expect(page.getByTestId('next-reset')).toHaveCount(0);

			// Nudge 15 minutes later → an override one-off is created; Reset appears.
			await adjust.getByRole('button', { name: '15 minutes later' }).click();
			await expect(page.getByTestId('next-reset')).toBeVisible({ timeout: 10000 });

			// Reset → back to the daily default (no override).
			await page.getByTestId('next-reset').click();
			await expect(page.getByTestId('next-reset')).toHaveCount(0, { timeout: 10000 });
		});
	});

	test.describe('Heat-now target dial', () => {
		test.beforeEach(async ({ page }) => {
			// The dial only shows in heat-to-target mode; enable it (admin) and clear sessions.
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: true, target_temp_f: 103, dynamic_mode: false }
			});
			await page.request.delete('/tub/backend/public/api/equipment/heat-to-target');
		});

		test.afterEach(async ({ page }) => {
			await page.request.delete('/tub/backend/public/api/equipment/heat-to-target');
			await page.request.put('/tub/backend/public/api/settings/heat-target', {
				data: { enabled: false, target_temp_f: 103, dynamic_mode: false }
			});
		});

		test('adjusts the saved target, and the Heat button + backend track it', async ({ page }) => {
			await page.reload();
			await expect(page.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });

			const dial = page.getByTestId('target-dial');
			await expect(dial).toBeVisible();
			await expect(page.getByTestId('target-value')).toHaveText('103°F');
			await expect(page.getByRole('button', { name: 'Heat to 103°F' })).toBeVisible();

			// Raise by one step (+0.5°F): label updates immediately.
			await dial.getByRole('button', { name: 'Raise target temperature' }).click();
			await expect(page.getByTestId('target-value')).toHaveText('103.5°F');
			await expect(page.getByRole('button', { name: 'Heat to 103.5°F' })).toBeVisible();

			// The new default persists to the backend (debounced write-level endpoint).
			await expect
				.poll(async () => {
					const res = await page.request.get('/tub/backend/public/api/settings/heat-target');
					return (await res.json()).target_temp_f;
				})
				.toBe(103.5);
		});
	});
});
