<script lang="ts">
	import CompactControlButton from '$lib/components/CompactControlButton.svelte';
	import QuickSchedulePanel from '$lib/components/QuickSchedulePanel.svelte';
	import SchedulePanel from '$lib/components/SchedulePanel.svelte';
	import { api } from '$lib/api';
	import { goto } from '$app/navigation';
	import { base } from '$app/paths';

	let { data } = $props();

	let status = $state<{ message: string; type: 'success' | 'error' } | null>(null);
	let schedulePanel = $state<{ loadJobs: () => Promise<void> } | null>(null);

	async function handleAction(action: () => Promise<unknown>, successMsg: string) {
		try {
			await action();
			status = { message: successMsg, type: 'success' };
		} catch (e) {
			if (e instanceof Error && e.message === 'Unauthorized') {
				goto(`${base}/login`);
				return;
			}
			status = { message: 'Action failed. Try again.', type: 'error' };
		}
		setTimeout(() => {
			status = null;
		}, 3000);
	}

	async function handleLogout() {
		try {
			await fetch(`${base}/backend/public/api/auth/logout`, {
				method: 'POST',
				credentials: 'include'
			});
		} catch (e) {
			// Ignore errors
		}
		goto(`${base}/login`);
	}

	function handleQuickScheduled(result: { success: boolean; message: string }) {
		status = {
			message: result.message,
			type: result.success ? 'success' : 'error'
		};
		setTimeout(() => {
			status = null;
		}, 3000);
		// Refresh the schedule panel to show the new job
		schedulePanel?.loadJobs();
	}
</script>

<div class="min-h-screen bg-slate-900 p-4 flex flex-col">
	<header class="mb-6 flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
		<h1 class="text-xl font-bold text-slate-100 tracking-wide">HOT TUB CONTROL</h1>
		{#if data.user}
			<div class="flex items-center gap-2">
				<span class="text-slate-400 text-sm">{data.user.username}</span>
				{#if data.user.role === 'admin'}
					<a href="{base}/users" class="text-slate-500 hover:text-slate-300 text-sm underline">
						Users
					</a>
				{/if}
				<button
					onclick={handleLogout}
					class="text-slate-500 hover:text-slate-300 text-sm underline"
				>
					Logout
				</button>
			</div>
		{/if}
	</header>

	<main class="flex-1 flex flex-col gap-3 max-w-md mx-auto w-full">
		<!-- Compact Primary Controls -->
		<div class="grid grid-cols-3 gap-2">
			<CompactControlButton
				label="ON"
				icon="flame"
				variant="primary"
				tooltip="Turn on the hot tub heater"
				onClick={() => handleAction(api.heaterOn, 'Heater turned ON')}
			/>
			<CompactControlButton
				label="OFF"
				icon="flame-off"
				variant="secondary"
				tooltip="Turn off the hot tub heater"
				onClick={() => handleAction(api.heaterOff, 'Heater turned OFF')}
			/>
			<CompactControlButton
				label="PUMP"
				icon="refresh"
				variant="tertiary"
				tooltip="Run the circulation pump for 2 hours"
				onClick={() => handleAction(api.pumpRun, 'Pump running for 2 hours')}
			/>
		</div>

		<!-- Quick Schedule Buttons -->
		<QuickSchedulePanel onScheduled={handleQuickScheduled} />

		<!-- Full Schedule Panel -->
		<SchedulePanel bind:this={schedulePanel} />
	</main>

	<footer class="mt-6 text-center">
		{#if status}
			<div
				class="inline-block px-4 py-2 rounded-lg text-sm font-medium transition-all {status.type === 'success'
					? 'bg-green-500/20 text-green-400 border border-green-500/50'
					: 'bg-red-500/20 text-red-400 border border-red-500/50'}"
			>
				{status.message}
			</div>
		{:else}
			<p class="text-slate-500 text-sm">Hold any button for help</p>
		{/if}
	</footer>
</div>
