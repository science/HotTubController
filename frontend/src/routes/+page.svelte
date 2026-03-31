<script lang="ts">
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import EquipmentStatusBar from '$lib/components/EquipmentStatusBar.svelte';
	import QuickSchedulePanel from '$lib/components/QuickSchedulePanel.svelte';
	import TemperaturePanel from '$lib/components/TemperaturePanel.svelte';
	import SchedulePanel from '$lib/components/SchedulePanel.svelte';
	import SettingsPanel from '$lib/components/SettingsPanel.svelte';
	import SensorConfigPanel from '$lib/components/SensorConfigPanel.svelte';
	import { api } from '$lib/api';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import { buildInfo } from '$lib/config';
	import { getRefreshTempOnHeaterOff } from '$lib/settings';
	import {
		getEnabled as getTargetTempEnabled,
		getTargetTempF,
		getHeatButtonLabel,
		getHeatButtonTooltip,
		getLastStallEvent
	} from '$lib/stores/heatTargetSettings.svelte';
	import type { LastStallEvent, TargetTemperatureState } from '$lib/api';
	import {
		fetchStatus,
		getHeaterOn,
		getPumpOn,
		getBlindsEnabled,
		setHeaterOn,
		setHeaterOff,
		setPumpOn
	} from '$lib/stores/equipmentStatus.svelte';
	import { onMount } from 'svelte';

	let { data } = $props();

	// Check if user has basic role (simplified UI)
	const isBasicUser = $derived(data.user?.role === 'basic');

	// Reactive equipment status
	let heaterOn = $derived(getHeaterOn());
	let pumpOn = $derived(getPumpOn());
	let blindsEnabled = $derived(getBlindsEnabled());

	// Fetch equipment status on mount, and verify auth state
	onMount(() => {
		// If we reach this page without a valid user, redirect to login
		// This handles the edge case where sessionStorage blocks the layout redirect
		// but the user's auth cookie is missing/expired
		if (!data.user) {
			goto(`${base}/login`, { replaceState: true });
			return;
		}
		fetchStatus();
	});

	let status = $state<{ message: string; type: 'success' | 'error' } | null>(null);
	let schedulePanel = $state<{ loadJobs: () => Promise<void> } | null>(null);
	let temperaturePanel = $state<{ loadTemperature: () => Promise<void> } | null>(null);
	let stallBannerDismissed = $state(false);
	let lastStallEvent = $derived(stallBannerDismissed ? null : getLastStallEvent());

	// ETA tracking for heat-to-target
	// ETA tracking for heat-to-target (active and projected)
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
			projected: eta.projected,
		};
	});

	// Poll heat-to-target status whenever target mode is enabled.
	// Reading heaterOn ensures the effect re-fires on heater state changes,
	// so the ETA switches between projected/active immediately.
	$effect(() => {
		const _heaterState = heaterOn; // track as dependency
		if (!getTargetTempEnabled()) {
			heatTargetEta = null;
			return;
		}
		api.getTargetTempStatus().then(s => { heatTargetEta = s; }).catch(() => {});
		const interval = setInterval(() => {
			api.getTargetTempStatus().then(s => { heatTargetEta = s; }).catch(() => {});
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
		setTimeout(() => {
			status = null;
		}, 3000);
	}

	async function handleLogout() {
		try {
			await fetch(`${base}/backend/public/api/auth/logout`, {
				method: 'POST',
				credentials: 'include'
			});
		} catch (e) {
			// Ignore errors
		}
		goto(`${base}/login`);
	}

	function handleQuickScheduled(result: { success: boolean; message: string }) {
		status = {
			message: result.message,
			type: result.success ? 'success' : 'error'
		};
		setTimeout(() => {
			status = null;
		}, 3000);
		// Refresh the schedule panel to show the new job
		schedulePanel?.loadJobs();
	}

	function handleHeaterOffCompleted() {
		// Refresh temperature if setting is enabled
		if (getRefreshTempOnHeaterOff()) {
			temperaturePanel?.loadTemperature();
		}
	}

	async function handleHeatOn() {
		if (getTargetTempEnabled()) {
			// Use target temperature mode
			const targetTempF = getTargetTempF();
			await handleAction(
				() => api.heatToTarget(targetTempF),
				`Heating to ${targetTempF}°F`,
				setHeaterOn
			);
		} else {
			// Use standard heater on
			await handleAction(api.heaterOn, 'Heater turned ON', setHeaterOn);
		}
	}
</script>

<div class="min-h-screen bg-slate-900 p-4 flex flex-col">
	<header class="mb-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
		<h1 class="text-xl font-bold text-slate-100 tracking-wide">HOT TUB CONTROL</h1>
		{#if data.user}
			<div class="flex items-center gap-2">
				<span class="text-slate-400 text-sm">{data.user.username}</span>
				{#if data.user.role === 'admin'}
					<a href="{base}/users" class="text-slate-500 hover:text-slate-300 text-sm underline">
						Users
					</a>
				{/if}
				<button
					onclick={handleLogout}
					class="text-slate-500 hover:text-slate-300 text-sm underline"
				>
					Logout
				</button>
			</div>
		{/if}
	</header>

	<main class="flex-1 flex flex-col gap-3 max-w-md mx-auto w-full">
		<!-- Compact Primary Controls -->
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
				tooltip="Turn off heater and pump"
				active={!heaterOn}
				onClick={() => handleAction(api.heaterOff, 'Heater and pump turned OFF', setHeaterOff)}
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

		<!-- ETA: Estimated time to reach target temperature -->
		{#if etaDisplay}
			{#if etaDisplay.projected}
				<div class="text-center text-sm text-slate-400 py-1" data-testid="eta-display">
					Heat now &rarr; {etaDisplay.targetTempF}°F by {etaDisplay.time}
					<span class="text-slate-500 text-xs">({etaDisplay.minutesRemaining} min)</span>
				</div>
			{:else}
				<div class="text-center text-sm text-orange-400/80 py-1" data-testid="eta-display">
					Target {etaDisplay.targetTempF}°F by {etaDisplay.time}
					<span class="text-slate-500 text-xs">({etaDisplay.minutesRemaining} min)</span>
				</div>
			{/if}
		{/if}

		<!-- Dining Room Blinds Controls (optional feature, half-height) -->
		{#if blindsEnabled}
			<div class="grid grid-cols-2 gap-2">
				<CompactControlButton
					label="Blinds Up"
					icon="blinds-open"
					variant="accent"
					size="compact"
					tooltip="Open dining room blinds for privacy"
					onClick={() => handleAction(api.blindsOpen, 'Blinds opening')}
				/>
				<CompactControlButton
					label="Blinds Down"
					icon="blinds-close"
					variant="accent"
					size="compact"
					tooltip="Close dining room blinds"
					onClick={() => handleAction(api.blindsClose, 'Blinds closing')}
				/>
			</div>
		{/if}

		<!-- Equipment Status Bar -->
		<EquipmentStatusBar />

		<!-- Stall Warning Banner -->
		{#if lastStallEvent}
			<div
				data-testid="stall-warning-banner"
				class="bg-red-500/20 border border-red-500/50 rounded-lg p-3 flex items-start gap-2"
			>
				<div class="flex-1">
					<p class="text-red-400 text-sm font-medium">Heating stalled</p>
					<p class="text-red-300 text-xs mt-0.5">
						Stalled at {lastStallEvent.current_temp_f.toFixed(1)}°F (target: {lastStallEvent.target_temp_f}°F) — heater turned off
					</p>
					<p class="text-red-400/60 text-xs mt-0.5">
						{new Date(lastStallEvent.timestamp).toLocaleString()}
					</p>
				</div>
				<button
					type="button"
					aria-label="Dismiss stall warning"
					onclick={() => { stallBannerDismissed = true; }}
					class="text-red-400 hover:text-red-300 text-lg leading-none px-1"
				>&times;</button>
			</div>
		{/if}

		<!-- Quick Schedule Buttons (hidden for basic users) -->
		{#if !isBasicUser}
			<QuickSchedulePanel onScheduled={handleQuickScheduled} />
		{/if}

		<!-- Temperature Display -->
		<TemperaturePanel bind:this={temperaturePanel} />

		<!-- Full Schedule Panel (hidden for basic users) -->
		{#if !isBasicUser}
			<SchedulePanel bind:this={schedulePanel} onHeaterOffCompleted={handleHeaterOffCompleted} />
		{/if}

		<!-- Settings Panel (hidden for basic users) -->
		{#if !isBasicUser}
			<SettingsPanel isAdmin={data.user?.role === 'admin'} />
		{/if}

		<!-- ESP32 Sensor Configuration (admin only) -->
		{#if data.user?.role === 'admin'}
			<SensorConfigPanel />
		{/if}
	</main>

	<footer class="mt-6 text-center">
		{#if status}
			<div
				class="inline-block px-4 py-2 rounded-lg text-sm font-medium transition-all {status.type === 'success'
					? 'bg-green-500/20 text-green-400 border border-green-500/50'
					: 'bg-red-500/20 text-red-400 border border-red-500/50'}"
			>
				{status.message}
			</div>
		{:else}
			<p class="text-slate-500 text-sm">Hold any button for help</p>
		{/if}
		{#if buildInfo.commitUrl}
			<a
				href={buildInfo.commitUrl}
				target="_blank"
				rel="noopener noreferrer"
				class="text-slate-600 hover:text-slate-400 text-xs mt-4 block"
			>
				{buildInfo.version}
			</a>
		{:else}
			<p class="text-slate-600 text-xs mt-4">{buildInfo.version}</p>
		{/if}
	</footer>
</div>
