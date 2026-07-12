<script lang="ts">
	import { base } from '$app/paths';
	import { page } from '$app/stores';
	import { goto, beforeNavigate } from '$app/navigation';
	import { onMount } from 'svelte';
	import { logout } from '$lib/stores/auth.svelte';
	import { visibleTabs, friendlyRoleName, type Tab } from '$lib/roles';
	import {
		hasPendingEdits,
		pendingEdits,
		saveAllPendingEdits,
		discardAllPendingEdits
	} from '$lib/stores/pendingEdits.svelte';
	import UnsavedChangesModal from '$lib/components/UnsavedChangesModal.svelte';

	let { children, data } = $props();

	const role = $derived(data.user?.role);
	const tabs = $derived(visibleTabs(role));

	const TAB_META: Record<Tab, { label: string; path: string }> = {
		home: { label: 'Home', path: '' },
		schedule: { label: 'Schedule', path: '/schedule' },
		setup: { label: 'Setup', path: '/setup' }
	};

	function tabHref(tab: Tab): string {
		return `${base}${TAB_META[tab].path}/`;
	}

	// Which tab the current URL belongs to, for active-state styling.
	const activeTab = $derived.by((): Tab => {
		const path = $page.url.pathname.replace(/\/+$/, '');
		if (path.endsWith('/schedule')) return 'schedule';
		if (path.endsWith('/setup')) return 'setup';
		return 'home';
	});

	onMount(() => {
		// Fallback guard: the root layout also redirects, but if we land here without a
		// session (expired cookie, blocked redirect) send to login.
		if (!data.user) {
			goto(`${base}/login`, { replaceState: true });
		}
		// Hard navigations (close tab / reload) can't show our modal — fall back to the
		// browser's native prompt so an in-flight schedule edit isn't silently lost.
		const onBeforeUnload = (event: BeforeUnloadEvent) => {
			if (hasPendingEdits()) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener('beforeunload', onBeforeUnload);
		return () => window.removeEventListener('beforeunload', onBeforeUnload);
	});

	async function handleLogout() {
		await logout();
		goto(`${base}/login`);
	}

	// ── Unsaved-changes guard ────────────────────────────────────────────────────
	// A schedule edit stages locally (see pendingEdits.svelte). Leaving a tab with one
	// unsaved would silently lose it, so intercept the navigation and prompt.
	let guardOpen = $state(false);
	let guardBusy = $state(false);
	let guardError = $state<string | null>(null);
	let pendingUrl = $state<URL | null>(null);
	const guardLines = $derived(pendingEdits().map((e) => e.describe()));

	beforeNavigate((nav) => {
		if (!hasPendingEdits()) return;
		if (nav.type === 'leave') return; // full-page unload → native beforeunload handles it
		if (!nav.to) return;
		nav.cancel();
		pendingUrl = nav.to.url;
		guardError = null;
		guardOpen = true;
	});

	function proceed() {
		guardOpen = false;
		const url = pendingUrl;
		pendingUrl = null;
		// Registry is now empty → the re-issued navigation passes the guard above.
		if (url) goto(url);
	}

	async function guardSave() {
		guardBusy = true;
		guardError = null;
		try {
			await saveAllPendingEdits();
			proceed();
		} catch (e) {
			guardError = e instanceof Error ? e.message : "Couldn't save the change. Try again.";
		} finally {
			guardBusy = false;
		}
	}

	function guardDiscard() {
		discardAllPendingEdits();
		proceed();
	}

	function guardStay() {
		guardOpen = false;
		pendingUrl = null;
		guardError = null;
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

	<UnsavedChangesModal
		open={guardOpen}
		lines={guardLines}
		busy={guardBusy}
		error={guardError}
		onSave={guardSave}
		onDiscard={guardDiscard}
		onStay={guardStay}
	/>
</div>
