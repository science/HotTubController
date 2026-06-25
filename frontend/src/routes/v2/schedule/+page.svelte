<script lang="ts">
	import { onMount } from 'svelte';
	import { api, type ScheduledJob } from '$lib/api';
	import { canSchedule } from '$lib/roles';
	import { getNextOccurrence, getTimezoneOffset } from '$lib/scheduleUtils';
	import { fetchStatus } from '$lib/stores/equipmentStatus.svelte';
	import {
		getTargetTempF,
		getScheduleMode,
		getTimezone as getConfiguredTimezone
	} from '$lib/stores/heatTargetSettings.svelte';
	import EventCard from '$lib/components/EventCard.svelte';

	let { data } = $props();
	const allowed = $derived(canSchedule(data.user?.role));

	let jobs = $state<ScheduledJob[]>([]);
	let error = $state<string | null>(null);

	// Single list, "which runs next?" first — a recurring event appears once.
	const sortedJobs = $derived(
		[...jobs].sort((a, b) => getNextOccurrence(a).getTime() - getNextOccurrence(b).getTime())
	);

	async function loadJobs() {
		try {
			const response = await api.listScheduledJobs();
			jobs = response.jobs;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load schedule';
		}
	}

	onMount(() => {
		fetchStatus(); // hydrate heat-target settings (target default, timezone, mode)
		loadJobs();
	});

	async function handleSkip(jobId: string) {
		try {
			await api.skipScheduledJob(jobId);
			await loadJobs();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to skip';
		}
	}
	async function handleUnskip(jobId: string) {
		try {
			await api.unskipScheduledJob(jobId);
			await loadJobs();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to unskip';
		}
	}
	async function handleCancel(jobId: string) {
		try {
			await api.cancelScheduledJob(jobId);
			await loadJobs();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to cancel';
		}
	}
	async function handleSaveTemp(jobId: string, tempF: number) {
		const updated = await api.updateScheduledJobTemp(jobId, tempF);
		jobs = jobs.map((j) =>
			j.jobId === jobId
				? { ...j, params: { ...j.params, target_temp_f: updated.params?.target_temp_f ?? tempF } }
				: j
		);
	}

	// ---- Add-heating sheet ----
	let showAdd = $state(false);
	let addAction = $state<'heat-to-target' | 'pump-run'>('heat-to-target');
	let addTemp = $state(103);
	let addTime = $state('06:30');
	let addRecurring = $state(true);
	let addDate = $state('');
	let adding = $state(false);
	let addError = $state<string | null>(null);

	const whenVerb = $derived(getScheduleMode() === 'ready_by' ? 'Ready by' : 'Start at');

	function openAdd() {
		addAction = 'heat-to-target';
		addTemp = getTargetTempF();
		addTime = '06:30';
		addRecurring = true;
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		addDate = tomorrow.toISOString().split('T')[0];
		addError = null;
		showAdd = true;
	}

	async function submitAdd() {
		if (!addTime) {
			addError = 'Pick a time';
			return;
		}
		if (!addRecurring && !addDate) {
			addError = 'Pick a date';
			return;
		}
		adding = true;
		addError = null;
		try {
			const params = addAction === 'heat-to-target' ? { target_temp_f: addTemp } : undefined;
			if (addRecurring) {
				await api.scheduleJob(addAction, addTime, true, params, getConfiguredTimezone());
			} else {
				const dt = `${addDate}T${addTime}:00${getTimezoneOffset()}`;
				await api.scheduleJob(addAction, dt, false, params);
			}
			await loadJobs();
			showAdd = false;
		} catch (e) {
			addError = e instanceof Error ? e.message : 'Failed to add';
		} finally {
			adding = false;
		}
	}
</script>

<section data-testid="v2-schedule" class="flex flex-col gap-3">
	<div class="flex items-center justify-between">
		<h2 class="text-sm uppercase tracking-wide text-slate-300">Schedule</h2>
		{#if allowed}
			<button
				type="button"
				onclick={openAdd}
				data-testid="schedule-add"
				class="rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-orange-500"
				>+ Add</button
			>
		{/if}
	</div>

	{#if !allowed}
		<p class="text-sm text-slate-500">Scheduling isn't available for your role.</p>
	{:else}
		{#if error}<p class="text-sm text-red-400">{error}</p>{/if}

		{#if showAdd}
			<div
				data-testid="schedule-add-sheet"
				class="flex flex-col gap-3 rounded-xl border border-slate-700 bg-slate-800/50 p-4"
			>
				<div class="grid grid-cols-2 gap-2">
					<button
						type="button"
						onclick={() => (addAction = 'heat-to-target')}
						class="rounded-lg border px-3 py-2 text-sm {addAction === 'heat-to-target'
							? 'border-orange-500 bg-orange-500/10 text-orange-300'
							: 'border-slate-600 text-slate-300'}">Heat to target</button
					>
					<button
						type="button"
						onclick={() => (addAction = 'pump-run')}
						class="rounded-lg border px-3 py-2 text-sm {addAction === 'pump-run'
							? 'border-cyan-500 bg-cyan-500/10 text-cyan-300'
							: 'border-slate-600 text-slate-300'}">Pump cycle</button
					>
				</div>

				{#if addAction === 'heat-to-target'}
					<label class="flex items-center justify-between text-sm text-slate-300">
						Heat to
						<span class="flex items-center gap-1">
							<input
								type="number"
								step="0.25"
								min="80"
								max="110"
								bind:value={addTemp}
								data-testid="add-temp"
								class="w-24 rounded-lg border border-slate-600 bg-slate-700 px-2 py-1 text-right text-slate-100 focus:outline-none focus:ring-2 focus:ring-orange-500"
							/>°F
						</span>
					</label>
				{/if}

				<label class="flex items-center justify-between text-sm text-slate-300">
					{whenVerb}
					<input
						type="time"
						bind:value={addTime}
						data-testid="add-time"
						class="rounded-lg border border-slate-600 bg-slate-700 px-2 py-1 text-slate-100 focus:outline-none focus:ring-2 focus:ring-orange-500"
					/>
				</label>

				<div class="flex items-center gap-2">
					<button
						type="button"
						onclick={() => (addRecurring = true)}
						class="flex-1 rounded-lg border px-3 py-1.5 text-sm {addRecurring
							? 'border-orange-500 bg-orange-500/10 text-orange-300'
							: 'border-slate-600 text-slate-300'}">Daily</button
					>
					<button
						type="button"
						onclick={() => (addRecurring = false)}
						class="flex-1 rounded-lg border px-3 py-1.5 text-sm {!addRecurring
							? 'border-orange-500 bg-orange-500/10 text-orange-300'
							: 'border-slate-600 text-slate-300'}">Once</button
					>
				</div>

				{#if !addRecurring}
					<label class="flex items-center justify-between text-sm text-slate-300">
						On
						<input
							type="date"
							bind:value={addDate}
							data-testid="add-date"
							class="rounded-lg border border-slate-600 bg-slate-700 px-2 py-1 text-slate-100 focus:outline-none focus:ring-2 focus:ring-orange-500"
						/>
					</label>
				{/if}

				{#if addError}<p class="text-sm text-red-400">{addError}</p>{/if}

				<div class="flex justify-end gap-2">
					<button
						type="button"
						onclick={() => (showAdd = false)}
						class="rounded-lg px-3 py-1.5 text-sm text-slate-400 hover:text-slate-200">Cancel</button
					>
					<button
						type="button"
						onclick={submitAdd}
						disabled={adding}
						data-testid="add-submit"
						class="rounded-lg bg-orange-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-orange-500 disabled:opacity-50"
						>Add</button
					>
				</div>
			</div>
		{/if}

		{#if sortedJobs.length === 0}
			<p class="text-sm text-slate-500" data-testid="schedule-empty">
				No heating scheduled. Add a time to heat automatically.
			</p>
		{:else}
			<div class="flex flex-col gap-2">
				{#each sortedJobs as job (job.jobId)}
					<EventCard
						{job}
						canAdjust={allowed}
						onSkip={handleSkip}
						onUnskip={handleUnskip}
						onCancel={handleCancel}
						onSaveTemp={handleSaveTemp}
					/>
				{/each}
			</div>
		{/if}
	{/if}
</section>
