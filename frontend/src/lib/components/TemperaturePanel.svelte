<script lang="ts">
	import { api, type TemperatureData } from '$lib/api';
	import { RefreshCw, Thermometer, ThermometerSun } from 'lucide-svelte';
	import { onMount } from 'svelte';

	let temperature = $state<TemperatureData | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);

	async function loadTemperature() {
		loading = true;
		error = null;

		try {
			temperature = await api.getTemperature();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load temperature';
		} finally {
			loading = false;
		}
	}

	async function handleRefresh() {
		await loadTemperature();
	}

	onMount(() => {
		loadTemperature();
	});
</script>

<div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700">
	<div class="flex items-center justify-between mb-2">
		<h3 class="text-sm font-medium text-slate-400">Temperature</h3>
		<button
			type="button"
			aria-label="Refresh temperature"
			onclick={handleRefresh}
			disabled={loading}
			class="p-1 text-slate-400 hover:text-slate-300 transition-colors rounded disabled:opacity-50"
		>
			<RefreshCw class="w-4 h-4 {loading ? 'animate-spin' : ''}" />
		</button>
	</div>

	{#if loading && !temperature}
		<div class="text-slate-400 text-sm py-2">
			Fetching temperature...
		</div>
	{:else if error && !temperature}
		<div class="text-red-400 text-sm py-2">
			Failed to load temperature
		</div>
	{:else if temperature}
		<div data-testid="temperature-readings" class="flex flex-wrap gap-x-4 gap-y-1">
			<!-- Water Temperature -->
			<div data-testid="water-temp" class="flex items-center gap-1.5 whitespace-nowrap">
				<Thermometer class="w-4 h-4 text-orange-400 shrink-0" />
				<span class="text-slate-300 text-sm">Water:</span>
				<span class="text-slate-100 font-medium">{temperature.water_temp_f.toFixed(1)}°F</span>
			</div>

			<!-- Ambient Temperature -->
			<div data-testid="ambient-temp" class="flex items-center gap-1.5 whitespace-nowrap">
				<ThermometerSun class="w-4 h-4 text-blue-400 shrink-0" />
				<span class="text-slate-300 text-sm">Ambient:</span>
				<span class="text-slate-100 font-medium">{temperature.ambient_temp_f.toFixed(0)}°F</span>
			</div>
		</div>

		{#if error}
			<div class="text-red-400 text-xs mt-1">
				Failed to refresh
			</div>
		{/if}
	{/if}
</div>
