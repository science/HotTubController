<script lang="ts">
	import { api } from '$lib/api';
	import { getScheduleTime, QUICK_SCHEDULE_OPTIONS } from '$lib/scheduleUtils';
	import {
		getAutoHeatOffEnabled,
		getAutoHeatOffMinutes,
		calculateHeatOffTime
	} from '$lib/autoHeatOff';

	interface Props {
		onScheduled?: (result: { success: boolean; message: string }) => void;
	}

	let { onScheduled }: Props = $props();

	let loadingOption = $state<string | null>(null);

	async function handleQuickSchedule(option: string) {
		if (loadingOption) return;

		loadingOption = option;

		try {
			const scheduledTime = getScheduleTime(option);
			await api.scheduleJob('heater-on', scheduledTime);

			const date = new Date(scheduledTime);
			const timeStr = date.toLocaleTimeString(undefined, {
				hour: 'numeric',
				minute: '2-digit'
			});

			// Check if auto heat-off is enabled and schedule heater-off
			const autoHeatOffEnabled = getAutoHeatOffEnabled();
			if (autoHeatOffEnabled) {
				const autoHeatOffMinutes = getAutoHeatOffMinutes();
				const heatOffTime = calculateHeatOffTime(scheduledTime, autoHeatOffMinutes);
				await api.scheduleJob('heater-off', heatOffTime);

				const offDate = new Date(heatOffTime);
				const offTimeStr = offDate.toLocaleTimeString(undefined, {
					hour: 'numeric',
					minute: '2-digit'
				});

				onScheduled?.({
					success: true,
					message: `Heat scheduled for ${timeStr}, auto off at ${offTimeStr}`
				});
			} else {
				onScheduled?.({
					success: true,
					message: `Heat scheduled for ${timeStr}`
				});
			}
		} catch (e) {
			onScheduled?.({
				success: false,
				message: 'Failed to schedule. Try again.'
			});
		} finally {
			loadingOption = null;
		}
	}
</script>

<div class="bg-slate-800/50 rounded-xl p-3 border border-slate-700">
	<h3 class="text-sm font-medium text-slate-400 mb-2">Quick Heat On</h3>
	<div class="flex flex-wrap gap-2">
		{#each QUICK_SCHEDULE_OPTIONS as option}
			<button
				type="button"
				onclick={() => handleQuickSchedule(option.value)}
				disabled={loadingOption !== null}
				class="px-3 py-1.5 text-sm font-medium rounded-lg transition-colors
					{loadingOption === option.value
					? 'bg-orange-500/30 text-orange-300'
					: 'bg-slate-700 hover:bg-slate-600 text-slate-200'}"
			>
				{option.label}
			</button>
		{/each}
	</div>
</div>
