import { test, expect } from '@playwright/test';

/**
 * Stage 2 smoke for the card-based v2 Schedule tab (/tub/v2/schedule).
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
		await page.goto('/tub/v2/schedule');
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
		await card.getByRole('button', { name: 'half a degree warmer' }).click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.75°F');
		await expect(card.getByTestId('event-oneoff-save')).toBeVisible();
		expect(await tempOf(jobId)).toBe(106.25);

		// Discard reverts the draft, leaving the card clean.
		await card.getByTestId('event-oneoff-discard').click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.25°F');
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0);

		// Re-nudge temp + time, then Save → persists in place (same job id), card goes clean.
		await card.getByRole('button', { name: 'half a degree warmer' }).click();
		await card.getByRole('button', { name: '15 minutes later' }).click();
		await card.getByTestId('event-oneoff-save').click();
		await expect(card.getByTestId('event-oneoff-save')).toHaveCount(0, { timeout: 10000 });
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('106.75°F');
		expect(await tempOf(jobId)).toBe(106.75);

		// Clean up.
		await card.getByTestId('event-cancel').click();
		await expect(card).toHaveCount(0, { timeout: 10000 });
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
		await card.getByRole('button', { name: 'half a degree warmer' }).click();
		await expect(card.getByTestId('event-oneoff-temp')).toHaveText('107.75°F');
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
