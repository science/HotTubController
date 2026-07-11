import { api } from './api';
import { jobClock, type LogicalEvent } from './scheduleUtils';

/**
 * Composed schedule actions over logical events, shared by Home and the Schedule tab.
 *
 * The backend models an "adjust just the next run" override as a skipped recurring
 * parent + a one-off, and its skip/cancel endpoints don't cascade to that one-off —
 * so acting on an adjusted event takes two calls. They run SEQUENTIALLY on purpose:
 * every call rewrites the crontab (slow on the host) and concurrent rewrites race.
 *
 * These are pure API compositions; callers own the reload and error toast.
 */

/**
 * Skip the next run. An existing override is dropped first (skipping an adjusted
 * event means "don't run at all", not "run at the adjusted time"); if that cleanup
 * fails the skip still proceeds.
 */
export async function skipEvent(e: LogicalEvent): Promise<void> {
	if (e.overridden) await api.clearOverrideNext(e.key).catch(() => {});
	await api.skipScheduledJob(e.key);
}

/**
 * Remove the event. An existing override is cleared first — cancelling the parent
 * alone would orphan the override one-off, which would then fire (and render) as a
 * ghost event. A failed clear aborts the removal.
 */
export async function cancelEvent(e: LogicalEvent): Promise<void> {
	if (e.overridden) await api.clearOverrideNext(e.key);
	await api.cancelScheduledJob(e.key);
}

/**
 * Promote an override into the everyday default: reschedule the recurring parent to
 * the override's wall clock + temp, then clear the override. Promote FIRST — if the
 * clear fails, the leftover override fires at the same time/temp and Reset-to-daily
 * recovers; the reverse order could lose the user's adjustment.
 */
export async function makePermanentEvent(e: LogicalEvent): Promise<void> {
	const override = e.overrideJob;
	if (!override) return;
	await api.rescheduleRecurring(e.key, jobClock(override), override.params?.target_temp_f);
	await api.clearOverrideNext(e.key);
}
