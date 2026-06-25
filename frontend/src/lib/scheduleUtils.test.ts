import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { getScheduleTime, getTimezoneOffset, getNextOccurrence, formatNextFire } from './scheduleUtils';

describe('scheduleUtils', () => {
	describe('getTimezoneOffset', () => {
		it('returns timezone offset in ISO format', () => {
			const offset = getTimezoneOffset();
			// Should match pattern like +00:00, -05:00, +05:30
			expect(offset).toMatch(/^[+-]\d{2}:\d{2}$/);
		});
	});

	describe('getScheduleTime', () => {
		beforeEach(() => {
			// Mock current time to 2025-12-11 10:00:00 local time
			vi.useFakeTimers();
			vi.setSystemTime(new Date(2025, 11, 11, 10, 0, 0));
		});

		afterEach(() => {
			vi.useRealTimers();
		});

		describe('relative time (+7.5h)', () => {
			it('returns ISO string 7.5 hours from now', () => {
				const result = getScheduleTime('+7.5h');
				const resultDate = new Date(result);

				// Should be 7.5 hours later = 17:30
				expect(resultDate.getHours()).toBe(17);
				expect(resultDate.getMinutes()).toBe(30);
				expect(resultDate.getDate()).toBe(11); // same day
			});
		});

		describe('absolute times', () => {
			it('returns tomorrow 6am when current time is 10am', () => {
				const result = getScheduleTime('6am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(6);
				expect(resultDate.getMinutes()).toBe(0);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});

			it('returns tomorrow 6:30am when current time is 10am', () => {
				const result = getScheduleTime('6:30am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(6);
				expect(resultDate.getMinutes()).toBe(30);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});

			it('returns tomorrow 7am when current time is 10am', () => {
				const result = getScheduleTime('7am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(7);
				expect(resultDate.getMinutes()).toBe(0);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});

			it('returns tomorrow 7:30am when current time is 10am', () => {
				const result = getScheduleTime('7:30am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(7);
				expect(resultDate.getMinutes()).toBe(30);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});

			it('returns tomorrow 8am when current time is 10am', () => {
				const result = getScheduleTime('8am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(8);
				expect(resultDate.getMinutes()).toBe(0);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});
		});

		describe('smart today/tomorrow logic', () => {
			it('returns today at 8am if current time is 5am', () => {
				vi.setSystemTime(new Date(2025, 11, 11, 5, 0, 0)); // 5am

				const result = getScheduleTime('8am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(8);
				expect(resultDate.getDate()).toBe(11); // today
			});

			it('returns tomorrow at 6am if current time is 6:30am', () => {
				vi.setSystemTime(new Date(2025, 11, 11, 6, 30, 0)); // 6:30am

				const result = getScheduleTime('6am');
				const resultDate = new Date(result);

				expect(resultDate.getHours()).toBe(6);
				expect(resultDate.getDate()).toBe(12); // tomorrow
			});
		});

		describe('ISO string format', () => {
			it('returns valid ISO 8601 string with timezone', () => {
				const result = getScheduleTime('6am');

				// Should include timezone offset
				expect(result).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
			});
		});
	});

	describe('getNextOccurrence', () => {
		// Fixed reference: 2026-06-25 (Thu) 10:00 local.
		const now = new Date(2026, 5, 25, 10, 0, 0);

		it('returns the scheduled instant for one-off jobs', () => {
			const iso = '2026-06-30T15:00:00-07:00';
			const result = getNextOccurrence({ recurring: false, scheduledTime: iso }, now);
			expect(result.getTime()).toBe(new Date(iso).getTime());
		});

		it('returns today for a recurring time still ahead today', () => {
			const result = getNextOccurrence({ recurring: true, scheduledTime: '18:30' }, now);
			expect(result.getDate()).toBe(25);
			expect(result.getHours()).toBe(18);
			expect(result.getMinutes()).toBe(30);
		});

		it('rolls to tomorrow for a recurring time already past today', () => {
			const result = getNextOccurrence({ recurring: true, scheduledTime: '06:55' }, now);
			expect(result.getDate()).toBe(26);
			expect(result.getHours()).toBe(6);
			expect(result.getMinutes()).toBe(55);
		});

		it('advances past a skipped next occurrence to the following day', () => {
			// 06:55 is past today → next would be the 26th, but the 26th is skipped.
			const result = getNextOccurrence(
				{
					recurring: true,
					scheduledTime: '06:55',
					skipped: true,
					skipDate: '2026-06-26T06:55:00-07:00'
				},
				now
			);
			expect(result.getDate()).toBe(27);
		});

		it('ignores a skip whose date is not the next occurrence', () => {
			const result = getNextOccurrence(
				{
					recurring: true,
					scheduledTime: '06:55',
					skipped: true,
					skipDate: '2026-06-30T06:55:00-07:00'
				},
				now
			);
			expect(result.getDate()).toBe(26);
		});
	});

	describe('formatNextFire', () => {
		const now = new Date(2026, 5, 25, 10, 0, 0);

		it('labels today', () => {
			expect(formatNextFire(new Date(2026, 5, 25, 18, 30), now)).toMatch(/^Today /);
		});

		it('labels tomorrow', () => {
			expect(formatNextFire(new Date(2026, 5, 26, 6, 55), now)).toMatch(/^Tomorrow /);
		});

		it('labels further-out days without a relative word', () => {
			const label = formatNextFire(new Date(2026, 5, 30, 15, 0), now);
			expect(label).not.toMatch(/^(Today|Tomorrow)/);
		});
	});
});
