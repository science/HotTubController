<script lang="ts">
	import { type ScheduledJob } from '$lib/api';
	import { getDynamicMode } from '$lib/stores/heatTargetSettings.svelte';
	import { getNextOccurrence, formatNextFire } from '$lib/scheduleUtils';

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
	}
	let {
		job,
		canAdjust = false,
		compact = false,
		onSkip,
		onUnskip,
		onCancel,
		onSaveTemp
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

	const nextFire = $derived(formatNextFire(getNextOccurrence(job)));
	const cadence = $derived(job.recurring ? 'every day' : 'one-time');

	function resumeLabel(iso?: string): string {
		if (!iso) return '';
		return new Date(iso).toLocaleDateString(undefined, {
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
			<p class="truncate font-semibold text-slate-100" data-testid="event-title">{title}</p>
			<p class="text-sm text-slate-400">
				{#if whenVerb}{whenVerb} {/if}{nextFire.replace(/^Today |^Tomorrow /, '')}
			</p>
			<p class="mt-0.5 text-xs text-slate-500">
				{cadence}
				<span aria-hidden="true">·</span>
				{#if job.skipped}
					<span class="text-amber-300" data-testid="event-skip-state"
						>skipped — resumes {resumeLabel(job.resumeDate)}</span
					>
				{:else}
					next {nextFire}
				{/if}
			</p>
		</div>

		{#if !compact}
			<span
				class="shrink-0 rounded-full px-2 py-0.5 text-xs {job.skipped
					? 'bg-amber-500/15 text-amber-300'
					: 'bg-emerald-500/15 text-emerald-300'}"
			>
				{job.skipped ? 'skipped' : 'active'}
			</span>
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
					class="ml-auto rounded-lg px-3 py-1 text-slate-400 hover:text-red-300">Cancel</button
				>
			</div>
		{/if}
	{/if}
</div>
