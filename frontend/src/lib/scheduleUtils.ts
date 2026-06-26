import type { ScheduledJob } from './api';

/**
 * Get the local timezone offset in ISO 8601 format (e.g., "+05:30", "-08:00")
 */
export function getTimezoneOffset(): string {
	const offset = new Date().getTimezoneOffset();
	const sign = offset <= 0 ? '+' : '-';
	const hours = Math.floor(Math.abs(offset) / 60);
	const minutes = Math.abs(offset) % 60;
	return `${sign}${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
}

/**
 * Parse time string like "6am", "6:30am", "7am" into hours and minutes
 */
function parseTimeString(timeStr: string): { hours: number; minutes: number } {
	const match = timeStr.match(/^(\d{1,2})(?::(\d{2}))?(?:am|pm)?$/i);
	if (!match) {
		throw new Error(`Invalid time string: ${timeStr}`);
	}

	const hours = parseInt(match[1], 10);
	const minutes = match[2] ? parseInt(match[2], 10) : 0;

	return { hours, minutes };
}

/**
 * Format a Date as ISO 8601 string with local timezone offset
 */
function formatWithTimezone(date: Date): string {
	const year = date.getFullYear();
	const month = (date.getMonth() + 1).toString().padStart(2, '0');
	const day = date.getDate().toString().padStart(2, '0');
	const hours = date.getHours().toString().padStart(2, '0');
	const minutes = date.getMinutes().toString().padStart(2, '0');
	const seconds = date.getSeconds().toString().padStart(2, '0');

	return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}${getTimezoneOffset()}`;
}

/**
 * Get the scheduled time as an ISO 8601 string for a quick schedule option.
 *
 * @param option - One of: "+7.5h", "6am", "6:30am", "7am", "7:30am", "8am"
 * @returns ISO 8601 string with timezone offset
 *
 * For relative time (+7.5h): Adds 7.5 hours to current time
 * For absolute times: Returns today's date if time is still in future, otherwise tomorrow
 */
export function getScheduleTime(option: string): string {
	const now = new Date();

	// Handle relative time (+7.5h)
	if (option === '+7.5h') {
		const hoursToAdd = 7.5;
		const scheduledTime = new Date(now.getTime() + hoursToAdd * 60 * 60 * 1000);
		return formatWithTimezone(scheduledTime);
	}

	// Handle absolute times (6am, 6:30am, 7am, 7:30am, 8am)
	const { hours, minutes } = parseTimeString(option);

	const scheduled = new Date(now);
	scheduled.setHours(hours, minutes, 0, 0);

	// If the scheduled time has already passed today, schedule for tomorrow
	if (scheduled <= now) {
		scheduled.setDate(scheduled.getDate() + 1);
	}

	return formatWithTimezone(scheduled);
}

/**
 * The minimal job shape needed to compute the next fire time.
 */
export interface NextOccurrenceJob {
	recurring: boolean;
	scheduledTime: string;
	skipped?: boolean;
	skipDate?: string;
}

/**
 * Parse a clock time ("06:55", "6:55", "06:55:00") into hours and minutes.
 */
function parseClockTime(timeString: string): { hours: number; minutes: number } {
	const [hh, mm] = timeString.split(':');
	return { hours: parseInt(hh, 10), minutes: parseInt(mm ?? '0', 10) };
}

/**
 * Compute the next datetime a job will fire — used to sort events ("which runs
 * next?") and to show "next: tomorrow" on the Home and Schedule surfaces.
 *
 * One-off jobs return their scheduled instant. Recurring daily jobs are evaluated
 * in the viewer's local timezone (which matches the job's IANA timezone in the
 * common single-household case): the next HH:MM at or after `now`, advanced one day
 * if that occurrence is the currently-skipped one (skip auto-resumes the day after).
 */
export function getNextOccurrence(job: NextOccurrenceJob, now: Date = new Date()): Date {
	if (!job.recurring) {
		return new Date(job.scheduledTime);
	}

	const { hours, minutes } = parseClockTime(job.scheduledTime);
	const next = new Date(now);
	next.setHours(hours, minutes, 0, 0);
	if (next.getTime() <= now.getTime()) {
		next.setDate(next.getDate() + 1);
	}

	// A skipped next occurrence doesn't fire; the real next fire is the day after.
	if (job.skipped && job.skipDate) {
		const skip = new Date(job.skipDate);
		if (
			next.getFullYear() === skip.getFullYear() &&
			next.getMonth() === skip.getMonth() &&
			next.getDate() === skip.getDate()
		) {
			next.setDate(next.getDate() + 1);
		}
	}

	return next;
}

/**
 * Format a next-fire datetime relative to now: "Today 6:55 AM", "Tomorrow 6:55 AM",
 * or "Thu, Jun 26 3:00 PM" for anything further out.
 */
export function formatNextFire(date: Date, now: Date = new Date()): string {
	const time = date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });

	const startOfDay = (d: Date): number => {
		const x = new Date(d);
		x.setHours(0, 0, 0, 0);
		return x.getTime();
	};
	const dayDiff = Math.round((startOfDay(date) - startOfDay(now)) / 86_400_000);

	if (dayDiff === 0) return `Today ${time}`;
	if (dayDiff === 1) return `Tomorrow ${time}`;

	const day = date.toLocaleDateString(undefined, {
		weekday: 'short',
		month: 'short',
		day: 'numeric'
	});
	return `${day} ${time}`;
}

/**
 * A single *logical* scheduled event for the Home "Next up" surface.
 *
 * The backend models an "adjust just the next run" as two jobs — the recurring parent
 * (now skipped for that date) plus a one-off override. Those are one thing to a person,
 * so we fold them into a single entry keyed by the *stable* parent id. The key survives
 * the override churn (each override one-off gets a fresh id), which is what lets the UI
 * keep a card selected across edits instead of re-binding controls to whatever floats to
 * the top of the sort.
 */
export interface LogicalEvent {
	/** Stable selection id: the recurring parent's id, or a standalone one-off's own id. */
	key: string;
	/** The job to display (the override when present, else the base). */
	job: ScheduledJob;
	/** The recurring/base job (its time + temp are the "everyday default"). */
	baseJob: ScheduledJob;
	/** The override one-off, when the next run has been adjusted. */
	overrideJob?: ScheduledJob;
	nextFire: Date;
	recurring: boolean;
	/** The next run has been moved to a one-off time/temp. */
	overridden: boolean;
	/** The next run is skipped (and not overridden); only "Resume" applies. */
	skipped: boolean;
	resumeDate?: string;
	/** Whether the next run can be adjusted/skipped (recurring events only). */
	adjustable: boolean;
}

/**
 * Build a local Date from a "YYYY-MM-DD" calendar date and an "HH:MM" clock time.
 * Used for skip/resume dates, whose stored offset can't be trusted (see foldScheduledEvents).
 */
function localDateFromParts(dateStr: string, clock: string): Date {
	const [year, month, day] = dateStr.split('-').map(Number);
	const { hours, minutes } = parseClockTime(clock);
	return new Date(year, month - 1, day, hours, minutes, 0, 0);
}

function oneOffEvent(job: ScheduledJob): LogicalEvent {
	return {
		key: job.jobId,
		job,
		baseJob: job,
		nextFire: new Date(job.scheduledTime),
		recurring: false,
		overridden: false,
		skipped: false,
		adjustable: false
	};
}

/**
 * Fold the raw jobs list into logical events for the Home "Next up" zone.
 *
 * - A recurring parent and its override one-off collapse into ONE entry (overridden).
 * - A skipped recurring event (no override) reports its resume date as the next fire —
 *   derived from the date part, so the backend's "+00:00 on a local date" skip/resume
 *   timestamps can't push it onto the wrong calendar day.
 * - Standalone one-offs pass through (not adjustable from Home; edited on the Schedule tab).
 * - An override whose parent has vanished is shown on its own rather than dropped.
 *
 * Sorted ascending by next fire ("which runs next?").
 */
export function foldScheduledEvents(jobs: ScheduledJob[], now: Date = new Date()): LogicalEvent[] {
	const groups = new Map<string, { base?: ScheduledJob; override?: ScheduledJob }>();
	const standalone: ScheduledJob[] = [];

	for (const job of jobs) {
		const overrideOf = job.params?.override_of;
		if (overrideOf) {
			const group = groups.get(overrideOf) ?? {};
			group.override = job;
			groups.set(overrideOf, group);
		} else if (job.recurring) {
			const group = groups.get(job.jobId) ?? {};
			group.base = job;
			groups.set(job.jobId, group);
		} else {
			standalone.push(job);
		}
	}

	const events: LogicalEvent[] = [];

	for (const [key, { base, override }] of groups) {
		if (!base) {
			// Override pointing at a parent we can't see — surface it rather than drop it.
			if (override) events.push(oneOffEvent(override));
			continue;
		}
		if (override) {
			events.push({
				key,
				job: override,
				baseJob: base,
				overrideJob: override,
				nextFire: new Date(override.scheduledTime),
				recurring: true,
				overridden: true,
				skipped: false,
				adjustable: true
			});
		} else if (base.skipped) {
			const nextFire = base.resumeDate
				? localDateFromParts(base.resumeDate.slice(0, 10), base.scheduledTime)
				: getNextOccurrence(base, now);
			events.push({
				key,
				job: base,
				baseJob: base,
				nextFire,
				recurring: true,
				overridden: false,
				skipped: true,
				resumeDate: base.resumeDate,
				adjustable: true
			});
		} else {
			events.push({
				key,
				job: base,
				baseJob: base,
				nextFire: getNextOccurrence(base, now),
				recurring: true,
				overridden: false,
				skipped: false,
				adjustable: true
			});
		}
	}

	for (const job of standalone) events.push(oneOffEvent(job));

	return events.sort((a, b) => a.nextFire.getTime() - b.nextFire.getTime());
}

/**
 * Quick schedule options available in the UI
 */
export const QUICK_SCHEDULE_OPTIONS = [
	{ label: '+7.5h', value: '+7.5h' },
	{ label: '6am', value: '6am' },
	{ label: '6:30', value: '6:30am' },
	{ label: '7am', value: '7am' },
	{ label: '7:30', value: '7:30am' },
	{ label: '8am', value: '8am' }
] as const;
