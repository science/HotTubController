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

	// Auto heat-off state (loaded from localStorage)
	let autoHeatOffEnabled = $state(getAutoHeatOffEnabled());
	let autoHeatOffMinutes = $state(getAutoHeatOffMinutes());

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
		const value = parseInt(target.value, 10);
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
					<span class="text-orange-400 font-medium">{targetTempF}°F</span>
				</div>
				<input
					type="range"
					id="targetTempSlider"
					aria-label="Target temp"
					value={targetTempF}
					oninput={handleTargetTempChange}
					min={TARGET_TEMP_DEFAULTS.minTempF}
					max={TARGET_TEMP_DEFAULTS.maxTempF}
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
</div>
