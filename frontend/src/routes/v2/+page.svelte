<script lang="ts">
	import { onMount } from 'svelte';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import EquipmentStatusBar from '$lib/components/EquipmentStatusBar.svelte';
	import TemperaturePanel from '$lib/components/TemperaturePanel.svelte';
	import { api, type TargetTemperatureState } from '$lib/api';
	import { canControl } from '$lib/roles';
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
		getLastStallEvent
	} from '$lib/stores/heatTargetSettings.svelte';

	let { data } = $props();

	// Guest/User/Owner can all act on hardware; read-only never reaches the UI.
	// Gate defensively so the control grid is honest about who may act.
	const canAct = $derived(canControl(data.user?.role));

	let heaterOn = $derived(getHeaterOn());
	let pumpOn = $derived(getPumpOn());
	let blindsEnabled = $derived(getBlindsEnabled());

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
