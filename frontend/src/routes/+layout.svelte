<script lang="ts">
	import '../app.css';
	import favicon from '$lib/assets/favicon.svg';
	import { goto } from '$app/navigation';
	import { page } from '$app/stores';
	import { onMount } from 'svelte';

	let { children, data } = $props();

	// Handle redirect if needed
	onMount(() => {
		if (data.redirectTo && $page.url.pathname !== data.redirectTo) {
			goto(data.redirectTo);
		}
	});

	// Also watch for changes
	$effect(() => {
		if (data.redirectTo && $page.url.pathname !== data.redirectTo) {
			goto(data.redirectTo);
		}
	});
</script>

<svelte:head>
	<link rel="icon" href={favicon} />
</svelte:head>

{@render children()}
