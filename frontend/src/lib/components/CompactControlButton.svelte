<script lang="ts">
	import { Flame, RefreshCw, Loader2, Ban, ChevronsUp, ChevronsDown } from 'lucide-svelte';

	interface Props {
		label: string;
		icon: 'flame' | 'flame-off' | 'refresh' | 'blinds-open' | 'blinds-close';
		onClick: () => Promise<void>;
		variant?: 'primary' | 'secondary' | 'tertiary' | 'accent';
		tooltip?: string;
		active?: boolean;
		size?: 'normal' | 'compact';
	}

	let {
		label,
		icon,
		onClick,
		variant = 'primary',
		tooltip = '',
		active = false,
		size = 'normal'
	}: Props = $props();

	// Normal: vertical layout (icon above text), Compact: horizontal layout (icon beside text)
	const sizeClasses = {
		normal: 'flex-col p-2 gap-1',
		compact: 'flex-row py-1.5 px-2 gap-1.5'
	};

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
		tertiary: 'bg-slate-800 hover:bg-cyan-500/20 border-cyan-500/50 text-cyan-400',
		accent: 'bg-slate-800 hover:bg-purple-500/20 border-purple-500/50 text-purple-400'
	};

	const activeClasses = {
		primary:
			'bg-orange-500/30 border-orange-400 text-orange-300 shadow-[0_0_12px_rgba(251,146,60,0.5)] ring-2 ring-orange-400/50',
		secondary:
			'bg-blue-500/30 border-blue-400 text-blue-300 shadow-[0_0_12px_rgba(96,165,250,0.5)] ring-2 ring-blue-400/50',
		tertiary:
			'bg-cyan-500/30 border-cyan-400 text-cyan-300 shadow-[0_0_12px_rgba(34,211,238,0.5)] ring-2 ring-cyan-400/50',
		accent:
			'bg-purple-500/30 border-purple-400 text-purple-300 shadow-[0_0_12px_rgba(192,132,252,0.5)] ring-2 ring-purple-400/50'
	};
</script>

<div class="relative">
	<button
		type="button"
		class="compact-btn {variant} {active ? activeClasses[variant] : variantClasses[variant]} {sizeClasses[size]} flex items-center justify-center rounded-lg border transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95 w-full"
		onclick={handleClick}
		onmousedown={handlePressStart}
		onmouseup={handlePressEnd}
		onmouseleave={handlePressEnd}
		ontouchstart={handlePressStart}
		ontouchend={handlePressEnd}
		ontouchcancel={handlePressEnd}
		disabled={loading}
	>
		<span class="icon-wrapper flex items-center justify-center {size === 'compact' ? 'w-4 h-4' : 'w-6 h-6'}">
			{#if loading}
				<Loader2 class="{size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} animate-spin" />
			{:else if icon === 'flame'}
				<Flame class={size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} />
			{:else if icon === 'flame-off'}
				<span class="relative">
					<Flame class="{size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} opacity-40" />
					<Ban class="{size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} absolute inset-0" />
				</span>
			{:else if icon === 'refresh'}
				<RefreshCw class={size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} />
			{:else if icon === 'blinds-open'}
				<ChevronsUp class={size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} />
			{:else if icon === 'blinds-close'}
				<ChevronsDown class={size === 'compact' ? 'w-3.5 h-3.5' : 'w-5 h-5'} />
			{/if}
		</span>
		<span class="label font-medium {size === 'compact' ? 'text-xs' : 'text-xs'}">{label}</span>
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
