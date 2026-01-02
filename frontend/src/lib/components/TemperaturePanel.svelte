<script lang="ts">
	import { api, type AllTemperaturesResponse } from '$lib/api';
	import { getTempSourceSettings } from '$lib/settings';
	import { RefreshCw, Thermometer, ThermometerSun, Cpu, Radio } from 'lucide-svelte';
	import { onMount } from 'svelte';

	// Export loadTemperature for parent components to trigger refresh
	export { loadTemperature };

	const POLL_INTERVAL_MS = 3000;
	const MAX_POLL_ATTEMPTS = 5;

	let allTemps = $state<AllTemperaturesResponse | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);
	// Track when WirelessTag data was last fetched (frontend timestamp)
	let wirelesstagFetchedAt = $state<number | null>(null);
	// Track refresh state separately for each source
	let refreshingWirelesstag = $state(false);
	let refreshingEsp32 = $state(false);

	// Get current source settings
	let sourceSettings = $state(getTempSourceSettings());

	// Refresh settings when component becomes visible
	function refreshSettings() {
		sourceSettings = getTempSourceSettings();
	}

	async function loadTemperature() {
		loading = true;
		error = null;
		refreshSettings();

		try {
			const data = await api.getAllTemperatures();
			allTemps = data;
			wirelesstagFetchedAt = Date.now();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load temperature';
		} finally {
			loading = false;
		}
	}

	async function pollForFreshReading(attemptCount: number = 0): Promise<void> {
		if (attemptCount >= MAX_POLL_ATTEMPTS) {
			refreshingWirelesstag = false;
			return;
		}

		try {
			const data = await api.getAllTemperatures();
			allTemps = data;
			wirelesstagFetchedAt = Date.now();

			// Check WirelessTag refresh status
			if (data.wirelesstag?.refresh_in_progress) {
				setTimeout(() => pollForFreshReading(attemptCount + 1), POLL_INTERVAL_MS);
			} else {
				refreshingWirelesstag = false;
			}
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to load temperature';
			refreshingWirelesstag = false;
		}
	}

	async function handleWirelesstagRefresh() {
		error = null;
		refreshingWirelesstag = true;

		try {
			// Request hardware sensor refresh (WirelessTag)
			await api.refreshTemperature();
			// Then start polling for the fresh reading
			await pollForFreshReading(0);
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to refresh';
			refreshingWirelesstag = false;
		}
	}

	async function handleEsp32Refresh() {
		error = null;
		refreshingEsp32 = true;

		try {
			// Just re-fetch all temperatures - ESP32 timestamp comes from backend
			const data = await api.getAllTemperatures();
			allTemps = data;
			wirelesstagFetchedAt = Date.now();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to refresh';
		} finally {
			refreshingEsp32 = false;
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

	// Computed: which sources to show
	let showEsp32 = $derived(sourceSettings.esp32Enabled && allTemps?.esp32 && !allTemps.esp32.error);
	let showWirelessTag = $derived(
		sourceSettings.wirelessTagEnabled && allTemps?.wirelesstag && !allTemps.wirelesstag.error
	);
	let hasAnyData = $derived(showEsp32 || showWirelessTag);

	// Check if ESP32 data is stale (older than 10 minutes)
	const STALE_THRESHOLD_MS = 10 * 60 * 1000; // 10 minutes

	function isDataStale(timestamp: string | undefined): boolean {
		if (!timestamp) return false;
		const dataTime = new Date(timestamp).getTime();
		const now = Date.now();
		return now - dataTime > STALE_THRESHOLD_MS;
	}

	let esp32IsStale = $derived(showEsp32 && allTemps?.esp32?.timestamp ? isDataStale(allTemps.esp32.timestamp) : false);
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
	{:else if !hasAnyData}
		<div class="text-slate-500 text-sm py-2">
			No temperature sources enabled or available.
			<br />
			<span class="text-xs">Check Settings to enable temperature sources.</span>
		</div>
	{:else}
		<div class="space-y-3">
			<!-- ESP32 Source -->
			{#if showEsp32 && allTemps?.esp32}
				<div data-testid="esp32-section" class="border-l-2 {esp32IsStale ? 'border-amber-500/50' : 'border-green-500/50'} pl-2">
					<div class="flex items-center justify-between gap-1 mb-1">
						<div class="flex items-center gap-1">
							<Cpu class="w-3 h-3 {esp32IsStale ? 'text-amber-400' : 'text-green-400'}" />
							<span class="text-xs {esp32IsStale ? 'text-amber-400' : 'text-green-400'} font-medium">ESP32</span>
							{#if esp32IsStale}
								<span data-testid="esp32-stale-warning" class="text-xs text-amber-400">(stale)</span>
							{/if}
						</div>
						<div class="flex items-center gap-1.5">
							<!-- ESP32 timestamp (when device last phoned home to backend) -->
							{#if allTemps.esp32.timestamp}
								<span data-testid="esp32-timestamp" class="text-xs {esp32IsStale ? 'text-amber-500' : 'text-slate-500'}">
									Last reading: {formatTimestamp(allTemps.esp32.timestamp)}
								</span>
							{/if}
							<!-- ESP32 refresh button -->
							<button
								type="button"
								data-testid="esp32-refresh"
								aria-label="Refresh ESP32 temperature"
								onclick={handleEsp32Refresh}
								disabled={refreshingEsp32}
								class="p-1 {esp32IsStale ? 'text-amber-400 hover:text-amber-300' : 'text-green-400 hover:text-green-300'} transition-colors rounded disabled:opacity-50"
							>
								<RefreshCw class="w-3.5 h-3.5 {refreshingEsp32 ? 'animate-spin' : ''}" />
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
			{/if}

			<!-- WirelessTag Source -->
			{#if showWirelessTag && allTemps?.wirelesstag}
				<div data-testid="wirelesstag-section" class="border-l-2 border-purple-500/50 pl-2">
					<div class="flex items-center justify-between gap-1 mb-1">
						<div class="flex items-center gap-1">
							<Radio class="w-3 h-3 text-purple-400" />
							<span class="text-xs text-purple-400 font-medium">WirelessTag</span>
						</div>
						<div class="flex items-center gap-1.5">
							<!-- WirelessTag timestamp (when data was fetched from API) -->
							{#if wirelesstagFetchedAt}
								<span data-testid="wirelesstag-timestamp" class="text-xs text-slate-500">
									Last fetched: {formatTimestamp(wirelesstagFetchedAt)}
								</span>
							{/if}
							<!-- WirelessTag refresh button -->
							<button
								type="button"
								data-testid="wirelesstag-refresh"
								aria-label="Refresh WirelessTag temperature"
								onclick={handleWirelesstagRefresh}
								disabled={refreshingWirelesstag}
								class="p-1 text-purple-400 hover:text-purple-300 transition-colors rounded disabled:opacity-50"
							>
								<RefreshCw class="w-3.5 h-3.5 {refreshingWirelesstag ? 'animate-spin' : ''}" />
							</button>
						</div>
					</div>
					{#if refreshingWirelesstag}
						<div class="text-blue-400 text-xs mb-1">Refreshing sensor...</div>
					{/if}
					<div data-testid="wirelesstag-readings" class="flex flex-wrap gap-x-4 gap-y-1">
						{#if allTemps.wirelesstag.water_temp_f !== null}
							<div class="flex items-center gap-1.5 whitespace-nowrap">
								<Thermometer class="w-4 h-4 text-orange-400 shrink-0" />
								<span class="text-slate-300 text-sm">Water:</span>
								<span class="text-slate-100 font-medium"
									>{formatTemp(allTemps.wirelesstag.water_temp_f)}°F</span
								>
							</div>
						{/if}
						{#if allTemps.wirelesstag.ambient_temp_f !== null}
							<div class="flex items-center gap-1.5 whitespace-nowrap">
								<ThermometerSun class="w-4 h-4 text-blue-400 shrink-0" />
								<span class="text-slate-300 text-sm">Ambient:</span>
								<span class="text-slate-100 font-medium"
									>{formatTemp(allTemps.wirelesstag.ambient_temp_f, 0)}°F</span
								>
							</div>
						{/if}
					</div>
				</div>
			{/if}
		</div>

		{#if error}
			<div class="text-red-400 text-xs mt-1">Failed to refresh</div>
		{/if}
	{/if}
</div>
