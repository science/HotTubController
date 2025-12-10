<script lang="ts">
	import { goto } from '$app/navigation';
	import { login, getError, clearError } from '$lib/stores/auth.svelte';

	let username = $state('');
	let password = $state('');
	let isSubmitting = $state(false);

	const error = $derived(getError());

	async function handleSubmit(e: Event) {
		e.preventDefault();
		isSubmitting = true;
		clearError();

		const success = await login(username, password);

		if (success) {
			goto('/');
		}

		isSubmitting = false;
	}
</script>

<div class="min-h-screen bg-slate-900 flex items-center justify-center p-4">
	<div class="w-full max-w-sm">
		<header class="text-center mb-8">
			<h1 class="text-xl font-bold text-slate-100 tracking-wide">HOT TUB CONTROL</h1>
			<p class="text-slate-400 text-sm mt-2">Sign in to continue</p>
		</header>

		<form onsubmit={handleSubmit} class="space-y-4">
			{#if error}
				<div class="bg-red-500/20 text-red-400 border border-red-500/50 px-4 py-2 rounded-lg text-sm">
					{error}
				</div>
			{/if}

			<div>
				<label for="username" class="block text-slate-300 text-sm font-medium mb-1">
					Username
				</label>
				<input
					type="text"
					id="username"
					bind:value={username}
					required
					autocomplete="username"
					class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
					placeholder="Enter username"
				/>
			</div>

			<div>
				<label for="password" class="block text-slate-300 text-sm font-medium mb-1">
					Password
				</label>
				<input
					type="password"
					id="password"
					bind:value={password}
					required
					autocomplete="current-password"
					class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
					placeholder="Enter password"
				/>
			</div>

			<button
				type="submit"
				disabled={isSubmitting}
				class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
			>
				{#if isSubmitting}
					Signing in...
				{:else}
					Sign In
				{/if}
			</button>
		</form>
	</div>
</div>
