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
