<script lang="ts">
	import {
		getAutoHeatOffEnabled,
		setAutoHeatOffEnabled,
		getAutoHeatOffMinutes,
		setAutoHeatOffMinutes,
		AUTO_HEAT_OFF_DEFAULTS
	} from '$lib/autoHeatOff';
	import { getRefreshTempOnHeaterOff, setRefreshTempOnHeaterOff } from '$lib/settings';
	import {
		getEnabled as getTargetTempEnabled,
		getTargetTempF,
		getTimezone,
		getScheduleMode,
		getMinTempF,
		getMaxTempF,
		updateSettings as updateTargetTempSettings,
		getIsLoading as getTargetTempLoading,
		getError as getTargetTempError
	} from '$lib/stores/heatTargetSettings.svelte';
	import {
		api,
		type TargetTemperatureState,
		type HeatingCharacteristics,
		type TemperatureData
	} from '$lib/api';
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

	// Local state for target temp form (synced from store)
	let localTargetTempEnabled = $state(getTargetTempEnabled());
	let localTargetTempF = $state(getTargetTempF());
	let localTimezone = $state(getTimezone());
	let localScheduleMode = $state<'start_at' | 'ready_by'>(getScheduleMode());
	let savingTargetTemp = $state(false);
	let targetTempSaveError = $state<string | null>(null);
	let userHasEditedTargetTemp = $state(false); // Track if user made changes

	// Common US timezones for the dropdown
	const TIMEZONE_OPTIONS = [
		'America/Los_Angeles',
		'America/Denver',
		'America/Chicago',
		'America/New_York',
		'America/Anchorage',
		'Pacific/Honolulu',
	];

	// Track if local values differ from store (dirty state)
	let targetTempDirty = $derived(
		localTargetTempEnabled !== getTargetTempEnabled() ||
			localTargetTempF !== getTargetTempF() ||
			localTimezone !== getTimezone() ||
			localScheduleMode !== getScheduleMode()
	);

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

	async function saveTargetTempSettings() {
		savingTargetTemp = true;
		targetTempSaveError = null;
		try {
			await updateTargetTempSettings(localTargetTempEnabled, localTargetTempF, localTimezone, localScheduleMode);
			userHasEditedTargetTemp = false; // Reset after successful save
		} catch (e) {
			targetTempSaveError = e instanceof Error ? e.message : 'Failed to save';
		} finally {
			savingTargetTemp = false;
		}
	}

	onMount(() => {
		if (isAdmin) {
			loadServerHeatTargetState();
			loadHeatingCharacteristics();
			api.getTemperature().then((t) => (currentTemp = t)).catch(() => {});
		}
	});

	// Sync local state with store when store updates (e.g., from health response)
	// Only sync when user hasn't made unsaved changes
	$effect(() => {
		if (!userHasEditedTargetTemp) {
			localTargetTempEnabled = getTargetTempEnabled();
			localTargetTempF = getTargetTempF();
			localTimezone = getTimezone();
			localScheduleMode = getScheduleMode();
		}
	});

	// Heating characteristics state (admin only)
	let heatingChars = $state<HeatingCharacteristics | null>(null);
	let loadingHeatingChars = $state(false);
	let generatingHeatingChars = $state(false);
	let heatingCharsError = $state<string | null>(null);
	let lookbackDays = $state(5);
	let currentTemp = $state<TemperatureData | null>(null);

	async function loadHeatingCharacteristics() {
		if (!isAdmin) return;
		loadingHeatingChars = true;
		heatingCharsError = null;
		try {
			const response = await api.getHeatingCharacteristics();
			heatingChars = response.results;
		} catch (e) {
			heatingCharsError = e instanceof Error ? e.message : 'Failed to load';
		} finally {
			loadingHeatingChars = false;
		}
	}

	async function generateHeatingCharacteristics() {
		generatingHeatingChars = true;
		heatingCharsError = null;
		try {
			const response = await api.generateHeatingCharacteristics(lookbackDays);
			heatingChars = response.results;
		} catch (e) {
			heatingCharsError = e instanceof Error ? e.message : 'Failed to generate';
		} finally {
			generatingHeatingChars = false;
		}
	}

	// Refresh temp on heater-off state
	let refreshTempOnHeaterOff = $state(getRefreshTempOnHeaterOff());

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
		localTargetTempEnabled = target.checked;
		userHasEditedTargetTemp = true;
	}

	function handleTargetTempChange(event: Event) {
		const target = event.target as HTMLInputElement;
		const value = parseFloat(target.value);
		if (!isNaN(value)) {
			localTargetTempF = value;
			userHasEditedTargetTemp = true;
		}
	}

	function handleTargetTempSliderInput() {
		userHasEditedTargetTemp = true;
	}

	function decreaseTargetTemp() {
		const newVal = localTargetTempF - 0.25;
		if (newVal >= getMinTempF()) {
			localTargetTempF = newVal;
			userHasEditedTargetTemp = true;
		}
	}

	function increaseTargetTemp() {
		const newVal = localTargetTempF + 0.25;
		if (newVal <= getMaxTempF()) {
			localTargetTempF = newVal;
			userHasEditedTargetTemp = true;
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

	<!-- Target Temperature (Admin only) -->
	{#if isAdmin}
		<div class="border-t border-slate-700 pt-4 mt-4">
			<h3 class="text-sm font-medium text-slate-300 mb-3">Target Temperature (Global Setting)</h3>
			<div class="space-y-3">
				<label class="flex items-center gap-2 cursor-pointer">
					<input
						type="checkbox"
						checked={localTargetTempEnabled}
						onchange={handleTargetTempEnabledToggle}
						disabled={savingTargetTemp}
						class="w-4 h-4 rounded border-slate-500 bg-slate-700 text-orange-500 focus:ring-orange-500 focus:ring-offset-slate-800 disabled:opacity-50"
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
							bind:value={localTargetTempF}
							onchange={() => { userHasEditedTargetTemp = true; }}
							min={getMinTempF()}
							max={getMaxTempF()}
							step="0.25"
							inputmode="decimal"
							disabled={savingTargetTemp}
							class="w-20 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-orange-400 font-medium text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 disabled:opacity-50"
						/><span class="text-orange-400 font-medium">°F</span>
					</div>
					<div class="flex items-center gap-2">
						<button
							type="button"
							aria-label="Decrease temperature"
							onclick={decreaseTargetTemp}
							disabled={savingTargetTemp || localTargetTempF <= getMinTempF()}
							class="w-16 sm:w-8 h-10 sm:h-8 flex items-center justify-center rounded bg-slate-700 hover:bg-slate-600 text-slate-200 text-2xl sm:text-lg font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
						>&minus;</button>
						<input
							type="range"
							id="targetTempSlider"
							aria-label="Target temp"
							bind:value={localTargetTempF}
							oninput={handleTargetTempSliderInput}
							min={getMinTempF()}
							max={getMaxTempF()}
							step="0.25"
							disabled={savingTargetTemp}
							class="flex-1 h-2 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-orange-500 disabled:opacity-50"
						/>
						<button
							type="button"
							aria-label="Increase temperature"
							onclick={increaseTargetTemp}
							disabled={savingTargetTemp || localTargetTempF >= getMaxTempF()}
							class="w-16 sm:w-8 h-10 sm:h-8 flex items-center justify-center rounded bg-slate-700 hover:bg-slate-600 text-slate-200 text-2xl sm:text-lg font-bold disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
						>&plus;</button>
					</div>
					<div class="flex justify-between text-xs text-slate-500">
						<span>{getMinTempF()}°F</span>
						<span>{getMaxTempF()}°F</span>
					</div>
				</div>

				<p class="text-slate-500 text-xs ml-6">
					When enabled, heater will automatically turn off when target is reached. This setting
					affects all users.
				</p>

				<div class="ml-6 mt-3">
					<label for="timezoneSelect" class="text-slate-400 text-sm block mb-1">System timezone</label>
					<select
						id="timezoneSelect"
						bind:value={localTimezone}
						onchange={() => { userHasEditedTargetTemp = true; }}
						disabled={savingTargetTemp}
						class="w-full bg-slate-700 border border-slate-600 rounded px-2 py-1.5 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 disabled:opacity-50"
					>
						{#each TIMEZONE_OPTIONS as tz}
							<option value={tz}>{tz.replace('_', ' ')}</option>
						{/each}
					</select>
					<p class="text-slate-500 text-xs mt-1">
						Used for scheduling and display purposes.
					</p>
				</div>

				<fieldset class="ml-6 mt-3">
					<legend class="text-slate-400 text-sm mb-2">Schedule mode</legend>
					<label class="flex items-center gap-2 cursor-pointer">
						<input
							type="radio"
							name="scheduleMode"
							value="start_at"
							checked={localScheduleMode === 'start_at'}
							onchange={() => { localScheduleMode = 'start_at'; userHasEditedTargetTemp = true; }}
							disabled={savingTargetTemp}
							class="w-4 h-4 border-slate-500 bg-slate-700 text-orange-500 focus:ring-orange-500"
						/>
						<span class="text-slate-300 text-sm">Scheduled time starts heating</span>
					</label>
					<label class="flex items-center gap-2 cursor-pointer mt-1">
						<input
							type="radio"
							name="scheduleMode"
							value="ready_by"
							checked={localScheduleMode === 'ready_by'}
							onchange={() => { localScheduleMode = 'ready_by'; userHasEditedTargetTemp = true; }}
							disabled={savingTargetTemp}
							class="w-4 h-4 border-slate-500 bg-slate-700 text-orange-500 focus:ring-orange-500"
						/>
						<span class="text-slate-300 text-sm">Tub ready by scheduled time</span>
					</label>
					<p class="text-slate-500 text-xs mt-1">
						"Ready by" calculates optimal start time using heating characteristics.
					</p>
				</fieldset>

				{#if targetTempDirty}
					<div class="ml-6 flex items-center gap-2">
						<button
							onclick={saveTargetTempSettings}
							disabled={savingTargetTemp}
							class="bg-orange-600 hover:bg-orange-700 disabled:bg-orange-800 disabled:cursor-not-allowed text-white text-sm font-medium py-1.5 px-4 rounded transition-colors"
						>
							{savingTargetTemp ? 'Saving...' : 'Save Settings'}
						</button>
						{#if targetTempSaveError}
							<span class="text-red-400 text-sm">{targetTempSaveError}</span>
						{/if}
					</div>
				{/if}
			</div>
		</div>
	{/if}

	<!-- Admin: Server Heat-Target Status -->
	{#if isAdmin}
		<div class="border-t border-slate-700 pt-4 mt-4">
			<h3 class="text-sm font-medium text-slate-300 mb-3">Active Heat-Target Job</h3>

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
						{cancellingHeatTarget ? 'Cancelling...' : 'Cancel Heat-Target Job'}
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

	<!-- Admin: Heating Characteristics Analysis -->
	{#if isAdmin}
		<div class="border-t border-slate-700 pt-4 mt-4">
			<h3 class="text-sm font-medium text-slate-300 mb-3">Heating Characteristics</h3>

			{#if loadingHeatingChars}
				<p class="text-slate-400 text-sm">Loading...</p>
			{:else if heatingCharsError}
				<p class="text-red-400 text-sm">{heatingCharsError}</p>
			{:else if heatingChars}
				<div class="space-y-2 mb-3">
					<div class="grid grid-cols-2 gap-2 text-sm">
						<span class="text-slate-400">Heating rate</span>
						<span class="text-slate-200">{heatingChars.heating_velocity_f_per_min}°F/min</span>
						<span class="text-slate-400">Startup lag</span>
						<span class="text-slate-200">{heatingChars.startup_lag_minutes} min</span>
						<span class="text-slate-400">Overshoot</span>
						<span class="text-slate-200">{heatingChars.overshoot_degrees_f}°F</span>
						<span class="text-slate-400">Cooling coefficient (k)</span>
						{#if heatingChars.cooling_coefficient_k != null}
							<span class="text-slate-200">{heatingChars.cooling_coefficient_k.toFixed(6)}/min <span class="text-slate-500">({heatingChars.cooling_data_points} pts)</span></span>
						{:else}
							<span class="text-slate-500">Insufficient data</span>
						{/if}
						<span class="text-slate-400">Model fit (R²)</span>
						{#if heatingChars.cooling_r_squared != null}
							<span class="text-slate-200">{heatingChars.cooling_r_squared.toFixed(3)}</span>
						{:else}
							<span class="text-slate-500">Insufficient data</span>
						{/if}
						<span class="text-slate-400">Max cooling (k)</span>
						{#if heatingChars.max_cooling_k != null}
							<span class="text-slate-200">{heatingChars.max_cooling_k.toFixed(6)}/min</span>
						{:else}
							<span class="text-slate-500">Insufficient data</span>
						{/if}
						{#if heatingChars.cooling_coefficient_k != null && currentTemp?.water_temp_f != null && currentTemp?.ambient_temp_f != null}
							{@const deltaT = currentTemp.water_temp_f - currentTemp.ambient_temp_f}
							{@const coolingPerHour = heatingChars.cooling_coefficient_k * deltaT * 60}
							<span class="text-slate-400">Current cooling rate</span>
							<span class="text-slate-200">{coolingPerHour.toFixed(1)}°F/hr <span class="text-slate-500">(ΔT={deltaT.toFixed(0)}°F)</span></span>
							{#if heatingChars.max_cooling_k != null}
								{@const worstCasePerHour = heatingChars.max_cooling_k * deltaT * 60}
								<span class="text-slate-400">Worst-case cooling</span>
								<span class="text-slate-200">{worstCasePerHour.toFixed(1)}°F/hr</span>
							{/if}
						{/if}
						<span class="text-slate-400">Sessions analyzed</span>
						<span class="text-slate-200">{heatingChars.sessions_analyzed}</span>
					</div>
					<p class="text-slate-500 text-xs">
						Generated: {new Date(heatingChars.generated_at).toLocaleString()}
					</p>
				</div>
			{:else}
				<p class="text-slate-400 text-sm mb-3">No analysis generated yet.</p>
			{/if}

			<div class="flex items-center gap-2 mb-3">
				<label for="lookbackDays" class="text-slate-400 text-sm">Analyze last</label>
				<input
					type="number"
					id="lookbackDays"
					bind:value={lookbackDays}
					min="1"
					max="90"
					class="w-16 bg-slate-700 border border-slate-600 rounded px-2 py-1 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
				/>
				<span class="text-slate-400 text-sm">days</span>
			</div>

			<button
				onclick={generateHeatingCharacteristics}
				disabled={generatingHeatingChars}
				class="bg-blue-600 hover:bg-blue-700 disabled:bg-blue-800 disabled:cursor-not-allowed text-white text-sm font-medium py-1.5 px-4 rounded transition-colors"
			>
				{generatingHeatingChars ? 'Analyzing...' : heatingChars ? 'Regenerate' : 'Generate Analysis'}
			</button>
			<p class="text-slate-500 text-xs mt-2">
				Analyzes temperature and equipment logs to measure heating rate, startup lag, and overshoot.
			</p>
		</div>
	{/if}
</div>
