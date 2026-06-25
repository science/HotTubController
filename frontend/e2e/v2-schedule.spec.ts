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
});
