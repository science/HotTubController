<script lang="ts">
	import { api, type AllTemperaturesResponse } from '$lib/api';
	import { RefreshCw, Thermometer, ThermometerSun, Cpu } from 'lucide-svelte';
	import { onMount } from 'svelte';

	// Export loadTemperature for parent components to trigger refresh
	export { loadTemperature };

	let allTemps = $state<AllTemperaturesResponse | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let refreshing = $state(false);

	async function loadTemperature() {
		loading = true;
		error = null;

		try {
			const data = await api.getAllTemperatures();
			allTemps = data;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load temperature';
		} finally {
			loading = false;
		}
	}

	async function handleRefresh() {
		error = null;
		refreshing = true;

		try {
			const data = await api.getAllTemperatures();
			allTemps = data;
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to refresh';
		} finally {
			refreshing = false;
		}
	}

	onMount(() => {
		loadTemperature();
	});

	function formatTimestamp(timestamp: number | string): string {
		const date = typeof timestamp === 'string' ? new Date(timestamp) : new Date(timestamp);
		return date.toLocaleString(undefined, {
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit'
		});
	}

	function formatTemp(temp: number | null | undefined, decimals: number = 1): string {
		if (temp === null || temp === undefined) return '--';
		return temp.toFixed(decimals);
	}

	// Computed: check if we have ESP32 data
	let hasData = $derived(allTemps?.esp32 && !allTemps.esp32.error);

	// Check if ESP32 data is stale (older than 10 minutes)
	const STALE_THRESHOLD_MS = 10 * 60 * 1000; // 10 minutes

	function isDataStale(timestamp: string | undefined): boolean {
		if (!timestamp) return false;
		const dataTime = new Date(timestamp).getTime();
		const now = Date.now();
		return now - dataTime > STALE_THRESHOLD_MS;
	}

	let isStale = $derived(hasData && allTemps?.esp32?.timestamp ? isDataStale(allTemps.esp32.timestamp) : false);
</script>

<div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700">
	<div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 mb-2">
		<h3 class="text-sm font-medium text-slate-400">Temperature</h3>
	</div>

	{#if loading && !allTemps}
		<div class="text-slate-400 text-sm py-2">Fetching temperature...</div>
	{:else if error && !allTemps}
		<div class="text-amber-400 text-sm py-2">
			{error}
		</div>
	{:else if !hasData}
		<div class="text-slate-500 text-sm py-2">
			No temperature data available.
			<br />
			<span class="text-xs">Check ESP32 sensor configuration.</span>
		</div>
	{:else if allTemps?.esp32}
		<div class="space-y-3">
			<!-- ESP32 Source -->
			<div data-testid="esp32-section" class="border-l-2 {isStale ? 'border-amber-500/50' : 'border-green-500/50'} pl-2">
				<div class="flex items-center justify-between gap-1 mb-1">
					<div class="flex items-center gap-1">
						<Cpu class="w-3 h-3 {isStale ? 'text-amber-400' : 'text-green-400'}" />
						<span class="text-xs {isStale ? 'text-amber-400' : 'text-green-400'} font-medium">ESP32</span>
						{#if isStale}
							<span data-testid="esp32-stale-warning" class="text-xs text-amber-400">(stale)</span>
						{/if}
					</div>
					<div class="flex items-center gap-1.5">
						<!-- ESP32 timestamp (when device last phoned home to backend) -->
						{#if allTemps.esp32.timestamp}
							<span data-testid="esp32-timestamp" class="text-xs {isStale ? 'text-amber-500' : 'text-slate-500'}">
								Last reading: {formatTimestamp(allTemps.esp32.timestamp)}
							</span>
						{/if}
						<!-- Refresh button -->
						<button
							type="button"
							data-testid="esp32-refresh"
							aria-label="Refresh ESP32 temperature"
							onclick={handleRefresh}
							disabled={refreshing}
							class="p-1 {isStale ? 'text-amber-400 hover:text-amber-300' : 'text-green-400 hover:text-green-300'} transition-colors rounded disabled:opacity-50"
						>
							<RefreshCw class="w-3.5 h-3.5 {refreshing ? 'animate-spin' : ''}" />
						</button>
					</div>
				</div>
				<div data-testid="esp32-readings" class="flex flex-wrap gap-x-4 gap-y-1">
					{#if allTemps.esp32.water_temp_f !== null}
						<div class="flex items-center gap-1.5 whitespace-nowrap">
							<Thermometer class="w-4 h-4 text-orange-400 shrink-0" />
							<span class="text-slate-300 text-sm">Water:</span>
							<span class="text-slate-100 font-medium"
								>{formatTemp(allTemps.esp32.water_temp_f)}°F</span
							>
						</div>
					{/if}
					{#if allTemps.esp32.ambient_temp_f !== null}
						<div class="flex items-center gap-1.5 whitespace-nowrap">
							<ThermometerSun class="w-4 h-4 text-blue-400 shrink-0" />
							<span class="text-slate-300 text-sm">Ambient:</span>
							<span class="text-slate-100 font-medium"
								>{formatTemp(allTemps.esp32.ambient_temp_f, 0)}°F</span
							>
						</div>
					{/if}
				</div>
			</div>
		</div>

		{#if error}
			<div class="text-red-400 text-xs mt-1">Failed to refresh</div>
		{/if}
	{/if}
</div>
