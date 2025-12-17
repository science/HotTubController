<script lang="ts">
	import { api, type User, type CreateUserResponse } from '$lib/api';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';
	import { RefreshCw } from 'lucide-svelte';

	let { data } = $props();

	let users = $state<User[]>([]);
	let isLoading = $state(true);
	let error = $state<string | null>(null);

	// Form state
	let newUsername = $state('');
	let newPassword = $state('');
	let newRole = $state<'user' | 'admin' | 'basic'>('user');
	let isCreating = $state(false);

	// Credential display modal
	let showCredentials = $state(false);
	let createdCredentials = $state<CreateUserResponse | null>(null);
	let loginUrl = $state('');

	// Refresh button tooltip state
	let showRefreshTooltip = $state(false);
	let refreshPressTimer: ReturnType<typeof setTimeout> | null = null;

	// Check if current user is admin
	const isAdmin = $derived(data.user?.role === 'admin');

	async function loadUsers() {
		try {
			isLoading = true;
			error = null;
			const response = await api.listUsers();
			users = response.users;
		} catch (e) {
			if (e instanceof Error) {
				if (e.message === 'Unauthorized') {
					goto(`${base}/login`);
					return;
				}
				if (e.message === 'Forbidden') {
					error = 'Admin access required';
					return;
				}
				error = e.message;
			}
		} finally {
			isLoading = false;
		}
	}

	async function createUser() {
		if (!newUsername || !newPassword) {
			error = 'Username and password are required';
			return;
		}

		try {
			isCreating = true;
			error = null;
			const result = await api.createUser(newUsername, newPassword, newRole);
			createdCredentials = result;
			// Compute login URL (window.location.origin + base path + /login)
			loginUrl = window.location.origin + base + '/login';
			showCredentials = true;

			// Clear form and reload
			newUsername = '';
			newPassword = '';
			newRole = 'user';
			await loadUsers();
		} catch (e) {
			if (e instanceof Error) {
				error = e.message;
			}
		} finally {
			isCreating = false;
		}
	}

	async function deleteUser(username: string) {
		if (!confirm(`Are you sure you want to delete user "${username}"?`)) {
			return;
		}

		try {
			await api.deleteUser(username);
			await loadUsers();
		} catch (e) {
			if (e instanceof Error) {
				error = e.message;
			}
		}
	}

	function copyToClipboard(text: string) {
		navigator.clipboard.writeText(text);
	}

	function closeModal() {
		showCredentials = false;
		createdCredentials = null;
	}

	async function handleRefresh() {
		if (showRefreshTooltip) return;
		await loadUsers();
	}

	function handleRefreshPressStart() {
		refreshPressTimer = setTimeout(() => {
			showRefreshTooltip = true;
		}, 500);
	}

	function handleRefreshPressEnd() {
		if (refreshPressTimer) {
			clearTimeout(refreshPressTimer);
			refreshPressTimer = null;
		}
		if (showRefreshTooltip) {
			setTimeout(() => {
				showRefreshTooltip = false;
			}, 100);
		}
	}

	// Load users on mount
	$effect(() => {
		if (isAdmin) {
			loadUsers();
		}
	});
</script>

<div class="min-h-screen bg-slate-900 p-4 flex flex-col">
	<header class="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
		<h1 class="text-xl font-bold text-slate-100 tracking-wide">USER MANAGEMENT</h1>
		<div class="flex items-center gap-4">
			<a href="{base}/" class="text-slate-400 hover:text-slate-200 text-sm underline">
				Back to Controls
			</a>
			{#if data.user}
				<span class="text-slate-400 text-sm">{data.user.username}</span>
			{/if}
		</div>
	</header>

	<main class="flex-1 flex flex-col gap-4 max-w-lg mx-auto w-full">
		{#if !isAdmin}
			<div class="bg-red-500/20 text-red-400 border border-red-500/50 rounded-lg p-4 text-center">
				Admin access required to manage users.
			</div>
		{:else}
			<!-- Error message -->
			{#if error}
				<div class="bg-red-500/20 text-red-400 border border-red-500/50 rounded-lg p-4 text-center">
					{error}
				</div>
			{/if}

			<!-- Add User Form -->
			<div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
				<h2 class="text-lg font-semibold text-slate-100 mb-3">Add New User</h2>
				<form onsubmit={(e) => { e.preventDefault(); createUser(); }} class="space-y-3">
					<div>
						<label for="username" class="block text-sm text-slate-400 mb-1">Username</label>
						<input
							type="text"
							id="username"
							bind:value={newUsername}
							class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-slate-100 focus:outline-none focus:border-cyan-500"
							placeholder="Enter username"
						/>
					</div>
					<div>
						<label for="password" class="block text-sm text-slate-400 mb-1">Password</label>
						<input
							type="text"
							id="password"
							bind:value={newPassword}
							class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-slate-100 focus:outline-none focus:border-cyan-500"
							placeholder="Enter password"
						/>
					</div>
					<div>
						<label for="role" class="block text-sm text-slate-400 mb-1">Role</label>
						<select
							id="role"
							bind:value={newRole}
							class="w-full px-3 py-2 bg-slate-700 border border-slate-600 rounded text-slate-100 focus:outline-none focus:border-cyan-500"
						>
							<option value="basic">Basic (simplified UI)</option>
							<option value="user">User</option>
							<option value="admin">Admin</option>
						</select>
					</div>
					<button
						type="submit"
						disabled={isCreating}
						class="w-full py-2 px-4 bg-cyan-600 hover:bg-cyan-500 disabled:bg-slate-600 text-white rounded font-medium transition-colors"
					>
						{isCreating ? 'Creating...' : 'Create User'}
					</button>
				</form>
			</div>

			<!-- User List -->
			<div class="bg-slate-800 rounded-lg p-4 border border-slate-700">
				<div class="flex items-center justify-between mb-3">
					<h2 class="text-lg font-semibold text-slate-100">Existing Users</h2>
					<div class="relative">
						<button
							type="button"
							aria-label="Refresh user list"
							onclick={handleRefresh}
							onmousedown={handleRefreshPressStart}
							onmouseup={handleRefreshPressEnd}
							onmouseleave={handleRefreshPressEnd}
							ontouchstart={handleRefreshPressStart}
							ontouchend={handleRefreshPressEnd}
							ontouchcancel={handleRefreshPressEnd}
							class="p-1 text-slate-400 hover:text-slate-300 transition-colors rounded"
						>
							<RefreshCw class="w-4 h-4" />
						</button>
						{#if showRefreshTooltip}
							<div
								class="absolute bottom-full right-0 mb-2 px-2 py-1 bg-slate-700 text-slate-100 text-xs rounded shadow-lg whitespace-nowrap z-10"
							>
								Refresh user list
								<div
									class="absolute top-full right-2 border-4 border-transparent border-t-slate-700"
								></div>
							</div>
						{/if}
					</div>
				</div>
				{#if isLoading}
					<p class="text-slate-400 text-center py-4">Loading users...</p>
				{:else if users.length === 0}
					<p class="text-slate-400 text-center py-4">No users found</p>
				{:else}
					<ul class="space-y-2">
						{#each users as user}
							<li class="flex items-center justify-between bg-slate-700/50 rounded p-3">
								<div>
									<span class="text-slate-100 font-medium">{user.username}</span>
									<span class="ml-2 px-2 py-0.5 text-xs rounded {user.role === 'admin' ? 'bg-amber-500/20 text-amber-400' : user.role === 'basic' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-slate-600 text-slate-300'}">
										{user.role}
									</span>
								</div>
								{#if user.username !== data.user?.username}
									<button
										onclick={() => deleteUser(user.username)}
										class="text-red-400 hover:text-red-300 text-sm"
									>
										Delete
									</button>
								{:else}
									<span class="text-slate-500 text-sm">(you)</span>
								{/if}
							</li>
						{/each}
					</ul>
				{/if}
			</div>
		{/if}
	</main>
</div>

<!-- Credential Modal -->
{#if showCredentials && createdCredentials}
	<div class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
		<div class="bg-slate-800 rounded-lg p-6 max-w-md w-full border border-slate-700">
			<h2 class="text-lg font-semibold text-slate-100 mb-4">User Created Successfully</h2>
			<p class="text-slate-400 text-sm mb-4">Share these credentials with the user:</p>

			<div class="bg-slate-900 rounded p-4 mb-4 space-y-2">
				<div class="flex items-center justify-between">
					<span class="text-slate-400">Login URL:</span>
					<code class="text-cyan-400">{loginUrl}</code>
				</div>
				<div class="flex items-center justify-between">
					<span class="text-slate-400">Username:</span>
					<code class="text-cyan-400">{createdCredentials.username}</code>
				</div>
				<div class="flex items-center justify-between">
					<span class="text-slate-400">Password:</span>
					<code class="text-cyan-400">{createdCredentials.password}</code>
				</div>
			</div>

			<button
				onclick={() => copyToClipboard(`Login URL: ${loginUrl}\nUsername: ${createdCredentials?.username}\nPassword: ${createdCredentials?.password}`)}
				class="w-full py-2 px-4 bg-cyan-600 hover:bg-cyan-500 text-white rounded font-medium mb-2 transition-colors"
			>
				Copy
			</button>
			<button
				onclick={closeModal}
				class="w-full py-2 px-4 bg-slate-700 hover:bg-slate-600 text-slate-200 rounded font-medium transition-colors"
			>
				Done
			</button>
		</div>
	</div>
{/if}
