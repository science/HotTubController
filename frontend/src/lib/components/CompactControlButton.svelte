<script lang="ts">
	import { Flame, RefreshCw, Loader2, Ban } from 'lucide-svelte';

	interface Props {
		label: string;
		icon: 'flame' | 'flame-off' | 'refresh';
		onClick: () => Promise<void>;
		variant?: 'primary' | 'secondary' | 'tertiary';
		tooltip?: string;
	}

	let { label, icon, onClick, variant = 'primary', tooltip = '' }: Props = $props();

	let loading = $state(false);
	let showTooltip = $state(false);
	let pressTimer: ReturnType<typeof setTimeout> | null = null;

	async function handleClick() {
		if (loading || showTooltip) return;
		loading = true;
		try {
			await onClick();
		} finally {
			loading = false;
		}
	}

	function handlePressStart() {
		pressTimer = setTimeout(() => {
			showTooltip = true;
		}, 500);
	}

	function handlePressEnd() {
		if (pressTimer) {
			clearTimeout(pressTimer);
			pressTimer = null;
		}
		if (showTooltip) {
			setTimeout(() => {
				showTooltip = false;
			}, 100);
		}
	}

	const variantClasses = {
		primary: 'bg-slate-800 hover:bg-orange-500/20 border-orange-500/50 text-orange-400',
		secondary: 'bg-slate-800 hover:bg-blue-500/20 border-blue-500/50 text-blue-400',
		tertiary: 'bg-slate-800 hover:bg-cyan-500/20 border-cyan-500/50 text-cyan-400'
	};
</script>

<div class="relative">
	<button
		type="button"
		class="compact-btn {variant} {variantClasses[variant]} flex flex-col items-center justify-center gap-1 p-2 rounded-lg border transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95 w-full"
		onclick={handleClick}
		onmousedown={handlePressStart}
		onmouseup={handlePressEnd}
		onmouseleave={handlePressEnd}
		ontouchstart={handlePressStart}
		ontouchend={handlePressEnd}
		ontouchcancel={handlePressEnd}
		disabled={loading}
	>
		<span class="icon-wrapper w-6 h-6 flex items-center justify-center">
			{#if loading}
				<Loader2 class="w-5 h-5 animate-spin" />
			{:else if icon === 'flame'}
				<Flame class="w-5 h-5" />
			{:else if icon === 'flame-off'}
				<span class="relative">
					<Flame class="w-5 h-5 opacity-40" />
					<Ban class="w-5 h-5 absolute inset-0" />
				</span>
			{:else if icon === 'refresh'}
				<RefreshCw class="w-5 h-5" />
			{/if}
		</span>
		<span class="label text-xs font-medium">{label}</span>
	</button>

	{#if showTooltip && tooltip}
		<div
			class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-3 py-2 bg-slate-700 text-slate-100 text-xs rounded-lg shadow-lg whitespace-nowrap z-10"
		>
			{tooltip}
			<div
				class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-slate-700"
			></div>
		</div>
	{/if}
</div>
