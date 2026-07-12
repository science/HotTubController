<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import EquipmentStatusBar from '$lib/components/EquipmentStatusBar.svelte';
	import TemperaturePanel from '$lib/components/TemperaturePanel.svelte';
	import EventCard from '$lib/components/EventCard.svelte';
	import { api, type TargetTemperatureState, type ScheduledJob } from '$lib/api';
	import { canControl, canSchedule, canTuneTarget } from '$lib/roles';
	import { foldScheduledEvents, type LogicalEvent } from '$lib/scheduleUtils';
	import { skipEvent, cancelEvent, makePermanentEvent } from '$lib/scheduleActions';
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
	// Owner/User only: the dial rewrites the *persistent household default* — a Guest
	// heats to it but doesn't redefine it (roles.canTuneTarget).
	const TEMP_STEP_F = 0.25;
	let targetEnabled = $derived(getTargetTempEnabled());
	let dynamicMode = $derived(getDynamicMode());
	let showDial = $derived(canTuneTarget(data.user?.role) && targetEnabled && !dynamicMode);
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

	// ── Next up ────────────────────────────────────────────────────────────────
	// Upcoming events, folded so each recurring event (with or without an override)
	// is ONE card. Each card carries its own controls (EventCard — the same paradigm
	// as the Schedule tab). Display is for everyone; the adjust controls are
	// User/Owner only. Here a recurring Save means "override just the next run"
	// (onOverrideNext); the daily default changes only via Make permanent.
	let scheduledJobs = $state<ScheduledJob[]>([]);
	const events = $derived(foldScheduledEvents(scheduledJobs));
	const canSched = $derived(canSchedule(data.user?.role));

	async function reloadJobs() {
		try {
			const r = await api.listScheduledJobs();
			scheduledJobs = r.jobs;
		} catch {
			/* keep the last list */
		}
	}

	function failToast(message: string) {
		status = { message, type: 'error' };
		setTimeout(() => (status = null), 3000);
	}

	// Reschedule paths: errors propagate to the card, which shows them inline (and
	// rethrows so the navigation guard's Save-all halts on failure).
	async function handleOverrideNext(jobId: string, clock: string, tempF: number) {
		await api.overrideNextOccurrence(jobId, clock, tempF);
		await reloadJobs();
	}
	async function handleRescheduleOneOff(jobId: string, scheduledTime: string, tempF?: number) {
		await api.rescheduleOneOff(jobId, scheduledTime, tempF);
		await reloadJobs();
	}

	// Button actions: the card has no inline error slot for these, so failures toast.
	async function handleSkip(e: LogicalEvent) {
		try {
			await skipEvent(e);
			await reloadJobs();
		} catch {
			failToast("Couldn't skip the next run. Try again.");
		}
	}
	async function handleUnskip(e: LogicalEvent) {
		try {
			await api.unskipScheduledJob(e.key);
			await reloadJobs();
		} catch {
			failToast("Couldn't resume the next run. Try again.");
		}
	}
	async function handleCancel(e: LogicalEvent) {
		try {
			await cancelEvent(e);
			await reloadJobs();
		} catch {
			failToast("Couldn't remove the event. Try again.");
		}
	}
	async function handleClearOverride(e: LogicalEvent) {
		try {
			await api.clearOverrideNext(e.key);
			await reloadJobs();
		} catch {
			failToast("Couldn't reset to the daily default. Try again.");
		}
	}
	async function handleMakePermanent(e: LogicalEvent) {
		try {
			await makePermanentEvent(e);
			await reloadJobs();
		} catch {
			failToast("Couldn't make the change permanent. Try again.");
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
	<!-- Temperature readout: the plain instrument-panel card, on purpose. Steve rejected
	     a big-number "hero" treatment here (2026-07-08) — this UI is a functional control
	     panel, and the dense labeled readout is the desired look. -->
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
			<span class="text-sm text-slate-400">Target</span>
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
				<span class="text-xs text-slate-500">({etaDisplay.minutesRemaining} min)</span>
			</div>
		{:else}
			<div class="text-center text-sm text-orange-400/80" data-testid="eta-display">
				Target {etaDisplay.targetTempF}°F by {etaDisplay.time}
				<span class="text-xs text-slate-500">({etaDisplay.minutesRemaining} min)</span>
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

	<!-- Next up: folded events, each card carrying its own controls (same paradigm as
	     the Schedule tab). Guests get display-only cards. -->
	{#if events.length > 0}
		<section class="flex flex-col gap-2" data-testid="v2-next-up">
			<h2 class="text-xs uppercase tracking-wide text-slate-400">Next up</h2>
			<div class="flex flex-col gap-2">
				{#each events as event (event.key)}
					<EventCard
						{event}
						canAdjust={canSched}
						onOverrideNext={handleOverrideNext}
						onReschedule={handleRescheduleOneOff}
						onSkip={handleSkip}
						onUnskip={handleUnskip}
						onCancel={handleCancel}
						onClearOverride={handleClearOverride}
						onMakePermanent={handleMakePermanent}
					/>
				{/each}
			</div>
		</section>
	{/if}

	<!-- Equipment status -->
	<EquipmentStatusBar />

	<!-- Stall warning -->
	{#if lastStallEvent}
		<div
			data-testid="stall-warning-banner"
			class="flex items-start gap-2 rounded-lg border border-red-500/50 bg-red-500/20 p-3"
		>
			<div class="flex-1">
				<p class="text-sm font-medium text-red-400">Heating stalled</p>
				<p class="mt-0.5 text-xs text-red-300">
					Stalled at {lastStallEvent.current_temp_f.toFixed(1)}°F (target: {lastStallEvent.target_temp_f}°F)
					— heater turned off
				</p>
				<p class="mt-0.5 text-xs text-red-400/60">
					{new Date(lastStallEvent.timestamp).toLocaleString()}
				</p>
			</div>
			<button
				type="button"
				aria-label="Dismiss stall warning"
				onclick={() => (stallBannerDismissed = true)}
				class="px-1 text-lg leading-none text-red-400 hover:text-red-300">&times;</button
			>
		</div>
	{/if}

	<!-- Action result: fixed above the tab bar so feedback is visible no matter how
	     far the page has scrolled (the buttons it confirms live at the top). -->
	{#if status}
		<div class="pointer-events-none fixed inset-x-0 bottom-16 z-40 flex justify-center px-4">
			<div
				data-testid="status-toast"
				role="status"
				class="rounded-full border px-4 py-1.5 text-sm font-medium shadow-lg {status.type ===
				'success'
					? 'border-green-500/40 bg-slate-800 text-green-400'
					: 'border-red-500/40 bg-slate-800 text-red-400'}"
			>
				{status.message}
			</div>
		</div>
	{/if}

</section>
