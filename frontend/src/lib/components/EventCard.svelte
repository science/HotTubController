<script lang="ts">
	import { onDestroy } from 'svelte';
	import { getDynamicMode } from '$lib/stores/heatTargetSettings.svelte';
	import {
		formatNextFire,
		formatTemp,
		formatClockHHMM,
		shiftHHMM,
		jobTitle,
		jobClock,
		resumeLabel,
		baseSummary,
		type LogicalEvent
	} from '$lib/scheduleUtils';
	import { registerPendingEdit, clearPendingEdit } from '$lib/stores/pendingEdits.svelte';

	/**
	 * A single logical scheduled event, rendered as a card carrying its own controls —
	 * the ONE schedule-adjustment paradigm, shared by the Schedule tab and Home's
	 * Next-up zone (review F8).
	 *
	 * Display is for everyone; the adjust controls only render when `canAdjust` is
	 * true (User/Owner — Guests get no scheduling). What Save means for a recurring
	 * event is chosen by which callback the page passes:
	 *  - `onOverrideNext` (Home): adjust just the next run — the daily default stays.
	 *  - `onRescheduleRecurring` (Schedule tab): change the everyday default.
	 * An overridden ("adjusted") card additionally offers Reset to daily
	 * (`onClearOverride`) and Make permanent (`onMakePermanent`).
	 */
	interface Props {
		event: LogicalEvent;
		canAdjust?: boolean;
		onSkip?: (event: LogicalEvent) => void;
		onUnskip?: (event: LogicalEvent) => void;
		onCancel?: (event: LogicalEvent) => void;
		onSaveTemp?: (jobId: string, tempF: number) => Promise<void> | void;
		onReschedule?: (jobId: string, scheduledTime: string, tempF?: number) => Promise<void> | void;
		onRescheduleRecurring?: (jobId: string, time: string, tempF?: number) => Promise<void> | void;
		onOverrideNext?: (jobId: string, time: string, tempF: number) => Promise<void> | void;
		onClearOverride?: (event: LogicalEvent) => void;
		onMakePermanent?: (event: LogicalEvent) => void;
	}
	let {
		event,
		canAdjust = false,
		onSkip,
		onUnskip,
		onCancel,
		onSaveTemp,
		onReschedule,
		onRescheduleRecurring,
		onOverrideNext,
		onClearOverride,
		onMakePermanent
	}: Props = $props();

	// Adjustability is a property of the underlying schedule (the base job); the title
	// and next-fire line describe the *effective* next run (the override when present).
	const isHeatToTarget = $derived(event.baseJob.action === 'heat-to-target');
	const title = $derived(jobTitle(event.job, getDynamicMode()));

	// "ready by" when the recurring parent is in ready-by mode, else "start"/one-off.
	const whenVerb = $derived(
		event.baseJob.params?.ready_by_time ? 'ready by' : event.recurring ? 'start' : ''
	);

	// One when-line, no repeats: "Daily · start 6:30 AM · next Tomorrow" for recurring,
	// "One-time · Tomorrow 3:00 PM" for one-offs. For an adjusted card the clock is the
	// effective next fire (the daily default lives on the resets-line below).
	const nextFire = $derived(formatNextFire(event.nextFire));
	const clockStr = $derived(
		event.nextFire.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
	);
	const nextDay = $derived(nextFire.slice(0, nextFire.length - clockStr.length).trim());
	const whenLine = $derived(
		event.recurring ? `Daily · ${whenVerb} ${clockStr} · next ${nextDay}` : `One-time · ${nextFire}`
	);

	// Inline temp editing (the "edit the everyday default" path for cards without
	// steppers, e.g. a skipped heat card) — always targets the base job.
	let editing = $state(false);
	let tempValue = $state('');
	let tempError = $state<string | null>(null);
	let saving = $state(false);

	function startEdit() {
		tempValue = String(event.baseJob.params?.target_temp_f ?? '');
		tempError = null;
		editing = true;
	}
	function cancelEdit() {
		editing = false;
		tempError = null;
	}
	async function saveTemp() {
		const t = Number(tempValue);
		if (Number.isNaN(t) || t < 80 || t > 110) {
			tempError = 'Must be 80–110°F';
			return;
		}
		saving = true;
		tempError = null;
		try {
			await onSaveTemp?.(event.baseJob.jobId, t);
			editing = false;
		} catch (e) {
			tempError = e instanceof Error ? e.message : 'Failed to save';
		} finally {
			saving = false;
		}
	}
	function onTempKeydown(keyEvent: KeyboardEvent) {
		if (keyEvent.key === 'Enter') {
			keyEvent.preventDefault();
			saveTemp();
		} else if (keyEvent.key === 'Escape') {
			keyEvent.preventDefault();
			cancelEdit();
		}
	}

	// Quick ± time / ± temp adjust, staged locally and committed as ONE backend call.
	// We never auto-send each tap — every commit rewrites the crontab (slow on the
	// host) — so taps mutate a draft and Save commits it once. One-off and recurring
	// heat events render the SAME steppers (card parity); they differ only in what
	// Save means: move the instant, override the next run, or change the daily default.
	const overrideMode = $derived(!!onOverrideNext);
	const oneOffAdjustable = $derived(
		isHeatToTarget && !event.recurring && !getDynamicMode() && !!onReschedule
	);
	const recurringAdjustable = $derived(
		isHeatToTarget &&
			event.recurring &&
			!event.skipped &&
			!getDynamicMode() &&
			(overrideMode || !!onRescheduleRecurring)
	);
	const stepperAdjustable = $derived(oneOffAdjustable || recurringAdjustable);

	// Override mode edits the *effective* next run (the override one-off when present);
	// permanent mode edits the everyday default (the base job).
	const stepperJob = $derived(event.recurring && !overrideMode ? event.baseJob : event.job);

	const TEMP_MIN = 80;
	const TEMP_MAX = 110;
	// Stage edits as offsets from server state — 0/0 is clean. Holding offsets (not absolute
	// values seeded from the prop) means the draft always tracks the live job and resets to
	// clean simply by zeroing, with no risk of capturing a stale initial prop value.
	let timeOffsetMin = $state(0);
	let tempOffset = $state(0);
	const dirty = $derived(timeOffsetMin !== 0 || tempOffset !== 0);

	// One-off: the draft is an instant. Recurring: the draft is a daily HH:MM wall clock,
	// based on the user-facing time (ready-by for DTDT jobs, whose scheduledTime is the
	// earlier cron wake-up; the local clock for an override one-off).
	const draftIso = $derived(
		new Date(new Date(stepperJob.scheduledTime).getTime() + timeOffsetMin * 60_000).toISOString()
	);
	const baseClockHHMM = $derived(jobClock(stepperJob));
	const draftClockHHMM = $derived(
		event.recurring ? shiftHHMM(baseClockHHMM, timeOffsetMin) : null
	);
	const baseTemp = $derived(stepperJob.params?.target_temp_f);
	const draftTemp = $derived(
		baseTemp != null ? Math.min(TEMP_MAX, Math.max(TEMP_MIN, baseTemp + tempOffset)) : null
	);
	const draftClockDisplay = $derived(
		event.recurring && draftClockHHMM
			? formatClockHHMM(draftClockHHMM)
			: new Date(draftIso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
	);
	let rescheduling = $state(false);
	let rescheduleError = $state<string | null>(null);

	function nudge(deltaMin: number, deltaTemp: number) {
		timeOffsetMin += deltaMin;
		tempOffset += deltaTemp;
	}

	async function saveReschedule() {
		if (!dirty || rescheduling) return;
		rescheduling = true;
		rescheduleError = null;
		try {
			if (event.recurring && overrideMode) {
				// override-next requires an explicit temp — send the effective one even untouched.
				await onOverrideNext?.(event.key, draftClockHHMM!, draftTemp ?? baseTemp!);
			} else if (event.recurring) {
				const tempArg = tempOffset !== 0 && draftTemp != null ? draftTemp : undefined;
				await onRescheduleRecurring?.(event.key, draftClockHHMM!, tempArg);
			} else {
				const tempArg = tempOffset !== 0 && draftTemp != null ? draftTemp : undefined;
				await onReschedule?.(event.key, draftIso, tempArg);
			}
			// Committed — server is the new truth; go clean (the reload refreshes the base time).
			timeOffsetMin = 0;
			tempOffset = 0;
		} catch (e) {
			rescheduleError = e instanceof Error ? e.message : 'Failed to reschedule';
			throw e; // let the guard's Save-all halt navigation when a save fails
		} finally {
			rescheduling = false;
		}
	}

	function discardReschedule() {
		timeOffsetMin = 0;
		tempOffset = 0;
		rescheduleError = null;
	}

	// While dirty, register this card so the v2 navigation guard can prompt before the change
	// is lost. The effect tracks `dirty` (a boolean edge), not every tap; the registered
	// closures read live draft state when the guard calls them.
	function describeChange(): string {
		const parts = [draftClockDisplay];
		if (draftTemp != null) parts.push(`${formatTemp(draftTemp)}°F`);
		return `${title} → ${parts.join(' · ')}`;
	}
	$effect(() => {
		const id = `card:${event.key}`;
		if (dirty) {
			registerPendingEdit({
				id,
				describe: describeChange,
				save: saveReschedule,
				discard: discardReschedule
			});
		} else {
			clearPendingEdit(id);
		}
	});
	onDestroy(() => clearPendingEdit(`card:${event.key}`));
</script>

<div
	data-testid="event-card"
	data-key={event.key}
	data-job-id={event.job.jobId}
	class="rounded-xl border p-3 {event.skipped
		? 'border-amber-700/40 bg-amber-900/20'
		: 'border-slate-700 bg-slate-800/50'}"
>
	<div class="flex items-start justify-between gap-3">
		<div class="min-w-0">
			<div class="flex items-center gap-1.5">
				<p class="truncate font-semibold text-slate-100" data-testid="event-title">{title}</p>
				{#if event.recurring}
					<svg
						viewBox="0 0 24 24"
						fill="none"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						stroke-linejoin="round"
						class="h-3.5 w-3.5 shrink-0 text-slate-500"
						role="img"
						aria-label="Repeats daily"
						data-testid="event-recurring-icon"
					>
						<path d="m17 2 4 4-4 4" /><path d="M3 11v-1a4 4 0 0 1 4-4h14" /><path d="m7 22-4-4 4-4" />
						<path d="M21 13v1a4 4 0 0 1-4 4H3" />
					</svg>
				{/if}
			</div>
			<p class="text-sm text-slate-400" data-testid="event-when">{whenLine}</p>
			{#if event.overridden}
				<p class="mt-0.5 text-xs text-orange-300/80" data-testid="event-resets-line">
					resets to {baseSummary(event)} daily
				</p>
			{:else if event.skipped}
				<p class="mt-0.5 text-xs text-amber-300" data-testid="event-skip-state">
					skips {resumeLabel(event.baseJob.skipDate)} · resumes {resumeLabel(event.resumeDate)}
				</p>
			{/if}
		</div>

		<!-- Badges mark exceptions only — every card saying "active" says nothing. -->
		{#if event.overridden}
			<span
				class="shrink-0 rounded-full bg-orange-500/15 px-2 py-0.5 text-xs text-orange-300"
				data-testid="event-adjusted">adjusted</span
			>
		{:else if event.skipped}
			<span class="shrink-0 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs text-amber-300"
				>skipped</span
			>
		{/if}
	</div>

	{#if canAdjust}
		{#if editing}
			<div class="mt-3 flex items-center gap-2">
				<input
					type="number"
					step="0.25"
					min="80"
					max="110"
					bind:value={tempValue}
					onkeydown={onTempKeydown}
					data-testid="event-temp-input"
					class="w-24 rounded-lg border border-slate-600 bg-slate-700 px-2 py-1 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
				/>
				<span class="text-slate-400">°F</span>
				<button
					type="button"
					onclick={saveTemp}
					disabled={saving}
					class="rounded-lg bg-blue-600 px-3 py-1 text-sm text-white hover:bg-blue-500 disabled:opacity-50"
					>Save</button
				>
				<button
					type="button"
					onclick={cancelEdit}
					class="rounded-lg px-2 py-1 text-sm text-slate-400 hover:text-slate-200">Cancel</button
				>
				{#if tempError}<span class="text-xs text-red-400">{tempError}</span>{/if}
			</div>
		{:else if stepperAdjustable}
			<!-- Heat-to-target quick ± time / temp adjust — identical for one-time and
			     recurring (card parity); clean recurring cards add Skip next / override
			     management below. -->
			<div class="mt-3 grid grid-cols-2 gap-2">
				<div class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5">
					<button
						type="button"
						aria-label="15 minutes earlier"
						onclick={() => nudge(-15, 0)}
						disabled={rescheduling}
						class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
						>&minus;</button
					>
					<span class="text-xs text-slate-400" data-testid="event-oneoff-time">{draftClockDisplay}</span>
					<button
						type="button"
						aria-label="15 minutes later"
						onclick={() => nudge(15, 0)}
						disabled={rescheduling}
						class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
						>+</button
					>
				</div>
				<div class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5">
					<button
						type="button"
						aria-label="a quarter degree cooler"
						onclick={() => nudge(0, -0.25)}
						disabled={rescheduling}
						class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
						>&minus;</button
					>
					<span class="text-xs text-slate-400" data-testid="event-oneoff-temp"
						>{formatTemp(draftTemp ?? 0)}°F</span
					>
					<button
						type="button"
						aria-label="a quarter degree warmer"
						onclick={() => nudge(0, 0.25)}
						disabled={rescheduling}
						class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
						>+</button
					>
				</div>
			</div>
			<div class="mt-2 flex items-center gap-3 text-xs">
				{#if rescheduleError}<span class="text-red-400">{rescheduleError}</span>{/if}
				{#if dirty}
					<button
						type="button"
						onclick={discardReschedule}
						disabled={rescheduling}
						data-testid="event-oneoff-discard"
						class="rounded-lg px-2 py-1 text-slate-400 hover:text-slate-200 disabled:opacity-40"
						>Discard</button
					>
					<button
						type="button"
						onclick={saveReschedule}
						disabled={rescheduling}
						data-testid="event-oneoff-save"
						class="rounded-lg bg-orange-600 px-3 py-1 font-medium text-white hover:bg-orange-500 disabled:opacity-50"
						>{rescheduling ? 'Saving…' : 'Save'}</button
					>
				{:else if event.overridden}
					<button
						type="button"
						onclick={() => onClearOverride?.(event)}
						data-testid="event-reset"
						class="rounded-lg border border-slate-600 px-3 py-1 text-slate-300 hover:bg-slate-700"
						>Reset to daily</button
					>
					<button
						type="button"
						onclick={() => onMakePermanent?.(event)}
						data-testid="event-make-permanent"
						class="rounded-lg border border-orange-600/50 px-3 py-1 text-orange-300 hover:bg-orange-600/10"
						>Make permanent</button
					>
				{:else if recurringAdjustable}
					<button
						type="button"
						onclick={() => onSkip?.(event)}
						data-testid="event-skip"
						class="rounded-lg border border-slate-600 px-3 py-1 text-slate-300 hover:bg-slate-700"
						>Skip next</button
					>
				{/if}
				<button
					type="button"
					onclick={() => onCancel?.(event)}
					data-testid="event-cancel"
					class="ml-auto rounded-lg px-3 py-1 text-slate-400 hover:bg-red-500/10 hover:text-red-300"
					>Remove</button
				>
			</div>
		{:else}
			<div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
				{#if event.recurring}
					{#if event.skipped}
						<button
							type="button"
							onclick={() => onUnskip?.(event)}
							data-testid="event-unskip"
							class="rounded-lg border border-amber-600/50 px-3 py-1 text-amber-300 hover:bg-amber-600/10"
							>Unskip</button
						>
					{:else}
						<button
							type="button"
							onclick={() => onSkip?.(event)}
							data-testid="event-skip"
							class="rounded-lg border border-slate-600 px-3 py-1 text-slate-300 hover:bg-slate-700"
							>Skip next</button
						>
					{/if}
				{/if}
				{#if isHeatToTarget && !getDynamicMode()}
					<button
						type="button"
						onclick={startEdit}
						data-testid="event-edit-temp"
						class="rounded-lg border border-slate-600 px-3 py-1 text-slate-300 hover:bg-slate-700"
						>Edit temp</button
					>
				{/if}
				<button
					type="button"
					onclick={() => onCancel?.(event)}
					data-testid="event-cancel"
					class="ml-auto rounded-lg px-3 py-1 text-slate-400 hover:bg-red-500/10 hover:text-red-300"
					>Remove</button
				>
			</div>
		{/if}
	{/if}
</div>
