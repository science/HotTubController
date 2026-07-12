import { test, expect } from '@playwright/test';

/**
 * Stage 2 smoke for the card-based v2 Schedule tab (/tub/schedule).
 *
 * Exercises the full lifecycle on existing endpoints: add a daily heat-to-target
 * event, see it as a card, skip the next occurrence, unskip, then cancel.
 *
 * Note: the suite shares one global-setup, so other specs may leave jobs behind.
 * We scope every assertion to *our* card (by its unique target temp) rather than
 * assuming the list is empty.
 */
test.describe('v2 Schedule tab', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/tub/login');
		await page.fill('#username', 'admin');
		await page.fill('#password', 'password');
		await page.press('#password', 'Enter');
		await expect(page.getByRole('button', { name: 'Logout' })).toBeVisible({ timeout: 15000 });
		await page.goto('/tub/schedule/');
		await expect(page.getByTestId('v2-schedule')).toBeVisible({ timeout: 15000 });
	});

	test('add a daily heat event, then skip, unskip, and cancel it', async ({ page }) => {
		// A unique temp so we can find our card amid any leftover jobs.
		const card = page.locator('[data-testid="event-card"]', { hasText: 'Heat to 104.75' });

		// Add a daily heat-to-target at 06:30, 104.75°F.
		await page.getByTestId('schedule-add').click();
		const sheet = page.getByTestId('schedule-add-sheet');
		await expect(sheet).toBeVisible();
		await sheet.getByTestId('add-temp').fill('104.75');
		await sheet.getByTestId('add-time').fill('06:30');
		await sheet.getByTestId('add-submit').click(); // Daily is the default

		// Our event renders as a card.
		await expect(card).toHaveCount(1, { timeout: 10000 });
		await expect(card.getByTestId('event-title')).toHaveText(/Heat to 104\.75/);

		// Skip the next occurrence → calm skipped badge with a resume date.
		await card.getByTestId('event-skip').click();
		await expect(card.getByTestId('event-skip-state')).toBeVisible({ timeout: 10000 });

		// Unskip → Skip-next is available again.
		await card.getByTestId('event-unskip').click();
		await expect(card.getByTestId('event-skip')).toBeVisible({ timeout: 10000 });

		// Cancel → our card is gone.
		await card.getByTestId('event-cancel').click();
		await expect(card).toHaveCount(0, { timeout: 10000 });
	});

	test('one-time heat ± is staged: Save persists in place, Discard reverts', async ({ page }) => {
		// Add a one-time heat-to-target tomorrow at 14:00, unique temp 106.25°F.
		await page.getByTestId('schedule-add').click();
		const sheet = page.getByTestId('schedule-add-sheet');
		await expect(sheet).toBeVisible();
		await sheet.getByTestId('add-temp').fill('106.25');
		await sheet.getByTestId('add-time').fill('14:00');
		await sheet.getByRole('button', { name: 'Once' }).click();
		await sheet.getByTestId('add-submit').click();

		// Find our one-off by its unique temp, then pin the card to its stable job id.
		const initialCard = page.locator('[data-testid="event-card"]', { hasText: 'Heat to 106.25' });
		await expect(initialCard).toHaveCount(1, { timeout: 10000 });
		const jobId = await initialCard.getAttribute('data-job-id');
		const card = page.locator(`[data-testid="event-card"][data-job-id="${jobId}"]`);

		const tempOf = async (id: string | null) => {
			const list = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
				.jobs as Array<{ jobId: string; params?: { target_temp_f?: number } }>;
			return list.find((j) => j.jobId === id)?.params?.target_temp_f;
		};

		// One-off heat shows ± quick adjust, NO skip, and starts clean (no Save).
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.25°F');
		await expect(card.getByTestId('event-skip')).toHaveCount(0);
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0);

		// + temp updates the DRAFT and reveals Save — but the backend is untouched until Save.
		await card.getByRole('button', { name: 'a quarter degree warmer' }).click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.5°F');
		await expect(card.getByTestId('event-oneoff-save')).toBeVisible();
		expect(await tempOf(jobId)).toBe(106.25);

		// Discard reverts the draft, leaving the card clean.
		await card.getByTestId('event-oneoff-discard').click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.25°F');
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0);

		// Re-nudge temp + time, then Save → persists in place (same job id), card goes clean.
		await card.getByRole('button', { name: 'a quarter degree warmer' }).click();
		await card.getByRole('button', { name: '15 minutes later' }).click();
		await card.getByTestId('event-oneoff-save').click();
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0, { timeout: 10000 });
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.5°F');
		expect(await tempOf(jobId)).toBe(106.50);

		// Clean up.
		await card.getByTestId('event-cancel').click();
		await expect(card).toHaveCount(0, { timeout: 10000 });
	});

	test('recurring heat ± is staged too: Save changes the daily default in place', async ({
		page
	}) => {
		// Add a daily heat-to-target at 06:30, unique temp 105.25°F.
		await page.getByTestId('schedule-add').click();
		const sheet = page.getByTestId('schedule-add-sheet');
		await expect(sheet).toBeVisible();
		await sheet.getByTestId('add-temp').fill('105.25');
		await sheet.getByTestId('add-time').fill('06:30');
		await sheet.getByTestId('add-submit').click(); // Daily is the default

		const initialCard = page.locator('[data-testid="event-card"]', { hasText: 'Heat to 105.25' });
		await expect(initialCard).toHaveCount(1, { timeout: 10000 });
		const jobId = await initialCard.getAttribute('data-job-id');
		const card = page.locator(`[data-testid="event-card"][data-job-id="${jobId}"]`);

		const jobOf = async () => {
			const list = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
				.jobs as Array<{
				jobId: string;
				scheduledTime: string;
				params?: { target_temp_f?: number };
			}>;
			return list.find((j) => j.jobId === jobId);
		};

		// Card parity: the recurring card has the SAME ± steppers, plus Skip next while clean.
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('105.25°F');
		await expect(card.getByTestId('event-skip')).toBeVisible();
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0);

		// Nudge time + temp: draft only — Skip next yields to Save/Discard, backend untouched.
		await card.getByRole('button', { name: '15 minutes later' }).click();
		await card.getByRole('button', { name: 'a quarter degree warmer' }).click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('105.5°F');
		await expect(card.getByTestId('event-skip')).toHaveCount(0);
		expect((await jobOf())?.scheduledTime).toBe('06:30');

		// Save → the everyday default moved in place: same rec- id, new daily time + temp.
		await card.getByTestId('event-oneoff-save').click();
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0, { timeout: 10000 });
		const after = await jobOf();
		expect(after?.scheduledTime).toBe('06:45');
		expect(after?.params?.target_temp_f).toBe(105.50);
		await expect(card.getByTestId('event-skip')).toBeVisible(); // clean again

		// Clean up.
		await card.getByTestId('event-cancel').click();
		await expect(card).toHaveCount(0, { timeout: 10000 });
	});

	test('a Home-created override folds into ONE adjusted card; permanent Save edits the daily default; Reset clears it', async ({
		page
	}) => {
		// Create a daily heat via API (unique temp; past time so the next run is tomorrow),
		// then override its next run — exactly what Home's card Save does.
		const tz = await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone);
		const past = new Date(Date.now() - 2 * 3600 * 1000);
		const hhmm = `${String(past.getHours()).padStart(2, '0')}:${String(past.getMinutes()).padStart(2, '0')}`;
		const res = await page.request.post('/tub/backend/public/api/schedule', {
			data: {
				action: 'heat-to-target',
				scheduledTime: hhmm,
				recurring: true,
				target_temp_f: 103.75,
				timezone: tz
			}
		});
		expect(res.ok()).toBeTruthy();
		const jobId = (await res.json()).jobId as string;

		const plus15 = new Date(past.getTime() + 15 * 60 * 1000);
		const overrideClock = `${String(plus15.getHours()).padStart(2, '0')}:${String(plus15.getMinutes()).padStart(2, '0')}`;
		const ovRes = await page.request.post(
			`/tub/backend/public/api/schedule/${jobId}/override-next`,
			{ data: { scheduledTime: overrideClock, target_temp_f: 103.75 } }
		);
		expect(ovRes.ok()).toBeTruthy();

		try {
			await page.reload();
			// ONE folded card wearing the adjusted badge — not a skipped parent plus an
			// anonymous one-off (the confusing pair this fold replaces).
			const card = page.locator(`[data-testid="event-card"][data-key="${jobId}"]`);
			await expect(card).toHaveCount(1, { timeout: 10000 });
			await expect(
				page.locator('[data-testid="event-card"]', { hasText: 'Heat to 103.75' })
			).toHaveCount(1);
			await expect(card.getByTestId('event-adjusted')).toBeVisible();
			await expect(card.getByTestId('event-resets-line')).toBeVisible();

			const parentOf = async () => {
				const list = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
					.jobs as Array<{ jobId: string; scheduledTime: string }>;
				return list.find((j) => j.jobId === jobId);
			};

			// Plain Save here is PERMANENT: the steppers edit the daily default (the base
			// job), and the override survives until reset.
			await card.getByRole('button', { name: '15 minutes later' }).click();
			await card.getByTestId('event-oneoff-save').click();
			await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0, { timeout: 10000 });
			expect((await parentOf())?.scheduledTime).toBe(overrideClock); // daily hhmm + 15
			await expect(card.getByTestId('event-adjusted')).toBeVisible(); // still adjusted

			// Reset to daily clears the override; the card stays as the (updated) daily.
			await card.getByTestId('event-reset').click();
			await expect(card.getByTestId('event-adjusted')).toHaveCount(0, { timeout: 10000 });
			await expect(card).toHaveCount(1);
		} finally {
			await page.request.delete(`/tub/backend/public/api/schedule/${jobId}`).catch(() => {});
		}
	});

	test('leaving the tab with an unsaved one-off prompts; Discard abandons it', async ({ page }) => {
		await page.getByTestId('schedule-add').click();
		const sheet = page.getByTestId('schedule-add-sheet');
		await expect(sheet).toBeVisible();
		await sheet.getByTestId('add-temp').fill('107.25');
		await sheet.getByTestId('add-time').fill('15:00');
		await sheet.getByRole('button', { name: 'Once' }).click();
		await sheet.getByTestId('add-submit').click();

		const card = page.locator('[data-testid="event-card"]', { hasText: 'Heat to 107.25' });
		await expect(card).toHaveCount(1, { timeout: 10000 });
		const jobId = await card.getAttribute('data-job-id');

		// Stage a temp change (dirty), then try to leave via the Home tab → guard prompts.
		await card.getByRole('button', { name: 'a quarter degree warmer' }).click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('107.5°F');
		await page.getByTestId('tab-home').click();

		const modal = page.getByTestId('unsaved-modal');
		await expect(modal).toBeVisible({ timeout: 10000 });
		// Navigation was held — still on the Schedule tab.
		await expect(page.getByTestId('v2-schedule')).toBeVisible();

		// Discard → navigation proceeds to Home, and the change never reached the backend.
		await modal.getByTestId('unsaved-discard').click();
		await expect(page.getByTestId('v2-home')).toBeVisible({ timeout: 10000 });
		const list = (await (await page.request.get('/tub/backend/public/api/schedule')).json())
			.jobs as Array<{ jobId: string; params?: { target_temp_f?: number } }>;
		expect(list.find((j) => j.jobId === jobId)?.params?.target_temp_f).toBe(107.25);

		// Clean up.
		await page.request.delete(`/tub/backend/public/api/schedule/${jobId}`).catch(() => {});
	});
});
