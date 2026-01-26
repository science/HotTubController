<script lang="ts">
	import {
		getAutoHeatOffEnabled,
		setAutoHeatOffEnabled,
		getAutoHeatOffMinutes,
		setAutoHeatOffMinutes,
		AUTO_HEAT_OFF_DEFAULTS
	} from '$lib/autoHeatOff';
	import {
		getRefreshTempOnHeaterOff,
		setRefreshTempOnHeaterOff,
		getTargetTempEnabled,
		setTargetTempEnabled,
		getTargetTempF,
		setTargetTempF,
		TARGET_TEMP_DEFAULTS
	} from '$lib/settings';
	import { api, type TargetTemperatureState } from '$lib/api';
	import { onMount } from 'svelte';

	// Props
	let { isAdmin = false }: { isAdmin?: boolean } = $props();

	// Auto heat-off state (loaded from localStorage)
	let autoHeatOffEnabled = $state(getAutoHeatOffEnabled());
	let autoHeatOffMinutes = $state(getAutoHeatOffMinutes());

	// Server-side heat-target state (admin only)
	let serverHeatTargetState = $state<TargetTemperatureState | null>(null);
	let loadingHeatTarget = $state(false);
	let cancellingHeatTarget = $state(false);
	let heatTargetError = $state<string | null>(null);

	async function loadServerHeatTargetState() {
		if (!isAdmin) return;
		loadingHeatTarget = true;
		heatTargetError = null;
		try {
			serverHeatTargetState = await api.getTargetTempStatus();
		} catch (e) {
			heatTargetError = e instanceof Error ? e.message : 'Failed to load';
		} finally {
			loadingHeatTarget = false;
		}
	}

	async function cancelServerHeatTarget() {
		cancellingHeatTarget = true;
		heatTargetError = null;
		try {
			await api.cancelTargetTemp();
			serverHeatTargetState = { active: false, target_temp_f: null };
		} catch (e) {
			heatTargetError = e instanceof Error ? e.message : 'Failed to cancel';
		} finally {
			cancellingHeatTarget = false;
		}
	}

	onMount(() => {
		if (isAdmin) {
			loadServerHeatTargetState();
		}
	});

	// Refresh temp on heater-off state
	let refreshTempOnHeaterOff = $state(getRefreshTempOnHeaterOff());

	// Target temperature settings
	let targetTempEnabled = $state(getTargetTempEnabled());
	let targetTempF = $state(getTargetTempF());

	function handleAutoHeatOffToggle(event: Event) {
		const target = event.target as HTMLInputElement;
		autoHeatOffEnabled = target.checked;
		setAutoHeatOffEnabled(target.checked);
	}

	function handleAutoHeatOffMinutesChange(event: Event) {
		const target = event.target as HTMLInputElement;
		const value = parseInt(target.value, 10);
		if (!isNaN(value)) {
			autoHeatOffMinutes = value;
			setAutoHeatOffMinutes(value);
			// Update display with clamped value
			autoHeatOffMinutes = getAutoHeatOffMinutes();
		}
	}

	function handleRefreshTempToggle(event: Event) {
		const target = event.target as HTMLInputElement;
		refreshTempOnHeaterOff = target.checked;
		setRefreshTempOnHeaterOff(target.checked);
	}

	function handleTargetTempEnabledToggle(event: Event) {
		const target = event.target as HTMLInputElement;
		targetTempEnabled = target.checked;
		setTargetTempEnabled(target.checked);
	}

	function handleTargetTempChange(event: Event) {
		const target = event.target as HTMLInputElement;
		const value = parseFloat(target.value);
		if (!isNaN(value)) {
			targetTempF = value;
			setTargetTempF(value);
		}
	}
</script>

<div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
	<h2 class="text-lg font-semibold text-slate-200 mb-4">Settings</h2>

	<!-- Auto Heat Off Configuration -->
	<div class="space-y-3 mb-4">
		<label class="flex items-center gap-2 cursor-pointer">
			<input
				type="checkbox"
				checked={autoHeatOffEnabled}
				onchange={handleAutoHeatOffToggle}
				class="w-4 h-4 rounded border-slate-500 bg-slate-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-slate-800"
			/>
			<span class="text-slate-200 text-sm">Enable auto heat-off</span>
		</label>

		<div class="flex items-center gap-2 ml-6">
			<label for="autoHeatOffMinutes" class="text-slate-400 text-sm">Turn off after</label>
			<input
				type="number"
				id="autoHeatOffMinutes"
				value={autoHeatOffMinutes}
				onblur={handleAutoHeatOffMinutesChange}
				min={AUTO_HEAT_OFF_DEFAULTS.minMinutes}
				max={AUTO_HEAT_OFF_DEFAULTS.maxMinutes}
				class="w-20 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
			/>
			<span class="text-slate-400 text-sm">minutes</span>
		</div>

		<p class="text-slate-500 text-xs ml-6">
			Automatically schedules heater-off when you schedule heater-on
		</p>
	</div>

	<!-- Refresh Temp on Heater Off -->
	<div class="border-t border-slate-700 pt-4">
		<label class="flex items-center gap-2 cursor-pointer">
			<input
				type="checkbox"
				checked={refreshTempOnHeaterOff}
				onchange={handleRefreshTempToggle}
				class="w-4 h-4 rounded border-slate-500 bg-slate-700 text-blue-500 focus:ring-blue-500 focus:ring-offset-slate-800"
			/>
			<span class="text-slate-200 text-sm">Refresh temperature when heater turns off</span>
		</label>

		<p class="text-slate-500 text-xs ml-6 mt-2">
			Auto-updates temperature after scheduled heater-off completes
		</p>
	</div>

	<!-- Target Temperature -->
	<div class="border-t border-slate-700 pt-4 mt-4">
		<h3 class="text-sm font-medium text-slate-300 mb-3">Target Temperature</h3>
		<div class="space-y-3">
			<label class="flex items-center gap-2 cursor-pointer">
				<input
					type="checkbox"
					checked={targetTempEnabled}
					onchange={handleTargetTempEnabledToggle}
					class="w-4 h-4 rounded border-slate-500 bg-slate-700 text-orange-500 focus:ring-orange-500 focus:ring-offset-slate-800"
				/>
				<span class="text-slate-200 text-sm">Enable heat to target</span>
			</label>

			<div class="ml-6 space-y-2">
				<div class="flex items-center gap-3">
					<label for="targetTempSlider" class="text-slate-400 text-sm">Target temp</label>
					<input
						type="number"
						id="targetTempInput"
						aria-label="Target temp input"
						value={targetTempF}
						onchange={handleTargetTempChange}
						min={TARGET_TEMP_DEFAULTS.minTempF}
						max={TARGET_TEMP_DEFAULTS.maxTempF}
						step="0.25"
						inputmode="decimal"
						class="w-20 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-orange-400 font-medium text-sm focus:outline-none focus:ring-2 focus:ring-orange-500"
					/><span class="text-orange-400 font-medium">°F</span>
				</div>
				<input
					type="range"
					id="targetTempSlider"
					aria-label="Target temp"
					value={targetTempF}
					oninput={handleTargetTempChange}
					min={TARGET_TEMP_DEFAULTS.minTempF}
					max={TARGET_TEMP_DEFAULTS.maxTempF}
					step="0.25"
					class="w-full h-2 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-orange-500"
				/>
				<div class="flex justify-between text-xs text-slate-500">
					<span>{TARGET_TEMP_DEFAULTS.minTempF}°F</span>
					<span>{TARGET_TEMP_DEFAULTS.maxTempF}°F</span>
				</div>
			</div>

			<p class="text-slate-500 text-xs ml-6">
				When enabled, heater will automatically turn off when target is reached
			</p>
		</div>
	</div>

	<!-- Admin: Server Heat-Target Status -->
	{#if isAdmin}
		<div class="border-t border-slate-700 pt-4 mt-4">
			<h3 class="text-sm font-medium text-slate-300 mb-3">Server Heat-Target Status (Admin)</h3>

			{#if loadingHeatTarget}
				<p class="text-slate-400 text-sm">Loading...</p>
			{:else if heatTargetError}
				<p class="text-red-400 text-sm">{heatTargetError}</p>
			{:else if serverHeatTargetState?.active}
				<div class="bg-orange-500/20 border border-orange-500/50 rounded-lg p-3 space-y-2">
					<div class="flex items-center gap-2">
						<span class="w-2 h-2 bg-orange-500 rounded-full animate-pulse"></span>
						<span class="text-orange-400 text-sm font-medium">Heat-to-target ACTIVE</span>
					</div>
					<p class="text-slate-300 text-sm">
						Target: {serverHeatTargetState.target_temp_f}°F
					</p>
					{#if serverHeatTargetState.started_at}
						<p class="text-slate-400 text-xs">
							Started: {new Date(serverHeatTargetState.started_at).toLocaleString()}
						</p>
					{/if}
					<button
						onclick={cancelServerHeatTarget}
						disabled={cancellingHeatTarget}
						class="mt-2 w-full bg-red-600 hover:bg-red-700 disabled:bg-red-800 disabled:cursor-not-allowed text-white text-sm font-medium py-2 px-4 rounded transition-colors"
					>
						{cancellingHeatTarget ? 'Cancelling...' : 'Cancel All Heat-Target Jobs'}
					</button>
					<p class="text-slate-500 text-xs">
						This will remove the heat-target state, all related cron entries, and job files.
					</p>
				</div>
			{:else}
				<div class="text-slate-400 text-sm flex items-center gap-2">
					<span class="w-2 h-2 bg-slate-500 rounded-full"></span>
					<span>No active heat-to-target job</span>
				</div>
				<button
					onclick={loadServerHeatTargetState}
					class="mt-2 text-slate-400 hover:text-slate-200 text-xs underline"
				>
					Refresh status
				</button>
			{/if}
		</div>
	{/if}
</div>
