<script lang="ts">
	import { api, type ScheduledJob } from '$lib/api';
	import { schedulerConfig } from '$lib/config';
	import {
		getAutoHeatOffEnabled,
		getAutoHeatOffMinutes,
		calculateHeatOffTime
	} from '$lib/autoHeatOff';
	import {
		getEnabled as getTargetTempEnabled,
		getTargetTempF
	} from '$lib/stores/heatTargetSettings.svelte';
	import { onDestroy } from 'svelte';
	import { RefreshCw } from 'lucide-svelte';

	// Export loadJobs function for parent components to trigger refresh
	export { loadJobs };

	// Optional callback when a heater-off job completes
	interface Props {
		onHeaterOffCompleted?: () => void;
	}
	let { onHeaterOffCompleted }: Props = $props();

	let jobs = $state<ScheduledJob[]>([]);
	let loading = $state(false);
	let error = $state<string | null>(null);
	let success = $state<string | null>(null);

	// Refresh button tooltip state
	let showRefreshTooltip = $state(false);
	let refreshPressTimer: ReturnType<typeof setTimeout> | null = null;

	// Form state
	let selectedAction = $state('heater-on');
	let scheduledDate = $state('');
	let scheduledTime = $state('');
	let isRecurring = $state(false);

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
					// Call callback for heater-off job completions
					if (job.action === 'heater-off' && onHeaterOffCompleted) {
						onHeaterOffCompleted();
					}
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
		if (!isRecurring && !scheduledDate) {
			error = 'Please select a date';
			return;
		}
		if (!scheduledTime) {
			error = 'Please select a time';
			return;
		}

		loading = true;
		error = null;
		success = null;

		try {
			// Check if target temp mode should override heater-on
			const targetTempEnabled = getTargetTempEnabled();
			const useTargetTemp = selectedAction === 'heater-on' && targetTempEnabled;
			const effectiveAction = useTargetTemp ? 'heat-to-target' : selectedAction;
			const targetTempF = useTargetTemp ? getTargetTempF() : undefined;

			if (isRecurring) {
				// For recurring jobs, send time with timezone offset (HH:MM+/-HH:MM format)
				// This ensures the server schedules the job at the correct local time
				const timeWithOffset = `${scheduledTime}${getTimezoneOffset()}`;

				if (useTargetTemp) {
					await api.scheduleJob(effectiveAction, timeWithOffset, true, { target_temp_f: targetTempF });
					success = `Recurring: Daily heat to ${targetTempF}°F at ${scheduledTime}`;
				} else {
					await api.scheduleJob(effectiveAction, timeWithOffset, true);

					// If auto heat-off is enabled and action is heater-on, create paired recurring off job
					const autoHeatOffEnabled = getAutoHeatOffEnabled();
					const autoHeatOffMinutes = getAutoHeatOffMinutes();
					if (autoHeatOffEnabled && selectedAction === 'heater-on') {
						// Calculate off time by adding minutes to the time
						const [hours, minutes] = scheduledTime.split(':').map(Number);
						const offDate = new Date();
						offDate.setHours(hours, minutes + autoHeatOffMinutes, 0, 0);
						const offTimeStr = `${offDate.getHours().toString().padStart(2, '0')}:${offDate.getMinutes().toString().padStart(2, '0')}${getTimezoneOffset()}`;
						await api.scheduleJob('heater-off', offTimeStr, true);

						success = `Recurring: Daily heater-on at ${scheduledTime} with auto off at ${offDate.getHours().toString().padStart(2, '0')}:${offDate.getMinutes().toString().padStart(2, '0')}`;
					} else {
						success = `Recurring job created: Daily at ${scheduledTime}`;
					}
				}
			} else {
				// One-off job with full datetime
				const scheduledDateTime = `${scheduledDate}T${scheduledTime}:00${getTimezoneOffset()}`;

				if (useTargetTemp) {
					await api.scheduleJob(effectiveAction, scheduledDateTime, false, { target_temp_f: targetTempF });
					const onTime = new Date(scheduledDateTime).toLocaleTimeString(undefined, {
						hour: 'numeric',
						minute: '2-digit'
					});
					success = `Scheduled heat to ${targetTempF}°F at ${onTime}`;
				} else {
					await api.scheduleJob(effectiveAction, scheduledDateTime, false);

					// If auto heat-off is enabled and action is heater-on, schedule heater-off too
					const autoHeatOffEnabled = getAutoHeatOffEnabled();
					const autoHeatOffMinutes = getAutoHeatOffMinutes();
					if (autoHeatOffEnabled && selectedAction === 'heater-on') {
						const heatOffTime = calculateHeatOffTime(scheduledDateTime, autoHeatOffMinutes);
						await api.scheduleJob('heater-off', heatOffTime, false);

						// Format times for success message
						const onTime = new Date(scheduledDateTime).toLocaleTimeString(undefined, {
							hour: 'numeric',
							minute: '2-digit'
						});
						const offTime = new Date(heatOffTime).toLocaleTimeString(undefined, {
							hour: 'numeric',
							minute: '2-digit'
						});
						success = `Scheduled heater-on at ${onTime} and auto heat-off at ${offTime}`;
					} else {
						success = 'Job scheduled successfully!';
					}
				}
			}

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

	async function handleRefresh() {
		if (showRefreshTooltip) return;
		await loadJobs();
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

	function formatRecurringTime(timeString: string): string {
		// Handle two formats:
		// 1. New UTC format: "14:30:00+00:00" (from timezone-aware scheduling)
		// 2. Legacy format: "06:30" (bare time, backward compatible)

		if (timeString.includes('+') || timeString.includes('Z')) {
			// New format: UTC time with offset indicator
			// Parse as ISO datetime using a reference date, then convert to local
			const refDate = `2030-01-01T${timeString}`;
			const date = new Date(refDate);
			return date.toLocaleTimeString(undefined, {
				hour: 'numeric',
				minute: '2-digit'
			});
		}

		// Legacy format: bare HH:MM (assumes server timezone)
		const [hours, minutes] = timeString.split(':').map(Number);
		const date = new Date();
		date.setHours(hours, minutes, 0, 0);
		return date.toLocaleTimeString(undefined, {
			hour: 'numeric',
			minute: '2-digit'
		});
	}

	// Computed: split jobs into recurring and one-off
	const recurringJobs = $derived(jobs.filter((j) => j.recurring));
	const oneOffJobs = $derived(jobs.filter((j) => !j.recurring));

	// Map for displaying job actions (includes heat-to-target for existing jobs)
	const actionDisplayLabels: Record<string, string> = {
		'heater-on': 'Heater ON',
		'heater-off': 'Heater OFF',
		'pump-run': 'Run Pump',
		'heat-to-target': 'Heat to Target'
	};

	function getActionLabel(action: string): string {
		return actionDisplayLabels[action] ?? action;
	}

	function getJobDisplayLabel(job: ScheduledJob): string {
		if (job.action === 'heat-to-target' && job.params?.target_temp_f) {
			const label = `Heat to ${job.params.target_temp_f}°F`;
			if (job.params?.ready_by_time) return `${label} (ready by)`;
			return label;
		}
		return getActionLabel(job.action);
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
			{#if !isRecurring}
				<div>
					<label for="date" class="block text-sm text-slate-400 mb-1">Date</label>
					<input
						type="date"
						id="date"
						bind:value={scheduledDate}
						class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
					/>
				</div>
			{/if}
			<div class={isRecurring ? 'col-span-2' : ''}>
				<label for="time" class="block text-sm text-slate-400 mb-1">Time</label>
				<input
					type="time"
					id="time"
					bind:value={scheduledTime}
					class="w-full bg-slate-700 border border-slate-600 rounded-lg px-3 py-2 text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
				/>
			</div>
		</div>

		<!-- Recurring checkbox -->
		<label class="flex items-center gap-2 cursor-pointer">
			<input
				type="checkbox"
				bind:checked={isRecurring}
				class="w-4 h-4 rounded bg-slate-700 border-slate-600 text-blue-500 focus:ring-blue-500 focus:ring-offset-slate-800"
			/>
			<span class="text-slate-300 text-sm">Recurring (daily)</span>
		</label>
		{#if isRecurring}
			<p class="text-slate-500 text-xs">Runs every day at {scheduledTime || 'selected time'}</p>
		{/if}

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

	<!-- Daily Schedule (Recurring Jobs) -->
	{#if recurringJobs.length > 0}
		<div class="border-t border-slate-700 pt-4 mb-4">
			<div class="flex items-center justify-between mb-2">
				<h3 class="text-sm font-medium text-slate-400">Daily Schedule</h3>
			</div>
			<ul class="space-y-2">
				{#each recurringJobs as job (job.jobId)}
					<li class="flex items-center justify-between bg-purple-900/30 border border-purple-700/50 rounded-lg px-3 py-2">
						<div>
							<span class="text-slate-200 font-medium">{getJobDisplayLabel(job)}</span>
							<span class="text-purple-300 text-sm ml-2">Daily at {formatRecurringTime(job.scheduledTime)}</span>
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
	{/if}

	<!-- Upcoming Jobs (One-off) -->
	<div class="border-t border-slate-700 pt-4">
		<div class="flex items-center justify-between mb-2">
			<h3 class="text-sm font-medium text-slate-400">Upcoming</h3>
			<div class="relative">
				<button
					type="button"
					aria-label="Refresh pending jobs"
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
						Refresh pending jobs
						<div
							class="absolute top-full right-2 border-4 border-transparent border-t-slate-700"
						></div>
					</div>
				{/if}
			</div>
		</div>
		{#if oneOffJobs.length > 0}
			<ul class="space-y-2">
				{#each oneOffJobs as job (job.jobId)}
					<li class="flex items-center justify-between bg-slate-700/50 rounded-lg px-3 py-2">
						<div>
							<span class="text-slate-200 font-medium">{getJobDisplayLabel(job)}</span>
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
		{:else}
			<p class="text-slate-500 text-sm text-center py-2">No upcoming jobs</p>
		{/if}
	</div>
</div>
