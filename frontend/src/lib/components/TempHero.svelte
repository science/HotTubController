<script lang="ts">
	import { api, type AllTemperaturesResponse } from '$lib/api';
	import { RefreshCw } from 'lucide-svelte';
	import { onMount } from 'svelte';
	import { formatRelativeAge, isStaleReading } from '$lib/relativeTime';

	/**
	 * v2 Home temperature hero: the water temperature IS the product on the "now"
	 * surface, so it gets the big number. Sensor/device detail (ESP32, addresses,
	 * calibration) lives in Setup, not here.
	 *
	 * States: loading → big reading (fresh) → amber note when stale (≥10 min, data
	 * still shown — stale is information, not an apology) → plain-language error.
	 */
	let allTemps = $state<AllTemperaturesResponse | null>(null);
	let loading = $state(true);
	let error = $state<string | null>(null);
	let refreshing = $state(false);
	// Ticks every 30s so the "2m ago" age line stays honest without refetching.
	let now = $state(new Date());

	async function refresh() {
		refreshing = true;
		error = null;
		try {
			allTemps = await api.getAllTemperatures();
			now = new Date();
		} catch {
			error = "Couldn't reach the tub. Check power and try again.";
		} finally {
			loading = false;
			refreshing = false;
		}
	}

	onMount(() => {
		refresh();
		const tick = setInterval(() => (now = new Date()), 30_000);
		return () => clearInterval(tick);
	});

	const esp32 = $derived(allTemps?.esp32 && !allTemps.esp32.error ? allTemps.esp32 : null);
	const stale = $derived(esp32 ? isStaleReading(esp32.timestamp, now) : false);
	const age = $derived(esp32?.timestamp ? formatRelativeAge(esp32.timestamp, now) : null);

	function fmt(t: number | null | undefined, decimals = 1): string {
		if (t === null || t === undefined) return '--';
		return t.toFixed(decimals);
	}
</script>

<div class="rounded-xl border border-slate-700 bg-slate-800/50 px-3 py-4" data-testid="temp-hero">
	{#if loading && !allTemps}
		<p class="py-6 text-center text-sm text-slate-400">Fetching temperature…</p>
	{:else if error && !esp32}
		<p class="py-6 text-center text-sm text-amber-400" data-testid="hero-error">{error}</p>
	{:else if !esp32}
		<p class="py-6 text-center text-sm text-slate-500" data-testid="hero-nodata">
			No temperature data. Check the sensor in Setup.
		</p>
	{:else}
		<div class="relative">
			<button
				type="button"
				aria-label="Refresh temperature"
				onclick={refresh}
				disabled={refreshing}
				class="absolute right-0 top-0 rounded p-1 transition-colors {stale
					? 'text-amber-400 hover:text-amber-300'
					: 'text-slate-500 hover:text-slate-300'} disabled:opacity-50"
			>
				<RefreshCw class="h-4 w-4 {refreshing ? 'animate-spin' : ''}" />
			</button>

			<div class="flex flex-col items-center">
				<p class="text-6xl font-semibold tabular-nums tracking-tight text-slate-50" data-testid="hero-water">
					{fmt(esp32.water_temp_f)}<span class="align-top text-3xl text-slate-400">°</span>
				</p>
				<p class="mt-0.5 text-xs uppercase tracking-widest text-slate-500">water</p>

				<p class="mt-2 text-sm text-slate-400" data-testid="hero-subline">
					{#if esp32.ambient_temp_f !== null && esp32.ambient_temp_f !== undefined}
						air {fmt(esp32.ambient_temp_f, 0)}°
						{#if age && !stale}<span aria-hidden="true"> · </span>{/if}
					{/if}
					{#if age && !stale}{age}{/if}
				</p>
				{#if stale}
					<p class="mt-1 text-xs text-amber-400" data-testid="hero-stale">
						Last reading {age}
					</p>
				{/if}
				{#if error}
					<p class="mt-1 text-xs text-red-400">Couldn't refresh — showing the last reading.</p>
				{/if}
			</div>
		</div>
	{/if}
</div>
