<script lang="ts">
	import { api, type ScheduledJob } from '$lib/api';
	import { schedulerConfig } from '$lib/config';
	import { onDestroy } from 'svelte';

	let jobs = $state<ScheduledJob[]>([]);
	let loading = $state(false);
	let error = $state<string | null>(null);
	let success = $state<string | null>(null);

	// Form state
	let selectedAction = $state('heater-on');
	let scheduledDate = $state('');
	let scheduledTime = $state('');

	// Auto-refresh timers for pending jobs
	const refreshTimers = new Map<string, ReturnType<typeof setTimeout>>();
	// Timer for the sliding window recheck
	let windowCheckTimer: ReturnType<typeof setTimeout> | null = null;

	const actions = [
		{ value: 'heater-on', label: 'Heater ON' },
		{ value: 'heater-off', label: 'Heater OFF' },
		{ value: 'pump-run', label: 'Run Pump' }
	];

	// Set default date/time to tomorrow at 6:00 AM
	$effect(() => {
		const tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		tomorrow.setHours(6, 0, 0, 0);
		scheduledDate = tomorrow.toISOString().split('T')[0];
		scheduledTime = '06:00';
	});

	/**
	 * Set up auto-refresh timers for jobs scheduled in the near future.
	 * Timers fire 60 seconds after the scheduled time to allow for execution.
	 */
	function setupRefreshTimers(jobList: ScheduledJob[]) {
		const now = Date.now();
		const { refreshBufferMs, maxTimerWindowMs } = schedulerConfig;

		for (const job of jobList) {
			// Skip if timer already exists for this job
			if (refreshTimers.has(job.jobId)) {
				continue;
			}

			const scheduledTime = new Date(job.scheduledTime).getTime();
			const refreshTime = scheduledTime + refreshBufferMs;
			const delay = refreshTime - now;

			// Only set timer if job is within the timer window and in the future
			if (delay > 0 && delay <= maxTimerWindowMs + refreshBufferMs) {
				const timerId = setTimeout(() => {
					refreshTimers.delete(job.jobId);
					loadJobs();
				}, delay);
				refreshTimers.set(job.jobId, timerId);
			}
		}
	}

	/**
	 * Clear all refresh timers.
	 */
	function clearRefreshTimers() {
		for (const timerId of refreshTimers.values()) {
			clearTimeout(timerId);
		}
		refreshTimers.clear();
	}

	/**
	 * Sliding window recheck: periodically examine in-memory jobs
	 * and promote any that have entered the timer window.
	 * No network call - just checks local state.
	 */
	function scheduleWindowRecheck() {
		windowCheckTimer = setTimeout(() => {
			// Check current in-memory jobs for any that now fall within the timer window
			setupRefreshTimers(jobs);
			// Reschedule for next interval
			scheduleWindowRecheck();
		}, schedulerConfig.recheckIntervalMs);
	}

	/**
	 * Clear the sliding window recheck timer.
	 */
	function clearWindowRecheck() {
		if (windowCheckTimer !== null) {
			clearTimeout(windowCheckTimer);
			windowCheckTimer = null;
		}
	}

	// Clean up all timers on component destroy
	onDestroy(() => {
		clearRefreshTimers();
		clearWindowRecheck();
	});

	async function loadJobs() {
		try {
			const response = await api.listScheduledJobs();
			jobs = response.jobs;
			// Set up timers for newly loaded jobs
			setupRefreshTimers(response.jobs);
		} catch (e) {
			console.error('Failed to load jobs', e);
		}
	}

	function getTimezoneOffset(): string {
		const offset = new Date().getTimezoneOffset();
		const sign = offset <= 0 ? '+' : '-';
		const hours = Math.floor(Math.abs(offset) / 60);
		const minutes = Math.abs(offset) % 60;
		return `${sign}${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
	}

	async function handleSchedule() {
		if (!scheduledDate || !scheduledTime) {
			error = 'Please select date and time';
			return;
		}

		loading = true;
		error = null;
		success = null;

		try {
			const scheduledDateTime = `${scheduledDate}T${scheduledTime}:00${getTimezoneOffset()}`;
			await api.scheduleJob(selectedAction, scheduledDateTime);
			success = 'Job scheduled successfully!';
			await loadJobs();

			// Clear success after 3 seconds
			setTimeout(() => {
				success = null;
			}, 3000);
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to schedule job';
		} finally {
			loading = false;
		}
	}

	async function handleCancel(jobId: string) {
		try {
			await api.cancelScheduledJob(jobId);
			await loadJobs();
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to cancel job';
		}
	}

	function formatDateTime(isoString: string): string {
		const date = new Date(isoString);
		return date.toLocaleString(undefined, {
			weekday: 'short',
			month: 'short',
			day: 'numeric',
			hour: 'numeric',
			minute: '2-digit'
		});
	}

	function getActionLabel(action: string): string {
		return actions.find((a) => a.value === action)?.label ?? action;
	}

	// Load jobs on mount and start the sliding window recheck
	$effect(() => {
		loadJobs();
		scheduleWindowRecheck();
	});
</script>

<div class="bg-slate-800/50 rounded-xl p-4 border border-slate-700">
	<h2 class="text-lg font-semibold text-slate-200 mb-4">Schedule</h2>

	<!-- Schedule Form -->
	<div class="space-y-3 mb-4">
		<div>
			<label for="action" class="block text-sm text-slate-400 mb-1">Action</label>
			<select
				id="action"
				bind:value={selectedAction}
				class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
			>
				{#each actions as action}
					<option value={action.value}>{action.label}</option>
				{/each}
			</select>
		</div>

		<div class="grid grid-cols-2 gap-2">
			<div>
				<label for="date" class="block text-sm text-slate-400 mb-1">Date</label>
				<input
					type="date"
					id="date"
					bind:value={scheduledDate}
					class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
				/>
			</div>
			<div>
				<label for="time" class="block text-sm text-slate-400 mb-1">Time</label>
				<input
					type="time"
					id="time"
					bind:value={scheduledTime}
					class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
				/>
			</div>
		</div>

		<button
			onclick={handleSchedule}
			disabled={loading}
			class="w-full bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 disabled:cursor-not-allowed text-white font-medium py-2 px-4 rounded-lg transition-colors"
		>
			{loading ? 'Scheduling...' : 'Schedule'}
		</button>
	</div>

	<!-- Status Messages -->
	{#if error}
		<div class="mb-4 px-3 py-2 bg-red-500/20 border border-red-500/50 rounded-lg text-red-400 text-sm">
			{error}
		</div>
	{/if}
	{#if success}
		<div class="mb-4 px-3 py-2 bg-green-500/20 border border-green-500/50 rounded-lg text-green-400 text-sm">
			{success}
		</div>
	{/if}

	<!-- Scheduled Jobs List -->
	{#if jobs.length > 0}
		<div class="border-t border-slate-700 pt-4">
			<h3 class="text-sm font-medium text-slate-400 mb-2">Pending Jobs</h3>
			<ul class="space-y-2">
				{#each jobs as job (job.jobId)}
					<li class="flex items-center justify-between bg-slate-700/50 rounded-lg px-3 py-2">
						<div>
							<span class="text-slate-200 font-medium">{getActionLabel(job.action)}</span>
							<span class="text-slate-400 text-sm ml-2">{formatDateTime(job.scheduledTime)}</span>
						</div>
						<button
							onclick={() => handleCancel(job.jobId)}
							class="text-red-400 hover:text-red-300 text-sm font-medium"
						>
							Cancel
						</button>
					</li>
				{/each}
			</ul>
		</div>
	{:else}
		<p class="text-slate-500 text-sm text-center py-2">No scheduled jobs</p>
	{/if}
</div>
