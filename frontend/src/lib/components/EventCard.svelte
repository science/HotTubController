<script lang="ts">
	import { onDestroy } from 'svelte';
	import { type ScheduledJob } from '$lib/api';
	import { getDynamicMode } from '$lib/stores/heatTargetSettings.svelte';
	import { getNextOccurrence, formatNextFire } from '$lib/scheduleUtils';
	import { registerPendingEdit, clearPendingEdit } from '$lib/stores/pendingEdits.svelte';

	/**
	 * A single scheduled heating event, rendered as a card (not a cramped row).
	 *
	 * Shared by the Schedule tab (full controls) and Home's Next-up zone (compact).
	 * Display is for everyone; the adjust controls only render when `canAdjust` is
	 * true (User/Owner — Guests get no scheduling).
	 */
	interface Props {
		job: ScheduledJob;
		canAdjust?: boolean;
		compact?: boolean;
		onSkip?: (jobId: string) => void;
		onUnskip?: (jobId: string) => void;
		onCancel?: (jobId: string) => void;
		onSaveTemp?: (jobId: string, tempF: number) => Promise<void> | void;
		onReschedule?: (jobId: string, scheduledTime: string, tempF?: number) => Promise<void> | void;
	}
	let {
		job,
		canAdjust = false,
		compact = false,
		onSkip,
		onUnskip,
		onCancel,
		onSaveTemp,
		onReschedule
	}: Props = $props();

	const ACTION_LABELS: Record<string, string> = {
		'heater-on': 'Heater on',
		'heater-off': 'Heater off',
		'pump-run': 'Run pump'
	};

	const isHeatToTarget = $derived(job.action === 'heat-to-target');

	function formatTemp(t: number): string {
		return Number.isInteger(t) ? `${t}` : t.toFixed(2).replace(/\.?0+$/, '');
	}

	// "Heat to 102.25°F" (or "~102°F" in dynamic mode); else the plain action name.
	const title = $derived.by(() => {
		if (isHeatToTarget && job.params?.target_temp_f != null) {
			const tilde = getDynamicMode() ? '~' : '';
			return `Heat to ${tilde}${formatTemp(job.params.target_temp_f)}°F`;
		}
		return ACTION_LABELS[job.action] ?? job.action;
	});

	// "ready by" when the recurring parent is in ready-by mode, else "start"/one-off.
	const whenVerb = $derived(job.params?.ready_by_time ? 'ready by' : job.recurring ? 'start' : '');

	// One when-line, no repeats: "Daily · start 6:30 AM · next Tomorrow" for recurring,
	// "One-time · Tomorrow 3:00 PM" for one-offs. (An earlier cut said the same time three
	// times per card.)
	const nextOcc = $derived(getNextOccurrence(job));
	const nextFire = $derived(formatNextFire(nextOcc));
	const clockStr = $derived(
		nextOcc.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
	);
	const nextDay = $derived(nextFire.slice(0, nextFire.length - clockStr.length).trim());
	const whenLine = $derived(
		job.recurring ? `Daily · ${whenVerb} ${clockStr} · next ${nextDay}` : `One-time · ${nextFire}`
	);

	function resumeLabel(iso?: string): string {
		if (!iso) return '';
		// Use the date part — skip/resume timestamps carry a misleading +00:00 offset
		// (the date is the local calendar date), so `new Date(iso)` would land a day early.
		const [y, mo, d] = iso.slice(0, 10).split('-').map(Number);
		return new Date(y, mo - 1, d).toLocaleDateString(undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric'
		});
	}

	// Inline temp editing (Schedule-tab "edit the everyday default").
	let editing = $state(false);
	let tempValue = $state('');
	let tempError = $state<string | null>(null);
	let saving = $state(false);

	function startEdit() {
		tempValue = String(job.params?.target_temp_f ?? '');
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
			await onSaveTemp?.(job.jobId, t);
			editing = false;
		} catch (e) {
			tempError = e instanceof Error ? e.message : 'Failed to save';
		} finally {
			saving = false;
		}
	}
	function onTempKeydown(event: KeyboardEvent) {
		if (event.key === 'Enter') {
			event.preventDefault();
			saveTemp();
		} else if (event.key === 'Escape') {
			event.preventDefault();
			cancelEdit();
		}
	}

	// One-off heat-to-target quick adjust: ± time / ± temp staged locally, then committed as
	// ONE in-place reschedule (no skip). We never auto-send each tap — a reschedule rewrites
	// the crontab (slow on the host) — so taps mutate a draft and Save commits it once.
	const oneOffAdjustable = $derived(
		isHeatToTarget && !job.recurring && !getDynamicMode() && !!onReschedule
	);

	const TEMP_MIN = 80;
	const TEMP_MAX = 110;
	// Stage edits as offsets from server state — 0/0 is clean. Holding offsets (not absolute
	// values seeded from the prop) means the draft always tracks the live job and resets to
	// clean simply by zeroing, with no risk of capturing a stale initial prop value.
	let timeOffsetMin = $state(0);
	let tempOffset = $state(0);
	const dirty = $derived(timeOffsetMin !== 0 || tempOffset !== 0);

	const draftIso = $derived(
		new Date(new Date(job.scheduledTime).getTime() + timeOffsetMin * 60_000).toISOString()
	);
	const draftTemp = $derived(
		job.params?.target_temp_f != null
			? Math.min(TEMP_MAX, Math.max(TEMP_MIN, job.params.target_temp_f + tempOffset))
			: null
	);
	const oneOffClock = $derived(
		new Date(draftIso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
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
			const tempArg = tempOffset !== 0 && draftTemp != null ? draftTemp : undefined;
			await onReschedule?.(job.jobId, draftIso, tempArg);
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
		const parts = [oneOffClock];
		if (draftTemp != null) parts.push(`${formatTemp(draftTemp)}°F`);
		return `${title} → ${parts.join(' · ')}`;
	}
	$effect(() => {
		const id = `card:${job.jobId}`;
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
	onDestroy(() => clearPendingEdit(`card:${job.jobId}`));
</script>

<div
	data-testid="event-card"
	data-job-id={job.jobId}
	class="rounded-xl border p-3 {job.skipped
		? 'border-amber-700/40 bg-amber-900/20'
		: 'border-slate-700 bg-slate-800/50'}"
>
	<div class="flex items-start justify-between gap-3">
		<div class="min-w-0">
			<div class="flex items-center gap-1.5">
				<p class="truncate font-semibold text-slate-100" data-testid="event-title">{title}</p>
				{#if job.recurring}
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
			{#if job.skipped}
				<p class="mt-0.5 text-xs text-amber-300" data-testid="event-skip-state">
					skips {resumeLabel(job.skipDate)} · resumes {resumeLabel(job.resumeDate)}
				</p>
			{/if}
		</div>

		<!-- Badges mark exceptions only — every card saying "active" says nothing. -->
		{#if !compact && job.skipped}
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
		{:else if oneOffAdjustable}
			<!-- One-time heat-to-target: quick ± time / temp adjust (no skip). -->
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
					<span class="text-xs text-slate-400" data-testid="event-oneoff-time">{oneOffClock}</span>
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
						aria-label="half a degree cooler"
						onclick={() => nudge(0, -0.5)}
						disabled={rescheduling}
						class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
						>&minus;</button
					>
					<span class="text-xs text-slate-400" data-testid="event-oneoff-temp"
						>{formatTemp(draftTemp ?? 0)}°F</span
					>
					<button
						type="button"
						aria-label="half a degree warmer"
						onclick={() => nudge(0, 0.5)}
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
				{/if}
				<button
					type="button"
					onclick={() => onCancel?.(job.jobId)}
					data-testid="event-cancel"
					class="ml-auto rounded-lg px-3 py-1 text-slate-400 hover:bg-red-500/10 hover:text-red-300"
					>Remove</button
				>
			</div>
		{:else}
			<div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
				{#if job.recurring}
					{#if job.skipped}
						<button
							type="button"
							onclick={() => onUnskip?.(job.jobId)}
							data-testid="event-unskip"
							class="rounded-lg border border-amber-600/50 px-3 py-1 text-amber-300 hover:bg-amber-600/10"
							>Unskip</button
						>
					{:else}
						<button
							type="button"
							onclick={() => onSkip?.(job.jobId)}
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
					onclick={() => onCancel?.(job.jobId)}
					data-testid="event-cancel"
					class="ml-auto rounded-lg px-3 py-1 text-slate-400 hover:bg-red-500/10 hover:text-red-300"
					>Remove</button
				>
			</div>
		{/if}
	{/if}
</div>
