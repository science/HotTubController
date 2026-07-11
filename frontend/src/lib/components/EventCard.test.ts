import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import EventCard from './EventCard.svelte';
import { foldScheduledEvents, type LogicalEvent } from '$lib/scheduleUtils';
import type { ScheduledJob } from '$lib/api';

// Non-dynamic heat-to-target so the ± adjust branch renders.
vi.mock('$lib/stores/heatTargetSettings.svelte', () => ({
	getDynamicMode: vi.fn(() => false)
}));

// The registry is exercised by its own unit test; here we just spy on registration so
// EventCard's staging logic is isolated from the global store.
vi.mock('$lib/stores/pendingEdits.svelte', () => ({
	registerPendingEdit: vi.fn(),
	clearPendingEdit: vi.fn()
}));

// Cards render logical events (the folded view both pages share), built through the
// real fold so the tests exercise the same shapes production sees.
function toEvent(...jobs: ScheduledJob[]): LogicalEvent {
	const events = foldScheduledEvents(jobs);
	expect(events).toHaveLength(1);
	return events[0];
}

function oneOffJob(overrides: Partial<ScheduledJob> = {}): ScheduledJob {
	return {
		jobId: 'job-1',
		action: 'heat-to-target',
		scheduledTime: '2026-06-26T18:00:00-07:00',
		createdAt: '2026-06-20T00:00:00Z',
		recurring: false,
		params: { target_temp_f: 105 },
		...overrides
	} as ScheduledJob;
}

function recurringJob(overrides: Partial<ScheduledJob> = {}): ScheduledJob {
	return {
		jobId: 'rec-1',
		action: 'heat-to-target',
		scheduledTime: '06:30',
		createdAt: '2026-06-20T00:00:00Z',
		recurring: true,
		timezone: 'America/Los_Angeles',
		params: { target_temp_f: 102 },
		...overrides
	} as ScheduledJob;
}

// An override one-off pointing at rec-1; the instant is built from local parts so the
// derived wall clock is deterministic regardless of the test machine's timezone.
function overrideJob(overrides: Partial<ScheduledJob> = {}): ScheduledJob {
	return {
		jobId: 'ovr-1',
		action: 'heat-to-target',
		scheduledTime: new Date(2026, 5, 26, 7, 10).toISOString(),
		createdAt: '2026-06-25T00:00:00Z',
		recurring: false,
		params: { target_temp_f: 102.25, override_of: 'rec-1' },
		...overrides
	} as ScheduledJob;
}

describe('EventCard one-off staging', () => {
	beforeEach(() => vi.clearAllMocks());

	it('stages ± edits locally and only calls onReschedule on Save', async () => {
		const onReschedule = vi.fn().mockResolvedValue(undefined);
		render(EventCard, { props: { event: toEvent(oneOffJob()), canAdjust: true, onReschedule } });

		// Clean to start: no Save affordance.
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();

		// A ± tap updates the local draft display but does NOT hit the backend.
		await fireEvent.click(screen.getByRole('button', { name: 'a quarter degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105.25');
		expect(onReschedule).not.toHaveBeenCalled();
		expect(screen.queryByTestId('event-oneoff-save')).not.toBeNull();

		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		expect(onReschedule).not.toHaveBeenCalled();

		// Save commits ONE call with the final time + temp.
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onReschedule).toHaveBeenCalledTimes(1));
		const [jobId, iso, temp] = onReschedule.mock.calls[0];
		expect(jobId).toBe('job-1');
		expect(temp).toBe(105.25);
		expect(new Date(iso as string).getTime()).toBe(
			new Date('2026-06-26T18:15:00-07:00').getTime()
		);
	});

	it('Discard reverts the draft without calling onReschedule', async () => {
		const onReschedule = vi.fn().mockResolvedValue(undefined);
		render(EventCard, { props: { event: toEvent(oneOffJob()), canAdjust: true, onReschedule } });

		await fireEvent.click(screen.getByRole('button', { name: 'a quarter degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105.25');

		await fireEvent.click(screen.getByTestId('event-oneoff-discard'));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105');
		expect(screen.getByTestId('event-oneoff-temp').textContent).not.toContain('105.25');
		expect(onReschedule).not.toHaveBeenCalled();
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();
	});
});

describe('EventCard recurring staging — permanent mode (Schedule tab)', () => {
	beforeEach(() => vi.clearAllMocks());

	it('renders the same ± steppers for a recurring heat event and stages locally', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: { event: toEvent(recurringJob()), canAdjust: true, onRescheduleRecurring }
		});

		// Clean: steppers present, Skip next visible, no Save yet, no legacy Edit temp.
		expect(screen.getByRole('button', { name: '15 minutes later' })).toBeTruthy();
		expect(screen.getByTestId('event-skip')).toBeTruthy();
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();
		expect(screen.queryByTestId('event-edit-temp')).toBeNull();

		// Nudge time + temp: draft updates, nothing hits the backend.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByRole('button', { name: 'a quarter degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('102.25');
		expect(onRescheduleRecurring).not.toHaveBeenCalled();

		// Save commits ONE call with the new daily HH:MM wall clock + temp.
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onRescheduleRecurring).toHaveBeenCalledTimes(1));
		expect(onRescheduleRecurring).toHaveBeenCalledWith('rec-1', '06:45', 102.25);
	});

	it('time-only save leaves the temp argument undefined', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: { event: toEvent(recurringJob()), canAdjust: true, onRescheduleRecurring }
		});

		await fireEvent.click(screen.getByRole('button', { name: '15 minutes earlier' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onRescheduleRecurring).toHaveBeenCalledTimes(1));
		expect(onRescheduleRecurring).toHaveBeenCalledWith('rec-1', '06:15', undefined);
	});

	it('uses the ready-by time as the stepper base for ready-by parents', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: {
				event: toEvent(
					recurringJob({
						scheduledTime: '03:35', // cron wake-up
						params: { target_temp_f: 102, ready_by_time: '06:30' }
					})
				),
				canAdjust: true,
				onRescheduleRecurring
			}
		});

		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onRescheduleRecurring).toHaveBeenCalledTimes(1));
		// 06:30 ready-by + 15, NOT the 03:35 wake-up time.
		expect(onRescheduleRecurring).toHaveBeenCalledWith('rec-1', '06:45', undefined);
	});

	it('a skipped recurring card keeps Unskip instead of steppers', () => {
		const onRescheduleRecurring = vi.fn();
		const onUnskip = vi.fn();
		render(EventCard, {
			props: {
				event: toEvent(
					recurringJob({ skipped: true, skipDate: '2026-06-27', resumeDate: '2026-06-28' })
				),
				canAdjust: true,
				onRescheduleRecurring,
				onUnskip
			}
		});

		expect(screen.getByTestId('event-unskip')).toBeTruthy();
		expect(screen.queryByRole('button', { name: '15 minutes later' })).toBeNull();
	});

	it('an overridden card in permanent mode seeds the steppers from the daily default', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: {
				event: toEvent(recurringJob(), overrideJob()),
				canAdjust: true,
				onRescheduleRecurring
			}
		});

		// Editing the everyday default (06:30), not the override's 07:10.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onRescheduleRecurring).toHaveBeenCalledTimes(1));
		expect(onRescheduleRecurring).toHaveBeenCalledWith('rec-1', '06:45', undefined);
	});
});

describe('EventCard recurring staging — override mode (Home)', () => {
	beforeEach(() => vi.clearAllMocks());

	it('Save calls onOverrideNext with the temp always present', async () => {
		const onOverrideNext = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: { event: toEvent(recurringJob()), canAdjust: true, onOverrideNext }
		});

		// Time-only nudge still sends the effective temp — the override endpoint requires it.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onOverrideNext).toHaveBeenCalledTimes(1));
		expect(onOverrideNext).toHaveBeenCalledWith('rec-1', '06:45', 102);
	});

	it('an already-overridden card seeds the steppers from the effective next run', async () => {
		const onOverrideNext = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: {
				event: toEvent(recurringJob(), overrideJob()),
				canAdjust: true,
				onOverrideNext
			}
		});

		// Nudging the 07:10 override, not the 06:30 daily default.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onOverrideNext).toHaveBeenCalledTimes(1));
		expect(onOverrideNext).toHaveBeenCalledWith('rec-1', '07:25', 102.25);
	});

	it("uses the override's ready-by time as the stepper base when present", async () => {
		const onOverrideNext = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: {
				event: toEvent(
					recurringJob(),
					overrideJob({ params: { target_temp_f: 102.25, ready_by_time: '07:30', override_of: 'rec-1' } })
				),
				canAdjust: true,
				onOverrideNext
			}
		});

		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onOverrideNext).toHaveBeenCalledTimes(1));
		expect(onOverrideNext).toHaveBeenCalledWith('rec-1', '07:45', 102.25);
	});
});

describe('EventCard overridden ("adjusted") card management', () => {
	beforeEach(() => vi.clearAllMocks());

	it('shows the adjusted badge and resets-line, never the skipped badge', () => {
		render(EventCard, {
			props: { event: toEvent(recurringJob(), overrideJob()), canAdjust: true, onOverrideNext: vi.fn() }
		});

		expect(screen.getByTestId('event-adjusted')).toBeTruthy();
		expect(screen.getByTestId('event-resets-line').textContent).toContain('resets to');
		expect(screen.queryByText('skipped')).toBeNull();
		expect(screen.queryByTestId('event-skip-state')).toBeNull();
	});

	it('Reset to daily and Make permanent act on the logical event', async () => {
		const onClearOverride = vi.fn();
		const onMakePermanent = vi.fn();
		render(EventCard, {
			props: {
				event: toEvent(recurringJob(), overrideJob()),
				canAdjust: true,
				onOverrideNext: vi.fn(),
				onClearOverride,
				onMakePermanent
			}
		});

		await fireEvent.click(screen.getByTestId('event-reset'));
		expect(onClearOverride).toHaveBeenCalledTimes(1);
		expect(onClearOverride.mock.calls[0][0].key).toBe('rec-1');

		await fireEvent.click(screen.getByTestId('event-make-permanent'));
		expect(onMakePermanent).toHaveBeenCalledTimes(1);
		expect(onMakePermanent.mock.calls[0][0].key).toBe('rec-1');
	});

	it('hides Reset to daily and Make permanent while the card is dirty', async () => {
		render(EventCard, {
			props: {
				event: toEvent(recurringJob(), overrideJob()),
				canAdjust: true,
				onOverrideNext: vi.fn().mockResolvedValue(undefined),
				onClearOverride: vi.fn(),
				onMakePermanent: vi.fn()
			}
		});

		expect(screen.getByTestId('event-reset')).toBeTruthy();
		expect(screen.getByTestId('event-make-permanent')).toBeTruthy();

		// Dirty: Save/Discard take over the row.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		expect(screen.queryByTestId('event-reset')).toBeNull();
		expect(screen.queryByTestId('event-make-permanent')).toBeNull();
		expect(screen.getByTestId('event-oneoff-save')).toBeTruthy();
		expect(screen.getByTestId('event-oneoff-discard')).toBeTruthy();
	});
});
