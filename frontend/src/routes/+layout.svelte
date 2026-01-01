<script lang="ts">
	import '../app.css';
	import { base } from '$app/paths';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';

	let { children, data } = $props();

	// Track if we've already redirected to prevent loops
	let hasRedirected = false;

	// Handle redirect if needed (only once)
	onMount(() => {
		if (data.redirectTo && !hasRedirected) {
			const currentPath = $page.url.pathname;
			// Normalize paths for comparison (handle trailing slashes)
			const normalizedCurrent = currentPath.replace(/\/$/, '') || '/';
			const normalizedTarget = data.redirectTo.replace(/\/$/, '') || '/';

			if (normalizedCurrent !== normalizedTarget) {
				hasRedirected = true;
				goto(data.redirectTo);
			}
		}
	});
</script>

<svelte:head>
	<link rel="icon" href="{base}/favicon.ico" />
</svelte:head>

{@render children()}
