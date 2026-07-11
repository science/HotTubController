import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
	getScheduleTime,
	getTimezoneOffset,
	getNextOccurrence,
	formatNextFire,
	foldScheduledEvents,
	formatTemp,
	shiftHHMM,
	formatClockHHMM,
	jobTitle,
	resumeLabel,
	jobClock,
	baseSummary,
	oneOffIso
} from './scheduleUtils';
import type { ScheduledJob } from './api';

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

	describe('foldScheduledEvents', () => {
		// 2026-06-25 (Thu) 10:00 local — 06:55 is already past today.
		const now = new Date(2026, 5, 25, 10, 0, 0);

		const recurring = (extra: Partial<ScheduledJob> = {}): ScheduledJob => ({
			jobId: 'rec-1',
			action: 'heat-to-target',
			scheduledTime: '06:55',
			createdAt: '2026-06-20T00:00:00Z',
			recurring: true,
			timezone: 'America/Los_Angeles',
			params: { target_temp_f: 102.25 },
			...extra
		});

		// The backend stores skip/resume dates with a +00:00 offset even though the date
		// part is the local calendar date — the exact artifact that broke sort order.
		const skipped = (extra: Partial<ScheduledJob> = {}): ScheduledJob =>
			recurring({
				skipped: true,
				skipDate: '2026-06-26T06:55:00+00:00',
				resumeDate: '2026-06-27T06:55:00+00:00',
				...extra
			});

		const overrideOneOff = (extra: Partial<ScheduledJob> = {}): ScheduledJob => ({
			jobId: 'job-9',
			action: 'heat-to-target',
			scheduledTime: '2026-06-26T07:10:00-07:00',
			createdAt: '2026-06-25T17:00:00Z',
			recurring: false,
			params: { target_temp_f: 102.25, override_of: 'rec-1' },
			...extra
		});

		it('returns an empty list for no jobs', () => {
			expect(foldScheduledEvents([], now)).toEqual([]);
		});

		it('passes a plain recurring event through as one adjustable entry', () => {
			const events = foldScheduledEvents([recurring()], now);
			expect(events).toHaveLength(1);
			const e = events[0];
			expect(e.key).toBe('rec-1');
			expect(e.recurring).toBe(true);
			expect(e.overridden).toBe(false);
			expect(e.skipped).toBe(false);
			expect(e.adjustable).toBe(true);
			expect(e.nextFire.getDate()).toBe(26);
			expect(e.nextFire.getHours()).toBe(6);
			expect(e.nextFire.getMinutes()).toBe(55);
		});

		it('collapses a recurring parent and its override one-off into one entry', () => {
			// This is the regression: the override + its now-skipped parent are ONE event.
			const events = foldScheduledEvents([skipped(), overrideOneOff()], now);
			expect(events).toHaveLength(1);
			const e = events[0];
			expect(e.key).toBe('rec-1'); // stable parent id — survives override churn
			expect(e.overridden).toBe(true);
			expect(e.skipped).toBe(false); // an override is an adjustment, not a skip
			expect(e.job.jobId).toBe('job-9'); // display the override
			expect(e.baseJob.jobId).toBe('rec-1');
			expect(e.overrideJob?.jobId).toBe('job-9');
			expect(e.adjustable).toBe(true);
			expect(e.nextFire.getTime()).toBe(new Date('2026-06-26T07:10:00-07:00').getTime());
		});

		it('represents a skipped recurring event (no override) by its resume date', () => {
			const events = foldScheduledEvents([skipped()], now);
			expect(events).toHaveLength(1);
			const e = events[0];
			expect(e.skipped).toBe(true);
			expect(e.overridden).toBe(false);
			expect(e.resumeDate).toBe('2026-06-27T06:55:00+00:00');
			// Resumes the 27th — derived from the date part, immune to the skipDate tz artifact.
			expect(e.nextFire.getFullYear()).toBe(2026);
			expect(e.nextFire.getMonth()).toBe(5);
			expect(e.nextFire.getDate()).toBe(27);
		});

		it('keeps a standalone one-off as its own non-adjustable entry', () => {
			const oneOff: ScheduledJob = {
				jobId: 'job-x',
				action: 'pump-run',
				scheduledTime: '2026-06-26T21:00:00-07:00',
				createdAt: '2026-06-25T00:00:00Z',
				recurring: false
			};
			const events = foldScheduledEvents([oneOff], now);
			expect(events).toHaveLength(1);
			const e = events[0];
			expect(e.key).toBe('job-x');
			expect(e.recurring).toBe(false);
			expect(e.adjustable).toBe(false);
			expect(e.nextFire.getTime()).toBe(new Date('2026-06-26T21:00:00-07:00').getTime());
		});

		it('marks a standalone heat-to-target one-off adjustable (movable in place from Home)', () => {
			const oneOff: ScheduledJob = {
				jobId: 'job-h',
				action: 'heat-to-target',
				scheduledTime: '2026-06-26T21:00:00-07:00',
				createdAt: '2026-06-25T00:00:00Z',
				recurring: false,
				params: { target_temp_f: 105 }
			};
			const events = foldScheduledEvents([oneOff], now);
			expect(events).toHaveLength(1);
			expect(events[0].recurring).toBe(false);
			expect(events[0].adjustable).toBe(true);
		});

		it('sorts by next fire and still collapses the override pair (no duplicate)', () => {
			const earlierOneOff: ScheduledJob = {
				jobId: 'job-x',
				action: 'pump-run',
				scheduledTime: '2026-06-26T05:00:00-07:00',
				createdAt: '2026-06-25T00:00:00Z',
				recurring: false
			};
			const events = foldScheduledEvents([skipped(), overrideOneOff(), earlierOneOff], now);
			expect(events).toHaveLength(2); // not 3 — the pair collapsed
			expect(events[0].key).toBe('job-x'); // 05:00 sorts before the 07:10 override
			expect(events[1].key).toBe('rec-1');
		});

		it('treats an override whose parent is missing as a standalone entry', () => {
			const events = foldScheduledEvents([overrideOneOff()], now);
			expect(events).toHaveLength(1);
			expect(events[0].key).toBe('job-9');
			// It's a real heat-to-target one-off, so it can still be moved in place from Home.
			expect(events[0].adjustable).toBe(true);
		});
	});

	// Shared display/clock helpers, consolidated from Home and EventCard (review F10).
	describe('formatTemp', () => {
		it('drops the decimals from whole degrees', () => {
			expect(formatTemp(102)).toBe('102');
		});

		it('keeps quarter-degree precision without trailing zeros', () => {
			expect(formatTemp(102.25)).toBe('102.25');
			expect(formatTemp(102.5)).toBe('102.5');
		});
	});

	describe('shiftHHMM', () => {
		it('shifts within the day', () => {
			expect(shiftHHMM('06:55', 15)).toBe('07:10');
			expect(shiftHHMM('06:55', -15)).toBe('06:40');
		});

		it('wraps forward past midnight', () => {
			expect(shiftHHMM('23:45', 15)).toBe('00:00');
		});

		it('wraps backward past midnight, even for repeated large steps', () => {
			expect(shiftHHMM('00:00', -15)).toBe('23:45');
			expect(shiftHHMM('00:00', -1440 * 3)).toBe('00:00');
		});
	});

	describe('formatClockHHMM', () => {
		it('renders an HH:MM wall clock in the locale time format', () => {
			const expected = new Date(2026, 0, 1, 6, 55).toLocaleTimeString(undefined, {
				hour: 'numeric',
				minute: '2-digit'
			});
			expect(formatClockHHMM('06:55')).toBe(expected);
		});
	});

	describe('jobTitle', () => {
		const heatJob = {
			jobId: 'j1',
			action: 'heat-to-target',
			scheduledTime: '06:55',
			createdAt: '2026-06-20T00:00:00Z',
			recurring: true,
			params: { target_temp_f: 102.25 }
		} as ScheduledJob;

		it('names a heat-to-target job by its target', () => {
			expect(jobTitle(heatJob, false)).toBe('Heat to 102.25°F');
		});

		it('marks the target approximate in dynamic mode', () => {
			expect(jobTitle(heatJob, true)).toBe('Heat to ~102.25°F');
		});

		it('uses the action label for non-target jobs', () => {
			expect(jobTitle({ ...heatJob, action: 'pump-run', params: undefined }, false)).toBe(
				'Run pump'
			);
		});

		it('falls back to the raw action name', () => {
			expect(jobTitle({ ...heatJob, action: 'mystery-op', params: undefined }, false)).toBe(
				'mystery-op'
			);
		});
	});

	describe('resumeLabel', () => {
		it('formats the calendar date, immune to the misleading +00:00 offset', () => {
			// The stored date part is the local calendar date; naive Date parsing could
			// land a day early west of UTC.
			const label = resumeLabel('2026-06-27T06:55:00+00:00');
			expect(label).toContain('27');
			expect(label).toContain('Jun');
		});

		it('returns an empty string for a missing date', () => {
			expect(resumeLabel(undefined)).toBe('');
		});
	});

	describe('jobClock', () => {
		it('prefers the ready-by time', () => {
			const job = {
				jobId: 'j1',
				action: 'heat-to-target',
				scheduledTime: '05:40',
				createdAt: '2026-06-20T00:00:00Z',
				recurring: true,
				params: { target_temp_f: 102, ready_by_time: '07:30' }
			} as ScheduledJob;
			expect(jobClock(job)).toBe('07:30');
		});

		it('uses the recurring HH:MM directly', () => {
			const job = {
				jobId: 'j1',
				action: 'heat-to-target',
				scheduledTime: '06:55',
				createdAt: '2026-06-20T00:00:00Z',
				recurring: true
			} as ScheduledJob;
			expect(jobClock(job)).toBe('06:55');
		});

		it('derives a padded local wall clock from a one-off instant', () => {
			const job = {
				jobId: 'j1',
				action: 'heat-to-target',
				scheduledTime: new Date(2026, 5, 26, 7, 5).toISOString(),
				createdAt: '2026-06-20T00:00:00Z',
				recurring: false
			} as ScheduledJob;
			expect(jobClock(job)).toBe('07:05');
		});
	});

	describe('baseSummary', () => {
		it('summarizes the everyday default as clock · temp', () => {
			const base = {
				jobId: 'rec-1',
				action: 'heat-to-target',
				scheduledTime: '06:55',
				createdAt: '2026-06-20T00:00:00Z',
				recurring: true,
				params: { target_temp_f: 102.25 }
			} as ScheduledJob;
			const [event] = foldScheduledEvents([base]);
			expect(baseSummary(event)).toBe(`${formatClockHHMM('06:55')} · 102.25°F`);
		});

		it('omits the temp when the base job has none', () => {
			const base = {
				jobId: 'rec-1',
				action: 'pump-run',
				scheduledTime: '21:00',
				createdAt: '2026-06-20T00:00:00Z',
				recurring: true
			} as ScheduledJob;
			const [event] = foldScheduledEvents([base]);
			expect(baseSummary(event)).toBe(formatClockHHMM('21:00'));
		});
	});

	describe('oneOffIso', () => {
		it("recomposes the job's calendar date with a new local wall clock", () => {
			const job = {
				jobId: 'j1',
				action: 'heat-to-target',
				scheduledTime: new Date(2026, 5, 26, 7, 5).toISOString(),
				createdAt: '2026-06-20T00:00:00Z',
				recurring: false
			} as ScheduledJob;
			const result = new Date(oneOffIso(job, '19:30'));
			expect(result.getFullYear()).toBe(2026);
			expect(result.getMonth()).toBe(5);
			expect(result.getDate()).toBe(26);
			expect(result.getHours()).toBe(19);
			expect(result.getMinutes()).toBe(30);
		});
	});
});
