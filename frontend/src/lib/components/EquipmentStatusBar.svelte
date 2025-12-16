<script lang="ts">
	import { RefreshCw } from 'lucide-svelte';
	import {
		fetchStatus,
		getLastUpdated,
		getIsLoading
	} from '$lib/stores/equipmentStatus.svelte';

	interface Props {
		onRefresh?: () => void;
	}

	let { onRefresh }: Props = $props();

	let isLoading = $derived(getIsLoading());
	let lastUpdated = $derived(getLastUpdated());

	// Reactive relative time calculation
	let now = $state(new Date());

	// Update "now" every 30 seconds for relative time display
	$effect(() => {
		const interval = setInterval(() => {
			now = new Date();
		}, 30000);
		return () => clearInterval(interval);
	});

	function formatRelativeTime(date: Date | null): string {
		if (!date) return 'Never';

		const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

		if (seconds < 5) return 'Just now';
		if (seconds < 60) return `${seconds}s ago`;
		if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
		if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
		return `${Math.floor(seconds / 86400)}d ago`;
	}

	async function handleRefresh() {
		await fetchStatus();
		onRefresh?.();
	}
</script>

<div class="flex items-center justify-between px-2 py-1 text-xs text-slate-400">
	<span>
		Status: <span class="text-slate-300">{formatRelativeTime(lastUpdated)}</span>
	</span>
	<button
		type="button"
		class="p-1 rounded hover:bg-slate-700/50 transition-colors disabled:opacity-50"
		onclick={handleRefresh}
		disabled={isLoading}
		title="Refresh equipment status"
	>
		<RefreshCw class="w-3.5 h-3.5 {isLoading ? 'animate-spin' : ''}" />
	</button>
</div>
