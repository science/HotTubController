import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import EventCard from './EventCard.svelte';
import type { ScheduledJob } from '$lib/api';

// Non-dynamic heat-to-target so the one-off ± adjust branch renders.
vi.mock('$lib/stores/heatTargetSettings.svelte', () => ({
	getDynamicMode: vi.fn(() => false)
}));

// The registry is exercised by its own unit test; here we just spy on registration so
// EventCard's staging logic is isolated from the global store.
vi.mock('$lib/stores/pendingEdits.svelte', () => ({
	registerPendingEdit: vi.fn(),
	clearPendingEdit: vi.fn()
}));

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

describe('EventCard one-off staging', () => {
	beforeEach(() => vi.clearAllMocks());

	it('stages ± edits locally and only calls onReschedule on Save', async () => {
		const onReschedule = vi.fn().mockResolvedValue(undefined);
		render(EventCard, { props: { job: oneOffJob(), canAdjust: true, onReschedule } });

		// Clean to start: no Save affordance.
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();

		// A ± tap updates the local draft display but does NOT hit the backend.
		await fireEvent.click(screen.getByRole('button', { name: 'half a degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105.5');
		expect(onReschedule).not.toHaveBeenCalled();
		expect(screen.queryByTestId('event-oneoff-save')).not.toBeNull();

		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		expect(onReschedule).not.toHaveBeenCalled();

		// Save commits ONE call with the final time + temp.
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onReschedule).toHaveBeenCalledTimes(1));
		const [jobId, iso, temp] = onReschedule.mock.calls[0];
		expect(jobId).toBe('job-1');
		expect(temp).toBe(105.5);
		expect(new Date(iso as string).getTime()).toBe(
			new Date('2026-06-26T18:15:00-07:00').getTime()
		);
	});

	it('Discard reverts the draft without calling onReschedule', async () => {
		const onReschedule = vi.fn().mockResolvedValue(undefined);
		render(EventCard, { props: { job: oneOffJob(), canAdjust: true, onReschedule } });

		await fireEvent.click(screen.getByRole('button', { name: 'half a degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105.5');

		await fireEvent.click(screen.getByTestId('event-oneoff-discard'));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('105');
		expect(screen.getByTestId('event-oneoff-temp').textContent).not.toContain('105.5');
		expect(onReschedule).not.toHaveBeenCalled();
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();
	});
});

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

describe('EventCard recurring staging (card parity)', () => {
	beforeEach(() => vi.clearAllMocks());

	it('renders the same ± steppers for a recurring heat event and stages locally', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: { job: recurringJob(), canAdjust: true, onRescheduleRecurring }
		});

		// Clean: steppers present, Skip next visible, no Save yet, no legacy Edit temp.
		expect(screen.getByRole('button', { name: '15 minutes later' })).toBeTruthy();
		expect(screen.getByTestId('event-skip')).toBeTruthy();
		expect(screen.queryByTestId('event-oneoff-save')).toBeNull();
		expect(screen.queryByTestId('event-edit-temp')).toBeNull();

		// Nudge time + temp: draft updates, nothing hits the backend.
		await fireEvent.click(screen.getByRole('button', { name: '15 minutes later' }));
		await fireEvent.click(screen.getByRole('button', { name: 'half a degree warmer' }));
		expect(screen.getByTestId('event-oneoff-temp').textContent).toContain('102.5');
		expect(onRescheduleRecurring).not.toHaveBeenCalled();

		// Save commits ONE call with the new daily HH:MM wall clock + temp.
		await fireEvent.click(screen.getByTestId('event-oneoff-save'));
		await waitFor(() => expect(onRescheduleRecurring).toHaveBeenCalledTimes(1));
		expect(onRescheduleRecurring).toHaveBeenCalledWith('rec-1', '06:45', 102.5);
	});

	it('time-only save leaves the temp argument undefined', async () => {
		const onRescheduleRecurring = vi.fn().mockResolvedValue(undefined);
		render(EventCard, {
			props: { job: recurringJob(), canAdjust: true, onRescheduleRecurring }
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
				job: recurringJob({
					scheduledTime: '03:35', // cron wake-up
					params: { target_temp_f: 102, ready_by_time: '06:30' }
				}),
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
		render(EventCard, {
			props: {
				job: recurringJob({ skipped: true, skipDate: '2026-06-27', resumeDate: '2026-06-28' }),
				canAdjust: true,
				onRescheduleRecurring
			}
		});

		expect(screen.getByTestId('event-unskip')).toBeTruthy();
		expect(screen.queryByRole('button', { name: '15 minutes later' })).toBeNull();
	});
});
