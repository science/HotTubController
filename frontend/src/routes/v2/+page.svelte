<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import EquipmentStatusBar from '$lib/components/EquipmentStatusBar.svelte';
	import TemperaturePanel from '$lib/components/TemperaturePanel.svelte';
	import EventCard from '$lib/components/EventCard.svelte';
	import { api, type TargetTemperatureState, type ScheduledJob } from '$lib/api';
	import { canControl, canSchedule } from '$lib/roles';
	import { getNextOccurrence } from '$lib/scheduleUtils';
	import {
		fetchStatus,
		getHeaterOn,
		getPumpOn,
		getBlindsEnabled,
		setHeaterOn,
		setHeaterOff,
		setPumpOn
	} from '$lib/stores/equipmentStatus.svelte';
	import {
		getEnabled as getTargetTempEnabled,
		getTargetTempF,
		getHeatButtonLabel,
		getHeatButtonTooltip,
		getLastStallEvent,
		getDynamicMode,
		getMinTempF,
		getMaxTempF,
		setTargetTempF,
		persistDefaultTargetTemp
	} from '$lib/stores/heatTargetSettings.svelte';

	let { data } = $props();

	// Guest/User/Owner can all act on hardware; read-only never reaches the UI.
	// Gate defensively so the control grid is honest about who may act.
	const canAct = $derived(canControl(data.user?.role));

	// The "Heat now" target dial. Shown only when heat-to-target mode is on and not
	// dynamic (an absolute dial is meaningless against an ambient-derived target).
	const TEMP_STEP_F = 0.5;
	let targetEnabled = $derived(getTargetTempEnabled());
	let dynamicMode = $derived(getDynamicMode());
	let showDial = $derived(canAct && targetEnabled && !dynamicMode);
	let targetTempF = $derived(getTargetTempF());
	let targetDisplay = $derived(
		Number.isInteger(targetTempF) ? `${targetTempF}` : targetTempF.toFixed(2).replace(/\.?0+$/, '')
	);
	let persistTimer: ReturnType<typeof setTimeout> | null = null;

	// Re-fetch the projected ETA so the "ready by" line reflects the dialed target.
	// computeProjectedEta() reads the saved default, which persistDefaultTargetTemp just wrote.
	function refreshEta() {
		if (!getTargetTempEnabled()) return;
		api
			.getTargetTempStatus()
			.then((s) => (heatTargetEta = s))
			.catch(() => {});
	}

	// Nudge the target: update locally at once (label/ETA/handleHeatOn all read the store),
	// then debounce-persist the saved default and refresh the ETA.
	function nudgeTarget(deltaF: number) {
		const next = setTargetTempF(getTargetTempF() + deltaF);
		if (persistTimer) clearTimeout(persistTimer);
		persistTimer = setTimeout(() => {
			persistDefaultTargetTemp(next)
				.then(refreshEta)
				.catch(() => {
					status = {
						message: "Couldn't save the target — it still applies to the next heat.",
						type: 'error'
					};
					setTimeout(() => (status = null), 3000);
				});
		}, 500);
	}

	let heaterOn = $derived(getHeaterOn());
	let pumpOn = $derived(getPumpOn());
	let blindsEnabled = $derived(getBlindsEnabled());

	// Upcoming scheduled events — a read-only peek (the next few). Full control lives
	// on the Schedule tab; the "adjust just the next run" controls arrive with override-next.
	let scheduledJobs = $state<ScheduledJob[]>([]);
	const upcoming = $derived(
		[...scheduledJobs]
			.sort((a, b) => getNextOccurrence(a).getTime() - getNextOccurrence(b).getTime())
			.slice(0, 3)
	);

	// "Adjust just the next run" lives on Home for User/Owner (Guests see Next up read-only).
	const canSched = $derived(canSchedule(data.user?.role));
	function parentIdOf(job: ScheduledJob): string | null {
		// The recurring event itself, or — once adjusted — the override one-off pointing back to it.
		return job.params?.override_of ?? (job.recurring ? job.jobId : null);
	}
	const canAdjustNext = $derived(canSched && upcoming.length > 0 && parentIdOf(upcoming[0]) !== null);
	let adjusting = $state(false);

	async function reloadJobs() {
		try {
			const r = await api.listScheduledJobs();
			scheduledJobs = r.jobs;
		} catch {
			/* keep the last list */
		}
	}

	// User-facing HH:MM for an event: ready-by time, else the recurring time, else the
	// one-off's local clock time.
	function eventClockTime(job: ScheduledJob): string {
		if (job.params?.ready_by_time) return job.params.ready_by_time;
		if (job.recurring) return job.scheduledTime;
		const d = new Date(job.scheduledTime);
		return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
	}
	function shiftClock(hhmm: string, deltaMin: number): string {
		const [h, m] = hhmm.split(':').map(Number);
		const total = (h * 60 + m + deltaMin + 1440) % 1440;
		return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
	}

	// Each tap reads the currently-shown next event and replaces its override, so repeated
	// taps compound. Only the next run changes — the everyday default is untouched.
	async function adjustNext(deltaMin: number, deltaTemp: number) {
		const ev = upcoming[0];
		if (!ev || adjusting) return;
		const parentId = parentIdOf(ev);
		if (!parentId) return;
		const newTime = shiftClock(eventClockTime(ev), deltaMin);
		const newTemp = Math.min(110, Math.max(80, (ev.params?.target_temp_f ?? 103) + deltaTemp));
		adjusting = true;
		try {
			await api.overrideNextOccurrence(parentId, newTime, newTemp);
			await reloadJobs();
		} catch {
			status = { message: "Couldn't adjust tomorrow. Try again.", type: 'error' };
			setTimeout(() => (status = null), 3000);
		} finally {
			adjusting = false;
		}
	}

	async function skipTomorrow() {
		const ev = upcoming[0];
		if (!ev || adjusting) return;
		const parentId = parentIdOf(ev);
		if (!parentId) return;
		adjusting = true;
		try {
			await api.clearOverrideNext(parentId).catch(() => {}); // drop any override (also unskips)
			await api.skipScheduledJob(parentId).catch(() => {}); // then skip tomorrow
			await reloadJobs();
		} finally {
			adjusting = false;
		}
	}

	async function resetNext() {
		const ev = upcoming[0];
		if (!ev || adjusting) return;
		const parentId = parentIdOf(ev);
		if (!parentId) return;
		adjusting = true;
		try {
			await api.clearOverrideNext(parentId);
			await reloadJobs();
		} finally {
			adjusting = false;
		}
	}

	let status = $state<{ message: string; type: 'success' | 'error' } | null>(null);
	let stallBannerDismissed = $state(false);
	let lastStallEvent = $derived(stallBannerDismissed ? null : getLastStallEvent());

	// Estimated time to reach the heat-to-target temperature (projected or active).
	let heatTargetEta = $state<TargetTemperatureState | null>(null);
	let etaDisplay = $derived.by(() => {
		if (!heatTargetEta?.eta) return null;
		const eta = heatTargetEta.eta;
		const etaDate = new Date(eta.eta_timestamp);
		const timeStr = etaDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
		return {
			targetTempF: eta.target_temp_f,
			time: timeStr,
			minutesRemaining: Math.round(eta.minutes_remaining),
			projected: eta.projected
		};
	});

	onMount(() => {
		fetchStatus();
		reloadJobs();
	});

	// Poll heat-to-target status while target mode is enabled. Reading heaterOn makes the
	// effect re-fire on heater changes so the ETA flips between projected and active.
	$effect(() => {
		const _heaterState = heaterOn; // tracked dependency
		if (!getTargetTempEnabled()) {
			heatTargetEta = null;
			return;
		}
		api
			.getTargetTempStatus()
			.then((s) => (heatTargetEta = s))
			.catch(() => {});
		const interval = setInterval(() => {
			api
				.getTargetTempStatus()
				.then((s) => (heatTargetEta = s))
				.catch(() => {});
		}, 60_000);
		return () => clearInterval(interval);
	});

	async function handleAction(
		action: () => Promise<unknown>,
		successMsg: string,
		onSuccess?: () => void
	) {
		try {
			await action();
			status = { message: successMsg, type: 'success' };
			onSuccess?.();
		} catch (e) {
			if (e instanceof Error && e.message === 'Unauthorized') {
				goto(`${base}/login`);
				return;
			}
			status = { message: 'Action failed. Try again.', type: 'error' };
		}
		setTimeout(() => (status = null), 3000);
	}

	async function handleHeatOn() {
		if (getTargetTempEnabled()) {
			const targetTempF = getTargetTempF();
			await handleAction(
				() => api.heatToTarget(targetTempF),
				`Heating to ${targetTempF}°F`,
				setHeaterOn
			);
		} else {
			await handleAction(api.heaterOn, 'Heater on', setHeaterOn);
		}
	}
</script>

<section data-testid="v2-home" class="flex flex-col gap-3">
	<!-- Temperature hero -->
	<TemperaturePanel />

	<!-- Primary controls: heat now / off / pump -->
	{#if canAct}
		<div class="grid grid-cols-3 gap-2">
			<CompactControlButton
				label={getHeatButtonLabel()}
				icon="flame"
				variant="primary"
				tooltip={getHeatButtonTooltip()}
				active={heaterOn}
				onClick={handleHeatOn}
			/>
			<CompactControlButton
				label="Heat/Pump Off"
				icon="flame-off"
				variant="secondary"
				tooltip="Turn off the heater and pump"
				active={!heaterOn}
				onClick={() => handleAction(api.heaterOff, 'Heater and pump off', setHeaterOff)}
			/>
			<CompactControlButton
				label="Pump (2h)"
				icon="refresh"
				variant="tertiary"
				tooltip="Run the circulation pump for 2 hours"
				active={pumpOn}
				onClick={() => handleAction(api.pumpRun, 'Pump running for 2 hours', setPumpOn)}
			/>
		</div>
	{/if}

	<!-- Heat-now target dial (heat-to-target mode, non-dynamic) -->
	{#if showDial}
		<div class="flex items-center justify-center gap-3" data-testid="target-dial">
			<span class="text-slate-400 text-sm">Target</span>
			<button
				type="button"
				aria-label="Lower target temperature"
				onclick={() => nudgeTarget(-TEMP_STEP_F)}
				disabled={targetTempF <= getMinTempF()}
				class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800 text-xl leading-none text-slate-200 transition-colors hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800"
			>
				&minus;
			</button>
			<span
				class="min-w-[5.5rem] text-center text-lg font-semibold tabular-nums text-slate-100"
				data-testid="target-value"
			>
				{targetDisplay}&deg;F
			</span>
			<button
				type="button"
				aria-label="Raise target temperature"
				onclick={() => nudgeTarget(TEMP_STEP_F)}
				disabled={targetTempF >= getMaxTempF()}
				class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800 text-xl leading-none text-slate-200 transition-colors hover:bg-slate-700 disabled:opacity-40 disabled:hover:bg-slate-800"
			>
				+
			</button>
		</div>
	{/if}

	<!-- ETA to target temperature -->
	{#if etaDisplay}
		{#if etaDisplay.projected}
			<div class="text-center text-sm text-slate-400" data-testid="eta-display">
				Heat now &rarr; {etaDisplay.targetTempF}°F by {etaDisplay.time}
				<span class="text-slate-500 text-xs">({etaDisplay.minutesRemaining} min)</span>
			</div>
		{:else}
			<div class="text-center text-sm text-orange-400/80" data-testid="eta-display">
				Target {etaDisplay.targetTempF}°F by {etaDisplay.time}
				<span class="text-slate-500 text-xs">({etaDisplay.minutesRemaining} min)</span>
			</div>
		{/if}
	{/if}

	<!-- Dining room blinds (optional feature) -->
	{#if blindsEnabled && canAct}
		<div class="grid grid-cols-2 gap-2">
			<CompactControlButton
				label="Blinds Up"
				icon="blinds-open"
				variant="accent"
				size="compact"
				tooltip="Open the dining room blinds"
				onClick={() => handleAction(api.blindsOpen, 'Blinds opening')}
			/>
			<CompactControlButton
				label="Blinds Down"
				icon="blinds-close"
				variant="accent"
				size="compact"
				tooltip="Close the dining room blinds"
				onClick={() => handleAction(api.blindsClose, 'Blinds closing')}
			/>
		</div>
	{/if}

	<!-- Next up: the next few events. The next one is adjustable (just tomorrow) for User/Owner. -->
	{#if upcoming.length > 0}
		<section class="flex flex-col gap-2" data-testid="v2-next-up">
			<h2 class="text-xs uppercase tracking-wide text-slate-400">Next up</h2>
			{#each upcoming as job, i (job.jobId)}
				{#if i === 0 && canAdjustNext}
					<div
						class="rounded-xl border border-slate-700 bg-slate-800/50 p-3"
						data-testid="next-adjust"
					>
						<EventCard {job} compact />
						<div class="mt-2 grid grid-cols-2 gap-2">
							<div class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5">
								<button
									type="button"
									aria-label="15 minutes earlier"
									onclick={() => adjustNext(-15, 0)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>&minus;</button
								>
								<span class="text-xs text-slate-400">time</span>
								<button
									type="button"
									aria-label="15 minutes later"
									onclick={() => adjustNext(15, 0)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>+</button
								>
							</div>
							<div class="flex items-center justify-between rounded-lg bg-slate-900/50 px-2 py-1.5">
								<button
									type="button"
									aria-label="half a degree cooler"
									onclick={() => adjustNext(0, -0.5)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>&minus;</button
								>
								<span class="text-xs text-slate-400">temp</span>
								<button
									type="button"
									aria-label="half a degree warmer"
									onclick={() => adjustNext(0, 0.5)}
									disabled={adjusting}
									class="h-7 w-7 rounded text-lg leading-none text-slate-200 hover:bg-slate-700 disabled:opacity-40"
									>+</button
								>
							</div>
						</div>
						<div class="mt-2 flex items-center gap-3 text-xs">
							<button
								type="button"
								onclick={skipTomorrow}
								disabled={adjusting}
								data-testid="next-skip"
								class="text-slate-400 hover:text-amber-300 disabled:opacity-40">Skip tomorrow</button
							>
							{#if job.params?.override_of}
								<button
									type="button"
									onclick={resetNext}
									disabled={adjusting}
									data-testid="next-reset"
									class="text-slate-400 hover:text-slate-200 disabled:opacity-40">Reset to daily</button
								>
							{/if}
							<span class="ml-auto text-slate-500">just tomorrow — default unchanged</span>
						</div>
					</div>
				{:else}
					<EventCard {job} compact />
				{/if}
			{/each}
		</section>
	{/if}

	<!-- Equipment status -->
	<EquipmentStatusBar />

	<!-- Stall warning -->
	{#if lastStallEvent}
		<div
			data-testid="stall-warning-banner"
			class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 flex items-start gap-2"
		>
			<div class="flex-1">
				<p class="text-red-400 text-sm font-medium">Heating stalled</p>
				<p class="text-red-300 text-xs mt-0.5">
					Stalled at {lastStallEvent.current_temp_f.toFixed(1)}°F (target: {lastStallEvent.target_temp_f}°F)
					— heater turned off
				</p>
				<p class="text-red-400/60 text-xs mt-0.5">
					{new Date(lastStallEvent.timestamp).toLocaleString()}
				</p>
			</div>
			<button
				type="button"
				aria-label="Dismiss stall warning"
				onclick={() => (stallBannerDismissed = true)}
				class="text-red-400 hover:text-red-300 text-lg leading-none px-1">&times;</button
			>
		</div>
	{/if}

	<!-- Action result -->
	{#if status}
		<div
			data-testid="status-toast"
			class="text-center text-sm font-medium {status.type === 'success'
				? 'text-green-400'
				: 'text-red-400'}"
		>
			{status.message}
		</div>
	{/if}
</section>
