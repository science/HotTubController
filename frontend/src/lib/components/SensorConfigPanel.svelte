<script lang="ts">
	import { api, type Esp32Sensor, type SensorRole } from '$lib/api';

	let sensors = $state<Esp32Sensor[]>([]);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let saving = $state<string | null>(null); // Address of sensor being saved

	const roleOptions: { value: SensorRole; label: string }[] = [
		{ value: 'unassigned', label: 'Unassigned' },
		{ value: 'water', label: 'Water Temperature' },
		{ value: 'ambient', label: 'Ambient Temperature' }
	];

	async function loadSensors() {
		loading = true;
		error = null;
		try {
			const response = await api.listEsp32Sensors();
			sensors = response.sensors;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load sensors';
		} finally {
			loading = false;
		}
	}

	async function updateSensor(
		address: string,
		field: 'role' | 'calibration_offset' | 'name',
		value: string | number
	) {
		saving = address;
		error = null;
		try {
			const data: Record<string, string | number> = {};
			data[field] = value;
			await api.updateEsp32Sensor(address, data);
			// Reload to get updated data
			await loadSensors();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to save';
		} finally {
			saving = null;
		}
	}

	function handleRoleChange(address: string, event: Event) {
		const target = event.target as HTMLSelectElement;
		updateSensor(address, 'role', target.value);
	}

	function handleOffsetChange(address: string, event: Event) {
		const target = event.target as HTMLInputElement;
		const value = parseFloat(target.value);
		if (!isNaN(value)) {
			updateSensor(address, 'calibration_offset', value);
		}
	}

	function handleNameChange(address: string, event: Event) {
		const target = event.target as HTMLInputElement;
		updateSensor(address, 'name', target.value);
	}

	// Load on mount
	$effect(() => {
		loadSensors();
	});
</script>

<div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
	<div class="flex items-center justify-between mb-4">
		<h2 class="text-lg font-semibold text-slate-200">ESP32 Sensor Configuration</h2>
		<button
			onclick={() => loadSensors()}
			disabled={loading}
			class="text-slate-400 hover:text-slate-200 transition-colors"
			title="Refresh sensors"
		>
			<svg
				class="w-5 h-5 {loading ? 'animate-spin' : ''}"
				fill="none"
				viewBox="0 0 24 24"
				stroke="currentColor"
			>
				<path
					stroke-linecap="round"
					stroke-linejoin="round"
					stroke-width="2"
					d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
				/>
			</svg>
		</button>
	</div>

	{#if error}
		<div class="bg-red-500/10 border border-red-500/30 rounded-lg p-3 mb-4">
			<p class="text-red-400 text-sm">{error}</p>
		</div>
	{/if}

	{#if loading && sensors.length === 0}
		<div class="flex items-center justify-center py-8">
			<div class="animate-spin w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full">
			</div>
		</div>
	{:else if sensors.length === 0}
		<div class="text-slate-400 text-sm text-center py-8">
			<p>No ESP32 sensors detected.</p>
			<p class="text-slate-500 mt-1">Connect sensors and wait for data.</p>
		</div>
	{:else}
		<div class="space-y-4">
			{#each sensors as sensor (sensor.address)}
				<div
					class="bg-slate-700/50 rounded-lg p-4 border border-slate-600 {saving === sensor.address
						? 'opacity-50'
						: ''}"
				>
					<!-- Address and current temp -->
					<div class="flex items-start justify-between mb-3">
						<div>
							<span class="text-slate-400 text-xs font-mono">{sensor.address}</span>
							{#if sensor.name}
								<p class="text-slate-200 font-medium">{sensor.name}</p>
							{/if}
						</div>
						<div class="text-right">
							<span class="text-lg font-bold text-slate-100">
								{sensor.temp_c.toFixed(1)}°C
							</span>
							{#if sensor.temp_f}
								<span class="text-slate-400 text-sm ml-1">
									({sensor.temp_f.toFixed(1)}°F)
								</span>
							{/if}
						</div>
					</div>

					<!-- Configuration fields -->
					<div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
						<!-- Role selector -->
						<div>
							<label class="text-slate-400 text-xs block mb-1">Role</label>
							<select
								value={sensor.role}
								onchange={(e) => handleRoleChange(sensor.address, e)}
								disabled={saving === sensor.address}
								class="w-full bg-slate-600 border border-slate-500 rounded px-2 py-1.5 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
							>
								{#each roleOptions as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
						</div>

						<!-- Calibration offset -->
						<div>
							<label class="text-slate-400 text-xs block mb-1">Calibration Offset</label>
							<div class="flex items-center gap-1">
								<input
									type="number"
									value={sensor.calibration_offset}
									step="0.1"
									onblur={(e) => handleOffsetChange(sensor.address, e)}
									disabled={saving === sensor.address}
									class="w-full bg-slate-600 border border-slate-500 rounded px-2 py-1.5 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
								/>
								<span class="text-slate-400 text-sm">°C</span>
							</div>
						</div>

						<!-- Friendly name -->
						<div>
							<label class="text-slate-400 text-xs block mb-1">Name</label>
							<input
								type="text"
								value={sensor.name}
								placeholder="Optional name"
								onblur={(e) => handleNameChange(sensor.address, e)}
								disabled={saving === sensor.address}
								class="w-full bg-slate-600 border border-slate-500 rounded px-2 py-1.5 text-slate-100 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 placeholder:text-slate-500"
							/>
						</div>
					</div>
				</div>
			{/each}
		</div>
	{/if}
</div>
