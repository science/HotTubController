<script lang="ts">
	import { base } from '$app/paths';
	import { page } from '$app/stores';
	import { goto } from '$app/navigation';
	import { onMount } from 'svelte';
	import { logout } from '$lib/stores/auth.svelte';
	import { visibleTabs, friendlyRoleName, type Tab } from '$lib/roles';

	let { children, data } = $props();

	const role = $derived(data.user?.role);
	const tabs = $derived(visibleTabs(role));

	const TAB_META: Record<Tab, { label: string; path: string }> = {
		home: { label: 'Home', path: '' },
		schedule: { label: 'Schedule', path: '/schedule' },
		setup: { label: 'Setup', path: '/setup' }
	};

	function tabHref(tab: Tab): string {
		return `${base}/v2${TAB_META[tab].path}`;
	}

	// Which tab the current URL belongs to, for active-state styling.
	const activeTab = $derived.by((): Tab => {
		const path = $page.url.pathname.replace(/\/+$/, '');
		if (path.endsWith('/v2/schedule')) return 'schedule';
		if (path.endsWith('/v2/setup')) return 'setup';
		return 'home';
	});

	onMount(() => {
		// Fallback guard: the root layout also redirects, but if we land here without a
		// session (expired cookie, blocked redirect) send to login.
		if (!data.user) {
			goto(`${base}/login`, { replaceState: true });
		}
	});

	async function handleLogout() {
		await logout();
		goto(`${base}/login`);
	}
</script>

<div class="min-h-screen bg-slate-900 text-slate-100 flex flex-col">
	<header class="flex items-center justify-between px-4 py-3 border-b border-slate-800">
		<h1 class="text-lg font-bold tracking-wide">Hot Tub</h1>
		{#if data.user}
			<div class="flex items-center gap-3 text-sm">
				<span class="text-slate-400" data-testid="role-label">{friendlyRoleName(role)}</span>
				<button onclick={handleLogout} class="text-slate-500 hover:text-slate-300 underline">
					Logout
				</button>
			</div>
		{/if}
	</header>

	<main class="flex-1 overflow-y-auto w-full max-w-md mx-auto p-4">
		{@render children()}
	</main>

	{#if tabs.length > 1}
		<nav
			class="border-t border-slate-800 bg-slate-900 sticky bottom-0"
			aria-label="Primary"
		>
			<ul class="flex max-w-md mx-auto">
				{#each tabs as tab (tab)}
					<li class="flex-1">
						<a
							href={tabHref(tab)}
							aria-current={activeTab === tab ? 'page' : undefined}
							data-testid="tab-{tab}"
							class="block text-center py-3 text-sm font-medium transition-colors {activeTab ===
							tab
								? 'text-orange-400'
								: 'text-slate-400 hover:text-slate-200'}"
						>
							{TAB_META[tab].label}
						</a>
					</li>
				{/each}
			</ul>
		</nav>
	{/if}
</div>
