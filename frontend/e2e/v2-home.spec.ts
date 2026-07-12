import { test, expect } from '@playwright/test';

/**
 * Smoke test for the Stage 1 v2 Home screen (/tub, the root mount).
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
		await page.goto('/tub/');
		await expect(page.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });
	});

	test('shows the temperature panel and primary controls', async ({ page }) => {
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
			await expect(nextUp.getByTestId('event-title')).toHaveText(/Heat to 101\.5/);
			// One paradigm everywhere: no floating control bar — the card carries its controls.
			await expect(page.getByTestId('next-controls')).toHaveCount(0);
		});
	});

	test.describe('Home adjust next run', () => {
		test.afterEach(async ({ page }) => {
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`).catch(() => {});
			}
		});

		// Clear leftovers, then create a daily event whose time has already passed today
		// (so the skip + override land tomorrow and are always in the future). Returns
		// the parent job id and its daily HH:MM.
		async function createDaily(page: import('@playwright/test').Page) {
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
			const jobId = (await res.json()).jobId as string;
			const plus15 = new Date(past.getTime() + 15 * 60 * 1000);
			const hhmmPlus15 = `${String(plus15.getHours()).padStart(2, '0')}:${String(plus15.getMinutes()).padStart(2, '0')}`;
			return { jobId, hhmm, hhmmPlus15 };
		}

		test('nudging the card stages the next run; Save overrides it as one folded card; reset returns to the daily default', async ({
			page
		}) => {
			await createDaily(page);

			await page.reload();
			const nextUp = page.getByTestId('v2-next-up');
			await expect(nextUp).toBeVisible({ timeout: 15000 });
			// One paradigm: the card carries its own controls — no floating control bar,
			// no selection step.
			await expect(page.getByTestId('next-controls')).toHaveCount(0);
			const card = nextUp.getByTestId('event-card');
			await expect(card).toHaveCount(1);
			await expect(card.getByTestId('event-reset')).toHaveCount(0); // not yet overridden

			// Nudge 15 minutes later → staged locally only; nothing persists until Save.
			await card.getByRole('button', { name: '15 minutes later' }).click();
			await expect(card.getByTestId('event-oneoff-save')).toBeVisible({ timeout: 10000 });
			await expect(card.getByTestId('event-adjusted')).toHaveCount(0); // not committed yet

			// Save → the override is created, folded into the SAME one card.
			await card.getByTestId('event-oneoff-save').click();
			await expect(card.getByTestId('event-reset')).toBeVisible({ timeout: 10000 });
			await expect(card.getByTestId('event-adjusted')).toBeVisible();
			await expect(card.getByTestId('event-resets-line')).toBeVisible();
			// Regression: the override does NOT split the daily into a second card.
			await expect(nextUp.getByTestId('event-card')).toHaveCount(1);

			// Reset → back to the daily default (no override, still one card).
			await card.getByTestId('event-reset').click();
			await expect(card.getByTestId('event-reset')).toHaveCount(0, { timeout: 10000 });
			await expect(nextUp.getByTestId('event-card')).toHaveCount(1);
		});

		test('Make permanent promotes the override into the daily default and clears it', async ({
			page
		}) => {
			const { jobId, hhmmPlus15 } = await createDaily(page);

			await page.reload();
			const nextUp = page.getByTestId('v2-next-up');
			await expect(nextUp).toBeVisible({ timeout: 15000 });
			const card = nextUp.getByTestId('event-card');
			await expect(card).toHaveCount(1);

			// Create the override through the card: nudge +15 → Save.
			await card.getByRole('button', { name: '15 minutes later' }).click();
			await card.getByTestId('event-oneoff-save').click();
			await expect(card.getByTestId('event-make-permanent')).toBeVisible({ timeout: 10000 });

			// Promote → the adjusted state clears; still ONE card.
			await card.getByTestId('event-make-permanent').click();
			await expect(card.getByTestId('event-adjusted')).toHaveCount(0, { timeout: 10000 });
			await expect(card.getByTestId('event-make-permanent')).toHaveCount(0);
			await expect(nextUp.getByTestId('event-card')).toHaveCount(1);

			// Backend truth: the parent's daily time moved to the override's clock, and no
			// override one-off survives.
			const jobs = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
				.jobs as Array<{
				jobId: string;
				scheduledTime: string;
				skipped?: boolean;
				params?: { override_of?: string };
			}>;
			const parent = jobs.find((j) => j.jobId === jobId);
			expect(parent?.scheduledTime).toBe(hhmmPlus15);
			expect(parent?.skipped).toBeFalsy();
			expect(jobs.some((j) => j.params?.override_of)).toBe(false);
		});
	});

	test.describe('Home adjust one-off', () => {
		test.afterEach(async ({ page }) => {
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`).catch(() => {});
			}
		});

		test('a one-off heat event is adjustable on Home: nudging stages, Save moves it in place, Remove deletes it', async ({
			page
		}) => {
			// Clear leftovers, then create a one-off heat-to-target tomorrow at a unique temp so we
			// can find exactly our card.
			const list = await (await page.request.get('/tub/backend/public/api/schedule')).json();
			for (const j of list.jobs ?? []) {
				await page.request.delete(`/tub/backend/public/api/schedule/${j.jobId}`);
			}
			const when = new Date(Date.now() + 24 * 3600 * 1000);
			when.setHours(17, 30, 0, 0);
			const res = await page.request.post('/tub/backend/public/api/schedule', {
				data: {
					action: 'heat-to-target',
					scheduledTime: when.toISOString(),
					recurring: false,
					target_temp_f: 108.25
				}
			});
			expect(res.ok()).toBeTruthy();
			const jobId = (await res.json()).jobId;

			await page.reload();
			// The card carries its own controls — no selection step, no floating bar.
			const card = page.locator(`[data-testid="event-card"][data-key="${jobId}"]`);
			await expect(card).toBeVisible({ timeout: 15000 });
			await expect(page.getByTestId('next-controls')).toHaveCount(0);
			// A one-off offers ±, but NOT the recurring-only Skip, and never the "edit on the
			// Schedule tab" redirect (the bug this test pins down).
			await expect(card.getByTestId('event-skip')).toHaveCount(0);
			await expect(page.getByText('edit it on the Schedule tab')).toHaveCount(0);

			const tempOf = async () => {
				const l = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
					.jobs as Array<{ jobId: string; params?: { target_temp_f?: number } }>;
				return l.find((j) => j.jobId === jobId)?.params?.target_temp_f;
			};

			// Nudge temp + time → staged locally; the backend is untouched until Save.
			await card.getByRole('button', { name: 'a quarter degree warmer' }).click();
			await card.getByRole('button', { name: '15 minutes later' }).click();
			await expect(card.getByTestId('event-oneoff-save')).toBeVisible();
			expect(await tempOf()).toBe(108.25);

			// Save → moved in place: SAME job id (not recreated), backend now 108.50.
			await card.getByTestId('event-oneoff-save').click();
			await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0, { timeout: 10000 });
			await expect(card).toBeVisible();
			expect(await tempOf()).toBe(108.50);

			// Full parity: Remove works from Home too.
			await card.getByTestId('event-cancel').click();
			await expect(card).toHaveCount(0, { timeout: 10000 });
			expect(await tempOf()).toBeUndefined();
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

			// Raise by one step (+0.25°F): label updates immediately.
			await dial.getByRole('button', { name: 'Raise target temperature' }).click();
			await expect(page.getByTestId('target-value')).toHaveText('103.25°F');
			await expect(page.getByRole('button', { name: 'Heat to 103.25°F' })).toBeVisible();

			// The new default persists to the backend (debounced write-level endpoint).
			await expect
				.poll(async () => {
					const res = await page.request.get('/tub/backend/public/api/settings/heat-target');
					return (await res.json()).target_temp_f;
				})
				.toBe(103.25);
		});

		test('a Guest can heat to the default but gets no dial to rewrite it', async ({
			page,
			browser
		}) => {
			// Create a basic-role user (as admin), then sign in as them in a FRESH context —
			// the admin session must survive for afterEach's settings reset.
			const username = `testuser_${Date.now()}`;
			await page.request.post('/tub/backend/public/api/users', {
				data: { username, password: 'guestpass123', role: 'basic' }
			});

			const guestContext = await browser.newContext();
			const guest = await guestContext.newPage();
			try {
				await guest.goto('/tub/login');
				await guest.fill('#username', username);
				await guest.fill('#password', 'guestpass123');
				await guest.press('#password', 'Enter');
				await expect(guest.getByRole('button', { name: 'Logout' })).toBeVisible({
					timeout: 15000
				});
				await guest.goto('/tub/');
				await expect(guest.getByTestId('v2-home')).toBeVisible({ timeout: 15000 });

				// Heat-to-target is on (beforeEach): the Heat button carries the household
				// default, but the dial that would rewrite that default is absent.
				await expect(guest.getByRole('button', { name: /Heat to 103/ })).toBeVisible();
				await expect(guest.getByTestId('target-dial')).toHaveCount(0);

				// Role matrix: Guest is Home-only — with a single tab there is no tab bar at all.
				await expect(guest.getByTestId('tab-schedule')).toHaveCount(0);
				await expect(guest.getByTestId('tab-setup')).toHaveCount(0);
				await expect(guest.getByTestId('tab-home')).toHaveCount(0);
			} finally {
				await guestContext.close();
				await page.request.delete(`/tub/backend/public/api/users/${username}`);
			}
		});
	});
});
