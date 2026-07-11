import { describe, it, expect, vi, beforeEach } from 'vitest';
import { skipEvent, cancelEvent, makePermanentEvent } from './scheduleActions';
import { foldScheduledEvents, type LogicalEvent } from './scheduleUtils';
import { api, type ScheduledJob } from './api';

vi.mock('./api', () => ({
	api: {
		clearOverrideNext: vi.fn(),
		skipScheduledJob: vi.fn(),
		cancelScheduledJob: vi.fn(),
		rescheduleRecurring: vi.fn()
	}
}));

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

// Local-parts instant so the derived wall clock is timezone-independent.
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

function toEvent(...jobs: ScheduledJob[]): LogicalEvent {
	const events = foldScheduledEvents(jobs);
	expect(events).toHaveLength(1);
	return events[0];
}

const plainEvent = () => toEvent(recurringJob());
const overriddenEvent = () => toEvent(recurringJob(), overrideJob());

function callOrder(fn: ReturnType<typeof vi.fn>): number {
	return fn.mock.invocationCallOrder[0];
}

describe('skipEvent', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.clearOverrideNext).mockResolvedValue(undefined as never);
		vi.mocked(api.skipScheduledJob).mockResolvedValue(undefined as never);
	});

	it('skips a plain recurring event without touching overrides', async () => {
		await skipEvent(plainEvent());
		expect(api.skipScheduledJob).toHaveBeenCalledWith('rec-1');
		expect(api.clearOverrideNext).not.toHaveBeenCalled();
	});

	it('drops the override before skipping an adjusted event', async () => {
		await skipEvent(overriddenEvent());
		expect(api.clearOverrideNext).toHaveBeenCalledWith('rec-1');
		expect(api.skipScheduledJob).toHaveBeenCalledWith('rec-1');
		expect(callOrder(vi.mocked(api.clearOverrideNext))).toBeLessThan(
			callOrder(vi.mocked(api.skipScheduledJob))
		);
	});

	it('still skips when clearing the override fails', async () => {
		vi.mocked(api.clearOverrideNext).mockRejectedValue(new Error('boom'));
		await skipEvent(overriddenEvent());
		expect(api.skipScheduledJob).toHaveBeenCalledWith('rec-1');
	});
});

describe('cancelEvent', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.clearOverrideNext).mockResolvedValue(undefined as never);
		vi.mocked(api.cancelScheduledJob).mockResolvedValue(undefined as never);
	});

	it('cancels a plain event directly', async () => {
		await cancelEvent(plainEvent());
		expect(api.cancelScheduledJob).toHaveBeenCalledWith('rec-1');
		expect(api.clearOverrideNext).not.toHaveBeenCalled();
	});

	it('clears the override before cancelling, so no ghost one-off survives', async () => {
		await cancelEvent(overriddenEvent());
		expect(api.clearOverrideNext).toHaveBeenCalledWith('rec-1');
		expect(api.cancelScheduledJob).toHaveBeenCalledWith('rec-1');
		expect(callOrder(vi.mocked(api.clearOverrideNext))).toBeLessThan(
			callOrder(vi.mocked(api.cancelScheduledJob))
		);
	});

	it('does not cancel when clearing the override fails', async () => {
		vi.mocked(api.clearOverrideNext).mockRejectedValue(new Error('boom'));
		await expect(cancelEvent(overriddenEvent())).rejects.toThrow('boom');
		expect(api.cancelScheduledJob).not.toHaveBeenCalled();
	});
});

describe('makePermanentEvent', () => {
	beforeEach(() => {
		vi.clearAllMocks();
		vi.mocked(api.rescheduleRecurring).mockResolvedValue(undefined as never);
		vi.mocked(api.clearOverrideNext).mockResolvedValue(undefined as never);
	});

	it("promotes the override's local wall clock and temp to the daily default, then clears", async () => {
		await makePermanentEvent(overriddenEvent());
		expect(api.rescheduleRecurring).toHaveBeenCalledWith('rec-1', '07:10', 102.25);
		expect(api.clearOverrideNext).toHaveBeenCalledWith('rec-1');
		expect(callOrder(vi.mocked(api.rescheduleRecurring))).toBeLessThan(
			callOrder(vi.mocked(api.clearOverrideNext))
		);
	});

	it("prefers the override's ready-by time when present", async () => {
		const e = toEvent(
			recurringJob(),
			overrideJob({ params: { target_temp_f: 102.25, ready_by_time: '07:30', override_of: 'rec-1' } })
		);
		await makePermanentEvent(e);
		expect(api.rescheduleRecurring).toHaveBeenCalledWith('rec-1', '07:30', 102.25);
	});

	it('does not clear the override when the promotion fails — Reset-to-daily can still recover', async () => {
		vi.mocked(api.rescheduleRecurring).mockRejectedValue(new Error('boom'));
		await expect(makePermanentEvent(overriddenEvent())).rejects.toThrow('boom');
		expect(api.clearOverrideNext).not.toHaveBeenCalled();
	});

	it('is a no-op for an event without an override', async () => {
		await makePermanentEvent(plainEvent());
		expect(api.rescheduleRecurring).not.toHaveBeenCalled();
		expect(api.clearOverrideNext).not.toHaveBeenCalled();
	});
});
