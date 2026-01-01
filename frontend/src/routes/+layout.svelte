<script lang="ts">
	import '../app.css';
	import { base } from '$app/paths';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';
	import { browser } from '$app/environment';

	let { children, data } = $props();

	// Track component lifecycle to prevent issues during tab restoration
	let mounted = $state(false);
	let redirectHandled = $state(false);

	onMount(() => {
		mounted = true;

		// Handle redirect if needed (only once per session)
		if (data.redirectTo && !redirectHandled) {
			// Use sessionStorage to track redirects across component remounts
			const redirectKey = 'sveltekit_redirect_handled';
			if (!sessionStorage.getItem(redirectKey)) {
				const currentPath = $page.url.pathname;
				const normalizedCurrent = currentPath.replace(/\/$/, '') || '/';
				const normalizedTarget = data.redirectTo.replace(/\/$/, '') || '/';

				if (normalizedCurrent !== normalizedTarget) {
					sessionStorage.setItem(redirectKey, 'true');
					redirectHandled = true;
					// Use replaceState to avoid history buildup
					goto(data.redirectTo, { replaceState: true });
				}
			}
		}

		// Clear redirect flag after successful page load
		return () => {
			// Only clear on unmount if we're navigating away (not on tab close)
			if (browser) {
				sessionStorage.removeItem('sveltekit_redirect_handled');
			}
		};
	});
</script>

<svelte:head>
	<link rel="icon" href="{base}/favicon.ico" />
</svelte:head>

{#if mounted || !browser}
	{@render children()}
{:else}
	<!-- Brief loading state during hydration -->
	<div class="min-h-screen bg-slate-900"></div>
{/if}
